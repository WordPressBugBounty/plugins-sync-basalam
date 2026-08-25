<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/wordpress/');
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

require_once dirname(__DIR__) . '/includes/Services/VendorSyncPolicy.php';
