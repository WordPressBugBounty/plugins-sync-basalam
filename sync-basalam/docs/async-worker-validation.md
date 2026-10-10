# Async worker health validation — 2026-10-10

Base: `master` at `5d3ab55` (lease guard MR !45 already merged).

## Automated checks

- Queue and health PHPUnit tests: **46 tests, 171 assertions, all passed**.
- Standalone orphaned-lease regressions: **12 cases passed**.
- Local WordPress + MySQL worker integration: **8 scenarios, 29 assertions passed**.
  Includes actual HTTP AJAX nonce rejection/acceptance, real queue persistence,
  a separate PHP worker terminated with SIGKILL, a competing runner blocked by
  the real advisory lock, stale recovery, permission checks, notice markup,
  separate API rejection, and warning clearance after protected execution.
  LiteSpeed flags are simulated on this local Apache stack.
- WooCommerce operations integration: **30 scenarios, 212 assertions passed**.
  WooCommerce hooks, mappings and operations are real; Basalam HTTP responses
  use deterministic fixtures. No production products or orders are created.
- PHP lint for all changed PHP files and `git diff --check` passed.

The **full suite is not green**: 214 tests, 479 assertions, four failures.
A clean archive of the base `master` produced the identical four failures
(204 tests, 462 assertions). Three expect the old “مشاهده” order-item link
label instead of the product name; the fourth concerns a stale variation
mapping expectation. They are unchanged by this queue-health patch:

- `OrderManagerAddBasalamItemTest::testMappedItemGetsBasalamNameMeta`
- `OrderManagerAddBasalamItemTest::testUnmappedItemFallsBackToPlaceholderAndKeepsBasalamName`
- `OrderManagerBasalamItemNameTest::testStoresClickableLinkMetaWhenBasalamProductIdIsGiven`
- `StaleVariationRecoveryTest::testCustomModeStaleVariationFallsBackToOneCompleteProductPatch`

PHPUnit 9.6.29 was obtained from the official PHPUnit distribution because
this checkout's Composer development package directories are incomplete.

## Live Mottasell check

The four runtime files were installed at 11:32:46 UTC after recording source
backups and comparing the installed baseline. Uploaded bytes were read back
and compared with the tested source. The previously scoped `.htaccess` rule
remained present; this patch did not rewrite it.

At 11:35:13 UTC, actual async executions reported effective `noabort=true`,
with no protection/interruption alert and the health option `autoload=off`.
All 56 jobs observed initially (IDs 18967–19022) had disappeared through normal
success cleanup; there were no new failures with a null error after job 18838.
After installation, the live product log also recorded product 10247 updating
successfully with HTTP 200 at 11:38:22 UTC.

New product and bulk work continued to arrive. This check establishes successful
real execution and movement of the observed queue, not that all future bulk
cycles have drained. No new live order was fabricated to test order ingestion.
Intentional process termination and absent-protection tests ran only locally.

Admin-warning screenshots are a local fixture, not an actual production outage.
The live success screenshot and full local outputs are retained with the
sanitized support evidence for ticket 9632.

## Actual webserver matrix — 2026-10-10

The added `tests/integration/webservers/run.py` exercises the installed plugin's
actual AJAX handler and background workers on four Docker webserver stacks.
WordPress 7.0.2, WooCommerce 10.9.4 and a separate MariaDB 11.4 database were used
on arm64. Runtime code remained at `f31c096`; this follow-up adds only local test
infrastructure and documentation. The images are pinned to the tested digests.

**40 scenarios, 153 assertions passed**, including fixture/environment checks.

| Actual server | PHP runtime | Scenarios | Result |
| --- | --- | ---: | --- |
| Apache 2.4.68 | PHP 8.2.34, `apache2handler` | 9 | Passed |
| Nginx 1.29.8 | PHP 8.2.34, `fpm-fcgi` | 10 | Passed |
| Caddy 2.11.7 | PHP 8.2.34, `fpm-fcgi` | 10 | Passed |
| OpenLiteSpeed 1.9.3 | PHP 8.3.33, `litespeed` | 11 | Passed |

Each server passed valid/invalid HTTP nonce checks, normal queue cleanup, fixture
API failure separation, real nonblocking loopback dispatch after client
disconnection, four simultaneous HTTP workers without duplicate execution,
slow-worker false-alert exclusion, actual PHP worker SIGKILL, independent admin
detection, anonymous notice exclusion, restart persistence, stale recovery,
orphaned dispatch lease recovery, and actual PHP fatal-error recovery.

Nginx and Caddy also passed a real PHP-FPM four-second request termination limit,
subsequent interruption alert and recovery after restoring the limit. Apache,
Nginx and Caddy did not display LiteSpeed-specific protection warnings or hosting
guidance. OpenLiteSpeed observed real `noabort=1` from the scoped rule, displayed
the warning with the rule absent even after a quick successful job, then cleared
it after the restored rule was observed by an actual background job.

The stale timestamp is aged by the fixture to test the existing 120-second
threshold without waiting two minutes for each case. Queue persistence and
MySQL locks are real. Fixture jobs test the worker lifecycle; external Basalam
API calls and customer order creation are excluded. The local OpenLiteSpeed run
does not replace the earlier production LiteSpeed-host verification.

Reproducible setup, exact scope and report formats are documented in
[`tests/integration/webservers/README.md`](../tests/integration/webservers/README.md).
All disposable matrix containers, its database volume and its network were
removed after the passing run. The existing local development stacks were left
running. Queue/health PHPUnit tests (46 tests, 171 assertions) and 12 lease
regressions were also rerun and passed. The four baseline full-suite failures
listed above remain outside this queue/health change.

OpenLiteSpeed's complete 11-scenario run was then repeated three times
(33 repeated scenarios, 125 additional assertions), all passed. Recovery history
recorded attempts `[0, 1]` and exactly one returned execution in each run. The
recovery assertion uses the actual queue attempt sequence to catch repeated
execution within one attempt while permitting normal queue retries.
