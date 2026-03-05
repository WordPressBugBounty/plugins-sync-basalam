<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;

defined('ABSPATH') || exit;

class Migration_1_5_4 implements MigrationInterface
{
    public function up()
    {
        \WC()->queue()->cancel_all('sync_basalam_plugin_create_product');
        \WC()->queue()->cancel_all('sync_basalam_plugin_update_product');
        \WC()->queue()->cancel_all('sync_basalam_plugin_connect_auto_product');
    }
}
