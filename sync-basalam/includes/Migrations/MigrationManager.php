<?php

namespace SyncBasalam\Migrations;

use SyncBasalam\Migrations\Versions\Migration_1_3_0;
use SyncBasalam\Migrations\Versions\Migration_1_3_2;
use SyncBasalam\Migrations\Versions\Migration_1_3_8;
use SyncBasalam\Migrations\Versions\Migration_1_4_0;
use SyncBasalam\Migrations\Versions\Migration_1_4_1;
use SyncBasalam\Migrations\Versions\Migration_1_5_4;
use SyncBasalam\Migrations\Versions\Migration_1_6_2;

defined('ABSPATH') || exit;

class MigrationManager
{
    private $migrations = [];

    public function __construct()
    {
        $this->migrations = [
            '1.3.0' => new Migration_1_3_0(),
            '1.3.2' => new Migration_1_3_2(),
            '1.3.8' => new Migration_1_3_8(),
            '1.4.0' => new Migration_1_4_0(),
            '1.4.1' => new Migration_1_4_1(),
            '1.6.2' => new Migration_1_6_2()
        ];
    }

    public function runMigrations($currentVersion, $newVersion)
    {
        foreach ($this->migrations as $version => $migration) {
            if (version_compare($currentVersion, $version, '<')) $migration->up();
        }

        update_option('sync_basalam_version', $newVersion);
    }
}
