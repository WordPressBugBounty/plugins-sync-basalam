<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Migration_1_4_1 implements Sync_Basalam_Migration_Interface
{
    public function up()
    {
        Sync_basalam_Plugin_Activator::activate();
    }
}
