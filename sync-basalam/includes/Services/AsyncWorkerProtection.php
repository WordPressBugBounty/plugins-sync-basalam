<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

/** Installs a server-gated, queue-only rule during authorized admin requests. */
class AsyncWorkerProtection
{
    public const OPTION_KEY = 'sync_basalam_async_worker_protection';
    public const BACKUP_OPTION_KEY = 'sync_basalam_async_worker_htaccess_backup';
    private const RULE_VERSION = 1;
    private const BEGIN = '# BEGIN SyncBasalam Async';
    private const END = '# END SyncBasalam Async';
    private const MAX_FILE_BYTES = 1048576;

    public function activate(): void
    {
        $this->ensureInstalled(true);
    }

    public function ensureInstalled(bool $force = false): void
    {
        if (!$this->mayConfigure()) return;

        if (!(new AsyncWorkerEnvironment())->shouldInstallProbe()) return;

        $state = get_option(self::OPTION_KEY, []);
        if (!$force && is_array($state) && ($state['version'] ?? null) === self::RULE_VERSION
            && (int) ($state['retry_at'] ?? 0) > time()) return;

        $home = $this->homePath();
        // Resolve the WordPress home directory before touching configuration.
        if (!$home || !is_dir($home) || realpath($home) === false) {
            $this->saveStatus('unavailable');
            return;
        }
        $home = realpath($home);
        // get_home_path() can fall back to / when a subdirectory installation
        // is activated from a CLI script outside WordPress. Never guess there.
        if (dirname($home) === $home && realpath(ABSPATH) !== $home) {
            $this->saveStatus('unavailable');
            return;
        }
        $this->install(rtrim($home, '/\\') . '/.htaccess');
    }

    protected function mayConfigure(): bool
    {
        return current_user_can('manage_options')
            && (!is_multisite() || is_super_admin())
            && !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)
            && wp_is_file_mod_allowed('sync_basalam_async_worker_protection');
    }

    protected function homePath(): string
    {
        if (!function_exists('get_home_path')) require_once ABSPATH . 'wp-admin/includes/file.php';
        return get_home_path();
    }

    public static function rules(): string
    {
        return <<<'HTACCESS'
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
HTACCESS;
    }

    private function install(string $path): void
    {
        $directory = dirname($path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            $this->saveStatus('unsupported_file');
            return;
        }
        $exists = file_exists($path);
        $original = $exists ? @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1) : '';
        if ($original === false || strlen($original) > self::MAX_FILE_BYTES) {
            $this->saveStatus('unreadable');
            return;
        }
        if (strpos($original, "\xEF\xBB\xBF") === 0 || strpos($original, "\0") !== false) {
            $this->saveStatus('unsupported_encoding');
            return;
        }

        // Remove only our complete, top-level marker block. Leave every other
        // byte (including WordPress/LiteSpeed Cache markers and CRLFs) intact.
        $rest = $this->withoutManagedBlock($original);
        if ($rest === null) {
            $this->saveStatus('invalid_markers');
            return;
        }
        $desired = self::rules() . "\n" . $rest;
        if ($original === $desired) {
            $this->saveStatus('installed');
            return;
        }
        if (!is_writable($directory) || ($exists && !is_writable($path))) {
            $this->saveStatus('unwritable');
            return;
        }

        // A DB backup avoids exposing server configuration in a downloadable
        // backup file. Keep it across no-op checks; do not write without it.
        $backup = ['path' => $path, 'existed' => $exists, 'content_base64' => base64_encode($original)];
        update_option(self::BACKUP_OPTION_KEY, $backup, false);
        if (get_option(self::BACKUP_OPTION_KEY) !== $backup) {
            $this->saveStatus('backup_failed');
            return;
        }

        $temporary = @tempnam($directory, '.htaccess.sync-basalam-');
        if ($temporary === false || dirname($temporary) !== realpath($directory)) {
            if ($temporary !== false) @unlink($temporary);
            $this->saveStatus('unwritable');
            return;
        }
        try {
            $permissions = $exists ? @fileperms($path) : 0644;
            if ($permissions === false) {
                $this->saveStatus('unreadable');
                return;
            }
            $mode = $permissions & 0777;
            if (@file_put_contents($temporary, $desired) !== strlen($desired)
                || !@chmod($temporary, $mode)
                || @file_get_contents($temporary) !== $desired) {
                $this->saveStatus('write_failed');
                return;
            }
            // Detect changes made while preparing the replacement. An atomic
            // rename leaves either the complete old file or complete new file.
            clearstatcache(true, $path);
            if (is_link($path) || file_exists($path) !== $exists
                || ($exists && (@file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1) !== $original
                    || @fileperms($path) !== $permissions))) {
                $this->saveStatus('file_changed');
                return;
            }
            if (!@rename($temporary, $path)) {
                $this->saveStatus('write_failed');
                return;
            }
            $this->saveStatus('installed');
        } finally {
            if (file_exists($temporary)) @unlink($temporary);
        }
    }

    private function withoutManagedBlock(string $contents): ?string
    {
        $beginCount = substr_count($contents, self::BEGIN);
        $endCount = substr_count($contents, self::END);
        if ($beginCount === 0 && $endCount === 0) return $contents;
        if ($beginCount !== 1 || $endCount !== 1) return null;

        // Only move a top-level block. A marker nested inside another plugin's
        // IfModule/If/Files container is not safe to relocate automatically.
        $pattern = '/^' . preg_quote(self::BEGIN, '/') . '\r?\n.*?^'
            . preg_quote(self::END, '/') . '(?:\r?\n|\z)/ms';
        if (!preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) return null;
        [$block, $offset] = $match[0];
        $before = substr($contents, 0, $offset);
        $depth = 0;
        foreach (preg_split('/\r?\n/', $before) as $line) {
            if (preg_match('/^\s*<\/[A-Za-z]/', $line)) $depth--;
            elseif (preg_match('/^\s*<[A-Za-z]/', $line)) $depth++;
            if ($depth < 0) return null;
        }
        if ($depth !== 0) return null;
        return $before . substr($contents, $offset + strlen($block));
    }

    private function saveStatus(string $status): void
    {
        // Internal metadata only: no admin notice and no success claim about
        // effective protection until an actual queue request observes noabort.
        update_option(self::OPTION_KEY, [
            'version' => self::RULE_VERSION,
            'status' => $status,
            'checked_at' => time(),
            'retry_at' => time() + ($status === 'installed' ? HOUR_IN_SECONDS : 300),
        ], false);
    }
}
