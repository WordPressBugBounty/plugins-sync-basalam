<?php

namespace SyncBasalam;

use SyncBasalam\Migrations\MigrationManager;

use SyncBasalam\Registrar\AdminRegistrar;
use SyncBasalam\Registrar\ListenerRegistrar;
use SyncBasalam\Registrar\OrderRegistrar;
use SyncBasalam\Registrar\ProductRegistrar;
use SyncBasalam\Registrar\QueueRegistrar;
use SyncBasalam\Services\FetchVersionDetail;

defined('ABSPATH') || exit;

class Plugin
{
    public const VERSION = '1.7.7';

    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->migrate();
        $this->Registrars();
    }

    private function migrate()
    {
        $currentVersion = \get_option('sync_basalam_version') ?: '0.0.0';
        if (version_compare($currentVersion, self::VERSION, '<')) {
            $manager = new MigrationManager();
            $manager->runMigrations($currentVersion, self::VERSION);
        }
    }

    static function checkForceUpdateByVersion()
    {
        $currentVersion = \get_option('sync_basalam_version') ?: '0.0.0';
        if (version_compare($currentVersion, self::VERSION, '<')) {
            $fetchVersionDetail = new FetchVersionDetail();
            $fetchVersionDetail->checkForceUpdate();
        }
    }

    private function Registrars()
    {
        $registrars = [
            AdminRegistrar::class,
            ProductRegistrar::class,
            OrderRegistrar::class,
            ListenerRegistrar::class,
            QueueRegistrar::class,
        ];

        foreach ($registrars as $registrarClass) {
            $registrar = new $registrarClass();
            $registrar->register();
        }
    }

    public function pluginPath()
    {
        return untrailingslashit(str_replace("includes", "", \plugin_dir_path(__FILE__)));
    }

    public function templatePath($path = null)
    {

        $path = $path ? "/" . $path : null;

        return $this->pluginPath() . "/templates" . $path;
    }

    public function assetsUrl($path = null)
    {
        return plugin_dir_url(dirname(__FILE__, 1)) . "assets/" . $path;
    }
}
