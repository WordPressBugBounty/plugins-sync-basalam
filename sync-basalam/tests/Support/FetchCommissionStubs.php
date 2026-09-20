<?php

namespace SyncBasalam\Services\Products;

if (!function_exists(__NAMESPACE__ . '\\syncBasalamContainer')) {
    function syncBasalamContainer()
    {
        if (!isset($GLOBALS['sync_basalam_test_container'])) {
            throw new \RuntimeException('The test service container has not been configured.');
        }

        return $GLOBALS['sync_basalam_test_container'];
    }
}
