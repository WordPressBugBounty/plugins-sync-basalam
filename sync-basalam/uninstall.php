<?php

/**
 * Sync Basalam Uninstall
 *
 * Default uninstall:
 * - Removes runtime jobs/queues/cron markers so no background process remains.
 *
 * Full data wipe:
 * - Define SYNC_BASALAM_REMOVE_ALL_DATA as true in wp-config.php before uninstall.
 * - Removes plugin tables, options, usermeta, and postmeta.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$sync_basalam_remove_all_data = defined('SYNC_BASALAM_REMOVE_ALL_DATA') && true === SYNC_BASALAM_REMOVE_ALL_DATA;

sync_basalam_uninstall_site($sync_basalam_remove_all_data);

function sync_basalam_uninstall_site(bool $remove_all_data): void
{
    sync_basalam_cleanup_runtime_data();

    if ($remove_all_data) {
        sync_basalam_remove_all_plugin_data();
    }
}

function sync_basalam_cleanup_runtime_data(): void
{
    global $wpdb;

    sync_basalam_clear_wp_cron_events();
    sync_basalam_clear_wc_queue_actions();
    sync_basalam_delete_action_scheduler_rows();

    $job_manager_table = $wpdb->prefix . 'sync_basalam_job_manager';
    if (sync_basalam_table_exists($job_manager_table)) {
        $wpdb->query("DELETE FROM {$job_manager_table}");
    }

    sync_basalam_delete_option_rows_like(
        [
            'sync_basalam_%_last_run',
            'sync_basalam_last_creatable_product_id',
            'CreateSingleProduct_%_batch_%',
            'sync_basalam_update_single_product_%_batch_%',
            'UpdateSingleProduct_%_batch_%',
            'sync_basalam_%_batch_%',
            '_transient_sync_basalam_%_lock',
            '_transient_timeout_sync_basalam_%_lock',
            '_transient_CreateSingleProduct_%_lock',
            '_transient_timeout_CreateSingleProduct_%_lock',
            '_transient_UpdateSingleProduct_%_lock',
            '_transient_timeout_UpdateSingleProduct_%_lock',
            '_site_transient_sync_basalam_%_process_lock',
            '_site_transient_timeout_sync_basalam_%_process_lock',
            '_site_transient_CreateSingleProduct_%_process_lock',
            '_site_transient_timeout_CreateSingleProduct_%_process_lock',
            '_site_transient_UpdateSingleProduct_%_process_lock',
            '_site_transient_timeout_UpdateSingleProduct_%_process_lock',
        ]
    );
}

function sync_basalam_remove_all_plugin_data(): void
{
    global $wpdb;

    $tables = [
        $wpdb->prefix . 'sync_basalam_payments',
        $wpdb->prefix . 'sync_basalam_map_options',
        $wpdb->prefix . 'sync_basalam_uploaded_photo',
        $wpdb->prefix . 'sync_basalam_debug_logs',
        $wpdb->prefix . 'sync_basalam_discount_tasks',
        $wpdb->prefix . 'sync_basalam_category_mappings',
        $wpdb->prefix . 'sync_basalam_job_manager',
        // Legacy tables from older naming/migrations.
        $wpdb->prefix . 'bsl_payments',
        $wpdb->prefix . 'bsl_map_options',
        $wpdb->prefix . 'bsl_uploaded_photo',
        $wpdb->prefix . 'bsl_payments_old',
        $wpdb->prefix . 'bsl_map_options_old',
        $wpdb->prefix . 'bsl_uploaded_photo_old',
    ];

    foreach ($tables as $table) {
        if (sync_basalam_table_exists($table)) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    sync_basalam_delete_option_rows_like(
        [
            'sync_basalam_%',
            '_transient_sync_basalam_%',
            '_transient_timeout_sync_basalam_%',
            '_site_transient_sync_basalam_%',
            '_site_transient_timeout_sync_basalam_%',
            'CreateSingleProduct_%_batch_%',
            'sync_basalam_update_single_product_%_batch_%',
            'UpdateSingleProduct_%_batch_%',
            'sync_basalam_%_batch_%',
            '_transient_CreateSingleProduct_%_lock',
            '_transient_timeout_CreateSingleProduct_%_lock',
            '_transient_UpdateSingleProduct_%_lock',
            '_transient_timeout_UpdateSingleProduct_%_lock',
            '_site_transient_CreateSingleProduct_%_process_lock',
            '_site_transient_timeout_CreateSingleProduct_%_process_lock',
            '_site_transient_UpdateSingleProduct_%_process_lock',
            '_site_transient_timeout_UpdateSingleProduct_%_process_lock',
        ]
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            'sync_basalam_%'
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s
                OR meta_key LIKE %s
                OR meta_key = %s
                OR meta_key = %s
                OR meta_key = %s
                OR meta_key = %s
                OR meta_key = %s",
            'sync_basalam_%',
            '_sync_basalam_%',
            '_is_sync_basalam_order',
            '_basalam_order_tracking_code',
            '_basalam_fee_amount',
            '_basalam_balance_amount',
            '_basalam_purchase_count'
        )
    );

    wp_cache_flush();
}

function sync_basalam_clear_wp_cron_events(): void
{
    $known_hooks = sync_basalam_get_known_hooks();

    foreach ($known_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    if (!function_exists('_get_cron_array')) {
        return;
    }

    $cron = _get_cron_array();
    if (!is_array($cron)) {
        return;
    }

    foreach (array_keys($cron) as $hook) {
        $hook = (string) $hook;

        if (sync_basalam_is_related_hook($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

function sync_basalam_clear_wc_queue_actions(): void
{
    $queue_hooks = [
        'sync_basalam_plugin_debug',
        'sync_basalam_fetch_version_detail',
    ];

    if (function_exists('as_unschedule_all_actions')) {
        foreach ($queue_hooks as $hook) {
            as_unschedule_all_actions($hook);
            as_unschedule_all_actions($hook, [], 'sync-basalam');
        }
    }

    if (!function_exists('WC')) {
        return;
    }

    $wc = WC();
    if (!is_object($wc) || !method_exists($wc, 'queue')) {
        return;
    }

    $queue = $wc->queue();
    if (!is_object($queue) || !method_exists($queue, 'cancel_all')) {
        return;
    }

    foreach ($queue_hooks as $hook) {
        $queue->cancel_all($hook);
    }
}

function sync_basalam_delete_action_scheduler_rows(): void
{
    global $wpdb;

    $actions_table = $wpdb->prefix . 'actionscheduler_actions';
    $groups_table  = $wpdb->prefix . 'actionscheduler_groups';

    if (!sync_basalam_table_exists($actions_table)) {
        return;
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$actions_table}
             WHERE hook LIKE %s
                OR hook LIKE %s
                OR hook LIKE %s",
            'sync_basalam_%',
            'CreateSingleProduct_%',
            'UpdateSingleProduct_%'
        )
    );

    if (!sync_basalam_table_exists($groups_table)) {
        return;
    }

    $group_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT group_id FROM {$groups_table} WHERE slug = %s",
            'sync-basalam'
        )
    );

    if (empty($group_ids)) {
        return;
    }

    $group_ids = array_map('intval', $group_ids);
    $group_ids = array_filter($group_ids);

    if (empty($group_ids)) {
        return;
    }

    $ids = implode(',', $group_ids);

    $wpdb->query("DELETE FROM {$actions_table} WHERE group_id IN ({$ids})");
    $wpdb->query("DELETE FROM {$groups_table} WHERE group_id IN ({$ids})");
}

function sync_basalam_cleanup_network_site_transients(): void
{
    global $wpdb;

    if (!is_multisite() || empty($wpdb->sitemeta)) {
        return;
    }

    $patterns = [
        '_site_transient_sync_basalam_%',
        '_site_transient_timeout_sync_basalam_%',
        '_site_transient_CreateSingleProduct_%',
        '_site_transient_timeout_CreateSingleProduct_%',
        '_site_transient_UpdateSingleProduct_%',
        '_site_transient_timeout_UpdateSingleProduct_%',
    ];

    foreach ($patterns as $pattern) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                $pattern
            )
        );
    }
}

function sync_basalam_delete_option_rows_like(array $patterns): void
{
    global $wpdb;

    foreach ($patterns as $pattern) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );
    }
}

function sync_basalam_table_exists(string $table_name): bool
{
    global $wpdb;

    $table = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    return $table === $table_name;
}

function sync_basalam_get_known_hooks(): array
{
    $create_identifier = 'CreateSingleProduct_' . substr(md5('SyncBasalam\\Queue\\Tasks\\CreateProduct'), 0, 8);
    $update_identifier = 'sync_basalam_update_single_product_' . substr(md5('SyncBasalam\\Queue\\Tasks\\UpdateProduct'), 0, 8);
    $legacy_update_identifier = 'UpdateSingleProduct_' . substr(md5('SyncBasalam\\Queue\\Tasks\\UpdateProduct'), 0, 8);

    return [
        'sync_basalam_plugin_debug',
        'sync_basalam_fetch_version_detail',
        $create_identifier . '_cron',
        $create_identifier . '_immediate',
        $update_identifier . '_cron',
        $update_identifier . '_immediate',
        $legacy_update_identifier . '_cron',
        $legacy_update_identifier . '_immediate',
    ];
}

function sync_basalam_is_related_hook(string $hook): bool
{
    $prefixes = [
        'sync_basalam_',
        'CreateSingleProduct_',
        'UpdateSingleProduct_',
    ];

    foreach ($prefixes as $prefix) {
        if (strpos($hook, $prefix) === 0) {
            return true;
        }
    }

    return false;
}
