# Live fallback verification — 2026-10-20

The complete 1.10.33 package was reinstalled on `https://mottasell.com` through
WordPress's plugin upload/replacement workflow. Existing settings and data were
retained. Tests ran from the site's host using the installed production classes,
saved authentication, ordinary DNS and verified TLS.

## Installed artifact

- Package SHA-256: `aded466d6a0696cdab38c3e81a48501cf64103fe8148574cb01a771313647a87`.
- Base commit: `b6b9e6cbce66efcc3ff39cce790803128d26c47b`.
- The package overlays the completed fallback runtime changes and documentation;
  it excludes concurrent, unfinished product-update retry changes.
- `includes/Config/Endpoints.php` installed SHA-256:
  `a0abb69d753ffc1b44db974b6084bc530ab736c8e10b6eefde9fee0c5577df94`.
- `includes/Services/Api/OpenApiConnectionFallback.php` installed SHA-256:
  `a86b97f35615550be3043bf1d225e9e26435ca002e92901cff3b4e186fb8db1b`.
- Installed plugin active; PHP 8.3.35, WordPress 7.1.3, WooCommerce 11.2.1.

The host's WP-CLI was run with `--skip-plugins --skip-themes` to avoid an
unrelated ionCube bootstrap failure. The diagnostic then loaded the installed
autoload, real settings accessor and `ApiServiceManager` explicitly. Browser
order checks, incoming webhook probes and public-page checks exercised normal
web requests with the site's plugins active. No public diagnostic endpoint or
production IP/DNS override was added.

## Provider operations

All 14 distinct mapped HTTP operations below naturally encountered OpenAPI
cURL 7 and reached their real replacement service. Readbacks independently
verified persisted effects.

| Operation | Replacement | Result and evidence |
| --- | --- | --- |
| GET categories | Core `/v3/categories` | 200; `data` array |
| GET category attributes | Core `/v3/categories/897/attributes` | 200; `exclude_multi_selects=true` preserved |
| GET vendor | Core `/v3/vendors/891059` | 200; expected vendor identity |
| GET product | Core `/v4/products/{id}` | 200; original, test and discount targets verified |
| POST product | Core `/v4/vendors/891059/products` | 201; product **63483423** created with price 1000000 and stock 0 |
| PATCH product | Core `/v4/products/63483423` | 200; price changed to 1200000 and read back |
| PATCH batch | Core `/v4/vendors/891059/products?continue_on_error=true` | 202; actual approved-product price effect and restoration verified; test-product revision verified |
| POST discounts | Core `/v3/vendors/891059/discounts` | 202; job **22318503**; actual 10% effect observed |
| DELETE discounts | Core `/v3/vendors/891059/discounts` | 202; job **22318745**; original price and null discount restored |
| GET vendor parcels | Order Processing `/v3/vendor-parcels` | 200; real parcels and cursor returned |
| GET webhooks | Webhook `/v1/webhooks` | 200; original subscription **3136** recorded |
| POST webhook | Webhook `/v1/webhooks` | 200; inactive test subscription **15356** created exactly once |
| PATCH webhook | Webhook `/v1/webhooks/15356` | 200; changed callback read back, subscription remained inactive |
| DELETE webhook | Webhook `/v1/webhooks/15356` | 200 after explicit user approval; subsequent GET matched the entire original subscription list |

The actual `SalamGate\Order\BasalamParcelReader::orders(90)` also returned 10
parcels through OpenAPI → Order Processing with the same cursor contract.

### Batch effects and readback timing

For unapproved test product **63483423**, batch returned
`is_product_for_revision=true, has_error=false`. The vendor representation
confirmed revision price **1300000**, stock **0**. Its public representation
continued to show the preceding price. An initial assertion expecting an
immediate public-price change therefore failed; it was corrected by checking
the persisted revision separately.

For approved zero-stock product **53491088**, batch returned
`is_product_for_revision=false, has_error=false`. The first immediate read also
showed the previous price. A bounded follow-up check observed **59241000** after
two two-second polling intervals. Restoration to **59240000** was independently
observed after another polling interval. Inventory remained 0 and
`is_saleable=false`. These initial readback failures were diagnostic timing and
representation assumptions, not failed provider writes.

### Discount effects

The unmodified `DiscountManager` applied and removed a 10% discount on approved,
zero-stock product **53491088**. The check verified actual product state rather
than treating HTTP 202 as completion:

| State | Primary price | Effective price | Discount |
| --- | ---: | ---: | --- |
| Baseline | 59240000 | 59240000 | null |
| Applied | 59240000 | 53316000 | 10% |
| Restored | 59240000 | 59240000 | null |

Job IDs were recorded; this run verified their effects through product reads
and did not inspect the jobs' internal `COMPLETE` counters.

## Order workflow and incoming webhook

The real admin **بررسی سفارشات باسلام** button started the site's normal job.
A fresh plugin log at **2026-10-20 12:01:43 Asia/Tehran** recorded nine orders
and no next cursor. Subsequent checks found no new fetch jobs left in the
queue, 432 mappings before and after, and zero duplicated invoice groups. All
nine orders were already imported; no new order was needed.

The receiver test retrieved the provider's current `VENDOR_NEW_ORDER` event
sample from `GET https://webhook.basalam.com/v1/webhooks/events`. It used that
sample with event ID 5 and an already mapped invoice, keeping the saved receiver
verification header on the host. Two authenticated HTTP POSTs to the existing
`/wp-json/sync-basalam/v1/order-manager` endpoint each returned:

```json
{"success":true,"message":"Order already exists.","order_id":26976}
```

The invoice still had exactly one mapping. This verifies live authenticated
receipt and duplicate handling using synthetic probes derived from the
provider's sample. It does **not** claim a new event was emitted and delivered
automatically by Basalam. No purchase, payment or shipping-state transition was
generated. The temporary subscription was inactive throughout its lifetime.

## Final state and evidence

- Original Basalam product **25859815** retained its captured business fields:
  price 39860000, inventory 2, status 2976 and no discount.
- Original WooCommerce product **16532**, including its captured metadata, and
  the complete plugin settings matched their baseline fingerprints.
- Existing webhook **3136**, including its original configuration and headers,
  matched the complete baseline list after test deletion and receiver probes.
- Product **63483423 remains** because the retrieved provider contract exposes
  no permanent product DELETE. It has inventory 0, price 1000000,
  `is_available=false`, `is_saleable=false`, `is_showable=false`; vendor revision
  status is **3790 (عدم انتشار)**. Its main moderation status is 3567, not 3790.
- Home page, original product URL and REST index returned HTTP 200 without a
  fatal-error marker.
- The circuit breaker was closed with zero failures after the operations.
- The final trace audit covered **91 HTTP attempts**, including **43 fallback
  executions** spanning all **14 distinct operations**. The actual SalamGate
  reader and website/receiver probes were also checked separately. Every audited
  fallback preserved its method, body and authentication-header digests, query
  string and TLS verification; no invalid trace was found.
- No new plugin error was logged between the baseline and the final audit at
  **2026-10-20 08:39:47 UTC**. The fresh order-fetch log confirmed nine orders
  with no next cursor.
- No version tag or Git push was performed for this deployment request.

Local deployment evidence is under `/tmp/woosalam-full-live-20261020/`, including
the package manifest, installation screenshot, per-phase reports and diagnostic
source. A private 0600 baseline ledger is outside the host's document root;
credentials and webhook verification header values are not printed in reports.

The existing local fallback regression tests were not rerun during deployment
because no runtime source changed in this run. This document reports live
provider and site behavior, independently of the earlier simulation results.
