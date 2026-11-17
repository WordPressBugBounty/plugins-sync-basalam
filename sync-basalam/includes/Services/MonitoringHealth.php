<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Api\RequestStatusTracker;

defined('ABSPATH') || exit;

/** Bounded read-only telemetry, without settings, tokens, payloads or log files. */
class MonitoringHealth
{
    public function snapshot(): array
    {
        global $wpdb;

        $units = MonitoringCatalog::connectedCount();

        $table = $wpdb->prefix . 'sync_basalam_job_manager';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $queue = $wpdb->get_row(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'processing') AS processing,
                    MIN(CASE WHEN status = 'pending' THEN created_at END) AS oldest_pending_at,
                    COALESCE(SUM(id), 0) AS ids, COALESCE(SUM(attempts), 0) AS attempts
             FROM {$table} WHERE status IN ('pending', 'processing')",
            ARRAY_A
        );
        if ($units === null || !is_array($queue) || $wpdb->last_error) {
            throw new \RuntimeException('Monitoring telemetry unavailable');
        }

        $worker = get_option(AsyncWorkerHealth::OPTION_KEY, []);
        $worker = is_array($worker) ? $worker : [];
        $settings = syncBasalamSettings()->getSettings();
        return [
            'schema_version' => 1,
            'diagnostics_supported' => true,
            'coverage_supported' => true,
            'measured_at' => time(),
            'site_url' => untrailingslashit(get_site_url()),
            'vendor_id' => (int) ($settings[SettingsConfig::VENDOR_ID] ?? 0),
            'connected_units' => (int) $units,
            'capacity_unit' => 'parent_product',
            'connected' => !empty($settings[SettingsConfig::TOKEN]),
            'can_update' => syncBasalamContainer()->get(VendorSyncPolicy::class)->canUpdate(false),
            'blocked_http' => (bool) (new CheckHttpBlockService())->SyncBasalamHttpBlock(),
            'worker_interrupted' => !empty($worker['interruption']),
            'queue' => [
                'pending' => (int) $queue['pending'],
                'processing' => (int) $queue['processing'],
                'oldest_pending_at' => $queue['oldest_pending_at'] === null ? null : (int) $queue['oldest_pending_at'],
                'marker' => hash('sha256', wp_json_encode($queue)),
                'last_progress_at' => isset($worker['last_returned_at']) ? (int) $worker['last_returned_at'] : null,
            ],
            'last_basalam_request' => RequestStatusTracker::getLastBasalamRequest(),
        ];
    }
}
