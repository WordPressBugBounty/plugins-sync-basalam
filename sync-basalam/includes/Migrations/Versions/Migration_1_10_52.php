<?php

namespace SyncBasalam\Migrations\Versions;

use SyncBasalam\Migrations\MigrationInterface;
use SyncBasalam\Migrations\MigratorService;

defined('ABSPATH') || exit;

class Migration_1_10_52 implements MigrationInterface
{
    public const VERSION = '1.10.52';

    public function up(): bool
    {
        return (new MigratorService())->widenPaymentOrderId();
    }
}
