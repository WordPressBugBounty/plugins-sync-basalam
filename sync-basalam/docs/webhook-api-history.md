# Webhook API history

This inventory comes from Git history, inspected on 2026-10-20. It documents
what released plugin code called. A subsequent provider-contract check and local
simulation established the CRUD fallback described below; no live webhook was
created, updated or deleted for this investigation.

## Automatic registration: direct service

Verified release snapshots: **1.5.9** and **1.7.3**.

| Operation | Method | URL |
| --- | --- | --- |
| List webhooks | GET | `https://webhook.basalam.com/v1/webhooks` |
| Create webhook | POST | `https://webhook.basalam.com/v1/webhooks` |
| Update webhook | PATCH | `https://webhook.basalam.com/v1/webhooks/{webhook_id}` |
| Delete webhook | DELETE | `https://webhook.basalam.com/v1/webhooks/{webhook_id}` |

The 1.7.3 implementation was `includes/Services/WebhookService.php`. It used
`Authorization: Bearer <Basalam access token>`. Creation sent this JSON body
(the nested `request_headers` value is a JSON **string**):

```json
{
  "event_ids": [3, 5, 7],
  "request_headers": "{\"token\":\"<site webhook verification token>\"}",
  "request_method": "POST",
  "url": "https://example.com/wp-json/sync-basalam/v1/order-manager",
  "is_active": true,
  "register_me": true
}
```

Updating sent the same fields except `register_me`. Deleting required the
webhook id and authorization, without a request body. An earlier migration
(`0944c93`) also listed webhooks with `?event_ids=5` before updating them to
events `[3, 5, 7]` and the newer callback path.

Automatic registration was introduced by commit `40c5e45` (2025-09-03).
Its initial authorization handling was subsequently revised; the Bearer
example above describes the later 1.7.3 snapshot.

## Migration to OpenAPI

Commit **`1ef4f59225744306b9d8cd4942cf65e846bfcde8`** (2025-12-28)
changed the four webhook operations from `webhook.basalam.com` to
`openapi.basalam.com`, retaining `/v1/webhooks` and the existing HTTP methods.
The file header still said 1.7.3 in that commit; the tagged **1.7.3** snapshot
uses the direct service and the tagged **1.7.4** snapshot uses OpenAPI.

| Operation | Current URL |
| --- | --- |
| GET / POST | `https://openapi.basalam.com/v1/webhooks` |
| PATCH / DELETE | `https://openapi.basalam.com/v1/webhooks/{webhook_id}` |

The current implementation is `includes/Services/WebhookService.php`, with
the base URL centralized in `includes/Config/Endpoints.php::WEBHOOKS`.
Its webhook operations now use the shared host fallback to the original service
for cURL 6/7 failures before sending. The current provider contract was retrieved
from `https://webhook.basalam.com/openapi.json` on 2026-10-20 and retained in
`postman/contracts/basalam-webhooks-fallback.openapi.json`. List, create, update,
delete and the actual setup/cleanup flow have local WordPress HTTP/TLS simulation
coverage; authenticated live CRUD and incoming event delivery are not verified.
See `docs/openapi-connection-fallback.md` for the safety guards and test results.

## Older manual registration page

In tagged **1.2.5** and **1.3.4**, users were directed to the developer panel:

```text
https://developers.basalam.com/webhooks?events_ids=5&request_headers=<URL-encoded JSON>&url=<URL-encoded callback>
```

Commit `abe5d87` (2025-08-17) changed the page path to `/panel/webhooks`.
Commit `855e6bf` (2025-08-27) changed its selected events from `5` to `3,5,7`
and the site's callback from `/wp-json/basalam/v1/new-order` to
`/wp-json/sync-basalam/v1/order-manager`.

These developer-panel links are browser registration pages, not the REST
endpoint used later for automatic creation. The panel uses the historical
query name `events_ids`; the REST JSON body uses `event_ids`.
