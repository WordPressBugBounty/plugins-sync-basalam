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
    public const VERSION = '1.7.9';

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
        $this->onboarding();
        $this->handleVersionUpdate();
        $this->registrars();
        $this->notices();
    }

    private function onboarding()
    {
        if (get_transient('sync_basalam_just_activated')) {
            delete_transient('sync_basalam_just_activated');
            if (!syncBasalamSettings()->hasToken()) {
                wp_redirect(admin_url('admin.php?page=basalam-onboarding'));
                exit();
            }
        }
    }

    private function handleVersionUpdate()
    {
        $currentVersion = \get_option('sync_basalam_version') ?: '0.0.0';
        if (version_compare($currentVersion, self::VERSION, '<')) {

            $fetchVersionDetail = new FetchVersionDetail(self::VERSION);
            $fetchVersionDetail->checkForceUpdate();

            $manager = new MigrationManager();
            $manager->runMigrations($currentVersion, self::VERSION);
        }
    }

    private function notices()
    {
        if (!get_option('sync_basalam_review_never_remind')) {
            add_action('admin_notices', function () {
                $template = syncBasalamPlugin()->templatePath("notifications/LikeAlert.php");
                require_once $template;
            });
        }

        if (!syncBasalamSettings()->hasToken()) {
            add_action('admin_notices', function () {
                $template = syncBasalamPlugin()->templatePath("notifications/AccessAlert.php");
                require_once($template);
            });
        }
    }

    private function registrars()
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

    public function getVersion()
    {
        return self::VERSION;
    }
}
