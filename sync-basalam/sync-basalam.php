<?php
if (! defined('ABSPATH')) exit;

/**
 * Plugin Name: sync basalam | ووسلام
 * Description: با استفاده از پلاگین ووسلام  میتوایند تمامی محصولات ووکامرس را با یک کلیک به غرفه باسلامی خود اضافه کنید‌، همچنین تمامی سفارش باسلامی شما به سایت شما اضافه میگردد.
 * Version: 1.3.11
 * Author: Woosalam Dev
 * Author URI: https://wp.hamsalam.ir/help
 * Plugin URI: https://wp.hamsalam.ir
 * Text Domain: sync-basalam
 * WC requires at least: 8.0.0
 * WC tested up to: 9.9.5
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */


// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});


add_filter('plugin_row_meta', 'sync_basalam_add_row_meta', 10, 2);

function sync_basalam_add_row_meta($links, $file)
{
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="https://wp.hamsalam.ir/help" target="_blank">مستندات</a>';
    }
    return $links;
}

add_action('init', 'sync_basalam_init');

function sync_basalam_init()
{
    require_once __DIR__ . '/includes/class-sync-basalam-plugin.php';
    sync_basalam_configure();
    if (!get_option('sync_basalam_like')) {
        add_action('admin_notices', function () {
            $template = sync_basalam_configure()->template_path("admin/utilities/like-alert.php");
            require_once($template);
        });
    }

    if (!sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN)) {
        add_action('admin_notices', function () {
            $template = sync_basalam_configure()->template_path("admin/utilities/access-alert.php");
            require_once($template);
        });
    }

    if (get_option('sync_basalam_version') != sync_basalam_configure()::VERSION) {
        sync_basalam_run_activation();
    }
}

function sync_basalam_redirect_after_activation()
{
    $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
    if ($token) return;
    if (get_transient('sync_basalam_do_activation_redirect')) {
        delete_transient('sync_basalam_do_activation_redirect');

        if (sanitize_text_field(!isset($_GET['activate-multi']))) {
            wp_safe_redirect(admin_url('admin.php?page=basalam-onboarding'));
            exit;
        }
    }
}
add_action('admin_init', 'sync_basalam_redirect_after_activation');

function sync_basalam_configure()
{
    require_once __DIR__ . '/includes/class-sync-basalam-plugin.php';
    return Sync_Basalam_Plugin::instance();
}

/**
 * Function to install tables or initial settings when activating the plugin
 */

register_activation_hook(__FILE__, 'Sync_basalam_handle_plugin_activation');
function Sync_basalam_run_activation()
{
    require_once __DIR__ . '/includes/class-sync-basalam-plugin-activator.php';
    Sync_basalam_Plugin_Activator::activate();
}
function Sync_Basalam_handle_plugin_activation()
{
    Sync_basalam_run_activation();
    set_transient('sync_basalam_do_activation_redirect', true, 30);
}


/**
 * Function to handle any required actions when deactivating the plugin.
 */
register_deactivation_hook(__FILE__, 'Sync_basalam_on_deactivation');
function Sync_basalam_on_deactivation()
{
    // Deactivation Logics
}

require_once plugin_dir_path(__FILE__)  . 'wp-bg-procces.php';