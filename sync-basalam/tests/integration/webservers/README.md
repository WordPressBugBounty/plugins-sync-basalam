# Actual webserver queue/health integration

Run from a machine with Docker Compose and Python 3. Supply an **unconfigured,
disposable** copy of WordPress core and WooCommerce. Do not copy a database,
`wp-config.php`, uploads, tokens or other plugins from a customer site.

For example, copy the core and WooCommerce code from an existing local Docker
development stack (replace the container name as appropriate):

```sh
mkdir -p /tmp/sync-basalam-matrix/site/wp-content/plugins
docker exec basalam-wordpress-local-wordpress-1 \
  tar -C /var/www/html --exclude=wp-config.php --exclude=wp-content --exclude=.htaccess -cf - . \
  | tar -C /tmp/sync-basalam-matrix/site -xf -
docker exec basalam-wordpress-local-wordpress-1 \
  tar -C /var/www/html/wp-content/plugins -cf - woocommerce \
  | tar -C /tmp/sync-basalam-matrix/site/wp-content/plugins -xf -
python3 tests/integration/webservers/run.py \
  --site-dir /tmp/sync-basalam-matrix/site \
  --results /tmp/sync-basalam-matrix/results
```

The runner creates its own MariaDB volume/network and installs WordPress,
WooCommerce and this checkout's plugin. It runs each backend sequentially because
the real MySQL advisory lock is global to the database server. The server/PHP
images are pinned to the tested digests. Ports 8141–8144 bind to loopback only.
Only this stack's containers and disposable DB volume are removed on completion.
The supplied disposable site directory and report files remain available.

`--servers nginx caddy` selects a subset. `--keep` retains test containers after
a failure for debugging; a subsequent runner invocation reconfigures their
ephemeral token. Use only one matrix run at a time.

`--protection-only` focuses on automatic `.htaccess` installation/repair, actual
server module detection, nonce validation, one real successful job and silent
health diagnostics. It also verifies a real three-second nonblocking background
job on every server. It runs on all four servers by default. OpenLiteSpeed also
removes and automatically restores the rule, reloads its disposable runtime and
verifies a real background job with `noabort=1`. Omit the flag to include the
longer concurrency, worker-kill, timeout and queue-continuation scenarios.

The control MU plugin requires a local WordPress environment and an ephemeral
token on each control request. It cannot be enabled by an ordinary production
plugin activation. Its installer requires CLI, the local environment and the
matrix token. Remote HTTP calls and email are blocked; only real loopback HTTP
to the tested WordPress instance is allowed.

The installed plugin's unmodified AJAX handler, job runner, queue table, executor,
registry and MySQL locks are exercised over HTTP. A small local fixture job
performs success, explicit failure, delayed execution or a real PHP fatal. These
tests do **not** call Basalam's external API or fabricate customer orders.
Product/order semantics have their separate integration/unit tests.

The continuation scenario enqueues five slow jobs, dispatches once and waits
using control requests that cannot restart the runner. With WP cron disabled,
the queue must drain across multiple batches exactly once and without overlaps.
All handoffs share one recovery event; invoking it after completion must stop
rearming it. Normal ready work continues directly after the batch releases its
database lock. Delayed retries and interrupted workers additionally use a
single 60-second WordPress cron recovery event, which needs functioning WP cron
or a server cron invoking WordPress.

Fast runner regressions can also be run without Docker:

```sh
php tests/JobsRunnerContinuationRegression.php
php tests/JobsRunnerLeaseRegression.php
```

Scenarios include nonce rejection/acceptance, successful cleanup, fixture API 422
separation, real nonblocking dispatch with a caller that disconnects, four
concurrent HTTP workers, slow-worker exclusion, SIGKILL of the actual PHP worker,
independent detection on a subsequent admin request without visible alerts,
restart persistence, stale recovery, legacy orphaned leases and PHP fatal errors.
Nginx/Caddy additionally test a real PHP-FPM `request_terminate_timeout`.
OpenLiteSpeed additionally installs/restores the scoped `.htaccess` rule through
the real production installer and checks the **actual** LSAPI `noabort` flag.
Anonymous/subscriber requests and `DISALLOW_FILE_MODS` cannot write configuration;
administrator installation preserves all original bytes and a non-autoloaded
private DB backup. Other webservers leave the original file intact. All contexts
remain silent, including interrupted or unprotected workers.

All three non-LiteSpeed cases also place the exact managed block in `.htaccess`,
even though their automatic setup correctly leaves the file unchanged. HTTP
requests and background work must keep succeeding with the block present.
Apache verifies its real non-LiteSpeed module marker. OpenLiteSpeed must
observe the positive marker and `noabort`; unrelated AJAX requests must not.
These environment flags are not simulated. Manual worker requests
include the same action query string as production dispatch, because the rule
is intentionally scoped to that query string.

OpenLiteSpeed 1.9.3 requires a server reload to apply changed rewrite rules. The
matrix records this distinction, verifies that writing the file alone is not
treated as effective protection, then reloads the disposable container. The
production plugin does not restart a customer's webserver or claim that a
successful file write proves effective protection. See the official
[OpenLiteSpeed rewrite documentation](https://docs.openlitespeed.org/config/rewriterules/).

For fast reproducible stale checks, the control fixture changes `started_at` to
121 seconds ago **after** a real worker has started or died. The production
120-second threshold is unchanged. A live worker must not be recorded as interrupted
while it holds the real DB lock. The batch window is one second in this fixture;
the production default remains twenty seconds.

Each recovery start records its PHP PID and the queue's attempt counter. Recovery
requires distinct consecutive attempts and exactly one returned execution. This
checks normal retries and catches duplicate execution within an attempt.

`results.json` records scenarios, assertions, actual PHP SAPI/version and sample
health states. `containers.log` retains HTTP/FPM logs and `images.json` records
image digests. Failed runs retain the last control state for diagnosis. The
control token is never written to the reports.

Official configuration references:

- [LiteSpeed's Docker image](https://docs.litespeedtech.com/cloud/docker/openlitespeed/)
- [LiteSpeed connection abort protection](https://docs.litespeedtech.com/lsws/extapp/php/configuration/control/)
- [Nginx FastCGI handling](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html)
- [Caddy PHP FastCGI handling](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi)
