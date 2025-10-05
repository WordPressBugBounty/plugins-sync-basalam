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
        $table_name_discount_tasks = $wpdb->prefix . 'sync_basalam_discount_tasks';
        $table_name_category_mappings = $wpdb->prefix . 'sync_basalam_category_mappings';
        $table_name_jobs_manager = $wpdb->prefix . 'sync_basalam_job_manager';
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
        woo_photo_id INT,
        sync_basalam_photo_id INT,
        sync_basalam_photo_url VARCHAR(2083),
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_woo_photo_id (woo_photo_id)
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

        $sql_discount_tasks = "CREATE TABLE $table_name_discount_tasks (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            product_id VARCHAR(50) DEFAULT NULL,
            variation_id VARCHAR(50) DEFAULT NULL,
            discount_percent DECIMAL(5,2) NOT NULL,
            active_days INT(11) NOT NULL,
            action ENUM('apply','remove') DEFAULT 'apply',
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_action (action),
            KEY idx_discount_percent (discount_percent),
            KEY idx_scheduled_at (scheduled_at),
            KEY idx_product_id (product_id),
            KEY idx_variation_id (variation_id)
        ) $charset_collate;";

        $sql_category_mappings = "CREATE TABLE $table_name_category_mappings (
            id int(11) NOT NULL AUTO_INCREMENT,
            woo_category_id int(11) NOT NULL,
            woo_category_name varchar(255) NOT NULL,
            basalam_category_id int(11) NOT NULL,
            basalam_category_name varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY woo_category_unique (woo_category_id),
            KEY basalam_category_idx (basalam_category_id)
        ) $charset_collate;";

        $sql_jobs_manager = "CREATE TABLE $table_name_jobs_manager (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            payload TEXT DEFAULT NULL,
            created_at  BIGINT(20) NOT NULL DEFAULT 0,
            started_at BIGINT(20) NOT NULL DEFAULT 0,
            completed_at BIGINT(20) NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_job_type (job_type(191)),
            KEY idx_status_job_type (status, job_type(100)),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_Payment);
        dbDelta($sql_options);
        dbDelta($sql_uploaded_photo);
        dbDelta($sql_debug_logs);
        dbDelta($sql_discount_tasks);
        dbDelta($sql_category_mappings);
        dbDelta($sql_jobs_manager);
    }
}
