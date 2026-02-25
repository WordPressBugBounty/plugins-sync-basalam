<?php

use SyncBasalam\Plugin;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Activator;
use SyncBasalam\JobsRunner;

defined('ABSPATH') || exit;

/**
 * Plugin Name: sync basalam | ووسلام
 * Description: با استفاده از پلاگین ووسلام  میتوایند تمامی محصولات ووکامرس را با یک کلیک به غرفه باسلامی خود اضافه کنید‌، همچنین تمامی سفارش باسلامی شما به سایت شما اضافه میگردد.
 * Version: 1.7.9
 * Author: Woosalam Dev
 * Author URI: https://wp.hamsalam.ir/
 * Plugin URI: https://wp.hamsalam.ir
 * Text Domain: sync-basalam
 * WC requires at least: 10.0.0
 * WC tested up to: 9.9.5
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html.
 */

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, 'syncBasalamActivatePlugin');

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (get_option('sync_basalam_force_update')) {
    add_action('admin_notices', function () {
        $template = __DIR__ . '/templates/notifications/ForceUpdateAlert.php';
        require_once $template;
    });
    return;
}

add_action('init', 'syncBasalamPlugin');

//  Singleton instance of the main plugin class.
function syncBasalamPlugin()
{
    return Plugin::getInstance();
}

// Singleton instance of the woosalam Settings container.
function syncBasalamSettings()
{
    return SettingsContainer::getInstance();
}

function syncBasalamActivatePlugin()
{
    Activator::activate();
    set_transient('sync_basalam_just_activated', true, 10);
}

JobsRunner::getInstance();