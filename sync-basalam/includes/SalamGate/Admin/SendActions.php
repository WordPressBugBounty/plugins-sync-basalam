<?php

defined('ABSPATH') || exit;

/**
 * @deprecated P3 include-path shim; load Admin\Ajax\SendActions through Composer.
 * This file neither declares an alias nor registers hooks. External consumers
 * may still include it; removal gates are in docs/salam-gate/compatibility.md.
 */
require_once __DIR__ . '/Ajax/SendActions.php';
