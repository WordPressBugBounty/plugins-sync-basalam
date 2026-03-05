<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;
use SyncBasalam\Queue\Tasks\DailyCheckForceUpdate;

defined('ABSPATH') || exit;

class Migration_1_7_4 implements MigrationInterface
{
    public function up()
    {
        // Schedule the task via hook to ensure WooCommerce is loaded
        add_action('woocommerce_init', function() {
            $dailyCheckForceUpdate = new DailyCheckForceUpdate();
            $dailyCheckForceUpdate->schedule();
        });
    }
}
