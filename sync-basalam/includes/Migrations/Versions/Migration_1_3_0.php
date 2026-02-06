<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Activator;
use SyncBasalam\Migrations\MigrationInterface;
use SyncBasalam\Migrations\MigratorService;

defined('ABSPATH') || exit;
class Migration_1_3_0 implements MigrationInterface
{
    public function up()
    {
        $service = new MigratorService();

        Activator::activate();

        $service->migratePayments();

        $service->migrateOptions();

        $service->migrateUploadedPhotos();

        $service->migratePostMeta();

        $service->migrateOptionsRows();

        $service->migrateSettings();

        $service->migrateActions();

        $service->renameOldTables();
    }
}
