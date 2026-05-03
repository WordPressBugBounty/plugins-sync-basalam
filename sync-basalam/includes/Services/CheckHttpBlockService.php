<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class CheckHttpBlockService
{
    public function SyncBasalamHttpBlock()
    {
        if (!defined('WP_HTTP_BLOCK_EXTERNAL') || WP_HTTP_BLOCK_EXTERNAL !== true) return false;

        $required_hosts = [
            'basalam.com',
            '*.basalam.com',
            '*.hamsalam.ir',
        ];

        $current_hosts = [];

        if (defined('WP_ACCESSIBLE_HOSTS')) {
            $current_hosts = array_filter(array_map('trim', explode(',', WP_ACCESSIBLE_HOSTS)));
        }

        $missing_hosts = array_diff($required_hosts, $current_hosts);

        if (empty($missing_hosts)) return false;

        return $required_hosts;
    }
}
