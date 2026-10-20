# OpenAPI connection fallback

Normally, `AbstractApiService` first sends the original OpenAPI request. If WordPress
reports a DNS or connection failure before sending it, `OpenApiConnectionFallback`
retries once against the corresponding Core, Order Processing or Webhook operation. The
existing response handler and circuit breaker process the final result.

After **10 eligible OpenAPI cURL 6/7 failures in a rolling 600-second window**,
requests with a known mapping go **directly to their fallback for 48 hours**.
The tenth failed request still receives its immediate fallback. At the fixed
deadline, requests start with OpenAPI again.

Core uses different route versions. Replacing only the hostname would produce
a 404 for product updates. The mappings are based on the provider's
[Swagger documentation](https://core.basalam.com/docs) and
[OpenAPI specification](https://core.basalam.com/openapi.json), retrieved on
2026-10-19. A provider-derived subset, including request and response schemas,
is saved in `postman/contracts/basalam-core-fallback.openapi.json`.

| Operation used by the plugin | OpenAPI path | Fallback path (Core unless specified) |
| --- | --- | --- |
| GET/PATCH product | `/v1/products/{id}` | `/v4/products/{id}` |
| POST product | `/v1/vendors/{id}/products` | `/v4/vendors/{id}/products` |
| PATCH product batch | `/v1/vendors/{id}/products/batch-updates` | `/v4/vendors/{id}/products` |
| GET categories | `/v1/categories` | `/v3/categories` |
| GET category attributes | `/v1/categories/{id}/attributes` | `/v3/categories/{id}/attributes` |
| GET vendor | `/v1/vendors/{id}` | `/v3/vendors/{id}` |
| POST/DELETE discounts | `/v1/vendors/{id}/discounts` | `/v3/vendors/{id}/discounts` |
| GET vendor parcels | `/v1/vendor-parcels` | Order Processing `/v3/vendor-parcels` |
| GET/POST webhooks | `/v1/webhooks` | Webhook `/v1/webhooks` |
| PATCH/DELETE webhook | `/v1/webhooks/{id}` | Webhook `/v1/webhooks/{id}` |

The order-list mapping comes from the [Order Processing specification](https://order-processing.basalam.com/openapi.json)
(version 1.820.1, retrieved 2026-10-19). The operation and its referenced schemas
are saved in `postman/contracts/basalam-orders-fallback.openapi.json`. It retains
`per_page`, `created_at[gte]`, opaque `cursor` and the `data`/`next_cursor` response.
Both `FetchOrdersService` (through `AbstractApiService`) and SalamGate's
`BasalamParcelReader::orders()` use this same fallback.

Both attempts use normal WordPress DNS, transport, proxy settings and request
timeouts. Production code performs no IP discovery, pinning, caching or cURL
override. The retry changes the hostname and mapped path, preserving the method,
query, body and headers. Host and TLS SNI match the mapped service hostname.

Only HTTPS `openapi.basalam.com` on port 443, without URL credentials and with a
mapped path, is eligible. The trigger is `http_request_failed` with cURL **6 or
7**. HTTP errors, TLS errors, WordPress blocking and ambiguous timeouts or
disconnections do not trigger replay. A failure after a followed redirect also
cannot replay the original operation. Exactly one fallback attempt is allowed, and
its real response or error is returned. Scoped WordPress filters enforce TLS
certificate/hostname verification on the destination and are removed in `finally`.

The batch job already sends PATCH. Its fallback uses Core **v4**, whose batch
schema accepts `primary_price` for products and variants, product `sku`, and the
`continue_on_error` query parameter. Core v3 uses `price`, lacks product `sku`
and does not document `continue_on_error`, so changing only to that path could
silently omit fields. The v4 batch operation and its nine referenced schemas
were copied from the provider specification on 2026-10-20. The unchanged 202
array response preserves per-item `has_error` and `error_message`, including
the existing duplicate-SKU handling. The previous inventory's claim that the
plugin sends POST for batch updates was incorrect.

Webhook CRUD falls back to `https://webhook.basalam.com`, retaining the exact
path, method, query, body and authentication. The provider's current
[Webhook specification](https://webhook.basalam.com/openapi.json), retrieved on
2026-10-20, documents these four operations, matching request fields, Bearer auth,
and the list/resource/delete response shapes. The operation subset and seven
referenced schemas are saved in
`postman/contracts/basalam-webhooks-fallback.openapi.json`. Only the collection
and numeric-id paths are mapped; events, customers and logs are excluded.
Historical service usage is recorded in `docs/webhook-api-history.md`.
Requests already targeting Core, Order Processing or Webhook do not use this
fallback.

## Persistent outage routing

`OpenApiFallbackPolicy` shares one counter across the site's mapped OpenAPI
requests: products, batches, categories, vendors, discounts, orders and webhooks.
Failures count even when their immediate fallback succeeds. Successful requests
between errors do not reset the rolling window. Only initial pre-redirect
`http_request_failed` responses containing cURL 6 or 7 contribute; HTTP errors,
TLS errors, ambiguous failures, destination errors, direct service calls and
unmapped/unsupported URLs do not contribute.

At each qualifying failure, timestamps older than 600 seconds are discarded;
the exact 600-second boundary is included. Nine failures leave normal routing
enabled. The tenth sets `fallback_until = now + 172800` and clears the timestamp
list. Requests starting during this interval skip OpenAPI and use their existing
documented destination. They preserve method, query, body, auth, TLS checks and
WordPress HTTP blocking. A destination error is returned without trying OpenAPI
or replaying the operation. Unknown routes retain their original URL.

The deadline is not extended by traffic, failures or late completions of
in-flight OpenAPI requests. No cron is needed for expiry: at the exact deadline,
normal routing resumes. A later qualifying error starts a fresh failure window.
The separate circuit breaker continues to process the final HTTP result.

State is stored as a non-autoloaded private JSON option,
`sync_basalam_openapi_fallback_policy`, scoped to the site's options table.
Direct database reads avoid stale option/object caches across workers and long
jobs. A site-specific MySQL advisory lock protects the brief read/update sequence;
the lock is always released and is never held during HTTP calls. The timestamp
list is bounded to nine entries. Lock waiting is limited to two seconds; if a
lock cannot be obtained, that sample is skipped and immediate fallback still runs.
There is no IP override, DNS cache or new database schema.

## Coverage audit (2026-10-20)

This inventory describes **host fallback**, not queue retries, OAuth refresh or
local cron recovery. No alternative service is assumed without a matching contract.

| Caller / feature | Destination | Host fallback |
| --- | --- | --- |
| Shared product/category/vendor/discount API | OpenAPI routes listed above | Core |
| Order importer and SalamGate order list | OpenAPI vendor-parcels | Order Processing v3 |
| `WebhookService` (list/create/update/delete) | OpenAPI `/v1/webhooks[/{id}]` | Webhook service, same v1 paths |
| `BulkUpdateProductsJob`, including its duplicate-SKU retry | OpenAPI PATCH batch-updates | Core PATCH `/v4/vendors/{id}/products` |
| Order details, confirm/cancel/delay/tracking/automation; SalamGate parcel/detail reads | Order Processing directly | None |
| Product variations/list/commissions and category preparation | Core directly | None |
| `FinancialManagementService` | accounting, identity | None |
| SalamGate client, balances and activation/connection identity | salam-gate, services, Core | None; 401 token renewal is separate |
| `SalamPay\ApiClient` | salam-pay-api | None; financial reconciliation is separate |
| Media initialize/complete/status and signed binary upload (`FileUploadApiService`) | uploadio and signed upload URL | None |
| Category prediction and app reviews | categorydetection, apps-api | None |
| OAuth proxy, tickets, announcements, version, business/site access | api.hamsalam.ir | None |
| Remote file and label downloads | supplied validated file/label URL | None |
| `JobsRunner` / `AsyncBackgroundProcess` | site's own admin-ajax | Local worker dispatch; cron recovery, no alternate Basalam host |

## Local verification

Run with PHP/cURL/mysqli, Python (`jsonschema`), OpenSSL, Postman CLI 1.73+,
Docker (MariaDB 10.6 image) and WordPress sources:

```bash
python3 tests/integration/openapi-fallback/run.py --wordpress /path/to/wordpress
```

The harness loads real WordPress HTTP, Requests, hooks and wpdb without a store
or active plugins. It creates an isolated temporary MariaDB container and options
table, removing the container after the run. Other store configuration remains
in memory. A local TLS bridge controls connection refusal and
certificate failures and forwards actual HTTP traffic to the Postman simulation.
Only test code overrides connections to localhost; its certificate is trusted
only by the test process. Production addresses receive no simulation writes.

The mock is a transport fixture, not complete provider schema validation.
Provider route versions are recorded in the saved contract; diagnostic query
parameters control failures only in the fixture. The Postman CLI was signed
out and Orbit discovery failed to connect, so the existing local fixture and
the provider's directly retrieved specification were used.

Verified with WordPress 7.0.2: **79 PHP assertions**, plus independent inspection
of **52 application simulation requests** and **3 provider-schema response checks**.
Evidence includes two unchanged product PATCH
payloads/query strings, one POST creation, correct Core v4 paths, Core Host/SNI
and preserved Bearer authentication. Covers healthy OpenAPI, mapped Core routes,
bounded retries, HTTP errors, timeout/disconnection, invalid TLS even when the
original caller disables verification, redirect replay guards, the actual
`PatchApiService`, circuit-breaker accounting and WordPress external blocking.
Order checks additionally run the actual importer and SalamGate reader over TLS,
read first/next/empty pages, preserve an opaque cursor and date filters, verify
Order Processing Host/SNI and auth, return HTTP 401/403/404/422/429/500/503 without
extra replay, and bound refusal/DNS recovery to one fallback. No real orders are
created in these local checks. Related PHPUnit regressions: 29 tests, 43 assertions.

### Batch extension verification (2026-10-20)

After adding batch PATCH, the complete local transport simulation passed **108
PHP assertions** on WordPress 7.0.2. Independent inspection verified **74
application requests**, including **22 batch requests**, and validated success/
partial batch responses against the copied provider schema (2 checks, alongside
the existing 3 order response checks). The real `PatchApiService` and WordPress
HTTP/cURL transport executed over TLS against the existing local Postman fixture.
The fixture was extended using the provider's v4 batch request/response schemas.
No production mutation was sent.

Checks preserve simple/variant prices, stock zero, product SKU, vendor id,
`continue_on_error`, auth, Host/SNI and per-item errors. They cover natural
TCP refusal, synthetic DNS error, healthy primary requests, HTTP errors,
TLS verification, timeout/disconnection, redirects, bounded attempts,
circuit-breaker accounting and WordPress blocking. Related SKU regressions
passed with **5 PHPUnit tests / 13 assertions**. These batch results are local;
the live checks below predate the batch mapping.

### Webhook extension verification (2026-10-20)

The complete local suite after adding webhook CRUD passed **203 PHP assertions**
on WordPress 7.0.2. The application sent **160 simulation requests**, including
**86 webhook requests**. Independent request inspection verified exactly **8
non-diagnostic webhook writes** (3 POST, 3 PATCH, 2 DELETE), unchanged auth,
callback, event ids, JSON-string verification headers and false/empty values.
The fixture's four CRUD response shapes passed **4 provider-schema checks**,
alongside the existing 2 batch and 3 order response checks. The test TLS
certificate, Host and observed SNI include `webhook.basalam.com`.

Tests execute the actual `ApiServiceManager` GET/POST/PATCH/DELETE transports and
`WebhookService::setupWebhook()` with real WordPress HTTP over TLS. A stateful
local Postman fixture based on the retrieved provider contract records creation,
repeated setup and incomplete-subscription cleanup; the resulting store contains
one complete subscription. Failure checks cover cURL 6/7, both connection
attempts refused, HTTP 401/403/404/422/429/500/503, ambiguous timeouts and
disconnections, primary/destination TLS errors, redirects and WordPress external
HTTP blocking. Successful primary requests and direct service requests do not
fallback. Related SKU regressions still pass: 5 tests, 13 assertions.

These are local management-API checks with synthetic subscriptions, not live
webhook creation/deletion or event-delivery tests. The production checks below
predate both batch and webhook mappings; these extensions have not been deployed
or exercised with the store's real webhook credentials.

### 48-hour routing verification (2026-10-20)

The complete local suite passed **244 transport assertions** and **77 policy
assertions** with **196 application requests** reaching the Postman simulation.
All 14 mapped operations went directly to the documented destination during
cooldown; independent request inspection verified hosts, SNI, auth, product
payload and the unchanged batch contract. The existing 9 provider-schema
response checks (2 batch, 3 order, 4 webhook) also passed. Provider contracts and
the existing Postman transport fixture were reused; no new provider endpoints
were introduced.

Policy checks used real MariaDB 10.6 and WordPress wpdb, including 49 independent
PHP workers: nine concurrent failures were retained, the tenth activated routing,
late workers did not extend it, a fresh PHP process saw the saved state, and a
20-worker burst activated one cooldown. Clock-controlled checks cover the rolling
ten-minute boundary, success interleaving, exact 48-hour expiry and a fresh window
after expiry. Non-qualifying errors, redirects, unknown URLs, fixed deadlines,
multisite isolation and bounded lock failure were checked. TLS, ambiguous
failures and final HTTP errors were also exercised on direct fallback traffic.
Related SKU regressions passed: 5 tests / 13 assertions. Both changed production
PHP files passed syntax checks on PHP 7.4 and 8.3.

These are local transport and persistence results. The policy was subsequently
included in the activation repair package installed on the store; runtime hashes
were verified. Its threshold and 48-hour expiry were not artificially exercised
in production. See [the activation repair report](activation-webhook-repair-2026-10-20.md).

## Order fallback production verification (2026-10-20)

Before the implementation, a read-only request from mottasell.com's own PHP/
WordPress runtime to Order Processing `/v3/vendor-parcels` returned HTTP 200
in 399 ms with 9 parcels, valid importer fields and the site's vendor identity.
Normal DNS and verified TLS were used; no import was performed by that check.

The updated 1.10.33 ZIP was subsequently installed using WordPress's normal
plugin upload/replace flow, which confirmed a successful update. Both installed
runtime files matched the tested source by SHA-256:

| File | SHA-256 |
| --- | --- |
| `includes/Services/Api/OpenApiConnectionFallback.php` | `ff9a5ed9b83a9a4c36e0024ca9aa7ffbb0830dc1723f850894cefa9e49827385` |
| `includes/SalamGate/Order/BasalamParcelReader.php` | `ef8730f39ff25c3d8b00f992a2c37602d3fd25b6f8f984341c932165f74b3b24` |

A read-only native WordPress request on the host then exercised the installed
fallback: OpenAPI `/v1/vendor-parcels` failed naturally with cURL 7, followed by
Order Processing `/v3/vendor-parcels` returning HTTP 200. All 9 rows contained the
importer fields and matched the site's vendor, with no next cursor. Normal DNS
and verified TLS were used, without IP or transport overrides.

The actual admin **check Basalam orders** button was run. At 08:02:02 UTC, the
plugin logged a page of 9 orders, followed by 3 successful order-creation logs
between 08:02:06 and 08:02:09 UTC. Payment mappings increased from 429 to 432.
A subsequent read verified all 9 returned invoices had mappings to existing
WooCommerce orders, and there were no duplicate invoice groups or new order/
transport error logs since the test began. Version 1.10.33 remained active. No
new order jobs remained, and the button returned to its enabled state. Existing failed
jobs from before deployment were left intact.

The installed SalamGate `BasalamParcelReader::orders(7)` was also executed on
the host: natural OpenAPI cURL 7, then Order Processing HTTP 200, returning the
same 9 invoices. This used WP-CLI with plugins/themes skipped because normal
CLI bootstrap encounters an unrelated ionCube error; a process-local helper
provided the real settings container. The actual reader, fallback and WordPress
HTTP transport ran without response mocks or a public helper file. This check
did not exercise the 401 refresh branch or SalamGate shipping/payment actions.

An earlier deployment attempt had lost its cPanel session, and the browser
blocked the File Manager upload page; no bypass was attempted. The successful
WordPress installation above supersedes that deployment blocker. The original
fallback backup remains outside public_html at
`/home/mottase1/OpenApiConnectionFallback-before-orders-20261020.php.bak`.
The live Core tests below predate this order change.

The PHPUnit suite reports 260 tests, 595 assertions and one existing failure in
`StaleVariationRecoveryTest::testCustomModeStaleVariationFallsBackToOneCompleteProductPatch`.
The same failure was reproduced with the original `AbstractApiService`, before
the fallback changes.

## Production verification (current domain fallback)

On 2026-10-19, native WordPress GET to
`https://core.basalam.com/v4/products/25859815` from `mottasell.com` returned
HTTP 200 and product id 25859815, using normal DNS and TLS verification.

Only `AbstractApiService.php` and `OpenApiConnectionFallback.php` were then
deployed to the active `sync-basalam` plugin. Installed contents were verified
against the local files. The previous IP-based files and their SHA-256 hashes
are backed up outside the document root at
`/home/mottase1/.woosalam-core-fallback-backup-20261019-db079098.json`.
The runtime reported PHP 8.3.35, WordPress 7.1.3; plugin version remains 1.10.32.

The normal `ProductOperations::updateExistProduct(16532)` operation produced:

1. Product commission GET to Core: HTTP 200.
2. PATCH `https://openapi.basalam.com/v1/products/25859815`: cURL 7 after 7 ms.
3. PATCH `https://core.basalam.com/v4/products/25859815`: HTTP 200.

The operation returned success, product sync status was `synced`, and the normal
WooCommerce log persisted:

```text
2026-10-19T14:05:44+00:00 INFO بروزرسانی محصول با موفقیت انجام شد.
product_id: 16532
```

This is 17:35:44 Asia/Tehran, 1405/07/27. No IP or DNS override was used in the
production code or live verification. At this stage, live coverage included this
product's existing price/stock update mode; creation and other mapped operations
had only local coverage. The later full live run below adds real creation and
discount effects.

The expiring, token-protected helper deleted itself, its private report and its
one-use marker after verification. Absence of the public helper was independently
confirmed through the hosting file API.

### Expanded live verification

A second, broader run on the same hosting account on 2026-10-19 completed at
17:45:50 Asia/Tehran. Both deployed PHP file hashes matched commit `d3e05a1`.
PHP was 8.3.35, WordPress 7.1.3 and WooCommerce 11.2.1. The existing update mode
was `price_stock`. No production source or store settings were changed.

| Group executed inside the site's WordPress runtime | Passed | Failed |
| --- | ---: | ---: |
| Runtime, connection, product and live GET checks | 9 | 0 |
| Controlled failure, route and AJAX access checks | 29 | 0 |
| Actual admin AJAX product update and data comparison | 10 | 0 |
| Persistence checks in a fresh PHP request | 6 | 0 |
| Total | **54** | **0** |

Live `GetApiService` requests for categories, vendor information, product
information and category 897 attributes each received a natural OpenAPI cURL 7
followed by Core HTTP 200. JSON shapes and matching resource ids were checked,
including the preserved `exclude_multi_selects=true` query. Categories returned
12 top-level groups and attributes returned one group.

The actual registered `wp_ajax_update_product_in_basalam` handler was dispatched
inside WordPress as an existing administrator, using its normal generated nonce
and product 16532. No browser session or login cookie was created. Its JSON
response had HTTP 200 and `success: true`. The observed PATCH sequence was:

| Target | Result | Observed request duration |
| --- | --- | ---: |
| `https://openapi.basalam.com/v1/products/25859815` | cURL 7 | 7 ms |
| `https://core.basalam.com/v4/products/25859815` | HTTP 200 | 220 ms |

Exactly one successful product PATCH reached Core. Request-body and header
digests matched between attempts; Bearer authentication was present and TLS
verification was enabled. Sent fields were `id`, `variants`, `type`,
`primary_price`, and `stock`. A native Core GET after the update and another GET
in a fresh PHP request verified `primary_price = 39860000` and `inventory = 2`,
equal to the actual outgoing `primary_price` and `stock` values. The product was
already at these values before the test; this proves an accepted, idempotent
update and correct persisted values, rather than a deliberate price change.
WooCommerce product fields remained identical, sync metadata persisted as
`synced`, and the circuit breaker remained closed with zero consecutive failures.

The normal product-specific success log was read back from WooCommerce storage:

```text
2026-10-19T14:15:25+00:00 INFO بروزرسانی محصول با موفقیت انجام شد.
product_id: 16532
```

The 29 guard checks used controlled callback responses for DNS/connection errors,
TLS errors, timeout/disconnection, HTTP 400/401/403/404/422/429/500/503, exhausted
connections, unsupported destinations and unmapped routes. They checked the
installed fallback's decisions without sending destructive or invalid upstream
requests. The registered AJAX handler also rejected an invalid nonce and a user
without permission, making no Basalam call. Product creation and discount route
mapping were checked synthetically; no real products or discounts were created
or deleted. These checks complement the earlier local TLS/Postman simulation;
they do not turn the synthetic cases into live provider validation.

All four host-side groups recorded no PHP warnings or fatal errors. The public
home page, product page and REST index returned HTTP 200 before and after the
update, with no fatal-error page; the product page contained the expected title.
The temporary helper rejected unauthenticated access with 404. It and its private
reports/one-use markers were deleted, their absence was independently verified
through cPanel, and the hosting session was cleared.

### Full live coverage of all mapped operations

A third run on `mottasell.com` on 2026-10-19 ran from 17:13:57 to 17:25:26 UTC
(20:43:57–20:55:26 Asia/Tehran). It exercised all **six mapped route groups and
eight HTTP operations** against real Basalam services from the site's WordPress
runtime, using the installed fallback. No production source or plugin settings
were changed for this run. Runtime versions were PHP 8.3.35, WordPress 7.1.3,
WooCommerce 11.2.1 and plugin 1.10.32.

Each operation below naturally received OpenAPI cURL 7, followed by exactly one
successful Core attempt. No error injection, IP selection, DNS override or
synthetic API response was used. Follow-up reads went directly to Core with
normal DNS, Bearer authentication and TLS verification.

| Real operation | Core route | HTTP result | Verification |
| --- | --- | ---: | --- |
| GET categories | `/v3/categories` | 200 | JSON `data` array |
| GET category attributes | `/v3/categories/897/attributes` | 200 | JSON `data` array; `exclude_multi_selects=true` preserved |
| GET vendor | `/v3/vendors/891059` | 200 | Matching vendor id |
| GET product | `/v4/products/25859815` and `/v4/products/63379131` | 200 | Matching original and newly created product ids |
| POST product | `/v4/vendors/891059/products` | 201 | New product 63379131 persisted with price 1000000 and inventory 0 |
| PATCH product | `/v4/products/63379131` | 200 | Price deliberately changed from 1000000 to 1200000; a new GET confirmed it |
| POST discounts | `/v3/vendors/891059/discounts` | 202 | Job 22250254 completed with one success; actual 10% discount observed |
| DELETE discounts | `/v3/vendors/891059/discounts` | 202 | Job 22250280 completed with one success; actual price and discount restored |

Creation used the real `CreateSingleProductService`, associated only with a
temporary draft/hidden WooCommerce product, 26967. The subsequent changed-price
update used `UpdateSingleProductService`. The creation and update Core requests
took 311 ms and 342 ms respectively. For creation, update, discount application
and discount removal, body/header digests matched between attempts, Bearer
authentication was present, and Core TLS verification was enabled.

The newly created Basalam product was not approved. Two initial discount jobs on
it (22249929 and 22250018) returned 202 and completed, but selected zero products.
They are evidence of transport acceptance only, not successful discount effects.
The full effect test therefore used published product **53491088**, which had
inventory 0, `is_saleable=false` and no existing discount. The unmodified
`DiscountManager` applied and removed a 10% discount:

| Observed state | Primary price | Effective price | Discount |
| --- | ---: | ---: | --- |
| Before application | 59240000 | 59240000 | null |
| After job 22250254 | 59240000 | 53316000 | 10% |
| After job 22250280 | 59240000 | 59240000 | null |

Both jobs reported `COMPLETE`, `successful_count=1`, `unsuccessful_count=0`.
Inventory stayed zero. Final comparison of the selected business fields confirmed
the discount target matched its baseline. Its WooCommerce product was untouched.

Cleanup and final checks in fresh PHP requests established:

- Original Basalam product 25859815 retained price 39860000, inventory 2,
  published status 2976 and no discount; its selected business fields matched
  the baseline fingerprint.
- Original WooCommerce product 16532, including all metadata captured by the
  check, and plugin settings matched their baseline fingerprints.
- Temporary WooCommerce product 26967 was permanently deleted.
- A PATCH requesting `status=3790, stock=0` for the new Basalam product returned
  200. The vendor representation confirmed `revision.data.status.value=3790`
  (عدم انتشار) and revision inventory 0. Its main moderation status remained
  3567 (تایید نشده), and `published` was null. The remote test record **63379131
  remains** with inventory 0, no discount, `is_available=false`,
  `is_saleable=false` and `is_showable=false`. This verifies the unpublished
  revision and hidden state, not permanent deletion or a main status of 3790.
  The retrieved provider specification exposes no permanent product DELETE.
- The expiring authenticated helper, private ledger and lock were deleted.
  cPanel independently confirmed their absence, then the hosting session was
  cleared.

The helper initially made two incorrect diagnostic assumptions:
`can_add_to_cart=false` for an unavailable zero-stock product, and immediate
main status 3790 after requesting non-publication. Those assertions failed;
subsequent availability fields and vendor revision reads established the actual
provider state described above. All eight operation results and real effects
were verified independently of those assumptions. Every recorded phase had no
PHP warnings/fatal errors, and the circuit remained closed with zero failures.
No new local suite run was needed because production source was unchanged.

## Complete reinstallation and live verification on 2026-10-20

The full 1.10.33 build, including batch and webhook fallback, was reinstalled on
`mottasell.com`. All 14 mapped HTTP operations were exercised against the real
services from the host, including webhook GET/POST/PATCH/DELETE, batch changes
and restoration, and actual discount effects and restoration. The admin order
check and SalamGate reader also succeeded. Two authenticated incoming webhook
probes confirmed duplicate handling. Original settings, product data and the
existing webhook were preserved; no new plugin errors were logged.

See [the full live report](live-fallback-verification-2026-10-20.md) for the
installed hashes, operation results, readback timing, scope limits and retained
hidden test product **63483423**.

## Earlier production evidence (previous IP-based implementation)

The previous implementation selected an alternate address from Core DNS while
retaining the OpenAPI hostname. That implementation was successfully deployed
on `https://mottasell.com/` on 2026-10-19. The original service file and hash are
backed up outside the document root at
`/home/mottase1/.woosalam-openapi-backup-20261019-87nkyomy.json`.

WooCommerce product **16532**, connected to Basalam product **25859815**, was
updated in its existing `price_stock` mode. The normal operation first received
cURL 7 and then PATCH HTTP 200. The persisted WooCommerce log was:

```text
2026-10-19T12:30:31+00:00 INFO بروزرسانی محصول با موفقیت انجام شد.
product_id: 16532
```

This historical result verifies the previous implementation, not the current
domain fallback. A later direct Core test with normal DNS used
`PATCH /v1/products/25859815` and received 404. The provider documentation then
identified the correct route, `PATCH /v4/products/{product_id}`, used above.
