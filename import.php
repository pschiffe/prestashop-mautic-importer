#!/usr/bin/env php
<?php
/**
 * PrestaShop -> Mautic newsletter subscriber importer.
 * Copyright (c) 2026 Peter Schiffer and contributors. SPDX-License-Identifier: MIT
 * Requires PHP >= 8.2, pdo_mysql and curl. No Composer dependencies.
 */
declare(strict_types=1);

namespace PrestaShopMautic;

use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ImportError extends RuntimeException {}
final class ConfigurationError extends RuntimeException {}
final class ContactError extends RuntimeException {}

/** Log one JSON object per line. Do not pass credentials or raw API responses. */
function logEvent(string $level, string $message, array $context = []): void
{
    fwrite(STDERR, json_encode(
        ['time' => gmdate('c'), 'level' => $level, 'message' => $message] + $context,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ) . PHP_EOL);
}

function contactRef(string $email): string
{
    return substr(hash('sha256', $email), 0, 16);
}

/** Email identity is case-insensitive here; never strip plus-addressing or dots. */
function normalizeEmail(string $email): ?string
{
    $email = strtolower(trim($email));
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
        ? $email : null;
}

function validateConfig(array $config): array
{
    $keys = static function (array $data, array $allowed, string $section): void {
        if (array_diff(array_keys($data), $allowed)) {
            throw new ConfigurationError("Unknown configuration key in {$section}.");
        }
    };
    $text = static function (array $data, string $key, string $section): string {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === ''
            || str_contains($data[$key], "\0")) {
            throw new ConfigurationError("{$section}.{$key} must be a nonempty string.");
        }
        return $data[$key];
    };
    $keys($config, ['source', 'prestashop', 'mautic'], 'root');
    $source = $text($config, 'source', 'root');
    if (!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $source)) {
        throw new ConfigurationError('source must be a lowercase slug, 1-32 characters, starting with a letter.');
    }
    foreach (['prestashop', 'mautic'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            throw new ConfigurationError("{$section} must be a JSON object.");
        }
    }
    $prestashop = $config['prestashop'];
    $keys($prestashop, ['dsn', 'username', 'password', 'table_prefix', 'shop_id', 'language_id'], 'prestashop');
    foreach (['dsn', 'username', 'password'] as $key) {
        $text($prestashop, $key, 'prestashop');
    }
    if (!str_starts_with($prestashop['dsn'], 'mysql:')
        || !preg_match('/(?:mysql:|;)\s*charset=utf8mb4(?:;|$)/i', $prestashop['dsn'])) {
        throw new ConfigurationError('prestashop.dsn must be a mysql: DSN containing charset=utf8mb4.');
    }
    if (!isset($prestashop['table_prefix']) || !is_string($prestashop['table_prefix'])
        || !preg_match('/\A[A-Za-z0-9_]{0,32}\z/D', $prestashop['table_prefix'])) {
        throw new ConfigurationError('prestashop.table_prefix must contain only letters, digits and underscores (up to 32).');
    }
    foreach (['shop_id', 'language_id'] as $key) {
        if (!isset($prestashop[$key]) || !is_int($prestashop[$key]) || $prestashop[$key] < 1) {
            throw new ConfigurationError("prestashop.{$key} must be a positive JSON integer.");
        }
    }
    $mautic = $config['mautic'];
    $keys($mautic, ['url', 'client_id', 'client_secret'], 'mautic');
    foreach (['url', 'client_id', 'client_secret'] as $key) {
        $text($mautic, $key, 'mautic');
    }
    $url = parse_url($mautic['url']);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        || preg_match('/[\x00-\x20\x7f]/', $mautic['url'])) {
        throw new ConfigurationError('mautic.url must be an HTTPS base URL without credentials, query or fragment.');
    }
    $config['mautic']['url'] = rtrim($mautic['url'], '/');
    return $config;
}

function readConfig(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new ConfigurationError('Configuration file is missing or unreadable.');
    }
    $json = @file_get_contents($path);
    if ($json === false) {
        throw new ConfigurationError('Cannot read configuration file.');
    }
    try {
        $config = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new ConfigurationError('Configuration is not valid JSON.');
    }
    if (!is_array($config)) {
        throw new ConfigurationError('Configuration must be a JSON object.');
    }
    return validateConfig($config);
}

function checkRuntime(): void
{
    if (PHP_VERSION_ID < 80200) {
        throw new ConfigurationError('PHP 8.2 or newer is required.');
    }
    foreach (['pdo_mysql', 'curl'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new ConfigurationError("The CLI PHP interpreter needs the {$extension} extension.");
        }
    }
}

/** Fixed queries: prefix is validated; all other input is bound. No GROUP_CONCAT. */
function sourceQueries(string $prefix): array
{
    if (!preg_match('/\A[A-Za-z0-9_]{0,32}\z/D', $prefix)) {
        throw new ConfigurationError('Invalid table prefix.');
    }
    return [
        'customers' => "SELECT c.id_customer, c.email, c.firstname, c.lastname, c.is_guest,
                   c.id_default_group, dgl.name AS default_group_name,
                   cg.id_group, gl.name AS group_name
            FROM `{$prefix}customer` c
            LEFT JOIN `{$prefix}customer_group` cg ON cg.id_customer = c.id_customer
            LEFT JOIN `{$prefix}group_lang` gl ON gl.id_group = cg.id_group AND gl.id_lang = :group_language
            LEFT JOIN `{$prefix}group_lang` dgl ON dgl.id_group = c.id_default_group AND dgl.id_lang = :default_language
            WHERE c.id_shop = :shop AND c.newsletter = 1 AND c.active = 1 AND c.deleted = 0
            ORDER BY c.id_customer, cg.id_group",
        'newsletter' => "SELECT e.id, e.email
            FROM `{$prefix}emailsubscription` e
            WHERE e.id_shop = :shop AND e.active = 1
            ORDER BY e.id",
    ];
}

/** Prefer a registered subscriber, then the newest eligible customer ID. */
function mergeCustomerRow(array &$contacts, array $row): bool
{
    $email = normalizeEmail((string) $row['email']);
    if ($email === null) {
        return false;
    }
    $id = (int) $row['id_customer'];
    $type = (int) $row['is_guest'] === 1 ? 'guest' : 'customer';
    $rank = [$type === 'customer' ? 1 : 0, $id];
    $current = $contacts[$email] ?? null;
    if ($current === null || $rank > $current['rank']) {
        $contacts[$email] = [
            'email' => $email,
            'firstname' => trim((string) $row['firstname']),
            'lastname' => trim((string) $row['lastname']),
            'type' => $type, 'rank' => $rank, 'customer_id' => $id,
            'default_group_id' => (int) $row['id_default_group'],
            'default_group_name' => (string) ($row['default_group_name'] ?? ''),
            'groups' => [],
        ];
    }
    if ($contacts[$email]['customer_id'] === $id && (int) ($row['id_group'] ?? 0) > 0) {
        $contacts[$email]['groups'][(int) $row['id_group']] = (string) ($row['group_name'] ?? '');
    }
    return true;
}

function mergeNewsletterRow(array &$contacts, array $row): bool
{
    $email = normalizeEmail((string) $row['email']);
    if ($email === null) {
        return false;
    }
    $contacts[$email] ??= [
        'email' => $email, 'firstname' => '', 'lastname' => '', 'type' => 'newsletter_only',
        'rank' => [-1, 0], 'customer_id' => null,
        'default_group_id' => 0, 'default_group_name' => '', 'groups' => [],
    ];
    return true;
}

/**
 * Read both populations before making API calls; the database is never written.
 * Returns contacts keyed by normalized email and the number of invalid source records.
 * Rank and customer_id select the winning source row; groups map IDs to raw source labels.
 *
 * @return array{0: array<string, array{
 *     email: string, firstname: string, lastname: string,
 *     type: 'customer'|'guest'|'newsletter_only', rank: array{int, int}, customer_id: int|null,
 *     default_group_id: int, default_group_name: string, groups: array<int, string>
 * }>, 1: int}
 */
function readSubscribers(array $prestashop, Closure $log): array
{
    try {
        // Driver-specific constants were introduced in PHP 8.4; keep PHP 8.2/8.3 support.
        $bufferedQueryAttribute = PHP_VERSION_ID >= 80400
            ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        $db = new PDO($prestashop['dsn'], $prestashop['username'], $prestashop['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
            $bufferedQueryAttribute => false,
        ]);
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        $queries = sourceQueries($prestashop['table_prefix']);
        $contacts = [];
        $invalid = [];
        foreach ($queries as $kind => $query) {
            $statement = $db->prepare($query);
            $parameters = ['shop' => $prestashop['shop_id']];
            if ($kind === 'customers') {
                $parameters += ['group_language' => $prestashop['language_id'], 'default_language' => $prestashop['language_id']];
            }
            $statement->execute($parameters);
            while ($row = $statement->fetch()) {
                $ok = $kind === 'customers'
                    ? mergeCustomerRow($contacts, $row) : mergeNewsletterRow($contacts, $row);
                if (!$ok) {
                    $id = (int) ($row['id_customer'] ?? $row['id']);
                    $invalid["{$kind}:{$id}"] = true;
                }
            }
            $statement->closeCursor();
        }
        $db->commit();
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        // PDO exception messages may contain database usernames, SQL or contact data.
        $state = preg_replace('/[^A-Z0-9]/', '', (string) $e->getCode());
        throw new ImportError("PrestaShop read failed (SQLSTATE {$state}); check DB access and schema.");
    }
    foreach (array_keys($invalid) as $rowRef) {
        $log('ERROR', 'Invalid source email; row skipped', ['source_row' => $rowRef]);
    }
    ksort($contacts, SORT_STRING);
    $log('INFO', 'Source snapshot loaded', ['contacts' => count($contacts), 'invalid_rows' => count($invalid)]);
    if ($contacts === []) {
        $log('WARNING', 'No eligible subscribers; check shop_id and source flags if unexpected');
    }
    return [$contacts, count($invalid)];
}

/** Match Mautic 7.2's tag sanitizer; repeat so nested entities stay stable on the API's next pass. */
function normalizeGroupLabel(string $name): string
{
    do {
        $previous = $name;
        $name = preg_replace('/\x00|<[^>]*>?/', '', html_entity_decode(trim($name), ENT_QUOTES, 'UTF-8'));
    } while ($name !== $previous);
    return $name;
}

function desiredTags(array $source, string $slug): array
{
    $prefix = "prestashop:{$slug}";
    $tags = [$prefix, "{$prefix}:type:{$source['type']}"];
    $label = static function (int $id, string $name): string {
        $name = normalizeGroupLabel($name);
        return $id . ':' . ($name !== '' ? $name : "group-{$id}");
    };
    foreach ($source['groups'] as $id => $name) {
        $tags[] = "{$prefix}:group:" . $label((int) $id, $name);
    }
    if ($source['default_group_id'] > 0) {
        $tags[] = "{$prefix}:default-group:" . $label($source['default_group_id'], $source['default_group_name']);
    }
    foreach ($tags as $tag) {
        if (strlen($tag) > 250 || preg_match('/[\x00-\x1f\x7f]/', $tag) || !preg_match('//u', $tag)) {
            throw new ContactError('Invalid or overlong source group tag.');
        }
    }
    sort($tags, SORT_STRING);
    return array_values(array_unique($tags));
}

function managedTag(string $tag, string $slug): bool
{
    $prefix = "prestashop:{$slug}";
    return $tag === $prefix || str_starts_with($tag, "{$prefix}:type:")
        || str_starts_with($tag, "{$prefix}:group:") || str_starts_with($tag, "{$prefix}:default-group:");
}

function fieldValue(array $contact, string $field): ?string
{
    $fields = $contact['fields'] ?? [];
    if (array_key_exists($field, $fields['all'] ?? [])) {
        $value = $fields['all'][$field];
        return is_scalar($value) ? (string) $value : null;
    }
    foreach ($fields as $group => $values) {
        if ($group !== 'all' && is_array($values) && isset($values[$field]['value'])) {
            return (string) $values[$field]['value'];
        }
    }
    return null;
}

function contactTags(array $contact): array
{
    if (!isset($contact['tags']) || !is_array($contact['tags'])) {
        throw new ImportError('Mautic returned a contact without a tags array; refusing an incomplete response.');
    }
    $tags = [];
    foreach ($contact['tags'] as $tag) {
        $name = is_array($tag) ? ($tag['tag'] ?? null) : $tag;
        if (!is_string($name)) {
            throw new ImportError('Mautic returned an invalid tag representation.');
        }
        $tags[] = $name;
    }
    return array_values(array_unique($tags));
}

/** Return only changed, importer-owned values. Never echo the API contact back. */
function contactPayload(array $source, string $slug, ?array $existing): array
{
    $payload = $existing === null ? ['email' => $source['email']] : [];
    foreach (['firstname', 'lastname'] as $field) {
        if ($source[$field] !== '' && ($existing === null || fieldValue($existing, $field) !== $source[$field])) {
            $payload[$field] = $source[$field];
        }
    }
    $desired = desiredTags($source, $slug);
    $current = $existing === null ? [] : contactTags($existing);
    $delta = array_values(array_diff($desired, $current));
    foreach (array_diff($current, $desired) as $tag) {
        if (managedTag($tag, $slug)) {
            $delta[] = '-' . $tag;
        }
    }
    if ($delta !== []) {
        $payload['tags'] = $delta;
    }
    return $payload;
}

/**
 * One cURL handle per client; the production path always verifies HTTPS.
 * Status 0 indicates a transport failure; retry_after is a delay in seconds or null.
 *
 * @return Closure(string, string, list<string>, ?string): array{status: int, body: string, errno: int, retry_after: int|null}
 */
function createCurlTransport(): Closure
{
    $handle = curl_init();
    if ($handle === false) {
        throw new ImportError('Cannot initialize cURL.');
    }
    return static function (string $method, string $url, array $headers, ?string $body) use ($handle): array {
        // Clear request bodies, headers and callbacks, but retain connections and TLS/DNS caches.
        curl_reset($handle);
        $retryAfter = null;
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            // libcurl can replay writes after an empty response on a reused connection.
            CURLOPT_FRESH_CONNECT => $method !== 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    $retryAfter = ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time());
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        return ['status' => $response === false ? 0 : $status,
            'body' => $response === false ? '' : $response, 'errno' => $errno, 'retry_after' => $retryAfter];
    };
}

final class MauticClient
{
    private string $token = '';
    private int $expiresAt = 0;
    private Closure $transport;
    private Closure $sleep;
    private Closure $log;

    public function __construct(private array $config, ?Closure $transport = null, ?Closure $sleep = null, ?Closure $log = null)
    {
        $this->transport = $transport ?? createCurlTransport();
        $this->sleep = $sleep ?? static function (int $seconds): void { sleep($seconds); };
        $this->log = $log ?? logEvent(...);
    }

    /** GET/token requests retry transient failures; contact writes are never blindly retried. */
    private function request(string $method, string $path, ?array $data = null, array $query = [], bool $tokenEndpoint = false): array
    {
        $url = $this->config['url'] . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $body = $data === null ? null : http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $safeRetry = $method === 'GET' || $tokenEndpoint;
        $renewed = false;
        $attempt = 0;
        while (true) {
            if (!$tokenEndpoint && ($this->token === '' || time() >= $this->expiresAt)) {
                $this->authenticate();
            }
            $headers = ['Accept: application/json', 'User-Agent: prestashop-mautic-importer'];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            if (!$tokenEndpoint) {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }
            $response = ($this->transport)($method, $url, $headers, $body);
            $status = (int) $response['status'];
            if ($status === 401 && !$tokenEndpoint && !$renewed) {
                $this->token = '';
                $renewed = true;
                continue; // An explicit authentication rejection did not accept the write.
            }
            $transient = in_array($status, [0, 408, 429, 500, 502, 503, 504], true);
            if ($transient && $safeRetry && $attempt < 2) {
                $delay = $response['retry_after'] ?? (1 << $attempt);
                if ($delay > 30) {
                    throw new ImportError('Mautic requested a long retry delay; stopping until the next scheduled run.');
                }
                ($this->log)('WARNING', 'Retrying a read/authentication request', ['http_status' => $status, 'delay_seconds' => $delay]);
                ($this->sleep)((int) $delay);
                ++$attempt;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $message = "Mautic {$method} {$path} failed (HTTP {$status}, cURL " . (int) ($response['errno'] ?? 0) . ').';
                // Validation errors are contact-specific; auth, redirects and availability are systemic.
                if (!$tokenEndpoint && $method !== 'GET' && in_array($status, [400, 409, 422], true)) {
                    throw new ContactError($message);
                }
                throw new ImportError($message);
            }
            try {
                $decoded = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ImportError("Mautic {$path} returned invalid JSON; response body was not logged.");
            }
            if (!is_array($decoded) || !empty($decoded['errors']) || !empty($decoded['error'])) {
                throw new ImportError("Mautic {$path} returned an unexpected API response.");
            }
            return $decoded;
        }
    }

    private function authenticate(): void
    {
        $response = $this->request('POST', '/oauth/v2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ], [], true);
        if (empty($response['access_token']) || !is_string($response['access_token'])
            || preg_match('/[\r\n]/', $response['access_token']) || (int) ($response['expires_in'] ?? 0) < 1
            || strtolower((string) ($response['token_type'] ?? 'bearer')) !== 'bearer') {
            throw new ImportError('Mautic returned an invalid OAuth2 token response.');
        }
        $this->token = $response['access_token'];
        $lifetime = (int) $response['expires_in'];
        $this->expiresAt = time() + max(1, $lifetime - min(30, intdiv($lifetime, 2)));
    }

    public function findByEmail(string $email): ?array
    {
        // Equality filtering avoids Mautic's search-language escaping and substring matches.
        // No published-only, segment, owner or DNC filter: suppressed contacts must be found.
        $response = $this->request('GET', '/api/contacts', null, [
            'where' => [['col' => 'email', 'expr' => 'eq', 'val' => $email]],
            'limit' => 2, 'start' => 0, 'orderBy' => 'id', 'orderByDir' => 'asc',
        ]);
        if (!isset($response['contacts']) || !is_array($response['contacts'])
            || !isset($response['total']) || !is_numeric($response['total'])) {
            throw new ImportError('Mautic contact lookup returned an incomplete response.');
        }
        $contacts = array_values($response['contacts']);
        // A stale count can cause a conservative skip, never a duplicate create.
        if (count($contacts) > 1 || (int) $response['total'] > 1) {
            throw new ContactError('Multiple matching Mautic contacts; resolve duplicates before importing this address.');
        }
        if ($contacts === []) {
            if ((int) $response['total'] !== 0) {
                throw new ContactError('Mautic lookup count disagrees with its contact list; retry next run.');
            }
            return null;
        }
        $contact = $contacts[0];
        if (!is_array($contact) || (int) ($contact['id'] ?? 0) < 1
            || normalizeEmail(fieldValue($contact, 'email') ?? '') !== $email) {
            throw new ContactError('Mautic lookup did not return an exact email match; no contact was changed.');
        }
        contactTags($contact); // Require the full tag representation before planning any write.
        return $contact;
    }

    public function save(?int $id, array $payload, string $email): void
    {
        // Structural guardrail: consent, DNC, owner, points and unrelated fields cannot be sent.
        $allowed = $id === null ? ['email', 'firstname', 'lastname', 'tags'] : ['firstname', 'lastname', 'tags'];
        if (array_diff(array_keys($payload), $allowed) || ($id === null && ($payload['email'] ?? '') !== $email)) {
            throw new ImportError('Refusing an unsafe contact payload.');
        }
        $path = $id === null ? '/api/contacts/new' : "/api/contacts/{$id}/edit";
        $response = $this->request($id === null ? 'POST' : 'PATCH', $path, $payload);
        $contact = $response['contact'] ?? null;
        if (!is_array($contact) || (int) ($contact['id'] ?? 0) < 1
            || ($id !== null && (int) $contact['id'] !== $id)
            || normalizeEmail(fieldValue($contact, 'email') ?? '') !== $email) {
            throw new ImportError('Contact write returned an unexpected identity; do not retry creation blindly.');
        }
        foreach (['firstname', 'lastname'] as $field) {
            if (isset($payload[$field]) && fieldValue($contact, $field) !== $payload[$field]) {
                throw new ContactError('Mautic did not retain a requested profile value.');
            }
        }
        $tags = contactTags($contact);
        foreach ($payload['tags'] ?? [] as $tag) {
            $remove = str_starts_with($tag, '-');
            if (in_array($remove ? substr($tag, 1) : $tag, $tags, true) === $remove) {
                throw new ContactError('Mautic did not retain a requested tag change.');
            }
        }
    }
}

/**
 * @see readSubscribers() Normalized subscriber shape; callers may supply an email map or a list.
 * @param array<array-key, array> $contacts
 * @return array{
 *     processed: int, created: int, updated: int, unchanged: int,
 *     would_create: int, would_update: int, failed: int
 * }
 */
function importContacts(array $contacts, string $source, MauticClient $mautic, bool $dryRun, Closure $log): array
{
    $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
        'would_create' => 0, 'would_update' => 0, 'failed' => 0];
    foreach ($contacts as $contact) {
        try {
            $existing = $mautic->findByEmail($contact['email']);
            $payload = contactPayload($contact, $source, $existing);
            if ($payload === []) {
                ++$stats['unchanged'];
            } elseif ($dryRun) {
                ++$stats[$existing === null ? 'would_create' : 'would_update'];
            } else {
                $mautic->save($existing === null ? null : (int) $existing['id'], $payload, $contact['email']);
                ++$stats[$existing === null ? 'created' : 'updated'];
            }
        } catch (ContactError $e) {
            ++$stats['failed'];
            $log('ERROR', $e->getMessage(), ['email_ref' => contactRef($contact['email'])]);
        } catch (ImportError $e) {
            ++$stats['failed'];
            $log('ERROR', 'Import aborted; partial progress', $stats + ['email_ref' => contactRef($contact['email'])]);
            throw $e;
        }
        ++$stats['processed'];
        if ($stats['processed'] % 100 === 0) {
            $log('INFO', 'Import progress', $stats);
        }
    }
    return $stats;
}

function usage(): string
{
    return <<<'TEXT'
Usage: php import.php --config /path/to/shop.json [--dry-run | --check-config]

  --config PATH    Required JSON configuration; --config=PATH also works.
  --dry-run        Read source and Mautic, but do not create or update contacts.
                   OAuth token issuance still occurs; this is not offline.
  --check-config   Validate configuration and PHP extensions; no network access.
  --help          Show this help.

One run imports currently subscribed contacts, then exits. Existing Mautic
suppression is never changed. Logs are JSON lines on stderr. See README.md.
Exit codes: 0 success, 1 import/partial failure, 2 configuration/runtime error.

TEXT;
}

function parseArguments(array $arguments): array
{
    $options = ['config' => null, 'dry_run' => false, 'check_config' => false, 'help' => false];
    for ($i = 1; $i < count($arguments); ++$i) {
        $arg = $arguments[$i];
        if ($arg === '--help') {
            $options['help'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--check-config') {
            $options['check_config'] = true;
        } elseif ($arg === '--config' || str_starts_with($arg, '--config=')) {
            if ($options['config'] !== null) {
                throw new ConfigurationError('--config may be given only once.');
            }
            $options['config'] = $arg === '--config' ? ($arguments[++$i] ?? '') : substr($arg, 9);
            if ($options['config'] === '' || str_starts_with($options['config'], '--')) {
                throw new ConfigurationError('--config requires a file path.');
            }
        } else {
            throw new ConfigurationError('Unknown command-line argument. Use --help.');
        }
    }
    if (!$options['help'] && $options['config'] === null) {
        throw new ConfigurationError('--config is required.');
    }
    if ($options['dry_run'] && $options['check_config']) {
        throw new ConfigurationError('--dry-run and --check-config are mutually exclusive.');
    }
    return $options;
}

function main(array $arguments): int
{
    $started = microtime(true);
    try {
        $options = parseArguments($arguments);
        if ($options['help']) {
            fwrite(STDOUT, usage());
            return 0;
        }
        $config = readConfig($options['config']);
        checkRuntime();
        if ($options['check_config']) {
            logEvent('INFO', 'Configuration and PHP runtime are valid', ['source' => $config['source']]);
            return 0;
        }
        $log = static function (string $level, string $message, array $context = []) use ($config): void {
            logEvent($level, $message, ['source' => $config['source']] + $context);
        };
        $log('INFO', 'Import started', ['dry_run' => $options['dry_run']]);
        [$contacts, $invalidRows] = readSubscribers($config['prestashop'], $log);
        $mautic = new MauticClient($config['mautic'], log: $log);
        $stats = importContacts($contacts, $config['source'], $mautic, $options['dry_run'], $log);
        $failed = $stats['failed'] > 0 || $invalidRows > 0;
        $log($failed ? 'ERROR' : 'INFO', 'Import completed', $stats + [
            'invalid_rows' => $invalidRows, 'dry_run' => $options['dry_run'],
            'duration_seconds' => round(microtime(true) - $started, 3),
        ]);
        return $failed ? 1 : 0;
    } catch (ConfigurationError $e) {
        logEvent('ERROR', $e->getMessage());
        return 2;
    } catch (ImportError $e) {
        logEvent('ERROR', $e->getMessage());
        return 1;
    } catch (Throwable $e) {
        // Unexpected exception messages may contain credentials; never dump a stack trace.
        logEvent('ERROR', 'Unexpected failure', ['exception' => $e::class, 'line' => $e->getLine()]);
        return 1;
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', 'stderr');
    ini_set('log_errors', '0');
    exit(main($argv));
}
