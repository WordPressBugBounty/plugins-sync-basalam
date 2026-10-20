<?php
if (PHP_SAPI !== 'cli' || !getenv('MATRIX_TOKEN')) throw new RuntimeException('Disposable matrix CLI only.');
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
// WP_INSTALLING skips active-plugin bootstraps when reusing a kept local DB.
require_once ABSPATH . 'wp-content/plugins/sync-basalam/vendor/autoload.php';
if (wp_get_environment_type() !== 'local') throw new RuntimeException('Local matrix only.');
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) {
    wp_install('Async worker matrix', 'matrix-admin', 'matrix@example.invalid', true, '', 'disposable-local-only');
}
if (!get_user_by('login', 'matrix-subscriber')) {
    wp_create_user('matrix-subscriber', 'disposable-subscriber-only', 'subscriber@example.invalid');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach (['woocommerce/woocommerce.php', 'sync-basalam/sync-basalam.php'] as $plugin) {
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
}
update_option('sync_basalam_settings', ['tasks_per_minute' => 600, 'tasks_per_minute_auto' => false]);
update_option('sync_basalam_version', '1.10.29');
delete_option(\SyncBasalam\Services\AsyncWorkerProtection::OPTION_KEY);
delete_option(\SyncBasalam\Services\AsyncWorkerProtection::BACKUP_OPTION_KEY);
echo "Disposable WordPress installed.\n";
