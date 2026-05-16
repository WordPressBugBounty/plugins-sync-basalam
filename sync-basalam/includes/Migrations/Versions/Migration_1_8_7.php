<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;
use SyncBasalam\Migrations\MigratorService;

defined('ABSPATH') || exit;

class Migration_1_8_7 implements MigrationInterface
{
    public function up()
    {
        $service = new MigratorService();
        $service->createUploadedMediaTable();
    }
}
