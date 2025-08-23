<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Migrator_Service
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
        $settings_row_name = 'sync_basalam_settings';

        $settings_serialized = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s",
                $settings_row_name
            )
        );

        if (!$settings_serialized) {
            return;
        }

        $settings = maybe_unserialize($settings_serialized);
        if (!is_array($settings)) {
            return;
        }

        $key_map = [
            'basalam_client_id'        => 'client_id',
            'basalam_webhook_id'       => 'webhook_id',
            'basalam_token'            => 'token',
            'basalam_refresh_token'    => 'refresh_token',
            'basalam_vendor_id'        => 'vendor_id',
            'basalam_is_vendor'        => 'is_vendor',
            'basalam_increase'         => 'increase_price_value',
            'basalam_round'            => 'round_price',
            'basalam_webhook_token'    => 'webhook_header_token',
            'basalam_auto_confirm_order' => 'auto_confirm_order',
            'default_shipping_method'  => 'order_shipping_method',
            'basalam_product_wholesale' => 'all_products_wholesale',
        ];

        $new_settings = [];
        foreach ($settings as $key => $value) {
            $new_settings[$key_map[$key] ?? $key] = $value;
        }

        update_option($settings_row_name, $new_settings);
    }

    public function renameOldTables()
    {
        $tables = [
            $this->wpdb->prefix . 'bsl_payments',
            $this->wpdb->prefix . 'bsl_map_options',
            $this->wpdb->prefix . 'bsl_uploaded_photo'
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
}
