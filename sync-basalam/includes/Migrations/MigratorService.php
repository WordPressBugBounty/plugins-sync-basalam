<?php

namespace SyncBasalam\Migrations;

use SyncBasalam\Services\WebhookService;

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
            $this->wpdb->query("INSERT INTO $target (payment_id, invoice_id, user_id, city_id, province_id, order_id)
                                SELECT payment_id, invoice_id, user_id, city_id, province_id, order_id FROM $source");
        }
    }

    public function migrateOptions()
    {
        $source = $this->wpdb->prefix . 'bsl_map_options';
        $target = $this->wpdb->prefix . 'sync_basalam_map_options';

        if ($this->tableExists($source) && $this->tableExists($target)) {
            $this->wpdb->query("INSERT INTO $target (woo_name, sync_basalam_name)
                                SELECT woo_name, bslm_name FROM $source");
        }
    }

    public function migrateUploadedPhotos()
    {
        $source = $this->wpdb->prefix . 'bsl_uploaded_photo';
        $target = $this->wpdb->prefix . 'sync_basalam_uploaded_photo';

        if ($this->tableExists($source) && $this->tableExists($target)) {
            $this->wpdb->query("INSERT INTO $target (woo_photo_id, sync_basalam_photo_id, sync_basalam_photo_url)
                                SELECT woo_photo_id, bslm_photo_id, bslm_photo_url FROM $source");
        }
    }

    public function migratePostMeta()
    {
        $this->wpdb->query(
            "UPDATE {$this->wpdb->postmeta}
             SET meta_key = REPLACE(meta_key, 'bslm_', 'sync_basalam_')
             WHERE meta_key LIKE '%bslm_%'"
        );

        $this->wpdb->query(
            "UPDATE {$this->wpdb->postmeta}
             SET meta_key = REPLACE(meta_key, 'bsl_', 'sync_basalam_')
             WHERE meta_key LIKE '%bsl_%'"
        );
    }

    public function migrateOptionsRows()
    {
        $this->wpdb->query(
            "UPDATE {$this->wpdb->options}
             SET option_name = REPLACE(option_name, 'bslm_', 'sync_basalam_')
             WHERE option_name LIKE '%bslm_%'"
        );

        $this->wpdb->query(
            "UPDATE {$this->wpdb->options}
             SET option_name = REPLACE(option_name, 'bsl_', 'sync_basalam_')
             WHERE option_name LIKE '%bsl_%'"
        );
    }

    public function migrateActions()
    {
        $source = $this->wpdb->prefix . 'actionscheduler_actions';

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE $source
                 SET hook = REPLACE(hook, 'bslm_', 'sync_basalam_')
                 WHERE hook LIKE %s AND status = %s",
                '%bslm_%',
                'pending'
            )
        );

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
            'basalam_increase'           => 'increase_price_value',
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
                $this->wpdb->query("RENAME TABLE $table TO {$table}_old");
            }
        }
    }

    private function tableExists($table)
    {

        return $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    private function columnExists($table, $column)
    {

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
                $this->wpdb->query($sql);
            }
        }
    }
}
