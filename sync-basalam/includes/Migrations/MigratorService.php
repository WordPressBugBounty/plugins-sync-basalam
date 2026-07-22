<?php

namespace SyncBasalam\Migrations;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\WebhookService;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;
class MigratorService
{
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function migratePayments()
    {
        $source = $this->wpdb->prefix . 'bsl_payments';
        $target = $this->wpdb->prefix . 'sync_basalam_payments';

        if ($this->tableExists($source) && $this->tableExists($target)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
            $this->wpdb->query("INSERT INTO $target (payment_id, invoice_id, user_id, city_id, province_id, order_id)
                                SELECT payment_id, invoice_id, user_id, city_id, province_id, order_id FROM $source");
        }
    }

    public function migrateOptions()
    {
        $source = $this->wpdb->prefix . 'bsl_map_options';
        $target = $this->wpdb->prefix . 'sync_basalam_map_options';

        if ($this->tableExists($source) && $this->tableExists($target)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
            $this->wpdb->query("INSERT INTO $target (woo_name, sync_basalam_name)
                                SELECT woo_name, bslm_name FROM $source");
        }
    }

    public function migrateUploadedPhotos()
    {
        $source = $this->wpdb->prefix . 'bsl_uploaded_photo';
        $target = $this->wpdb->prefix . 'sync_basalam_uploaded_media';

        if ($this->tableExists($source) && $this->tableExists($target)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
            $this->wpdb->query(
                "INSERT IGNORE INTO {$target} (type, source_identity, media_id, media_url, created_at)
                 SELECT 'photo', CONCAT('attachment:', woo_photo_id), bslm_photo_id, bslm_photo_url, NOW()
                 FROM {$source}
                 WHERE woo_photo_id IS NOT NULL"
            );
        }
    }

    public function migratePostMeta()
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
        $this->wpdb->query(
            "UPDATE {$this->wpdb->postmeta}
             SET meta_key = REPLACE(meta_key, 'bslm_', 'sync_basalam_')
             WHERE meta_key LIKE '%bslm_%'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
        $this->wpdb->query(
            "UPDATE {$this->wpdb->postmeta}
             SET meta_key = REPLACE(meta_key, 'bsl_', 'sync_basalam_')
             WHERE meta_key LIKE '%bsl_%'"
        );
    }

    public function migrateOptionsRows()
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
        $this->wpdb->query(
            "UPDATE {$this->wpdb->options}
             SET option_name = REPLACE(option_name, 'bslm_', 'sync_basalam_')
             WHERE option_name LIKE '%bslm_%'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix / core $wpdb properties, not user input.
        $this->wpdb->query(
            "UPDATE {$this->wpdb->options}
             SET option_name = REPLACE(option_name, 'bsl_', 'sync_basalam_')
             WHERE option_name LIKE '%bsl_%'"
        );
    }

    public function migrateActions()
    {
        $source = $this->wpdb->prefix . 'actionscheduler_actions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input; values are prepared.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE $source
                 SET hook = REPLACE(hook, 'bslm_', 'sync_basalam_')
                 WHERE hook LIKE %s AND status = %s",
                '%bslm_%',
                'pending'
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input; values are prepared.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE $source
                 SET hook = REPLACE(hook, 'bsl_', 'sync_basalam_')
                 WHERE hook LIKE %s AND status = %s",
                '%bsl_%',
                'pending'
            )
        );
    }

    public function migrateSettings()
    {
        $settingsRowName = 'sync_basalam_settings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from core $wpdb property, not user input; value is prepared.
        $settingsSerialized = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s",
                $settingsRowName
            )
        );

        if (!$settingsSerialized) return;

        $settings = maybe_unserialize($settingsSerialized);

        if (!is_array($settings)) return;

        $keyMap = [
            'basalam_client_id'          => 'client_id',
            'basalam_webhook_id'         => 'webhook_id',
            'basalam_token'              => 'token',
            'basalam_refresh_token'      => 'refresh_token',
            'basalam_vendor_id'          => 'vendor_id',
            'basalam_is_vendor'          => 'is_vendor',
            'basalam_increase'           => 'price_change_value',
            'basalam_round'              => 'round_price',
            'basalam_webhook_token'      => 'webhook_header_token',
            'basalam_auto_confirm_order' => 'auto_confirm_order',
            'basalam_product_wholesale'  => 'all_products_wholesale',
        ];

        $newSettings = [];
        foreach ($settings as $key => $value) {
            $newSettings[$keyMap[$key] ?? $key] = $value;
        }

        update_option($settingsRowName, $newSettings);
    }

    public function renameOldTables()
    {
        $tables = [
            $this->wpdb->prefix . 'bsl_payments',
            $this->wpdb->prefix . 'bsl_map_options',
            $this->wpdb->prefix . 'bsl_uploaded_photo',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
                $this->wpdb->query("RENAME TABLE $table TO {$table}_old");
            }
        }
    }

    private function tableExists($table)
    {

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy-data migration; no object cache for these one-time operational queries.
        return $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    private function columnExists($table, $column)
    {

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input; value is prepared.
        $result = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            $column
        ));

        return !empty($result);
    }

    public function addCreatedAtColumnToUploadedPhoto()
    {

        $table = $this->wpdb->prefix . 'sync_basalam_uploaded_photo';

        if ($this->tableExists($table) && !$this->columnExists($table, 'created_at')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
            $this->wpdb->query("ALTER TABLE $table ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function addNewWebhookEvents()
    {
        $webhookService = new WebhookService();
        $webhookService->setupWebhook();
    }

    public function addFinancialDataColumns()
    {
        $table = $this->wpdb->prefix . 'sync_basalam_payments';

        if (!$this->tableExists($table)) {
            return;
        }

        $columns = [
            'fee_amount' => "ALTER TABLE $table ADD COLUMN fee_amount DECIMAL(10,2) DEFAULT 0",
            'balance_amount' => "ALTER TABLE $table ADD COLUMN balance_amount DECIMAL(10,2) DEFAULT 0",
            'purchase_count' => "ALTER TABLE $table ADD COLUMN purchase_count INT DEFAULT 0"
        ];

        foreach ($columns as $columnName => $sql) {
            if (!$this->columnExists($table, $columnName)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; ALTER statement built from static SQL with a $wpdb->prefix table identifier, not user input.
                $this->wpdb->query($sql);
            }
        }
    }

    public function addUniqueInvoiceIdIndex()
    {
        $table = $this->wpdb->prefix . 'sync_basalam_payments';

        if (!$this->tableExists($table)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy-data migration; no object cache for these one-time operational queries; values are prepared.
        $existingIndex = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1",
                $table,
                'unique_invoice_id'
            )
        );

        if ($existingIndex) {
            return;
        }

        $this->removeDuplicateInvoicePayments($table);

        $this->wpdb->hide_errors();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
        $this->wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY unique_invoice_id (invoice_id)");
        $this->wpdb->show_errors();
    }

    private function removeDuplicateInvoicePayments($table)
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input.
        $duplicates = $this->wpdb->get_results(
            "SELECT invoice_id, MIN(id) AS keep_id
             FROM {$table}
             WHERE invoice_id IS NOT NULL AND invoice_id > 0
             GROUP BY invoice_id
             HAVING COUNT(*) > 1"
        );

        if (empty($duplicates)) {
            return;
        }

        foreach ($duplicates as $duplicate) {
            $invoiceId = (int) $duplicate->invoice_id;
            $keepId    = (int) $duplicate->keep_id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input; values are prepared.
            $rowsToDelete = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, order_id FROM {$table} WHERE invoice_id = %d AND id <> %d",
                    $invoiceId,
                    $keepId
                )
            );

            foreach ($rowsToDelete as $row) {
                $orderId = (int) ($row->order_id ?? 0);
                if ($orderId > 0 && function_exists('wc_get_order')) {
                    $order = wc_get_order($orderId);
                    if ($order instanceof \WC_Order) {
                        try {
                            $order->delete(true);
                        } catch (\Throwable $e) {
                            \SyncBasalam\Logger\Logger::error(
                                "حذف سفارش تکراری ووکامرس با شناسه {$orderId} ناموفق بود: " . $e->getMessage()
                            );
                        }
                    }
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy-data migration; no object cache for these one-time operational queries.
                $this->wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
            }

            \SyncBasalam\Logger\Logger::debug(
                "سفارشات تکراری برای invoice_id {$invoiceId} پاک‌سازی شدند، شناسه نگه‌داشته‌شده: {$keepId}"
            );
        }
    }

    public function addRetryAfterColumnToJobManager()
    {
        $tableName = $this->wpdb->prefix . 'sync_basalam_job_manager';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from $wpdb->prefix, not user input; value is prepared.
        $columnExists = $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE %s", 'retry_after')
        );

        if (!$columnExists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
            $this->wpdb->query("ALTER TABLE {$tableName} ADD COLUMN retry_after BIGINT(20) NULL DEFAULT NULL");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
            $this->wpdb->query("ALTER TABLE {$tableName} ADD INDEX idx_retry_after (retry_after)");
        }
    }

    public function migrateProductMetaKeysToVendorId()
    {
        $settings = get_option('sync_basalam_settings', []);
        if (!is_array($settings)) return;

        $vendorId = $settings[SettingsConfig::VENDOR_ID] ?? null;
        $vendorId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $vendorId);

        if ($vendorId === '') return;

        $postmetaTable = $this->wpdb->postmeta;

        $metaKeyMap = [
            ProductMetaKey::PRODUCT_ID => ProductMetaKey::basalamProductId($vendorId),
            ProductMetaKey::PRODUCT_SYNC_STATUS => ProductMetaKey::basalamProductSyncStatus($vendorId),
            ProductMetaKey::PRODUCT_STATUS => ProductMetaKey::basalamProductStatus($vendorId),
        ];

        foreach ($metaKeyMap as $oldMetaKey => $newMetaKey) {
            if ($oldMetaKey === $newMetaKey) continue;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from core $wpdb property, not user input; values are prepared.
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO {$postmetaTable} (post_id, meta_key, meta_value)
                     SELECT old_meta.post_id, %s, old_meta.meta_value
                     FROM {$postmetaTable} old_meta
                     LEFT JOIN {$postmetaTable} new_meta
                        ON new_meta.post_id = old_meta.post_id
                        AND new_meta.meta_key = %s
                     WHERE old_meta.meta_key = %s
                        AND new_meta.meta_id IS NULL",
                    $newMetaKey,
                    $newMetaKey,
                    $oldMetaKey
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifier from core $wpdb property, not user input; value is prepared.
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$postmetaTable} WHERE meta_key = %s",
                    $oldMetaKey
                )
            );
        }
    }

    public function clearAuthTokens()
    {
        $settings = get_option('sync_basalam_settings', []);
        if (!is_array($settings)) return;

        $settings[SettingsConfig::TOKEN] = null;
        $settings[SettingsConfig::REFRESH_TOKEN] = null;

        update_option('sync_basalam_settings', $settings);
    }

    public function createUploadedMediaTable()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $tableName = $this->wpdb->prefix . 'sync_basalam_uploaded_media';
        $charsetCollate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tableName (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(16) NOT NULL,
            source_identity VARCHAR(191) NOT NULL,
            media_id BIGINT UNSIGNED NULL,
            media_url VARCHAR(2083) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_type_source (type, source_identity),
            KEY idx_type_created (type, created_at),
            KEY idx_created_at (created_at)
        ) $charsetCollate;";

        dbDelta($sql);

        $this->migrateLegacyUploadedPhotos($tableName);
    }

    private function migrateLegacyUploadedPhotos(string $targetTable): void
    {
        $legacyTable = $this->wpdb->prefix . 'sync_basalam_uploaded_photo';

        if (!$this->tableExists($legacyTable)) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy-data migration; table identifiers from $wpdb->prefix, not user input.
        $this->wpdb->query(
            "INSERT IGNORE INTO {$targetTable} (type, source_identity, media_id, media_url, created_at)
             SELECT 'photo', CONCAT('attachment:', woo_photo_id), sync_basalam_photo_id, sync_basalam_photo_url, COALESCE(created_at, NOW())
             FROM {$legacyTable}
             WHERE woo_photo_id IS NOT NULL"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration; table identifier from $wpdb->prefix, not user input.
        $this->wpdb->query("DROP TABLE IF EXISTS {$legacyTable}");
    }

}
