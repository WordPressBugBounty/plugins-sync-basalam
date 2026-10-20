# LiteSpeed async queue workers

The jobs runner sends a nonblocking request to
`wp-admin/admin-ajax.php?action=sync_basalam_run_jobs_async`. The caller closes
the connection before the worker finishes. On LiteSpeed, `ignore_user_abort(true)`
does not prevent the server from aborting that worker.

The plugin installs a scoped block automatically on activation or the next
administrator request after an update. It only attempts installation for a
LiteSpeed server signature/module marker or an ambiguous LSAPI runtime. Apache,
Nginx, Caddy and IIS signatures are excluded. The PHP SAPI alone is **not** proof
of LiteSpeed: Apache on CloudLinux can also report `PHP_SAPI=litespeed`.

The managed block is prepended before other rewrite rules, outside WordPress's
generated section. The server enables protection inside `<IfModule LiteSpeed>`;
the fallback only records another server when the positive marker is absent:

```apache
# BEGIN SyncBasalam Async
<IfModule LiteSpeed>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\.php$
RewriteCond %{QUERY_STRING} (^|&)action=sync_basalam_run_jobs_async(&|$)
RewriteRule .* - [E=noabort:1,E=SYNCBASALAM_LITESPEED:1]
</IfModule>
</IfModule>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{ENV:SYNCBASALAM_LITESPEED} !^1$
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\.php$
RewriteCond %{QUERY_STRING} (^|&)action=sync_basalam_run_jobs_async(&|$)
RewriteRule .* - [E=SYNCBASALAM_NOT_LITESPEED:1]
</IfModule>
# END SyncBasalam Async
```

Both the protection and module markers apply only to the queue action. Normal
AJAX requests are unaffected. The effective module marker takes precedence over
masked server signatures; LSAPI without a marker or recognizable server
signature remains unknown instead of being classified as LiteSpeed. No client
HTTP header or active LiteSpeed Cache plugin is used as server evidence.

Only users with `manage_options` can trigger configuration; multisite additionally
requires a super administrator. `DISALLOW_FILE_MODS` and WordPress's
`wp_is_file_mod_allowed()` policy are respected. There is no FTP prompt or admin
notice. A denied/unwritable configuration is left intact, with an internal status
and a five-minute retry interval. Successful checks are limited to once an hour.

Before changing the file, the installer stores its exact previous bytes, canonical
path and whether it existed in `sync_basalam_async_worker_htaccess_backup`, with
`autoload=false`. The bytes are base64 encoded for lossless restoration. No
public backup file is created. The installer preserves all unrelated bytes and
file mode, migrates its own complete top-level marker block, avoids duplicates,
and refuses symlinks, malformed/nested markers and files larger than 1 MiB. It
prepares and verifies a temporary file in the same directory, checks that the
original has not changed, then atomically replaces it. Insufficient directory
permissions prevent installation even if the original file itself is writable.

`sync_basalam_async_worker_protection` records installation status and retry time,
with `autoload=false`. `installed` means the file contains the intended rule;
it does **not** mean the host has already applied it. The next real async request
checks effective `noabort` / `REDIRECT_noabort`. Servers that ignore `.htaccess`
or require a configuration reload may still need hosting support. No automatic
web-server restart or broad `noabort` rule is attempted.

## Worker recovery

An unfinished batch now dispatches its successor after releasing the global
database lock, so ready work can continue without another visitor request.
A single WordPress cron event checks pending/processing work every 60 seconds
while needed, covering delayed retries and interrupted workers. This fallback
needs working WordPress cron or a server cron. Neither continuation nor cron
replaces the scoped LiteSpeed protection above: a killed worker may never reach
its handoff, and its processing job must wait for stale recovery.

An aborted worker can leave a job in `processing` with no exception recorded.
The stale-job recovery retries it after 120 seconds and eventually marks it
failed. This differs from an orphaned dispatch transient, which can prevent any
worker from starting. The deadline stored in the dispatch lease fixes that
separate problem.

After applying the rule, verify that the bulk cursor advances, successful jobs
complete through normal cleanup, and no new stale retries occur. For failed
product creation jobs, search the vendor's existing products before retrying:
an earlier request may have created the product before its local mapping was
saved. Keep historical failures and actual API rejection messages for diagnosis.

References:

- [LiteSpeed: controlling LSPHP and connection aborts](https://docs.litespeedtech.com/lsws/extapp/php/configuration/control/)
- [LiteSpeed Cache's own scoped async rewrite rule](https://github.com/litespeedtech/lscache_wp/blob/master/src/htaccess.cls.php)

## Silent health diagnostics

Worker health monitoring no longer registers an `admin_notices` callback or renders
an alert template. An authorized request on relevant admin screens still inspects
stale work via `current_screen`, and actual async executions still observe their
request environment. Explicit API rejections remain normal job errors.

The latest evidence is retained in `sync_basalam_async_worker_health`, with
`autoload=false` and no payloads, credentials or request headers. A stale job is
recorded as interrupted only when the runner's global MySQL lock can be acquired;
a slow live worker still owns that lock. Empty or contended batches do not clear
interruption evidence. On confirmed LiteSpeed, a returned job clears it only when
effective `noabort` was observed. Internal diagnosis data is available to code and
the integration fixtures, but is never rendered as an admin alert.
