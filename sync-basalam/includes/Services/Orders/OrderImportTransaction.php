<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

/** Keeps callbacks which issue implicit commits outside the order/stock transaction. */
final class OrderImportTransaction
{
    private $database;
    private $active = false;
    private $committed = false;
    private $deferredHooks = [];
    private $deferredEvents = [];
    private $hookGuards = [];
    private const SAVEPOINT = 'sync_basalam_order_import';
    private const DEFERRED_CALLBACKS = [
        'litespeed_purged_post' => ['LiteSpeed\\Thirdparty\\Rank_Math::invalidate_sitemap_cache', false, true],
        // SmsLogService::create_log() runs dbDelta(), even when its table already exists.
        // Notifications must see the committed order, and must not survive a rollback.
        'woocommerce_new_order' => ['WooCommersa\\Core\\Plugin::handle_new_order', true, false],
        'woocommerce_order_status_changed' => ['WooCommersa\\Core\\Plugin::handle_order_status_change', true, false],
    ];

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function begin(): void
    {
        $this->committed = false;
        $this->execute('START TRANSACTION');
        $this->active = true;
        $this->execute('SAVEPOINT ' . self::SAVEPOINT);

        foreach (self::DEFERRED_CALLBACKS as $hookName => $config) {
            $this->deferCallbacks($hookName);
            // Integrations can register lazily after begin(), before the first event.
            $guard = function () use ($hookName) {
                $this->deferCallbacks($hookName);
            };
            add_action($hookName, $guard, PHP_INT_MIN, 0);
            $this->hookGuards[$hookName] = $guard;
        }
    }

    private function deferCallbacks(string $hookName): void
    {
        global $wp_filter;
        [$target, $commitOnly, $coalesce] = self::DEFERRED_CALLBACKS[$hookName];
        $hook = $wp_filter[$hookName] ?? null;
        foreach ($hook->callbacks ?? [] as $priority => $callbacks) {
            foreach ($callbacks as $config) {
                $callback = $config['function'];
                $name = is_array($callback)
                    ? (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1]
                    : $callback;
                if (!is_string($name) || strcasecmp(ltrim($name, '\\'), $target) !== 0) {
                    continue;
                }

                $pending = (object) compact('callback', 'commitOnly', 'coalesce');
                $capture = function (...$args) use ($pending) {
                    $pending->lastIndex = count($this->deferredEvents);
                    $this->deferredEvents[] = ['pending' => $pending, 'args' => $args];
                };
                remove_action($hookName, $callback, $priority);
                add_action($hookName, $capture, $priority, $config['accepted_args']);
                $this->deferredHooks[] = compact('hookName', 'callback', 'capture', 'priority', 'config');
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
        $this->committed = true;
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
        foreach ($this->hookGuards as $hookName => $guard) {
            remove_action($hookName, $guard, PHP_INT_MIN);
        }
        $this->hookGuards = [];
        foreach ($this->deferredHooks as $entry) {
            remove_action($entry['hookName'], $entry['capture'], $entry['priority']);
            add_action($entry['hookName'], $entry['callback'], $entry['priority'], $entry['config']['accepted_args']);
        }
        $this->deferredHooks = [];
        $events = $this->deferredEvents;
        $this->deferredEvents = [];
        foreach ($events as $index => $event) {
            $pending = $event['pending'];
            if ($canInvalidate && (!$pending->commitOnly || $this->committed)
                && (!$pending->coalesce || $pending->lastIndex === $index)) {
                try {
                    call_user_func_array($pending->callback, $event['args']);
                } catch (\Throwable $error) {
                    // The order is already committed; a cache failure must not request a duplicate import.
                    Logger::warning('Deferred order import callback failed: ' . $error->getMessage());
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
