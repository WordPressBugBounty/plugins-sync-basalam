<?php
namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Config\Endpoints;

defined('ABSPATH') || exit;

/** Bounded owner-authenticated reads. No Gateway credential reaches the browser. */
class MonitoringDashboardClient
{
    const FRESH_SECONDS = 30;
    const LAST_GOOD_SECONDS = 3600;
    const REPAIR_TERMS = '2026-10-12-plugin-sync';
    protected $deadline;
    protected $requestTimeout = 2.5;
    private $token;
    protected $error = 'unavailable';
    private $unauthorized = false;

    public function __construct()
    {
        $this->deadline = microtime(true) + 5;
        $this->token = (string) SettingsManager::getSettings(SettingsConfig::HAMSALAM_TOKEN);
    }

    private function key(string $suffix): string
    {
        $settings = SettingsManager::getSettings();
        $identity = [untrailingslashit(get_site_url()), $settings[SettingsConfig::VENDOR_ID] ?? '',
            $settings[SettingsConfig::TOKEN] ?? '', $settings[SettingsConfig::REFRESH_TOKEN] ?? '', $this->token];
        return 'woosalam_dashboard_' . hash('sha256', wp_json_encode($identity)) . '_' . $suffix;
    }

    public function cached(): array
    {
        if (!(bool) SettingsManager::getSettings(SettingsConfig::TOKEN)) return $this->result(null, 'disconnected');
        $record = get_transient($this->key('status'));
        if (!is_array($record) || !isset($record['data'], $record['fetched_at'])) return $this->result(null);
        return $this->result($record['data'], time() - $record['fetched_at'] > self::FRESH_SECONDS ? 'stale' : '', $record['fetched_at']);
    }

    private function result(?array $data, string $error = '', ?int $fetchedAt = null): array
    {
        return ['data' => $data, 'error' => $error, 'fetched_at' => $fetchedAt,
            'stale' => $data !== null && $error !== '', 'connected' => (bool) SettingsManager::getSettings(SettingsConfig::TOKEN)];
    }

    public function status(bool $refresh = false): array
    {
        $cached = $this->cached();
        if (!$cached['connected'] || (!$refresh && $cached['data'] && !$cached['stale'])) return $cached;
        // A short outage backoff also bounds repeated refreshes across admin tabs.
        if (get_transient($this->key('backoff'))) return $this->result($cached['data'], 'unavailable', $cached['fetched_at']);
        $site = $this->site();
        if ($site === 0) {
            $data = ['site_id' => null, 'site_url' => untrailingslashit(get_site_url()),
                'subscription' => ['active' => false, 'health' => 'unknown', 'products' => [],
                    'message' => 'اشتراک پایشگر برای این فروشگاه فعال نشده است.']];
        } else {
            $data = $site ? $this->request('/sites/' . $site) : null;
            if ($data && (!$this->validStatus($data) || (int) ($data['site_id'] ?? 0) !== $site)) {
                $data = null;
                $this->unauthorized = true;
                $this->error = 'connection_changed';
            }
        }
        if ($data) {
            $now = time();
            set_transient($this->key('status'), ['data' => $data, 'fetched_at' => $now], self::LAST_GOOD_SECONDS);
            delete_transient($this->key('backoff'));
            return $this->result($data, '', $now);
        }
        if ($this->unauthorized) {
            delete_transient($this->key('status'));
            delete_transient($this->key('site'));
            return $this->result(null, $this->error);
        }
        set_transient($this->key('backoff'), true, 10);
        return $this->result($cached['data'], $this->error, $cached['fetched_at']);
    }

    private function validStatus(array $data): bool
    {
        return isset($data['subscription']) && is_array($data['subscription'])
            && is_bool($data['subscription']['active'] ?? null)
            && (!$data['subscription']['active'] ||
                (is_array($data['subscription']['plan'] ?? null) && is_string($data['subscription']['expires_at'] ?? null)
                 && strtotime($data['subscription']['expires_at']) !== false))
            && untrailingslashit((string) ($data['site_url'] ?? '')) === untrailingslashit(get_site_url());
    }

    /** Resolve this site's id server-side. Never accept a browser-supplied site id. */
    protected function site(): ?int
    {
        if (!(bool) SettingsManager::getSettings(SettingsConfig::TOKEN)) { $this->error = 'disconnected'; return null; }
        $cached = get_transient($this->key('site'));
        if (is_int($cached) && $cached > 0) return $cached;
        $response = $this->request('/sites');
        if (!isset($response['data']) || !is_array($response['data'])) return null;
        foreach ($response['data'] as $site) {
            if (is_array($site) && untrailingslashit((string) ($site['site_url'] ?? '')) === untrailingslashit(get_site_url())
                    && isset($site['id']) && is_int($site['id']) && $site['id'] > 0) {
                set_transient($this->key('site'), $site['id'], self::FRESH_SECONDS);
                return $site['id'];
            }
        }
        return 0;
    }

    public function history(?int $cursor = null): ?array
    {
        $site = $this->site();
        if (!$site) return null;
        return $this->request('/sites/' . $site . '/scans' . ($cursor ? '?before_id=' . $cursor : ''));
    }

    public function issues(?int $cursor = null): ?array
    {
        $site = $this->site();
        return $site ? $this->request('/sites/' . $site . '/issues' . ($cursor ? '?before_id=' . $cursor : '')) : null;
    }

    public function repairProduct(int $product, string $requestId): ?array
    {
        $this->deadline = microtime(true) + 40;
        $this->requestTimeout = 35;
        $site = $this->site();
        if (!$site) return null;
        delete_transient($this->key('status'));
        return $this->request('/sites/' . $site . '/repair-product', ['product_id' => $product, 'request_id' => $requestId]);
    }

    public function changes(int $scan, ?int $cursor = null): ?array
    {
        $site = $this->site();
        if (!$site) return null;
        return $this->request('/sites/' . $site . '/scans/' . $scan . '/changes' . ($cursor ? '?before_id=' . $cursor : ''));
    }

    public function plans(): ?array
    {
        // Price/capacity/duration come only from the server-authoritative plan API.
        return $this->request('/plans');
    }

    public function coverage(string $view = 'selected', ?int $after = null, string $query = ''): ?array
    {
        if (mb_strlen($query) > 160 || !in_array($view, ['selected', 'catalog', 'all', 'monitored', 'unmonitored'], true) || ($after !== null && $after < 1)) {
            $this->error = 'coverage_invalid';
            return null;
        }
        $site = $this->site();
        if (!$site) return null;
        return $this->request('/sites/' . $site . '/coverage?view=' . $view . '&limit=20&after_id=' . ($after ?? 0) . '&query=' . rawurlencode($query));
    }

    /** One request makes a replacement atomic, including when no free slot remains. */
    public function updateCoverage(array $add, array $remove, int $revision): ?array
    {
        $site = $this->site();
        if (!$site) return null;
        delete_transient($this->key('status'));
        delete_transient($this->key('backoff'));
        // An uncertain mutation is reconciled by reading coverage; never retried here.
        return $this->request('/sites/' . $site . '/coverage', [
            'add' => $add, 'remove' => $remove, 'revision' => $revision,
        ]);
    }

    public function startNextCoveragePeriod(int $revision): ?array
    {
        $site = $this->site();
        if (!$site) return null;
        delete_transient($this->key('status'));
        delete_transient($this->key('backoff'));
        return $this->request('/sites/' . $site . '/coverage/start-next-period', ['revision' => $revision]);
    }

    public function settings(bool $enabled, bool $accepted): array
    {
        if ($enabled && !$accepted) return $this->result(null, 'terms_required');
        $site = $this->site();
        if (!$site) return $this->result(null, $this->error);
        delete_transient($this->key('status'));
        delete_transient($this->key('backoff'));
        // Never retry a mutation after a timeout or an ambiguous provider response.
        $data = $this->request('/sites/' . $site . '/settings', [
            'auto_repair_enabled' => $enabled,
            'accepted_terms' => $enabled ? self::REPAIR_TERMS : null,
        ]);
        if (!$data || !$this->validStatus($data) || (int) ($data['site_id'] ?? 0) !== $site) {
            return $this->result(null, $this->unauthorized ? 'authorization' : 'settings_unconfirmed');
        }
        $now = time();
        set_transient($this->key('status'), ['data' => $data, 'fetched_at' => $now], self::LAST_GOOD_SECONDS);
        return $this->result($data, '', $now);
    }

    public function error(): string { return $this->error; }

    private function args(): ?array
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining < .15) { $this->error = 'timeout'; return null; }
        return ['timeout' => min($this->requestTimeout, $remaining), 'redirection' => 0, 'limit_response_size' => 524288,
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json']];
    }

    private function refreshToken(): bool
    {
        $args = $this->args();
        if (!$args) return false;
        $args['headers'] = ['Content-Type' => 'application/json'];
        $args['body'] = wp_json_encode(['basalam_token' => (string) SettingsManager::getSettings(SettingsConfig::TOKEN)]);
        $response = wp_remote_post(Endpoints::HAMSALAM_AUTH_TOKEN, $args);
        if (is_wp_error($response)) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_string($data['access_token'] ?? null)
                || trim($data['access_token']) === '') return false;
        $this->token = $data['access_token'];
        SettingsManager::updateSettings([SettingsConfig::HAMSALAM_TOKEN => $this->token]);
        return true;
    }

    protected function request(string $path, ?array $body = null): ?array
    {
        if ($path !== '/plans' && !$this->token && !$this->refreshToken()) {
            $this->error = 'authorization'; $this->unauthorized = true; return null;
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $args = $this->args();
            if (!$args) return null;
            if ($body !== null) $args['body'] = wp_json_encode($body);
            $url = Endpoints::HAMSALAM_BASE . '/woo-plugin/monitoring' . $path;
            $response = $body === null ? wp_remote_get($url, $args) : wp_remote_post($url, $args);
            if (is_wp_error($response)) { $this->error = 'timeout'; return null; }
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 || $code === 403) {
                delete_transient($this->key('status'));
                delete_transient($this->key('site'));
                $this->error = 'authorization'; $this->unauthorized = true;
                if ($body === null && $code === 401 && $attempt === 0 && $this->refreshToken()) {
                    // A new credential must never revive data cached under a
                    // rejected identity when the following request fails.
                    continue;
                }
                return null;
            }
            if ($code !== 200) {
                $this->error = $code === 404 ? 'not_found' : ($code === 409 ? 'not_ready' : 'unavailable');
                $failure = json_decode(wp_remote_retrieve_body($response), true);
                if ($code === 409 && ($failure['detail']['code'] ?? null) === 'subscription_active') {
                    $this->error = 'subscription_active';
                }
                if (($failure['detail']['code'] ?? null) === 'monitoring_billing_unavailable') {
                    $this->error = 'monitoring_billing_unavailable';
                }
                // Gateway's application-level schema handler returns 400;
                // service validation uses 422. Both reject this purchase request.
                if (in_array($code, [400, 422], true) && preg_match('~/sites/[1-9][0-9]*/checkout$~D', $path)) {
                    $this->error = 'purchase_request_invalid';
                }
                if (strpos($path, '/coverage') !== false) {
                    $failure = json_decode(wp_remote_retrieve_body($response), true);
                    $detail = is_array($failure['detail'] ?? null) ? $failure['detail'] : [];
                    $reason = $detail['code'] ?? $failure['code'] ?? null;
                    if (is_string($reason) && in_array($reason, ['coverage_conflict', 'coverage_full',
                            'coverage_invalid', 'coverage_inactive', 'coverage_product_unavailable',
                            'coverage_upgrade_required', 'coverage_not_ready'], true)) $this->error = $reason;
                    elseif ($code === 409) $this->error = 'coverage_conflict';
                }
                if ($code === 404) {
                    delete_transient($this->key('site'));
                    delete_transient($this->key('status'));
                    $this->unauthorized = true;
                }
                return null;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) { $this->error = 'invalid_response'; return null; }
            return $data;
        }
        return null;
    }
}
