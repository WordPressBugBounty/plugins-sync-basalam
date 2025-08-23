<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Migration_1_3_2 implements Sync_Basalam_Migration_Interface
{
    public function up()
    {
        $service = new Sync_Basalam_Migrator_Service();
        Sync_basalam_Plugin_Activator::activate();
        $service->migrateActions();
    }
}
