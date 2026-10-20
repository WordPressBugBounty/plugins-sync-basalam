# Product update validation fallback

An HTTP 400 or 422 from a product update triggers one immediate retry using only
`primary_price` and `stock`. Product/variation identifiers and variation
`properties` remain where the endpoint needs them. SKU, description, name,
photos and other product fields are excluded from the retry. This takes
precedence over the existing SKU/description repair attempts for these statuses.

The retry applies to single product updates, the custom variation endpoint,
SKU-only follow-up patches and batch update HTTP failures. In custom mode, missing price/stock data is rebuilt
through the existing WooCommerce price, stock and variation services. The saved
update mode is unchanged. An already identical price/stock payload or a payload
without updateable price/stock values is not repeated. A failed retry propagates
the error; other HTTP statuses keep their existing behavior. For variable products,
variation mappings use the latest successful fallback response, including when
the fallback follows a SKU-only patch. Status-only operations remain status-only.

The provider's request schemas are retained in
`postman/contracts/basalam-core-fallback.openapi.json`. The local fixture uses
those endpoint/payload shapes and synthetic 400/422 responses to exercise the
requested failure behavior. No provider workspace lookup is needed: this
isolated fixture intentionally injects failures into the existing local contract.

Run the plugin through real WordPress HTTP against the Postman simulation:

```sh
python3 tests/integration/product-update-retry/run.py --wordpress /path/to/wordpress
```

The default harness loads WordPress HTTP source without wp-config or a store database;
product/configuration/metadata storage is in memory. It checks outgoing requests,
zero stock, missing custom-mode fields, bounded retries, ordinary successes,
unrelated errors, variation updates, batch payloads and adapters that return error
arrays instead of throwing exceptions.

To include the actual local WordPress, WooCommerce and MariaDB store:

```sh
python3 tests/integration/product-update-retry/run.py \
  --wordpress /path/to/wordpress \
  --store-container basalam-wordpress-local-wordpress-1 \
  --report-dir /tmp/product-update-retry-results
```

The Docker container must mount this checkout at
`/var/www/html/wp-content/plugins/sync-basalam`, have the plugin and WooCommerce
active, and report `WP_ENVIRONMENT_TYPE=local`. The runner also needs Postman CLI,
Python 3 and `host.docker.internal` connectivity. It starts a temporary HTTP bridge
for the synthetic dependency and stops it after the run. `--wordpress` is used
for the default source-only harness; the store run loads WordPress inside Docker.

The store harness creates real simple and variable products, uses the registered
AJAX action and job executor, and sends real WordPress HTTP requests to the local
Postman simulation. Only media/category fixtures and the remote provider are
replaced. It checks all/custom/price_stock modes, persisted metadata after cache
invalidation, current variation ids, zero stock, adjusted prices/currency,
HTML/malformed JSON errors, SKU follow-ups, stale mappings, batch selection,
permanent/temporary queue failures, nonce checks, real socket timeouts and
connection failures, and OpenAPI-to-Core connection fallback combined with a 422.
Cleanup restores settings, removes fixture products/jobs and verifies that all
preexisting queue rows are unchanged. Saved reports contain synthetic requests.

Validation on 2026-10-20:

| Check | Result |
| --- | --- |
| Full PHPUnit suite on PHP 8.3.35 | 308 tests, 761 assertions; normal and random order passed |
| Full PHPUnit suite on PHP 8.2.34, unprivileged Docker user | 308 tests, 761 assertions; normal and random order passed, no skips |
| Retry helper cases included in PHPUnit | 48 tests, 166 assertions |
| Source-only real HTTP simulation | 22 scenarios, 39 HTTP requests, 32 assertions |
| WordPress 7.0.2 / WooCommerce 10.9.4 / MariaDB store simulation | 52 scenarios, 94 HTTP requests, 367 assertions; cleanup verified |
| Existing product sync operations regression | 30 scenarios, 12 simulated HTTP responses, 212 assertions |
| Existing OpenAPI/Core transport regression, isolated commit snapshot | 79 assertions, 52 simulation requests |
| PHP syntax | All 528 PHP files outside vendor passed PHP 8.2 lint |
| Fixture/runner hygiene | Node syntax, Python syntax and `git diff --check` passed |

Randomized PHPUnit runs used seed `20261020`. Run the same suite with a PHPUnit
9.6 runner and Composer autoloading:

```sh
php -d auto_prepend_file=vendor/autoload.php /path/to/phpunit-9.6.phar \
  --configuration phpunit.xml --order-by=random --random-order-seed=20261020
```

The validation used PHPUnit 9.6.38 PHAR because this checkout's existing vendor
launcher is incomplete. Test fixture fixes centralize metadata write history and
create the order placeholder database stub per test, making the combined suite
independent of file loading and execution order.

These results establish local behavior against a synthetic Basalam dependency.
They do not establish live Basalam acceptance of a store's product data; no live
Basalam product was modified by these tests.
