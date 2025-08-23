<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Plugin_Activator
{
    public static function activate()
    {
        self::activate_db();
    }
    public static function activate_db()
    {
        global $wpdb;
        $table_name_payments       = $wpdb->prefix . 'sync_basalam_payments';
        $table_name_options        = $wpdb->prefix . 'sync_basalam_map_options';
        $table_name_uploaded_photo = $wpdb->prefix . 'sync_basalam_uploaded_photo';
        $table_name_debug_logs     = $wpdb->prefix . 'sync_basalam_debug_logs';
        $charset_collate           = $wpdb->get_charset_collate();

        $sql_Payment = "CREATE TABLE $table_name_payments (
            id INT AUTO_INCREMENT,
            payment_id INT,
            invoice_id INT NOT NULL,
            user_id INT NOT NULL,
            city_id INT NOT NULL,
            province_id INT NOT NULL,
            order_id INT DEFAULT NULL,
            PRIMARY KEY (id)

        ) $charset_collate;";

        $sql_options = "CREATE TABLE $table_name_options (
            id INT AUTO_INCREMENT,
            woo_name VARCHAR(255),
            sync_basalam_name VARCHAR(255),
            PRIMARY KEY (id)
        ) $charset_collate;";


        $sql_uploaded_photo = "CREATE TABLE $table_name_uploaded_photo (
            id INT AUTO_INCREMENT,
            woo_photo_id INT UNIQUE,
            sync_basalam_photo_id INT,
            sync_basalam_photo_url VARCHAR(2083),
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql_debug_logs = "CREATE TABLE $table_name_debug_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            request_url VARCHAR(2083) NOT NULL,
            status_code INT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            response_time_ms INT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_at_idx (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_Payment);
        dbDelta($sql_options);
        dbDelta($sql_uploaded_photo);
        dbDelta($sql_debug_logs);
    }
}
