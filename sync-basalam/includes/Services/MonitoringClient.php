<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Pages\MonitoringPage;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\Hamsalam\FetchHamsalamToken;

defined('ABSPATH') || exit;

/** Owner-authenticated display and a short-lived handoff to the purchase page. */
class MonitoringClient
{
    public function registerHooks(): void
    {
        add_action('admin_post_sync_basalam_monitoring_handoff', [$this, 'handoff']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    public function enqueueStyles($hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== MonitoringPage::SLUG) return;
        $stylePath = syncBasalamPlugin()->pluginPath() . '/assets/css/monitoring.css';
        $version = syncBasalamPlugin()->getVersion();
        if (file_exists($stylePath)) $version .= '-' . filemtime($stylePath);
        wp_enqueue_style('sync-basalam-monitoring', syncBasalamPlugin()->assetsUrl('css/monitoring.css'),
            ['basalam-admin-style', 'basalam-admin-font-style'], $version);
    }

    public function getStatus(bool $refresh = false): ?array
    {
        $siteUrl = untrailingslashit(get_site_url());
        $key = 'sync_basalam_monitoring_' . md5($siteUrl . '|' . (string) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID));
        $cached = get_transient($key);
        if (!$refresh && is_array($cached)) return !empty($cached['unavailable']) ? null : $cached;
        $sites = $this->request('/sites');
        if (!$sites || !isset($sites['data']) || !is_array($sites['data'])) {
            set_transient($key, ['unavailable' => true], MINUTE_IN_SECONDS);
            return null;
        }
        foreach ($sites['data'] as $site) {
            if (untrailingslashit((string) ($site['site_url'] ?? '')) !== $siteUrl) continue;
            $status = $this->request('/sites/' . (int) $site['id']);
            if (is_array($status) && isset($status['subscription'])) {
                set_transient($key, $status, MINUTE_IN_SECONDS);
                return $status;
            }
            return null;
        }
        return ['subscription' => ['active' => false, 'health' => 'unknown',
                'message' => 'اشتراک پایشگر برای این فروشگاه فعال نشده است.']];
    }

    /** No cache: the fixed Gateway authority consumes and validates one command. */
    public function repairPermission(string $grant): ?array
    {
        $response = wp_remote_post(Endpoints::HAMSALAM_BASE . '/woo-plugin/monitoring/repair-permission', [
            'timeout' => 5, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['grant' => $grant, 'site_url' => untrailingslashit(get_site_url())]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $proof = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($proof) && ($proof['authorized'] ?? false) === true ? $proof : null;
    }

    public function handoff(): void
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی کافی ندارید.', '', ['response' => 403]);
        check_admin_referer('sync_basalam_monitoring_handoff');
        $result = $this->request('/handoff', [
            'site_url' => untrailingslashit(get_site_url()),
            'basalam_token' => (string) syncBasalamSettings()->getSettings(SettingsConfig::TOKEN),
        ]);
        $url = is_array($result) ? ($result['url'] ?? '') : '';
        // The gateway controls this URL; still pin the redirect to the expected
        // landing path so a malformed response cannot expose a handoff ticket.
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || ($parts['host'] ?? '') !== 'hamsalam.ir'
                || ($parts['path'] ?? '') !== '/wp/monitoring'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            wp_die('ورود به پایشگر انجام نشد. اتصال غرفه، نسخه افزونه و دسترسی هاست را بررسی کنید و دوباره تلاش کنید.', '', ['response' => 503, 'back_link' => true]);
        }
        wp_redirect(esc_url_raw($url));
        exit;
    }

    private function request(string $path, ?array $data = null): ?array
    {
        $token = syncBasalamSettings()->getSettings(SettingsConfig::HAMSALAM_TOKEN);
        if (!is_string($token) || trim($token) === '') $token = (new FetchHamsalamToken())->fetch();
        if (!is_string($token) || trim($token) === '' || strpos($token, 'خطا') === 0) return null;
        $url = Endpoints::HAMSALAM_BASE . '/woo-plugin/monitoring' . $path;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $args = ['timeout' => $data === null ? 3 : 20, 'redirection' => 0,
                     'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']];
            if ($data !== null) $args['body'] = wp_json_encode($data);
            $response = $data === null ? wp_remote_get($url, $args) : wp_remote_post($url, $args);
            if (is_wp_error($response)) return null;
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 && $attempt === 0) {
                $token = (new FetchHamsalamToken())->fetch();
                if (!is_string($token) || strpos($token, 'خطا') === 0) return null;
                continue;
            }
            if ($code !== 200 && $code !== 201) return null;
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return is_array($body) ? $body : null;
        }
        return null;
    }
}
