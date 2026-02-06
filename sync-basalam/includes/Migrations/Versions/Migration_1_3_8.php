<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Activator;
use SyncBasalam\Migrations\MigrationInterface;
use SyncBasalam\Migrations\MigratorService;

defined('ABSPATH') || exit;
class Migration_1_3_8 implements MigrationInterface
{
    public function up()
    {
        $service = new MigratorService();

        Activator::activate();

        $service->addCreatedAtColumnToUploadedPhoto();
    }
}
