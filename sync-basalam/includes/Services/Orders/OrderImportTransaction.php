<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

/** Keeps sitemap invalidation outside the order/stock transaction. */
final class OrderImportTransaction
{
    private $database;
    private $active = false;
    private $deferredHooks = [];
    private const SAVEPOINT = 'sync_basalam_order_import';

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function begin(): void
    {
        $this->execute('START TRANSACTION');
        $this->active = true;
        $this->execute('SAVEPOINT ' . self::SAVEPOINT);

        global $wp_filter;
        $hook = $wp_filter['litespeed_purged_post'] ?? null;
        foreach ($hook->callbacks ?? [] as $priority => $callbacks) {
            foreach ($callbacks as $config) {
                $callback = $config['function'];
                $name = is_array($callback) && is_string($callback[0])
                    ? implode('::', $callback) : $callback;
                if (!is_string($name) || strcasecmp(ltrim($name, '\\'), 'LiteSpeed\\Thirdparty\\Rank_Math::invalidate_sitemap_cache') !== 0) {
                    continue;
                }

                $pending = (object) ['called' => false, 'args' => []];
                $capture = static function (...$args) use ($pending) {
                    $pending->called = true;
                    $pending->args = $args;
                };
                remove_action('litespeed_purged_post', $callback, $priority);
                add_action('litespeed_purged_post', $capture, $priority, $config['accepted_args']);
                $this->deferredHooks[] = compact('callback', 'capture', 'priority', 'config', 'pending');
            }
        }
    }

    /** A lost savepoint detects implicit rollback/reconnection even if wpdb's last_error was overwritten. */
    public function assertActive(): void
    {
        $this->execute('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        $this->execute('SAVEPOINT ' . self::SAVEPOINT);
    }

    public function commit(): void
    {
        $this->assertActive();
        $this->execute('COMMIT');
        $this->active = false;
    }

    public function finish(): void
    {
        $canInvalidate = true;
        if ($this->active) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control; do not hide rollback failure.
            $canInvalidate = $this->database->query('ROLLBACK') !== false;
            $this->active = false;
            if (!$canInvalidate) {
                Logger::error('Order import rollback failed: ' . $this->database->last_error);
            }
        }

        // Restore every hook before calling any of them, including when one throws.
        foreach ($this->deferredHooks as $entry) {
            remove_action('litespeed_purged_post', $entry['capture'], $entry['priority']);
            add_action('litespeed_purged_post', $entry['callback'], $entry['priority'], $entry['config']['accepted_args']);
        }
        $hooks = $this->deferredHooks;
        $this->deferredHooks = [];
        foreach ($hooks as $entry) {
            if ($canInvalidate && $entry['pending']->called) {
                try {
                    call_user_func_array($entry['callback'], $entry['pending']->args);
                } catch (\Throwable $error) {
                    // The order is already committed; a cache failure must not request a duplicate import.
                    Logger::warning('Deferred Rank Math sitemap invalidation failed: ' . $error->getMessage());
                }
            }
        }
    }

    private function execute(string $sql): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static transaction/savepoint statements only.
        if ($this->database->query($sql) === false) {
            throw new \RuntimeException('Order import transaction failed: ' . $this->database->last_error);
        }
    }
}
