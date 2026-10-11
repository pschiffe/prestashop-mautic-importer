<?php
/** Dependency-free unit/contract tests. No network or production data. */
declare(strict_types=1);
require dirname(__DIR__) . '/import.php';

use PrestaShopMautic\ConfigurationError;
use PrestaShopMautic\ContactError;
use PrestaShopMautic\ImportError;
use PrestaShopMautic\MauticClient;
use function PrestaShopMautic\{validateConfig, normalizeEmail, sourceQueries, mergeCustomerRow, mergeNewsletterRow,
    desiredTags, contactPayload, fieldValue, contactTags, importContacts, parseArguments};

$passed = 0;
$failed = 0;
function test(string $name, Closure $body): void
{
    global $passed, $failed;
    try {
        $body();
        ++$passed;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "FAIL {$name}: " . $e::class . ": {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}\n";
        echo $e->getTraceAsString() . "\n";
    }
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}
function truth(bool $value): void { same(true, $value); }
function raises(string $class, Closure $body): void
{
    try { $body(); } catch (Throwable $e) {
        if ($e instanceof $class) { return; }
        throw $e;
    }
    throw new RuntimeException("Expected {$class}.");
}
function config(): array
{
    return json_decode(file_get_contents(dirname(__DIR__) . '/config.example.json'), true, 32, JSON_THROW_ON_ERROR);
}
function row(array $overrides = []): array
{
    return $overrides + ['id_customer' => '10', 'email' => 'jane@example.test', 'firstname' => 'Jane',
        'lastname' => 'Novak', 'is_guest' => '0', 'id_default_group' => '3',
        'default_group_name' => 'Customer', 'id_group' => '3', 'group_name' => 'Customer'];
}
function source(array $overrides = []): array
{
    $contacts = []; mergeCustomerRow($contacts, row($overrides));
    return array_values($contacts)[0];
}
function apiContact(int $id = 1, string $email = 'jane@example.test', array $tags = [], array $dnc = []): array
{
    return ['id' => $id, 'fields' => ['all' => ['email' => $email, 'firstname' => 'Jane', 'lastname' => 'Novak']],
        'tags' => array_map(fn ($tag) => ['tag' => $tag], $tags), 'doNotContact' => $dnc];
}
function response(int $status, array|string $body): array
{
    return ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR), 'errno' => 0];
}

/** Simulates the documented HTTP contract, not a real Mautic implementation. */
final class MockMautic
{
    public array $contacts = [];
    public array $requests = [];
    public array $sleeps = [];
    public array $logs = [];
    public ?Closure $interceptor = null;
    public bool $loseCreateResponse = false;
    public function client(): MauticClient
    {
        return new MauticClient(config()['mautic'], $this->transport(...),
            function ($seconds): void { $this->sleeps[] = $seconds; },
            function ($level, $message, $context = []): void { $this->logs[] = [$level, $message, $context]; });
    }
    public function transport(string $method, string $url, array $headers, ?string $body): array
    {
        $parsed = parse_url($url); $path = $parsed['path'];
        parse_str($parsed['query'] ?? '', $query); parse_str($body ?? '', $data);
        $request = compact('method', 'url', 'path', 'headers', 'body', 'query', 'data');
        $this->requests[] = $request;
        if ($this->interceptor !== null && ($result = ($this->interceptor)($request, $this)) !== null) {
            return $result;
        }
        if ($path === '/oauth/v2/token') {
            same('POST', $method); same('client_credentials', $data['grant_type']);
            truth(!str_contains($url, 'client_secret'));
            return response(200, ['access_token' => 'test-token', 'expires_in' => 3600, 'token_type' => 'bearer']);
        }
        truth(in_array('Authorization: Bearer test-token', $headers, true));
        if ($path === '/api/contacts') {
            same('GET', $method); same('eq', $query['where'][0]['expr']); same('email', $query['where'][0]['col']);
            same('2', $query['limit']); truth(!isset($query['publishedOnly']));
            $matching = array_filter($this->contacts,
                fn ($c) => strtolower(trim($c['fields']['all']['email'])) === $query['where'][0]['val']);
            return response(200, ['total' => (string) count($matching), 'contacts' => array_slice($matching, 0, 2, true)]);
        }
        truth(!isset($data['doNotContact']));
        truth(!str_contains($path, '/dnc/'));
        truth(!isset($data['lastActive']));
        if ($path === '/api/contacts/new') {
            same('POST', $method);
            $id = $this->contacts ? max(array_keys($this->contacts)) + 1 : 1;
            $this->contacts[$id] = apiContact($id, $data['email']);
            $this->contacts[$id]['fields']['all']['firstname'] = '';
            $this->contacts[$id]['fields']['all']['lastname'] = '';
        } elseif (preg_match('#^/api/contacts/([0-9]+)/edit$#', $path, $match)) {
            same('PATCH', $method); truth(!isset($data['email'])); $id = (int) $match[1];
            if (!isset($this->contacts[$id])) { return response(404, ['errors' => [['code' => 404]]]); }
        } else { throw new RuntimeException('Unexpected endpoint: ' . $path); }
        foreach (['firstname', 'lastname'] as $field) {
            if (isset($data[$field])) { $this->contacts[$id]['fields']['all'][$field] = $data[$field]; }
        }
        $tags = array_column($this->contacts[$id]['tags'], 'tag');
        foreach ($data['tags'] ?? [] as $tag) {
            // Mautic 7.2 LeadModel::modifyTags and InputHelper::string normalize before applying deltas.
            $tag = preg_replace('/\x00|<[^>]*>?/', '', html_entity_decode(trim($tag), ENT_QUOTES, 'UTF-8'));
            if (str_starts_with($tag, '-')) { $tags = array_diff($tags, [substr($tag, 1)]); }
            elseif (!in_array($tag, $tags, true)) { $tags[] = $tag; }
        }
        $this->contacts[$id]['tags'] = array_map(fn ($tag) => ['tag' => $tag], array_values($tags));
        if ($path === '/api/contacts/new' && $this->loseCreateResponse) {
            $this->loseCreateResponse = false;
            return ['status' => 0, 'body' => '', 'errno' => 28];
        }
        return response($path === '/api/contacts/new' ? 201 : 200, ['contact' => $this->contacts[$id]]);
    }
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r['method'] !== 'GET' && $r['path'] !== '/oauth/v2/token'));
    }
}
$quiet = static function ($level, $message, $context = []): void {};

// Config, identity and extraction logic.
test('reject unknown config keys', function (): void { $c = config(); $c['dryrun'] = true; raises(ConfigurationError::class, fn () => validateConfig($c)); });
test('reject plaintext HTTP', function (): void { $c = config(); $c['mautic']['url'] = 'http://example.test'; raises(ConfigurationError::class, fn () => validateConfig($c)); });
test('reject credentials or query in base URL', function (): void {
    foreach (['https://user:pass@example.test', 'https://example.test/?token=secret'] as $url) {
        $c = config(); $c['mautic']['url'] = $url; raises(ConfigurationError::class, fn () => validateConfig($c));
    }
});
test('subdirectory base URL preserved', function (): void { $c = config(); $c['mautic']['url'] = 'https://example.test/marketing/'; same('https://example.test/marketing', validateConfig($c)['mautic']['url']); });
test('reject SQL prefix injection', fn () => raises(ConfigurationError::class, fn () => sourceQueries('ps_`; DROP TABLE x; --')));
test('reject string shop ID', function (): void { $c = config(); $c['prestashop']['shop_id'] = '1'; raises(ConfigurationError::class, fn () => validateConfig($c)); });
test('reject non-MySQL DSN', function (): void { $c = config(); $c['prestashop']['dsn'] = 'sqlite:/tmp/test'; raises(ConfigurationError::class, fn () => validateConfig($c)); });
test('normalize case and whitespace, preserve plus and dots', fn () => same('j.ane+shop@example.test', normalizeEmail(' J.Ane+shop@Example.TEST ')));
test('reject malformed and overlong email', function (): void { same(null, normalizeEmail('not-an-email')); same(null, normalizeEmail(str_repeat('a', 255) . '@example.test')); });
test('merge all actual customer groups', function (): void {
    $contacts = []; mergeCustomerRow($contacts, row()); mergeCustomerRow($contacts, row(['id_group' => '7', 'group_name' => 'Wholesale']));
    same([3 => 'Customer', 7 => 'Wholesale'], $contacts['jane@example.test']['groups']);
});
test('newsletter-only has no invented name or group', function (): void {
    $contacts = []; mergeNewsletterRow($contacts, ['id' => 4, 'email' => 'jane@example.test']);
    $s = $contacts['jane@example.test']; same('newsletter_only', $s['type']); same([], $s['groups']); same('', $s['firstname']);
});
test('registered subscriber wins over newer guest', function (): void {
    $contacts = []; mergeCustomerRow($contacts, row()); mergeCustomerRow($contacts, row(['id_customer' => '100', 'is_guest' => '1', 'id_group' => '2', 'group_name' => 'Guest']));
    same(10, $contacts['jane@example.test']['customer_id']); same([3 => 'Customer'], $contacts['jane@example.test']['groups']);
});
test('newer same-type account wins without merging its predecessor groups', function (): void {
    $contacts = []; mergeCustomerRow($contacts, row()); mergeCustomerRow($contacts, row(['id_customer' => '11', 'id_group' => '7', 'group_name' => 'Wholesale']));
    same(11, $contacts['jane@example.test']['customer_id']); same([7 => 'Wholesale'], $contacts['jane@example.test']['groups']);
});
test('customer replaces newsletter-only, not vice versa', function (): void {
    $contacts = []; $guest = ['id' => 4, 'email' => 'JANE@example.test'];
    mergeNewsletterRow($contacts, $guest); mergeCustomerRow($contacts, row()); mergeNewsletterRow($contacts, $guest);
    same(1, count($contacts)); same('customer', $contacts['jane@example.test']['type']);
});
test('invalid source row does not enter contact map', function (): void {
    $contacts = []; same(false, mergeCustomerRow($contacts, row(['email' => 'bad']))); same(false, mergeNewsletterRow($contacts, ['id' => 1, 'email' => 'bad'])); same([], $contacts);
});
test('group labels and default group represented', function (): void {
    same([
        'prestashop:example-shop',
        'prestashop:example-shop:default-group:3:Customer',
        'prestashop:example-shop:group:3:Customer',
        'prestashop:example-shop:type:customer',
    ], desiredTags(source(), 'example-shop'));
});
test('Unicode names and groups survive', function (): void {
    $s = source(['firstname' => "Ji\u{0159}\u{00ed}", 'group_name' => "Z\u{00e1}kazn\u{00ed}k"]);
    $p = contactPayload($s, 'example-shop', null); same("Ji\u{0159}\u{00ed}", $p['firstname']);
    truth(in_array("prestashop:example-shop:group:3:Z\u{00e1}kazn\u{00ed}k", $p['tags'], true));
});
test('missing group translation has deterministic ID fallback', function (): void { truth(in_array('prestashop:example-shop:group:3:group-3', desiredTags(source(['group_name' => null]), 'example-shop'), true)); });
test('overlong group label fails safely', fn () => raises(ContactError::class, fn () => desiredTags(source(['group_name' => str_repeat('x', 260)]), 'example-shop')));
test('unchanged contact produces no PATCH', function (): void {
    $existing = apiContact(tags: [
        'prestashop:example-shop',
        'prestashop:example-shop:default-group:3:Customer',
        'prestashop:example-shop:group:3:Customer',
        'prestashop:example-shop:type:customer',
    ]);

    same([], contactPayload(source(), 'example-shop', $existing));
});
test('blank source names never erase existing Mautic names', function (): void {
    $s = source(['firstname' => '', 'lastname' => '']); $p = contactPayload($s, 'example-shop', apiContact());
    truth(!isset($p['firstname']) && !isset($p['lastname']));
});
test('only managed tag namespaces are reconciled', function (): void {
    $existing = apiContact(tags: ['manual-vip', 'prestashop:other:group:7:Wholesale', 'prestashop:example-shop:manual:vip', 'prestashop:example-shop:group:2:Guest']);
    $delta = contactPayload(source(), 'example-shop', $existing)['tags'];
    truth(in_array('-prestashop:example-shop:group:2:Guest', $delta, true));
    truth(!in_array('-manual-vip', $delta, true)); truth(!in_array('-prestashop:other:group:7:Wholesale', $delta, true));
    truth(!in_array('-prestashop:example-shop:manual:vip', $delta, true));
});
test('nested fields representation supported', fn () => same('Jane', fieldValue(['fields' => ['core' => ['firstname' => ['value' => 'Jane']]]], 'firstname')));
test('missing tags is not treated as empty', fn () => raises(ImportError::class, fn () => contactTags(['id' => 1])));

// API-contract and run behavior tests.
test('create new contact; second import is unchanged', function () use ($quiet): void {
    $mock = new MockMautic();

    $stats = importContacts([source()], 'example-shop', $mock->client(), false, $quiet);
    same(1, $stats['created']);

    $stats = importContacts([source()], 'example-shop', $mock->client(), false, $quiet);
    same(1, $stats['unchanged']);
    same(1, count($mock->writes()));
});
test('normalized group tags survive repeat imports and renames', function () use ($quiet): void {
    $mock = new MockMautic();
    $s = source(['group_name' => ' R&amp;amp;D ', 'default_group_name' => '&lt;b&gt;Retail&lt;/b&gt;']);
    same(1, importContacts([$s], 'example-shop', $mock->client(), false, $quiet)['created']);
    same(['prestashop:example-shop', 'prestashop:example-shop:default-group:3:Retail',
        'prestashop:example-shop:group:3:R&D', 'prestashop:example-shop:type:customer'], contactTags($mock->contacts[1]));
    same(1, importContacts([$s], 'example-shop', $mock->client(), false, $quiet)['unchanged']);
    same(1, count($mock->writes()));

    $s = source(['group_name' => '<i>Wholesale</i>', 'default_group_name' => '&lt;b&gt;&lt;/b&gt;']);
    same(1, importContacts([$s], 'example-shop', $mock->client(), false, $quiet)['updated']);
    $tags = contactTags($mock->contacts[1]); sort($tags, SORT_STRING);
    same(['prestashop:example-shop', 'prestashop:example-shop:default-group:3:group-3',
        'prestashop:example-shop:group:3:Wholesale', 'prestashop:example-shop:type:customer'], $tags);
    same(1, importContacts([$s], 'example-shop', $mock->client(), false, $quiet)['unchanged']);
    same(2, count($mock->writes()));
});
// These scenarios check importer payloads and mock state, not real Mautic send suppression.
test('profile update payload leaves DNC untouched across repeat imports', function () use ($quiet): void {
    $mock = new MockMautic();
    $dnc = [['channel' => 'email', 'reason' => 1, 'comments' => 'Email unsubscribe']];
    $mock->contacts[1] = apiContact(dnc: $dnc);
    $s = source(['firstname' => 'Janet']);

    $stats = importContacts([$s], 'example-shop', $mock->client(), false, $quiet);

    same(1, $stats['updated']);
    same($dnc, $mock->contacts[1]['doNotContact']);
    same(1, importContacts([$s], 'example-shop', $mock->client(), false, $quiet)['unchanged']);
    same(['firstname', 'tags'], array_keys($mock->writes()[0]['data']));
});
test('bounce/manual/SMS suppression all preserved', function () use ($quiet): void {
    $mock = new MockMautic(); $dnc = [['channel' => 'email', 'reason' => 2], ['channel' => 'sms', 'reason' => 3]];
    $mock->contacts[1] = apiContact(dnc: $dnc); importContacts([source()], 'example-shop', $mock->client(), false, $quiet); same($dnc, $mock->contacts[1]['doNotContact']);
});
test('DNC added concurrently before PATCH is not overwritten', function () use ($quiet): void {
    $mock = new MockMautic();
    $mock->contacts[1] = apiContact();
    $dnc = [['channel' => 'email', 'reason' => 1]];
    $mock->interceptor = function ($r, $m) use ($dnc) {
        if ($r['method'] === 'PATCH') {
            $m->contacts[1]['doNotContact'] = $dnc;
        }
        return null;
    };

    importContacts([source()], 'example-shop', $mock->client(), false, $quiet);

    same($dnc, $mock->contacts[1]['doNotContact']);
});
test('DNC payload is rejected structurally', function (): void {
    $mock = new MockMautic(); raises(ImportError::class, fn () => $mock->client()->save(1, ['doNotContact' => []], 'jane@example.test')); same([], $mock->requests);
});
test('email migration through PATCH is forbidden', function (): void {
    $mock = new MockMautic(); raises(ImportError::class, fn () => $mock->client()->save(1, ['email' => 'new@example.test'], 'jane@example.test'));
});
test('dry-run plans create but performs no contact writes', function () use ($quiet): void {
    $mock = new MockMautic();

    $stats = importContacts([source()], 'example-shop', $mock->client(), true, $quiet);

    same(1, $stats['would_create']);
    same(0, $stats['created']);
    same([], $mock->writes());
    same([], $mock->contacts);
});
test('dry-run plans updates without changing contacts', function () use ($quiet): void {
    $mock = new MockMautic(); $mock->contacts[1] = apiContact(); $before = $mock->contacts;
    same(1, importContacts([source()], 'example-shop', $mock->client(), true, $quiet)['would_update']); same($before, $mock->contacts); same([], $mock->writes());
});
test('duplicates are skipped and reported as partial failure', function () use ($quiet): void {
    $mock = new MockMautic(); $mock->contacts = [1 => apiContact(1), 2 => apiContact(2)];
    same(1, importContacts([source()], 'example-shop', $mock->client(), false, $quiet)['failed']); same([], $mock->writes());
});
test('unexpected email match never creates or edits', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(200, ['total' => 1, 'contacts' => [apiContact(email: 'other@example.test')]]) : null;
    raises(ContactError::class, fn () => $mock->client()->findByEmail('jane@example.test')); same([], $mock->writes());
});
test('stale positive total with empty list does not create', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(200, ['total' => '1', 'contacts' => []]) : null;
    raises(ContactError::class, fn () => $mock->client()->findByEmail('jane@example.test'));
});
test('invalid JSON stops rather than treating as empty contacts', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(200, '<html>login</html>') : null;
    raises(ImportError::class, fn () => $mock->client()->findByEmail('jane@example.test'));
});
test('HTTP redirect response aborts without retry', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(302, '') : null;
    raises(ImportError::class, fn () => $mock->client()->findByEmail('jane@example.test')); same([], $mock->sleeps);
});
test('temporary GET failure retries with bounded delays', function (): void {
    $mock = new MockMautic(); $count = 0;
    $mock->interceptor = function ($r) use (&$count) { return $r['path'] === '/api/contacts' && ++$count <= 2 ? response(503, []) : null; };
    same(null, $mock->client()->findByEmail('jane@example.test')); same([1, 2], $mock->sleeps);
});
test('long Retry-After stops instead of hammering API', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(429, []) + ['retry_after' => 120] : null;
    raises(ImportError::class, fn () => $mock->client()->findByEmail('jane@example.test')); same([], $mock->sleeps);
});
test('persistent GET error stops after three attempts', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(503, []) : null;
    raises(ImportError::class, fn () => $mock->client()->findByEmail('jane@example.test')); same([1, 2], $mock->sleeps);
});
test('explicit 401 refreshes token once', function (): void {
    $mock = new MockMautic(); $calls = 0;
    $mock->interceptor = function ($r) use (&$calls) { return $r['path'] === '/api/contacts' && ++$calls === 1 ? response(401, []) : null; };
    same(null, $mock->client()->findByEmail('jane@example.test'));
    same(2, count(array_filter($mock->requests, fn ($r) => $r['path'] === '/oauth/v2/token')));
});
test('repeated 401 aborts without infinite renewal', function (): void {
    $mock = new MockMautic(); $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts' ? response(401, []) : null;
    raises(ImportError::class, fn () => $mock->client()->findByEmail('jane@example.test')); same(4, count($mock->requests));
});
test('lost successful create response does not cause a blind retry', function () use ($quiet): void {
    $mock = new MockMautic();
    $mock->loseCreateResponse = true;

    raises(ImportError::class, fn () => importContacts([source()], 'example-shop', $mock->client(), false, $quiet));

    same(1, count($mock->contacts));
    same(1, count($mock->writes()));
    same(1, importContacts([source()], 'example-shop', $mock->client(), false, $quiet)['unchanged']);
    same(1, count($mock->writes()));
});
test('PATCH 404 is not converted to create', function () use ($quiet): void {
    $mock = new MockMautic(); $mock->contacts[1] = apiContact();
    $mock->interceptor = fn ($r) => $r['method'] === 'PATCH' ? response(404, []) : null;
    raises(ImportError::class, fn () => importContacts([source()], 'example-shop', $mock->client(), false, $quiet));
    same('PATCH', $mock->writes()[0]['method']); same(1, count($mock->writes()));
});
test('contact validation failure does not block next address', function () use ($quiet): void {
    $mock = new MockMautic();
    $mock->interceptor = fn ($r) => ($r['data']['email'] ?? '') === 'jane@example.test' ? response(422, []) : null;
    $stats = importContacts([source(), source(['email' => 'next@example.test'])], 'example-shop', $mock->client(), false, $quiet);
    same(1, $stats['failed']); same(1, $stats['created']); same(2, $stats['processed']);
});
foreach (['create-email', 'update-id'] as $mismatch) {
    test("successful write with wrong {$mismatch} identity aborts without retry", function () use ($quiet, $mismatch): void {
        $mock = new MockMautic();
        if ($mismatch === 'update-id') {
            $mock->contacts[1] = apiContact();
        }
        $returned = $mismatch === 'create-email'
            ? apiContact(email: 'other@example.test')
            : apiContact(id: 99);
        $mock->interceptor = fn ($r) => in_array($r['method'], ['POST', 'PATCH'], true)
            && $r['path'] !== '/oauth/v2/token' ? response(200, ['contact' => $returned]) : null;

        raises(ImportError::class, fn () => importContacts(
            [source(), source(['email' => 'next@example.test'])], 'example-shop', $mock->client(), false, $quiet
        ));

        same(1, count($mock->writes()));
        same([], $mock->sleeps);
        same(1, count(array_filter($mock->requests, fn ($r) => $r['path'] === '/api/contacts')));
    });
}
foreach (['profile', 'tag-addition', 'tag-removal'] as $ignored) {
    test("successful write ignoring {$ignored} fails only that contact", function () use ($quiet, $ignored): void {
        $mock = new MockMautic();
        $tags = [
            'prestashop:example-shop',
            'prestashop:example-shop:default-group:3:Customer',
            'prestashop:example-shop:group:3:Customer',
            'prestashop:example-shop:type:customer',
        ];
        if ($ignored === 'tag-addition') {
            $tags = [];
        } elseif ($ignored === 'tag-removal') {
            $tags[] = 'prestashop:example-shop:group:7:Old';
        }
        $mock->contacts[1] = apiContact(tags: $tags);
        $returned = apiContact(tags: $tags);
        $mock->interceptor = fn ($r) => $r['path'] === '/api/contacts/1/edit'
            ? response(200, ['contact' => $returned]) : null;
        $first = source($ignored === 'profile' ? ['firstname' => 'Janet'] : []);

        $stats = importContacts([$first, source(['email' => 'next@example.test'])],
            'example-shop', $mock->client(), false, $quiet);

        same(1, $stats['failed']);
        same(0, $stats['updated']);
        same(1, $stats['created']);
        same(2, $stats['processed']);
        same(['PATCH', 'POST'], array_column($mock->writes(), 'method'));
        same([], $mock->sleeps);
    });
}
test('empty source performs no API calls', function () use ($quiet): void { $mock = new MockMautic(); same(0, importContacts([], 'example-shop', $mock->client(), false, $quiet)['processed']); same([], $mock->requests); });
test('source disappearance has no delete or DNC operation', function () use ($quiet): void {
    $mock = new MockMautic(); $mock->contacts[1] = apiContact(); $before = $mock->contacts;
    importContacts([], 'example-shop', $mock->client(), false, $quiet); same($before, $mock->contacts); same([], $mock->writes());
});
test('newsletter-only upgrades tags to customer without duplicating contact', function () use ($quiet): void {
    $mock = new MockMautic(); $contacts = []; mergeNewsletterRow($contacts, ['id' => 1, 'email' => 'jane@example.test']);
    importContacts($contacts, 'example-shop', $mock->client(), false, $quiet);
    same(1, importContacts([source()], 'example-shop', $mock->client(), false, $quiet)['updated']);
    truth(!in_array('prestashop:example-shop:type:newsletter_only', contactTags($mock->contacts[1]), true)); same(1, count($mock->contacts));
});
test('plus addressing sent as exact advanced filter', function (): void {
    $mock = new MockMautic(); same(null, $mock->client()->findByEmail('j.ane+shop@example.test'));
    same('j.ane+shop@example.test', $mock->requests[1]['query']['where'][0]['val']);
});
test('help and config argument parsing', function (): void {
    same(true, parseArguments(['import.php', '--help'])['help']);
    same('/tmp/x.json', parseArguments(['import.php', '--config=/tmp/x.json'])['config']);
    same(true, parseArguments(['import.php', '--config', '/tmp/x.json', '--dry-run'])['dry_run']);
});
test('missing, duplicate and unknown arguments rejected', function (): void {
    foreach ([['import.php'], ['import.php', '--config'], ['import.php', '--unknown'], ['import.php', '--config', 'a', '--config', 'b']] as $args) {
        raises(ConfigurationError::class, fn () => parseArguments($args));
    }
});
test('offline check and dry-run cannot be combined', fn () => raises(ConfigurationError::class, fn () => parseArguments(['import.php', '--config', 'a', '--check-config', '--dry-run'])));

/** Run the real CLI with the same interpreter as this suite; no shell or network needed. */
function runCli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/import.php', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch importer CLI.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}
test('CLI help succeeds on stdout without requiring configuration', function (): void {
    $result = runCli(['--help']);

    same(0, $result['exit']);
    truth(str_contains($result['stdout'], '--config'));
    same('', $result['stderr']);
});
test('CLI missing configuration argument exits with configuration status', function (): void {
    $result = runCli([]);

    same(2, $result['exit']);
    same('', $result['stdout']);
    same('ERROR', json_decode($result['stderr'], true, 32, JSON_THROW_ON_ERROR)['level']);
});
foreach (['missing-file', 'malformed-json', 'invalid-config'] as $case) {
    test("CLI {$case} exits safely without exposing sensitive input", function () use ($case): void {
        $directory = sys_get_temp_dir() . '/importer-cli-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create temporary config directory.');
        }
        $secret = 'secret-' . bin2hex(random_bytes(16));
        $path = $directory . '/' . $secret . '.json';
        try {
            if ($case === 'malformed-json') {
                file_put_contents($path, '{"password":"' . $secret);
            } elseif ($case === 'invalid-config') {
                $config = config();
                $config['prestashop']['password'] = $secret;
                $config['mautic']['client_secret'] = $secret;
                $config['mautic']['url'] = 'http://example.test/?token=' . $secret;
                file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
            }

            $result = runCli(['--config', $path, '--check-config']);

            same(2, $result['exit']);
            same('', $result['stdout']);
            same('ERROR', json_decode($result['stderr'], true, 32, JSON_THROW_ON_ERROR)['level']);
            truth(!str_contains($result['stdout'] . $result['stderr'], $secret));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    });
}

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
