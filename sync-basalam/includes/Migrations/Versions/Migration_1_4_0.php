<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Activator;
use SyncBasalam\Migrations\MigrationInterface;

defined('ABSPATH') || exit;
class Migration_1_4_0 implements MigrationInterface
{
    public function up()
    {
        Activator::activate();
    }
}
