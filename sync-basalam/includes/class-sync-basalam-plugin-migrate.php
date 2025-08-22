<?php
defined('ABSPATH') || exit;

class Sync_basalam_Plugin_Migrator
{
    public static function migrate_all()
    {
        self::migrate_payments();
        self::migrate_options();
        self::migrate_uploaded_photos();
        self::migrate_postmeta_rows();
        self::migrate_options_rows();
        self::migrate_settings_values();
        self::migrate_actions();
        self::rename_old_tables();
        // self::drop_old_tables();
    }

    public static function migrate_payments()
    {
        global $wpdb;
        $source = $wpdb->prefix . 'bsl_payments';
        $target = $wpdb->prefix . 'sync_basalam_payments';

        if (self::table_exists($source) && self::table_exists($target)) {
            $wpdb->query("INSERT INTO $target (payment_id, invoice_id, user_id, city_id, province_id, order_id)
                            SELECT payment_id, invoice_id, user_id, city_id, province_id, order_id FROM $source");
        }
    }

    public static function migrate_options()
    {
        global $wpdb;
        $source = $wpdb->prefix . 'bsl_map_options';
        $target = $wpdb->prefix . 'sync_basalam_map_options';

        if (self::table_exists($source) && self::table_exists($target)) {
            $wpdb->query("INSERT INTO $target (woo_name, sync_basalam_name)
                            SELECT woo_name, bslm_name FROM $source");
        }
    }

    public static function migrate_uploaded_photos()
    {
        global $wpdb;
        $source = $wpdb->prefix . 'bsl_uploaded_photo';
        $target = $wpdb->prefix . 'sync_basalam_uploaded_photo';

        if (self::table_exists($source) && self::table_exists($target)) {
            $wpdb->query("INSERT INTO $target (woo_photo_id, sync_basalam_photo_id, sync_basalam_photo_url)
                            SELECT woo_photo_id, bslm_photo_id, bslm_photo_url FROM $source");
        }
    }

    public static function migrate_postmeta_rows()
    {
        global $wpdb;

        $wpdb->query(
            "UPDATE {$wpdb->postmeta}
         SET meta_key = REPLACE(meta_key, 'bslm_', 'sync_basalam_')
         WHERE meta_key LIKE '%bslm_%'"
        );

        $wpdb->query(
            "UPDATE {$wpdb->postmeta}
         SET meta_key = REPLACE(meta_key, 'bsl_', 'sync_basalam_')
         WHERE meta_key LIKE '%bsl_%'"
        );
    }

    public static function migrate_options_rows()
    {
        global $wpdb;

        $wpdb->query(
            "UPDATE {$wpdb->options}
         SET option_name = REPLACE(option_name, 'bslm_', 'sync_basalam_')
         WHERE option_name LIKE '%bslm_%'"
        );

        $wpdb->query(
            "UPDATE {$wpdb->options}
         SET option_name = REPLACE(option_name, 'bsl_', 'sync_basalam_')
         WHERE option_name LIKE '%bsl_%'"
        );
    }

    public static function migrate_actions()
    {
        global $wpdb;
        $source = $wpdb->prefix . 'actionscheduler_actions';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $source
             SET hook = REPLACE(hook, 'bslm_', 'sync_basalam_')
             WHERE hook LIKE %s AND status = %s",
                '%bslm_%',
                'pending'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $source
             SET hook = REPLACE(hook, 'bsl_', 'sync_basalam_')
             WHERE hook LIKE %s AND status = %s",
                '%bsl_%',
                'pending'
            )
        );
    }

    public static function migrate_settings_values()
    {
        global $wpdb;

        $settings_row_name = 'sync_basalam_settings';

        $settings_serialized = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
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
            'basalam_client_id' => 'client_id',
            'basalam_webhook_id' => 'webhook_id',
            'basalam_token' => 'token',
            'basalam_refresh_token' => 'refresh_token',
            'basalam_vendor_id' => 'vendor_id',
            'basalam_is_vendor' => 'is_vendor',
            'basalam_increase' => 'increase_price_value',
            'basalam_round' => 'round_price',
            'basalam_webhook_token' => 'webhook_header_token',
            'basalam_auto_confirm_order' => 'auto_confirm_order',
            'default_shipping_method' => 'order_shipping_method',
            'basalam_product_wholesale' => 'all_products_wholesale',
        ];

        $new_settings = [];

        foreach ($settings as $key => $value) {
            if (isset($key_map[$key])) {
                $new_key = $key_map[$key];
            } else {
                $new_key = $key;
            }

            $new_settings[$new_key] = $value;
        }

        update_option($settings_row_name, $new_settings);
    }


    public static function drop_old_tables()
    {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'bsl_payments',
            $wpdb->prefix . 'bsl_map_options',
            $wpdb->prefix . 'bsl_uploaded_photo'
        ];

        foreach ($tables as $table) {
            if (self::table_exists($table)) {
                $wpdb->query("DROP TABLE IF EXISTS $table");
            }
        }
    }

    public static function rename_old_tables()
    {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'bsl_payments',
            $wpdb->prefix . 'bsl_map_options',
            $wpdb->prefix . 'bsl_uploaded_photo'
        ];
        foreach ($tables as $table) {
            if (self::table_exists($table)) {
                $wpdb->query("RENAME TABLE $table TO $table" . "_old");
            }
        }
    }

    private static function table_exists($table)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )) === $table;
    }
}
