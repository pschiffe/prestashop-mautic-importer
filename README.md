# PrestaShop -> Mautic Importer

A dependency-free PHP CLI script that imports currently subscribed PrestaShop
newsletter contacts into Mautic. Deploy one script, one configuration per shop,
and an hourly systemd timer per instance.

**PrestaShop supplies contacts and profile data. Mautic owns email suppression.**
The importer never changes Do Not Contact (DNC), deletes contacts, or writes to
PrestaShop. It needs no Composer packages, sync-state database, web endpoint, or
continuously running daemon.

[![GitHub Sponsors](https://img.shields.io/badge/GitHub_Sponsors-grey?logo=github)](https://github.com/sponsors/pschiffe) [![PayPal](https://img.shields.io/badge/paypal.me-grey?logo=paypal)](https://www.paypal.com/paypalme/pschiffe)

If this project is useful to you, please consider sponsoring me to support maintenance and further development. Thank you!

- [Consent and suppression](#consent-and-suppression)
- [Requirements](#requirements) and [imported contacts](#imported-contacts)
- [Mautic setup](#configure-mautic), [database access](#read-only-prestashop-account), and [configuration](#configuration)
- [Installation](#installation), [scheduling](#scheduling-and-sandbox), and [operations](#operations-and-logs)
- [Updates](#update), [tests](#tests), and [deployment checklist](#deployment-acceptance)

## Consent and suppression

Unsubscribing, deactivating, or deleting a contact in PrestaShop does **not**
update an already imported Mautic contact. Re-importing a subscribed contact
does **not** remove an existing Mautic unsubscribe, bounce, or manual suppression.

Before adopting this model, remove or replace the editable newsletter preference
in PrestaShop My Account. Do not leave a checkbox that appears to unsubscribe a
customer while Mautic continues sending. Keep homepage/registration opt-ins and
point customers to the email unsubscribe link or support; support must apply
withdrawals in Mautic. This repository does **not** change storefront templates
or controllers. Test that your account-form customization does not clear the
newsletter flag when unrelated profile changes are saved.

Use email-channel/global Mautic DNC for ordinary unsubscribes, not just a segment
preference. With a preference center enabled, verify that the chosen unsubscribe
link actually sets DNC (Mautic provides `{dnc_url}` for global marketing opt-out).
Use suppression, **not contact deletion**: deleting a Mautic contact while it is
still subscribed in PrestaShop allows re-creation.
Erasure requests require handling the source data as well. Verified re-subscription
is a separate Mautic-side workflow; this importer cannot reactivate a contact.

## Requirements

- PHP **8.2 or newer**, with `pdo_mysql` and `curl` in the **CLI** interpreter.
- Linux with systemd **247 or newer** for the supplied credential-based units.
- Read-only access to the selected PrestaShop database; run beside the shop and
  use loopback TCP or an appropriately accessible Unix socket.
- A Mautic HTTPS URL with a trusted certificate and OAuth2 client credentials.

The target is **PrestaShop 8.x + ps_emailsubscription + Mautic 7.2**. The selected
columns were checked against the PrestaShop 8.2 schema and ps_emailsubscription
2.8.3/3.0.0; the API contract was checked against Mautic 7.2 documentation and
source. This does not imply that every minor version or customized installation
has been tested. In particular, ps_emailsubscription 3.0.0 itself requires
PrestaShop 8.2 or newer. See [upstream references](#upstream-references).

This implementation does not bootstrap PrestaShop or Mautic, so its PHP CLI
version can differ from the storefront's PHP-FPM version. No YAML extension,
Mautic custom fields, application SDKs, or PHP packages need to be installed.

Check the interpreter actually used by systemd, not just the website's PHP version:

```bash
/usr/bin/php -v
/usr/bin/php -m | grep -E '^(curl|PDO|pdo_mysql)$'
systemd --version
```

## Imported contacts

The script takes a read-only, repeatable-read snapshot and finishes all source
reads before making API calls. Use InnoDB tables for snapshot consistency.
Table names use the configured prefix.

| Source | Selection |
| --- | --- |
| `customer` | Selected `id_shop`, `newsletter = 1`, `active = 1`, `deleted = 0` |
| `emailsubscription` | Selected `id_shop`, `active = 1` |
| `customer_group`, `group_lang` | Memberships and labels for eligible customers |

Inactive/pending newsletter rows are excluded. This reads persisted flags; it
does not implement an additional double-opt-in workflow or independently prove
how consent was collected. Partner-offers `customer.optin` is not used.

Addresses are trimmed and lowercased. Plus addressing and dots are preserved.
PHP email validation is used; Unicode/SMTPUTF8 addresses that it cannot validate
are skipped and reported. Invalid source rows make the run exit unsuccessfully.

Sources are deduplicated by email. An eligible registered customer is preferred
over an eligible guest-checkout customer, and either is preferred over a
newsletter-only record. Among multiple eligible accounts of the same type, the
highest customer ID wins; names and groups come only from that selected account.
Inactive or non-subscribed customer accounts do not supply profile information.

One instance selects one `id_shop`. Shared-customer multistore semantics are not
expanded through shop groups. Different instances can target different Mautic
installations. When sharing a Mautic installation, contact identity, names, and
email-channel DNC remain global; this is **not** independent per-brand consent.
Do not run overlapping instances for the same source/audience.

## Fields and tags

`email`, nonempty `firstname`, and nonempty `lastname` map to Mautic's built-in
fields. Blank source names never erase existing Mautic names. Existing contact
email addresses are not changed. Unrelated contact fields are not sent.

Everything else is represented by namespaced tags. With `source = example-shop`:

| Example tag | Meaning |
| --- | --- |
| `prestashop:example-shop` | Contact imported from this source |
| `prestashop:example-shop:type:newsletter_only` | Homepage subscriber without an eligible customer account |
| `prestashop:example-shop:type:guest` | Eligible PrestaShop guest-checkout customer |
| `prestashop:example-shop:type:customer` | Eligible registered customer |
| `prestashop:example-shop:group:3:Customer` | Actual customer group membership |
| `prestashop:example-shop:group:7:Wholesale` | Another actual membership |
| `prestashop:example-shop:default-group:3:Customer` | Actual default group |

IDs and labels above are illustrative, not hard-coded. Labels use `language_id`.
The importer decodes HTML entities (including nested entities), strips HTML
markup and null bytes, and trims whitespace to produce tags stable under Mautic's
tag sanitizer. A missing translation or a label empty after normalization falls
back to `group-<id>`. Group renaming or changing `language_id` changes the
corresponding tag names, so adjust segment filters too.

All actual memberships are imported. A newsletter-only subscriber is not assigned
an invented Guest group. The importer removes obsolete tags only from this
source's reserved `type:`, `group:`, and `default-group:` namespaces. Manual tags,
other sources' tags, and other subnamespaces are preserved.

Tags and fields are reconciled only for contacts currently present in the
positive source list. Consequently, source tags and group tags may remain on
contacts no longer in that list. **A source tag is provenance, not evidence of
current consent or sendability.** Mautic suppression remains authoritative.

## Configure Mautic

### Create OAuth2 credentials

1. Sign in as a **Mautic administrator** and enable the API under
   **Configuration -> API Settings**.
2. Open **API Credentials**, create a new **OAuth2** client, and give it a name
   such as `PrestaShop importer`.
3. For the required **Redirect URI**, enter your Mautic base URL, for example
   `https://mautic.example.com/`. This is only a placeholder: the importer uses
   the `client_credentials` grant and never redirects a browser to this URL.
4. Save the client and copy its ID and secret into `mautic.client_id` and
   `mautic.client_secret` in the instance configuration.

No callback endpoint, browser authorization, refresh-token file, or API SDK is
needed. Do **not** add `redirect_uri` to the JSON configuration. The script
obtains an access token directly with the client ID and secret, keeps it in
memory, and renews it when needed. See the [Mautic authentication guide][auth].

### Permissions and security

Mautic 7.2 has **no separate permissions selector in the OAuth2 credential form**.
It assigns the creating user's role to the client and enables `client_credentials`
only for clients created by an administrator. Credentials created this way
already have access to all contacts; no additional contact privilege needs
configuring. See [Mautic's credential-creation code][oauth-client-controller].

**Treat the client secret as an administrator-level API credential**, not a
contact-only credential. The importer reads, creates, and edits contacts and
their tags, but never deletes contacts or changes DNC. Those limits are enforced
by the importer, not by the credentials' permissions. Keep the secret in the
protected instance configuration; do not commit it or share it in logs.

Contact lookup must include contacts owned by other users, including suppressed
and unpublished contacts. Hidden matches can lead to duplicate creation. Verify
lookup and re-import with an existing contact owned by another user and an
existing suppressed contact before enabling production marketing.

### Segments and sends

Use a segment filtered by the source tag and, optionally, group tags. Keep
Mautic's own segment/campaign cron jobs configured separately; this timer does
not run them or send any email itself.

**Disable "Send to unsubscribed contacts" for all marketing emails.** Mautic 7.2
supports that bypass for transactional use; it must not defeat the unsubscribe
policy used here. Validate suppression with an actual marketing segment or
campaign send to test contacts, not only the administrative test-email feature.
See the [Mautic email documentation][emails].

Existing Mautic workflows remain your responsibility: a campaign triggered by
contact creation, tags, or updates can send messages. Perform the initial import
with affected marketing automation paused or in a staging Mautic instance.

## Read-only PrestaShop account

Example for a database named `prestashop`, prefix `ps_`, and loopback TCP. Run
these as a database administrator, replacing the password and identifiers.
The column-level grants deliberately exclude unrelated customer information
such as password hashes and addresses.

```sql
CREATE USER 'ps_mautic'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_RANDOM_PASSWORD';

GRANT SELECT (id_customer, id_shop, email, firstname, lastname, is_guest,
              id_default_group, newsletter, active, deleted)
ON `prestashop`.`ps_customer` TO 'ps_mautic'@'127.0.0.1';

GRANT SELECT (id_customer, id_group)
ON `prestashop`.`ps_customer_group` TO 'ps_mautic'@'127.0.0.1';

GRANT SELECT (id_group, id_lang, name)
ON `prestashop`.`ps_group_lang` TO 'ps_mautic'@'127.0.0.1';

GRANT SELECT (id, id_shop, email, active)
ON `prestashop`.`ps_emailsubscription` TO 'ps_mautic'@'127.0.0.1';
```

Match the database account's host to the actual connection method. No write,
DDL, FILE, SUPER, or administrative grants are needed. A Unix socket DSN can be
used instead, with its corresponding account host and filesystem permissions.

This version does not expose MySQL TLS configuration. Do not connect to a remote
DB over an untrusted network; run locally or use a secured local tunnel instead.

## Configuration

Each instance uses a root-owned, mode `0600` JSON configuration file, for example:

```text
/etc/prestashop-mautic-importer/example-shop.json
```

The format is native JSON, not executable PHP and not YAML. Comments and trailing
commas are not allowed. Unknown keys are rejected rather than silently ignored.
Passwords with quotes or backslashes must be JSON-escaped.

| Key | Purpose |
| --- | --- |
| `source` | Stable lowercase tag namespace, 1-32 characters; normally the instance name |
| `prestashop.dsn` | PDO MySQL DSN, including `charset=utf8mb4` |
| `prestashop.username`, `prestashop.password` | Read-only database credentials |
| `prestashop.table_prefix` | Actual table prefix, for example `ps_`; empty is allowed |
| `prestashop.shop_id` | Positive integer shop ID, not the hostname |
| `prestashop.language_id` | Positive integer language ID for group labels |
| `mautic.url` | Canonical HTTPS base URL, optionally with a subdirectory; not `/api` |
| `mautic.client_id`, `mautic.client_secret` | OAuth2 client credentials |

Keep `source` stable. Changing it intentionally creates a new tag namespace and
does not clean up old source tags. Instance names such as `example-shop` or
`shop-two` work well; avoid spaces and complex escaped systemd instance names.

## Installation

Production needs only `import.php`, the two systemd units, and one configuration
per instance. The checkout and test files need not remain on the server.

### 1. Install the script and units

Run from an extracted archive or checkout:

```bash
sudo install -o root -g root -m 0755 import.php /usr/local/bin/prestashop-mautic-importer
sudo install -o root -g root -m 0644 systemd/prestashop-mautic-importer@.service /etc/systemd/system/
sudo install -o root -g root -m 0644 systemd/prestashop-mautic-importer@.timer /etc/systemd/system/
sudo install -d -o root -g root -m 0700 /etc/prestashop-mautic-importer
```

### 2. Configure an instance

Create the instance configuration without overwriting an existing file, then edit it:

```bash
if ! sudo test -e /etc/prestashop-mautic-importer/example-shop.json; then
    sudo install -o root -g root -m 0600 config.example.json /etc/prestashop-mautic-importer/example-shop.json
fi
sudoedit /etc/prestashop-mautic-importer/example-shop.json
sudo systemctl daemon-reload
```

### 3. Validate and preview

Check the configuration and run a read-only comparison before enabling the timer:

```bash
sudo /usr/bin/php /usr/local/bin/prestashop-mautic-importer \
    --config /etc/prestashop-mautic-importer/example-shop.json --check-config

sudo /usr/bin/php /usr/local/bin/prestashop-mautic-importer \
    --config /etc/prestashop-mautic-importer/example-shop.json --dry-run
```

`--check-config` validates configuration and PHP runtime requirements without
network access. `--dry-run` reads PrestaShop and Mautic and logs
`would_create`/`would_update` counts. It obtains an OAuth token but never creates
or changes contacts. Neither option prints the configuration or secrets.
The direct dry-run runs as the caller, outside the service sandbox.

### 4. Run and schedule

With affected marketing automation paused or in staging, review the dry-run and
execute the real job under systemd. Check the result before enabling the timer:

```bash
sudo systemctl start prestashop-mautic-importer@example-shop.service
sudo journalctl -u prestashop-mautic-importer@example-shop.service -n 50 --no-pager
sudo systemctl enable --now prestashop-mautic-importer@example-shop.timer
```

For another shop, create `another-shop.json`, set its own `source` and credentials,
and enable `prestashop-mautic-importer@another-shop.timer`. The executable and
unit templates are shared; each service receives only its own configuration.

### Alternative: download without a checkout

Download the files to a working directory, review them, then follow the
installation steps above:

```bash
mkdir -p prestashop-mautic-importer/systemd
cd prestashop-mautic-importer
base=https://raw.githubusercontent.com/pschiffe/prestashop-mautic-importer/main
curl --fail --show-error --location "$base/import.php" -o import.php
curl --fail --show-error --location "$base/config.example.json" -o config.example.json
curl --fail --show-error --location "$base/systemd/prestashop-mautic-importer@.service" -o systemd/prestashop-mautic-importer@.service
curl --fail --show-error --location "$base/systemd/prestashop-mautic-importer@.timer" -o systemd/prestashop-mautic-importer@.timer
/usr/bin/php -l import.php
```

For reproducible deployments, replace `main` in the download URL with a reviewed
commit SHA. Do not download and execute new code automatically in a scheduled run.

## Scheduling and sandbox

The supplied service uses `Type=oneshot`, `DynamicUser=yes`, and
`LoadCredential=config.json:/etc/prestashop-mautic-importer/%i.json`. Root reads
the private source configuration; systemd supplies a read-only copy to the
unprivileged instance through `%d/config.json`. No permanent user per shop is
needed. The source file remains plaintext on disk, protected by permissions;
`LoadCredential` is not at-rest encryption. See [systemd.exec][systemd-exec].

The sandbox is read-only, with private temporary files and no access to `/home`
or `/root`. Keep configuration and CA certificates under `/etc`, not inside a
site user's home directory. The default loopback database connection avoids
site-user and socket-permission coupling.

Each timer runs hourly with a stable, per-instance randomized delay of up to five
minutes. `Persistent=true` catches up a missed calendar activation after downtime;
it does not replay one job for every missed hour. Enabling it may cause an
immediate run. See [systemd.timer][systemd-timer].

Systemd will not launch a second execution of an already active **same service
instance**. Use `systemctl start` for normal manual runs too. There is no
application-level lock: direct PHP execution, a second instance for the same
source, or another server is outside that protection. Disable the timer and
confirm the service is inactive before a direct diagnostic run.

A 45-minute `TimeoutStartSec` bounds a oneshot execution. Measure the first full
import and adjust this limit if needed. There is no restart loop; the next timer
activation retries currently eligible contacts. A successfully completed oneshot
normally shows `inactive (dead)`, not a permanently running service.

The default interpreter is `/usr/bin/php`. To use another installed CLI version,
create a per-instance drop-in:

```bash
sudo systemctl edit prestashop-mautic-importer@example-shop.service
```

```ini
[Service]
ExecStart=
ExecStart=/usr/bin/php8.3 /usr/local/bin/prestashop-mautic-importer --config %d/config.json
```

Then run `sudo systemctl daemon-reload` and use that interpreter for diagnostic
commands too. Do not enable both a hosting-panel cron job and a systemd timer for
the same instance.

## Operations and logs

```bash
# Run now, with the normal credentials and sandbox.
sudo systemctl start prestashop-mautic-importer@example-shop.service

# Inspect results and follow the next run.
sudo journalctl -u prestashop-mautic-importer@example-shop.service --since today
sudo journalctl -fu prestashop-mautic-importer@example-shop.service

# List all instance timers and inspect failures.
systemctl list-timers 'prestashop-mautic-importer@*.timer'
systemctl --failed 'prestashop-mautic-importer@*.service'

# Stop future scheduled imports. This does not stop an already active service.
sudo systemctl disable --now prestashop-mautic-importer@example-shop.timer
```

The script writes JSON lines to stderr, which systemd captures in journald. A
summary reports processed, created, updated, unchanged, would-create,
would-update, failed, invalid-row counts, and duration. Progress is logged every
100 processed contacts. The `level` property is inside the JSON message, not a
promise that journald's native priority matches it. Journal retention is controlled
by the server's journald configuration, not this application.

Raw emails, names, tokens, passwords, request bodies, and response bodies are not
logged. Contact-specific failures use a truncated SHA-256 `email_ref`, and invalid
source records use their table kind and row ID. These are pseudonymous operational
identifiers, **not anonymization**. To locate an `email_ref`, calculate the first
16 hexadecimal characters of SHA-256 over the normalized source email.

| Exit code | Meaning |
| --- | --- |
| `0` | Run completed, including an empty eligible source list |
| `1` | Import, partial-contact, or source-data failure |
| `2` | Invalid configuration, arguments, or missing runtime dependency |

Monitor failed units and successful completion timestamps with your existing
monitoring. A skipped/failed positive import can be retried next hour if the
contact remains eligible. The script does not send its own failure notifications.

### Failure and concurrency rules

Lookups use Mautic's `where` email-equality filter, request up to two results, and
verify the normalized returned address. Multiple matches, inconsistent results,
or an unexpected address are never treated as permission to create a contact.
Relevant duplicate contacts must be resolved in Mautic.

Only changed managed fields and tag deltas are sent. Existing contacts use
`PATCH`, never `PUT`. Payload keys are explicitly restricted to profile fields
and tags, so DNC and other contact metadata cannot accidentally be echoed back.
The [contact API][contacts] documents these operations; the [7.2 controller][controller]
shows why DNC parameters must be omitted entirely.

GET requests and OAuth token issuance retry transient errors at most twice.
Each HTTP request has a 10-second connection timeout and a 30-second total timeout.
`Retry-After` is honored for read/auth retries up to 30 seconds; longer delays
stop the run rather than hammering the API. An explicit 401 can renew the token
once. TLS verification stays enabled and redirects are not followed.

Contact writes are not blindly retried after a timeout or server error. A create
may have succeeded despite a lost response; the next run searches again before
creating. Validation/conflict errors skip the affected contact and continue;
authentication, unavailable API, malformed responses, and source-query failures
stop the run with an unsuccessful exit. API response bodies are withheld from
logs; inspect Mautic's own logs for detailed validation errors.

There is no cross-process exactly-once guarantee. Do not run multiple writers for
the same source; changing/deleting/creating the same contact concurrently in other
systems can require manual reconciliation.

## Update

Download and review a new `import.php`, lint it, then replace the installed copy
atomically on the same filesystem. Prefer doing this with imports inactive:

```bash
/usr/bin/php -l import.php
sudo install -o root -g root -m 0755 import.php /usr/local/bin/.prestashop-mautic-importer.new
sudo mv -f /usr/local/bin/.prestashop-mautic-importer.new /usr/local/bin/prestashop-mautic-importer
```

Configurations stay untouched. When unit files change, install the new templates
and run `sudo systemctl daemon-reload`. No script restart is needed between runs.
Reverting the executable does not undo changes already made to Mautic.

## Scope and limits

Each run imports the full eligible list sequentially, with one lookup per email
and one write per new or changed contact. The source snapshot stays in memory.
There is no timestamp cursor, checkpoint, cache, batch API, parallel worker, or
retry queue. Measure runtime, PHP memory use, and API load with your actual list;
very large lists may need a different approach. A run that continually exceeds
the timeout will not resume where it stopped on the next run.

No order data, carts, purchases, tracking, campaign creation, custom-field
provisioning, subscription history, reverse synchronization, or automatic
re-consent is implemented. The [consent model](#consent-and-suppression)
applies regardless of changes to source records.

## Tests

Run the fast suites and local syntax/unit checks from the checkout:

```bash
php -l import.php
php tests/run.php
python3 tests/sql_test.py
systemd-analyze verify systemd/prestashop-mautic-importer@.service systemd/prestashop-mautic-importer@.timer
```

GitHub Actions (`.github/workflows/tests.yml`) runs on pushes to `main` and pull
requests. Both PHP **8.2 and 8.5** jobs run all three suites, with **MariaDB 11.8**
for database integration, on Ubuntu 26.04. Systemd unit verification remains a
local deployment check.

| Suite | Coverage | Requirements |
| --- | --- | --- |
| `tests/run.php` | Import decisions, payload restrictions, retries, response validation, lost-create recovery, and real CLI subprocesses | Base PHP CLI; no cURL/PDO extensions |
| `tests/sql_test.py` | Generated SELECTs, joins, and source filters against SQLite fixtures | PHP CLI and Python 3 with SQLite |
| `tests/mariadb_test.php` | Real PDO extraction, complete contact map, source precedence, merged groups, invalid-record counts, and restricted grants | PHP with `pdo_mysql` and a disposable MariaDB server |

The PHP suite mocks HTTP: its DNC cases verify importer payloads, not actual
Mautic send suppression. Its redirect case checks HTTP error handling, not
cURL's redirect policy. The database tests do not certify a production PrestaShop
schema or concurrent snapshot behavior. Use the [deployment checklist](#deployment-acceptance)
to validate the actual installations.

### MariaDB integration test

Run **only against a disposable MariaDB server**, with an administrator account
that can create databases/users and grant privileges. The test creates a uniquely
named database and account and removes them in `finally`. PHP warnings and
deprecations fail the test.

```bash
MYSQL_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' \
MYSQL_TEST_USER=root \
MYSQL_TEST_PASSWORD='YOUR_DISPOSABLE_SERVER_PASSWORD' \
php tests/mariadb_test.php
```

Omit `dbname` from the DSN: the test selects its own temporary database.
`MYSQL_TEST_USER` defaults to `root`; the DSN and password are required.

## Deployment acceptance

Before production marketing, verify on the actual installations:

1. A confirmed homepage subscriber, a newsletter-enabled registered customer,
   and an eligible guest customer each import correctly; inactive/unconfirmed
   records and another shop's records do not.
2. Names and all customer groups are correct, a second run is unchanged, and
   unrelated Mautic tags survive group updates.
3. A Mautic email unsubscribe and a bounced/manual-suppressed contact remain
   suppressed after re-import, including after a profile update. Verify an actual
   marketing send honors suppression with the bypass option disabled.
4. The My Account interface and staff procedures do not promise shop-side
   unsubscribe behavior this importer does not implement.
5. The real systemd instance can reach the database and Mautic, exits successfully,
   and produces a completion summary before the next scheduled run.

Automated tests do not replace live PrestaShop/Mautic acceptance or verification
of HTTPS connectivity and marketing-send suppression.

## Dependency maintenance

Renovate is configured in `.github/renovate.json` to update GitHub Actions
dependencies while preserving commit-SHA pins:

- Action digest, pin, patch, and minor updates are grouped into one PR.
- Major action upgrades remain separate and require approval in the Dependency
  Dashboard before Renovate creates a PR.
- Automerge is disabled; review changes and CI results before merging.
- The PHP 8.2/8.5 matrix and Ubuntu 26.04 runner are manually managed.

Enable the [Renovate GitHub App](https://github.com/apps/renovate) for this
repository and keep GitHub Issues enabled. Once this configuration is on the
default branch, Renovate creates and maintains the **Dependency Dashboard**
issue. The configuration alone does not install or enable the app.

To validate the configuration locally, if the Renovate CLI is installed:

```bash
renovate-config-validator --strict --no-global .github/renovate.json
```

## Upstream references

- [PrestaShop 8.2 database schema][ps-schema]
- [ps_emailsubscription 3.0.0][ps-module] and [2.8.3][ps-module-old]
- [Mautic 7.2 authentication][auth]
- [Mautic 7.2 OAuth2 credential creation][oauth-client-controller]
- [Mautic 7.2 Contacts API][contacts]
- [Mautic 7.2 contact API controller][controller]
- [Mautic 7.2 email behavior][emails]
- [systemd execution/credentials][systemd-exec] and [timers][systemd-timer]

## License

[MIT](LICENSE). Independently implemented; no PrestaShop or Mautic application
source is bundled. This project is not an official PrestaShop or Mautic product.

[auth]: https://devdocs.mautic.org/en/7.2/rest_api/authentication.html
[oauth-client-controller]: https://github.com/mautic/mautic/blob/7.2.0/app/bundles/ApiBundle/Controller/ClientController.php
[contacts]: https://devdocs.mautic.org/en/7.2/rest_api/contacts.html
[controller]: https://github.com/mautic/mautic/blob/7.2.0/app/bundles/LeadBundle/Controller/Api/LeadApiController.php
[emails]: https://docs.mautic.org/en/7.2/channels/emails.html
[ps-schema]: https://github.com/PrestaShop/PrestaShop/blob/8.2.0/install-dev/data/db_structure.sql
[ps-module]: https://github.com/PrestaShop/ps_emailsubscription/blob/v3.0.0/ps_emailsubscription.php
[ps-module-old]: https://github.com/PrestaShop/ps_emailsubscription/blob/v2.8.3/ps_emailsubscription.php
[systemd-exec]: https://manpages.debian.org/trixie/systemd/systemd.exec.5.en.html
[systemd-timer]: https://manpages.debian.org/trixie/systemd/systemd.timer.5.en.html
