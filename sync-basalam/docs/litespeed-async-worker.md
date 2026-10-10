# LiteSpeed async queue workers

The jobs runner sends a nonblocking request to
`wp-admin/admin-ajax.php?action=sync_basalam_run_jobs_async`. The caller closes
the connection before the worker finishes. On LiteSpeed, `ignore_user_abort(true)`
does not prevent the server from aborting that worker.

Install this block near the top of the site's document-root `.htaccess`, before
other rewrite rules. Back up the existing file first. The rule applies only to
the plugin's async action; the AJAX nonce check, PHP execution limit and runner
database lock still apply.

```apache
# BEGIN SyncBasalam Async
<IfModule LiteSpeed>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\.php$
RewriteCond %{QUERY_STRING} (^|&)action=sync_basalam_run_jobs_async(&|$)
RewriteRule .* - [E=noabort:1]
</IfModule>
</IfModule>
# END SyncBasalam Async
```

This is a host configuration requirement. The plugin does not install this rule
automatically. Do not apply `noabort` to every site request.

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

## Automatic admin warning

Actual async workers check the effective `noabort` / `REDIRECT_noabort`
request environment. On the LiteSpeed PHP runtime, an absent flag produces a
warning on Woosalam, product and order admin screens for users who can manage
WooCommerce or site options. Absence is reported as unverified protection:
a global server configuration may protect workers without exposing that flag.
The plugin never assumes that a LiteSpeed Cache plugin is active and never
rewrites `.htaccess` itself.

If a job remains `processing` beyond 120 seconds and the runner global MySQL
lock can be acquired, its previous worker has stopped. The next runner records
this before stale recovery, and an admin request can also discover it when no
new worker starts. A live worker retains the lock and cannot trigger this
interruption warning merely by being slow. Explicit API failures are handled
separately. The alert includes the job ID, log link, hosting guidance and an
expandable scoped rewrite rule.

The latest evidence is retained in `sync_basalam_async_worker_health`, with
`autoload=false` and no payloads, credentials or request headers. Empty or
contended batches do not clear interruption evidence. On LiteSpeed, a returned
job clears it only when effective `noabort` was observed; a quick unprotected
return still leaves the warning visible. On another PHP runtime, a completed
async execution clears the interruption warning. Detection runs on async
workers and relevant admin visits; it is not an external push notification.

### Reproducible validation

- `php tests/JobsRunnerLeaseRegression.php` checks the orphaned lease cases.
- `phpunit --configuration phpunit.xml` runs the unit regression suite.
- In the local Docker WordPress stack, run `php tests/integration/async-worker-health.php`.
  This uses the real queue and MySQL locks, kills a separate PHP process during
  execution, verifies slow-worker exclusion, stale recovery, admin permissions,
  actionable markup, and separate API rejection. LiteSpeed environment flags
  are simulated in this local Apache environment.
- `php tests/integration/product-sync-operations.php` exercises actual WooCommerce
  save hooks and product operations against deterministic API response fixtures.
- Verify automatic AJAX execution against the live host separately; local
  fixtures do not test LiteSpeed's actual connection-abort behavior.
