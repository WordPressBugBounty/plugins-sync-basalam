# Webhook setup on plugin activation — 2026-10-20

Reactivating the plugin did not set up a missing webhook. The activation hooks
created database tables and schedules, but never called `WebhookService`.
OAuth completion and incoming-token recovery were the existing active setup
callers. The service fallback could recover a request only after a caller
actually sent it.

## Change

The main plugin file now registers `WebhookService::onActivation()` as an
activation callback. A connected store reconciles its webhook through the normal
GET/create/update flow and logs success or failure. A new installation without a
Basalam token waits for the existing OAuth setup flow. API failures do not prevent
activation. This callback does not add a new periodic retry job.

Setup now considers only subscriptions with the current store's exact callback
URL. Complete or incomplete webhooks belonging to other stores are neither
overwritten nor deleted. The local-host guard uses `strpos`, compatible with
PHP 7.4. Existing setup/fallback routes, event ids and authentication are reused.

## Local verification

The real WordPress HTTP/TLS + Postman simulation with isolated MariaDB passed:

- 248 transport/setup assertions and 77 persistence/concurrency assertions.
- 202 application requests, including 99 webhook requests and 11 webhook writes.
- The existing 9 provider-schema response checks and host/SNI/auth checks.
- Activation without credentials, creation through fallback, repeated activation
  without a duplicate, preservation of two foreign callbacks (complete and
  incomplete), and failure logging without interrupting activation.
- Existing 48-hour routing checks, including 49 independent PHP workers.

Related SKU regressions passed: 5 tests / 13 assertions. Main/plugin service
syntax checks passed; `WebhookService.php` was also checked with PHP 7.4.

## Live verification on mottasell.com

Before installation, a native request from the host confirmed an empty webhook
list. OpenAPI naturally failed with cURL 7; the existing service fallback returned
HTTP 200. The installed main file had no webhook activation callback. No IP/DNS
override or synthetic provider response was used.

The corrected 1.10.34 ZIP was installed through WordPress upload/replace. All 393
PHP files under `includes/` in the ZIP matched the working tree; CRC validation
passed. Version metadata stayed 1.10.34, without a tag or push. This package also
includes the previously tested 10-failures-in-10-minutes / 48-hour routing policy.
The installed runtime hashes matched the source:

| File | SHA-256 |
| --- | --- |
| `sync-basalam.php` | `b62db54e133fb2cca5cdbab61cbfbf4b51962fb0c41392b4999f9f8b3ff6d4e4` |
| `includes/Services/WebhookService.php` | `89ebcb0cfa25e0a42701c62b219eed0ab868433e8c6ac0186e8c495294e03121` |
| `includes/Services/Api/OpenApiConnectionFallback.php` | `916f010c357edac5ff918072e5661b8c6fbef09a4d52b7cc5035615ec2ef137c` |
| `includes/Services/Api/OpenApiFallbackPolicy.php` | `a86ab9deb340db0eee0b91b6c39dc5d38abfc5490f3a27f27c83517846f0f891` |
| `includes/Config/Endpoints.php` | `a0abb69d753ffc1b44db974b6084bc530ab736c8e10b6eefde9fee0c5577df94` |

The list was still empty after replacement, before the activation test. The
plugin was then deactivated and activated through WordPress's actual Plugins
screen. Activation succeeded and created exactly one active webhook, **15360**:

- Callback: `https://mottasell.com/wp-json/sync-basalam/v1/order-manager`.
- Events: 3, 5, 7; verification token matched the site's saved header token.
- The account-registration list returned HTTP 200 and contained id 15360.
- The normal Info log displayed success at **14:44:12 Asia/Tehran**,
  1405/07/28, with zero errors shown on the plugin log screen.

The initial optional account-registration diagnostic used `per_page=100`, which
the service rejected with HTTP 422. The corrected read used `per_page=10` and
exited successfully. This read-only diagnostic did not change the subscription.

The live check verifies activation, remote subscription state, account
registration and the logged result. It did not create an order or test delivery
of a new production order event. The 48-hour threshold/expiry and repeated
activation scenarios were covered locally; no artificial outage was injected
into production.

Pre-installation runtime files are backed up outside the document root at
`/home/mottase1/.woosalam-activation-backup-20261020-111152/`.
Local evidence, the prior ZIP and a screenshot are in
`/tmp/woosalam-activation-repair-20261020/`. No public diagnostic helper was created.
