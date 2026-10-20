<?php

if (!function_exists('add_action')) {
    function add_action($hookName, $callback, $priority = 10, $acceptedArgs = 1)
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['actions'][$hookName][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];

        return true;
    }
}

if (!function_exists('did_action')) {
    function did_action($hookName)
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['did_actions'][$hookName] ?? 0;
    }
}

if (!function_exists('current_filter')) {
    function current_filter()
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['current_filter'] ?? '';
    }
}

if (!function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request()
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'fastcgi_finish_request';

        return true;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax()
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['doing_ajax'] ?? false;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'get_transient';
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transient_reads'][] = $transient;

        return $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][$transient] ?? false;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['sync_basalam_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['sync_basalam_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache()
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['external_object_cache'] ?? false;
    }
}

if (!function_exists('wp_installing')) {
    function wp_installing()
    {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'set_transient';
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transient_writes'][] = [
            'name' => $transient,
            'value' => $value,
            'expiration' => $expiration,
        ];
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][$transient] = $value;
        if ($expiration > 0) {
            $GLOBALS['sync_basalam_test_options']['_transient_timeout_' . $transient] = time() + $expiration;
        }

        return true;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url)
    {
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return $url;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test-nonce-for-' . $action;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hookName, $value)
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['filter_values'][$hookName] ?? $value;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'remote_post';
        $GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests'][] = [
            'url' => $url,
            'args' => $args,
        ];

        return ['response' => ['code' => 200]];
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state']['scheduled'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = [])
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['scheduled'][$hook] = $timestamp;
        return true;
    }
}
