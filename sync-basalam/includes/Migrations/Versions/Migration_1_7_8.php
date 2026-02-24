<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;

defined('ABSPATH') || exit;

class Migration_1_7_8 implements MigrationInterface
{
    public function up()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_job_manager';

        $this->addNewColumns($wpdb, $tableName);
        $this->removeProductOperationTypeSetting();
    }

    private function addNewColumns($wpdb, $tableName)
    {
        $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3");
        $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN failed_at INT NULL");
    }

    private function removeProductOperationTypeSetting()
    {
        $settings = (array) get_option('sync_basalam_settings', []);

        if (is_array($settings) && isset($settings['product_operation_type'])) {
            unset($settings['product_operation_type']);
            update_option('sync_basalam_settings', $settings);
        }
    }
}
