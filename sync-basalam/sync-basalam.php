<?php

use SyncBasalam\Plugin;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Activator;
use SyncBasalam\JobsRunner;
use SyncBasalam\Infrastructure\Container\AppServiceProvider;
use SyncBasalam\Infrastructure\Container\Container;
use SyncBasalam\Services\ForceUpdateGate;
use SyncBasalam\Services\VendorSyncPolicy;
use SyncBasalam\Services\ScheduledProductSync;

defined('ABSPATH') || exit;

/**
 * Plugin Name: sync basalam | ووسلام
 * Description: با استفاده از پلاگین ووسلام  میتوایند تمامی محصولات ووکامرس را با یک کلیک به غرفه باسلامی خود اضافه کنید‌، همچنین تمامی سفارش باسلامی شما به سایت شما اضافه میگردد.
 * Version: 1.10.39
 * Author: Woosalam Dev
 * Author URI: https://hamsalam.ir/wp
 * Plugin URI: https://hamsalam.ir/wp
 * Text Domain: sync-basalam
 * WC tested up to: 9.9.5
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html.
 */

require_once __DIR__ . '/vendor/autoload.php';

$asyncWorkerProtection = new \SyncBasalam\Services\AsyncWorkerProtection();
add_action('admin_init', [$asyncWorkerProtection, 'ensureInstalled']);
register_activation_hook(__FILE__, [$asyncWorkerProtection, 'activate']);

register_activation_hook(__FILE__, 'syncBasalamActivatePlugin');
register_activation_hook(__FILE__, [\SyncBasalam\Services\WebhookService::class, 'onActivation']);
register_deactivation_hook(__FILE__, 'syncBasalamDeactivatePlugin');

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Keep payment callbacks available when synchronization requires an update.
add_action('plugins_loaded', [\SyncBasalam\SalamPay\Module::class, 'register'], 30);

$forceUpdate = get_option('sync_basalam_force_update');
$installedVersion = (string) (get_option('sync_basalam_version') ?: '0.0.0');

if (ForceUpdateGate::shouldBlock($forceUpdate, $installedVersion, Plugin::VERSION)) {
    add_action('admin_notices', function () {
        $template = __DIR__ . '/templates/notifications/ForceUpdateAlert.php';
        require_once $template;
     });
    return;
}

if ($forceUpdate) {
    delete_option('sync_basalam_force_update');
}

add_action('init', 'syncBasalamPlugin');
// Delay ownership until all active add-on bootstraps have registered their hooks.
add_action('plugins_loaded', [\SyncBasalam\SalamGate\Module::class, 'register'], 20);

function syncBasalamContainer()
{
    static $container = null;

    if ($container === null) {
        $container = new Container();
        $container->provider(new AppServiceProvider());
    }

    return $container;
}

//  Singleton instance of the main plugin class.
function syncBasalamPlugin()
{
    return syncBasalamContainer()->get(Plugin::class);
}

// Singleton instance of the woosalam Settings container.
function syncBasalamSettings()
{
    return syncBasalamContainer()->get(SettingsContainer::class);
}

function syncBasalamActivatePlugin()
{
    Activator::activate();
    syncBasalamContainer()->get(VendorSyncPolicy::class)->ensureScheduled();
    (new ScheduledProductSync())->ensureScheduled();
    set_transient('sync_basalam_just_activated', true, 10);
}

function syncBasalamDeactivatePlugin()
{
    VendorSyncPolicy::unschedule();
    ScheduledProductSync::unschedule();
    \SyncBasalam\SalamGate\Module::deactivate();
    \SyncBasalam\SalamPay\Module::deactivate();
}

syncBasalamContainer()->get(JobsRunner::class);
syncBasalamContainer()->get(VendorSyncPolicy::class)->registerHooks();
(new ScheduledProductSync())->registerHooks();
