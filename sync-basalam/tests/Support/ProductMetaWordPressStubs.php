<?php

// Shared metadata storage and write history. Defining these before test files
// prevents load order from choosing an incomplete per-file WordPress stub.
if (!function_exists('get_post_meta')) {
    function get_post_meta($postId, $metaKey, $single = false)
    {
        return $GLOBALS['sync_basalam_test_meta'][$postId][$metaKey] ?? '';
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($postId, $metaKey, $value)
    {
        $GLOBALS['sync_basalam_test_meta'][$postId][$metaKey] = $value;
        $GLOBALS['sync_basalam_updated_meta'][] = [$postId, $metaKey, $value];
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($postId, $metaKey)
    {
        unset($GLOBALS['sync_basalam_test_meta'][$postId][$metaKey]);
        $GLOBALS['sync_basalam_deleted_meta'][] = [$postId, $metaKey];
        return true;
    }
}
