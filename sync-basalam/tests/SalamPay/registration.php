<?php
/** Smoke-test bootstrap gates with the actual local WooCommerce runtime. */
if (PHP_SAPI !== 'cli') { exit; }
$mode = $argv[1] ?? 'normal';
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
if ($mode === 'standalone') { class Salampay_Plugin {} }
if ($mode === 'force-update') {
    require_once '/var/www/html/wp-includes/plugin.php';
    add_filter('pre_option_sync_basalam_force_update', static fn() => true);
    add_filter('pre_option_sync_basalam_version', static fn() => '999.0.0');
}
require getenv('WORDPRESS_BOOTSTRAP') ?: '/var/www/html/wp-load.php';
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WordPress required.'); }
$owns = \SyncBasalam\SalamPay\Module::ownsPayments();
if ($mode === 'standalone') {
    if ($owns) { throw new RuntimeException('Two gateway owners'); }
} else {
    if (!$owns) { throw new RuntimeException('Payment module did not start'); }
    $gateway = WC()->payment_gateways()->payment_gateways()['salampay'] ?? null;
    if (!$gateway instanceof \SyncBasalam\SalamPay\Gateway) { throw new RuntimeException('Gateway missing'); }
    if (!isset(rest_get_server()->get_routes()['/salampay/v1/callback'])) { throw new RuntimeException('Callback missing'); }
    $before = apply_filters('woocommerce_payment_gateways', []);
    \SyncBasalam\SalamPay\Module::register();
    if ($before !== apply_filters('woocommerce_payment_gateways', [])) { throw new RuntimeException('Duplicate registration'); }
}
echo "PASS: payment bootstrap {$mode}.\n";
