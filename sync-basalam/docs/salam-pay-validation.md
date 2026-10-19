# Salam Pay integration validation — 2026-10-19

The integrated implementation was exercised on the existing **local** WordPress 7.0.2 / WooCommerce 10.9.4 / PHP 8.2 runtime with **HPOS enabled**, using the real WordPress HTTP client against the committed Postman simulation. No live payment or refund was submitted.

- **67 integration assertions passed:** session creation/reuse, exact basket amounts, Persian mobile normalization, currency validation, callback authorization, forged callback state, amount mismatch, HTML gateway failures, replay/locking, stock reduction, transaction persistence, asynchronous partial/full refunds, selected-item restocking, cumulative refund limits, lost-response recovery, cancellation refunds, edited/closed orders, legacy callbacks/refunds and interrupted inquiry resumption.
- Bootstrap checks passed for ordinary loading, an active standalone Salam Pay owner, and a forced synchronization update. Classic gateway and the actual WooCommerce Blocks registry both register the internal method.
- Existing SalamGate offline registration checks: **220 passed**.
- PHP syntax checks for all new PHP files and the entry point passed. JavaScript syntax checks and OpenAPI syntax lint passed. All three browser assets returned HTTP 200. The WooCommerce payment settings page was inspected in the local browser. The 1.10.32 local WordPress admin check confirmed that WooSalam registers its menu without a Salam Pay submenu, the plugin action link opens WooCommerce payment settings directly, and the gateway settings form renders.
- The existing PHPUnit suite ran **227 tests / 516 assertions**, with the same **one error and one failure** reproduced in an isolated archive of the unchanged HEAD before this work:
  - `AsyncWorkerServerDetectionTest::testApacheServerSignatureOverridesLsapiSapiWithoutMarker`: array offset on false.
  - `StaleVariationRecoveryTest::testCustomModeStaleVariationFallsBackToOneCompleteProductPatch`: expected stale variation metadata update absent.

The local `vendor/bin/phpunit` references a missing executable. The existing suite was run using the official PHPUnit 9 PHAR downloaded to `/tmp`, without changing Composer files or production dependencies.

The simulation request log confirmed actual calls through all four integration operations, including session rejection/lost-response errors, HTML inquiry failure, and refund lost-response recovery. It is intentionally not committed because HTTP traces can contain order keys and test credentials.

Remaining live evidence: merchant credentials, production-domain acceptance, actual provider status-enum behavior, bank/credit checkout, and completed real refund settlement. The API contract exposes integer status values without their names; the implementation preserves success value `3` from the supplied plugin and keeps unfamiliar states unresolved.
