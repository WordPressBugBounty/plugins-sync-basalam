<?php
// Disposable local database. No site/customer credentials belong here.
if (!getenv('MATRIX_TOKEN') || !getenv('MATRIX_SITE_URL')) exit;
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', 'local-matrix-only');
define('DB_HOST', 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'matrix_';
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
    define($name, 'disposable-webserver-matrix-' . $name);
}
define('WP_HOME', getenv('MATRIX_SITE_URL'));
define('WP_SITEURL', getenv('MATRIX_SITE_URL'));
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', '/tmp/matrix-wordpress.log');
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_MODS', true);
defined('ABSPATH') || define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-settings.php';
