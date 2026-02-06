<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;

defined('ABSPATH') || exit;

class Migration_1_6_2 implements MigrationInterface
{
    public function up()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_category_mappings';

        $oldDataExists = $wpdb->get_var("SHOW COLUMNS FROM $tableName LIKE 'basalam_category_id'");

        if (!$oldDataExists) return;

        $this->addNewColumns($wpdb, $tableName);

        $this->removeOldColumn($wpdb, $tableName);
    }

    private function addNewColumns($wpdb, $tableName)
    {
        $wpdb->query("ALTER TABLE $tableName
            ADD COLUMN basalam_category_level1 INT(11) DEFAULT NULL AFTER woo_category_name,
            ADD COLUMN basalam_category_level2 INT(11) DEFAULT NULL AFTER basalam_category_level1,
            ADD COLUMN basalam_category_level3 INT(11) DEFAULT NULL AFTER basalam_category_level2
        ");

        $wpdb->query("ALTER TABLE $tableName
            ADD INDEX basalam_category_level1_idx (basalam_category_level1)
        ");
    }

    private function removeOldColumn($wpdb, $tableName)
    {
        $wpdb->query("ALTER TABLE $tableName DROP INDEX basalam_category_idx");
        $wpdb->query("ALTER TABLE $tableName DROP COLUMN basalam_category_id");
    }
}
