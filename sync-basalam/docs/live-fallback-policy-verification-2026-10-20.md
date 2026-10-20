# Live 48-hour fallback-policy verification — 2026-10-20

The installed runtime on `https://mottasell.com` passed **49 checks** on
2026-10-20, from 12:04:48 to 12:05:22 UTC (15:34:48–15:35:22 Asia/Tehran).
Three independent host PHP processes used the real WordPress HTTP transport,
saved authorization and hosting database. All outgoing provider requests were
GETs with normal DNS and certificate verification.

## Isolation and scope

The test loaded the deployed `OpenApiFallbackPolicy` and
`OpenApiConnectionFallback` classes through the installed Composer autoloader.
A diagnostic subclass changed only the options-table scope while executing the
parent policy's methods. Its counter used a new, empty disposable table in the
site's own database. Ordinary HTTP calls used the site's normal WordPress context.
The production routing option was never seeded, reset or overridden.

The first nine failures and the tenth failure used real wall-clock timestamps.
The policy's existing injectable clock was advanced for expiry and rolling-window
boundary checks. The server clock and production policy were not changed, and
this was not a two-day elapsed-time soak test.

The host WP-CLI ran with `--skip-plugins --skip-themes` to avoid the previously
identified unrelated ionCube bootstrap failure. The actual installed fallback
classes, WordPress HTTP and database were used. These checks cover the policy
and transport on the host, rather than a full browser order-import workflow.

## Results

| Behavior | Evidence |
| --- | --- |
| Errors 1–9 | Each native OpenAPI request failed with cURL 7; its real replacement returned HTTP 200. The sample count increased to nine without activating the cooldown. |
| Persistence before activation | A second PHP process read all nine saved samples within ten minutes. |
| Successful primary between errors | A controlled primary success preserved the nine samples. |
| Error 10 | The tenth native cURL 7 activated a deadline exactly 172800 seconds after its timestamp; immediate Core recovery returned HTTP 200. |
| Active destination routing | Real requests went directly to Core categories, Order Processing parcels and Webhook subscriptions, without an OpenAPI attempt. All returned HTTP 200. |
| Persistence after activation | A third PHP process observed the same active deadline. |
| Last second | At deadline minus one second, real Core was called directly and returned HTTP 200. |
| Fixed deadline | Successful traffic, a controlled destination cURL 7 and a late in-flight failure did not extend the deadline or probe OpenAPI. |
| Exact expiry | At the exact deadline, OpenAPI was attempted again. Its native cURL 7 recovered through real Core and started a fresh window with one sample. |
| Rolling window, 601 seconds | Nine older samples were discarded; a new native failure left one sample and no cooldown. |
| Rolling window, 600 seconds | Nine samples exactly ten minutes old still counted; a native tenth failure activated the cooldown. |
| cURL 6 | A controlled DNS failure contributed to the threshold and recovered through the real Core endpoint. No actual DNS outage was introduced. |
| Production state | The production routing option and plugin settings matched their original fingerprints after the test. |

There were **20 logical requests**, comprising **31 real HTTP attempts** and
three controlled callback responses. The real attempts produced 13 native
OpenAPI cURL 7 failures and 18 HTTP 200 responses from the destination services.
The three controlled responses were primary success, destination connection
failure and primary DNS failure. The separate native preflight also returned
cURL 7 and is not included in those totals.

All three test processes exited with code 0. Their assertion counts were 21,
11 and 17. No provider write, product update, webhook change or order creation
was performed by this diagnostic.

## Installed code

| File | SHA-256 |
| --- | --- |
| `includes/Services/Api/OpenApiFallbackPolicy.php` | `a86ab9deb340db0eee0b91b6c39dc5d38abfc5490f3a27f27c83517846f0f891` |
| `includes/Services/Api/OpenApiConnectionFallback.php` | `916f010c357edac5ff918072e5661b8c6fbef09a4d52b7cc5035615ec2ef137c` |

## Cleanup

The disposable database table and private diagnostic PHP file outside the
document root were removed. A final independent check at 12:06:13 UTC confirmed
both were absent and the site's home page returned HTTP 200. No production code
or configuration was changed, and no public diagnostic endpoint was created.

Local evidence is retained under `/tmp/woosalam-live-policy-20261020/`:
the three phase outputs and JSON reports, `summary.json`, `cleanup.txt` and
`cleanup.png`. The local diagnostic source is
`/tmp/woosalam-live-policy-20261020.php`. Reports contain no tokens, response
bodies or raw settings. The phase-tenth report's broad `clock_accelerated` label
is true, but that phase uses `time()` throughout; only the expiry phase actually
advances the injected clock.
