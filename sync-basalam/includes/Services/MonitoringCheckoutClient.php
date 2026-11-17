<?php
namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;

defined('ABSPATH') || exit;

/** Private purchase intent survives a lost AJAX response, reload and login expiry. */
class MonitoringCheckoutClient extends MonitoringDashboardClient
{
    const TERMS = '2026-11-05-single-active-period';

    public function __construct()
    {
        parent::__construct();
        $this->deadline = microtime(true) + 40;
        $this->requestTimeout = 35;
    }

    private function intentKey(): string
    {
        // Refreshing access tokens must not lose a paid/pending purchase intent.
        $key = 'woosalam_purchase_' . hash('sha256', get_site_url() . '|' .
            SettingsManager::getSettings(SettingsConfig::VENDOR_ID));
        // Local integration adapters may isolate separate financial ledgers.
        // The default key and existing production purchase intents stay stable.
        $scope = (string) apply_filters('sync_basalam_monitoring_purchase_scope', '');
        return $scope === '' ? $key : $key . '_' . hash('sha256', $scope);
    }

    public static function uuid($value): bool
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value);
    }

    public static function validPaymentUrl($value): bool
    {
        if (!is_string($value) || preg_match('/[\\\\\x00-\x20]/', $value)) return false;
        $parts = wp_parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'apps.basalam.com'
            && (!isset($parts['port']) || $parts['port'] === 443)
            && !isset($parts['user']) && !isset($parts['pass'])
            && strpos($parts['path'] ?? '', '/pay/') === 0 && strlen($parts['path']) > 5;
    }

    public function prepare(): ?array
    {
        return $this->request('/prepare', ['site_url' => untrailingslashit(get_site_url()),
            'basalam_token' => (string) SettingsManager::getSettings(SettingsConfig::TOKEN)]);
    }

    public function preflight(string $plan): ?array
    {
        $site = $this->site();
        return $site ? $this->request('/sites/' . $site . '/preflight', ['plan_code' => $plan]) : null;
    }

    private function replaceIntent(string $name, array $previous, array $next): bool
    {
        global $wpdb;
        $changed = $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($next)],
            ['option_name' => $name, 'option_value' => maybe_serialize($previous)], ['%s'], ['%s', '%s']);
        wp_cache_delete($name, 'options');
        return $changed === 1;
    }

    public function checkout(string $plan, bool $accepted, ?string $replaceKey = null): ?array
    {
        if (!$accepted) { $this->error = 'purchase_terms'; return null; }
        $site = $this->site();
        if (!$site) return null;
        $name = $this->intentKey();
        $intent = get_option($name);
        if (!is_array($intent)) {
            // add_option is atomic across simultaneous admin tabs.
            add_option($name, ['request_id' => wp_generate_uuid4(), 'plan' => $plan,
                'replaces' => $replaceKey], '', false);
            $intent = get_option($name);
        }
        if (!is_array($intent) || !self::uuid($intent['request_id'] ?? null)) return null;
        $recoverableKey = $intent['payment_key'] ?? ($intent['previous_intent']['payment_key'] ?? null);
        if ($intent['plan'] !== $plan && !$recoverableKey && $replaceKey === null) {
            $lookup = $this->request('/sites/' . $site . '/checkout/' . $intent['request_id']);
            if (!$lookup || ($lookup['status'] ?? '') !== 'not_started') {
                $this->error = 'purchase_pending'; return null;
            }
            // Reuse the UUID if no payment was reserved. If an old request is
            // delayed, Gateway still reserves at most one payment for this UUID.
            $next = $intent;
            $next['plan'] = $plan;
            if (!$this->replaceIntent($name, $intent, $next)) { $this->error = 'purchase_pending'; return null; }
            $intent = $next;
        }
        if ($intent['plan'] !== $plan || (self::uuid($replaceKey) && ($intent['payment_key'] ?? null) === $replaceKey)) {
            // A stale tab must not overwrite a newer checkout. Gateway checks
            // ownership and unpaid state again while reserving its invoice.
            if (!self::uuid($replaceKey) || $recoverableKey !== $replaceKey) {
                $this->error = 'purchase_pending'; return null;
            }
            $next = ['request_id' => wp_generate_uuid4(), 'plan' => $plan, 'replaces' => $replaceKey,
                'previous_intent' => $intent];
            if (!$this->replaceIntent($name, $intent, $next)) { $this->error = 'purchase_pending'; return null; }
            $intent = $next;
        }
        $result = $this->request('/sites/' . $site . '/checkout', [
            'plan_code' => $plan, 'accepted_terms' => self::TERMS,
            'request_id' => $intent['request_id'],
            'replace_payment_key' => $intent['replaces'] ?? null,
            'return_url' => admin_url('admin.php?page=sync_basalam_monitoring'),
        ]);
        if ($result && self::uuid($result['idempotency_key'] ?? null)) {
            // Another already-pending payment may have been resumed by Gateway.
            $next = $intent;
            $next['payment_key'] = $result['idempotency_key'];
            unset($next['previous_intent']);
            if ($next !== $intent) $this->replaceIntent($name, $intent, $next);
        } elseif (in_array($this->error, ['not_ready', 'subscription_active'], true) && is_array($intent['previous_intent'] ?? null)) {
            // A definite 409 before reservation (e.g. a concurrent successful
            // callback) leaves the previous checkout available for recovery.
            // Unknown network outcomes retain the new durable request instead.
            $this->replaceIntent($name, $intent, $intent['previous_intent']);
        }
        return $result;
    }

    public function payment(?string $key = null, bool $reconcile = false): ?array
    {
        $site = $this->site();
        // A newly connected shop can select its first plan before prepare()
        // registers it. A saved/returned intent must still be reconciled.
        if ($site === 0 && !$key && !get_option($this->intentKey())) return ['status' => 'none'];
        if (!$site) return null;
        $intent = get_option($this->intentKey());
        $key = $key ?: ($intent['payment_key'] ?? null);
        if (!$key && is_array($intent) && self::uuid($intent['request_id'] ?? null)) {
            $result = $this->request('/sites/' . $site . '/checkout/' . $intent['request_id']);
            if (!$result || ($result['status'] ?? '') === 'not_started') {
                // An interrupted replacement can have no reserved row yet.
                // Keep that durable intent and recover the prior owned payment;
                // a delayed checkout still has its UUID and cannot double-charge.
                $previousKey = $intent['previous_intent']['payment_key'] ?? null;
                $notStarted = $result && ($result['status'] ?? '') === 'not_started';
                if (!$notStarted && $this->error !== 'not_found') return null;
                if (self::uuid($previousKey)) {
                    return $reconcile ? $this->request('/sites/' . $site . '/payment-check', ['key' => $previousKey])
                        : $this->request('/payment?site_id=' . $site . '&key=' . rawurlencode($previousKey));
                }
                if (!$notStarted) return null;
                $status = $this->request('/sites/' . $site);
                if (!$status) return null;
                return $status['payment'] ?? ['status' => 'not_started'];
            }
            $key = $result['idempotency_key'] ?? null;
            if (self::uuid($key)) {
                $next = $intent;
                $next['payment_key'] = $key;
                unset($next['previous_intent']);
                if ($next !== $intent) $this->replaceIntent($this->intentKey(), $intent, $next);
            }
            if (!$reconcile) return $result;
        }
        if (!$key) {
            $status = $this->request('/sites/' . $site);
            if (!$status) return null;
            return $status['payment'] ?? ['status' => 'none'];
        }
        if (!self::uuid($key)) { $this->error = 'invalid_response'; return null; }
        return $reconcile ? $this->request('/sites/' . $site . '/payment-check', ['key' => $key])
            : $this->request('/payment?site_id=' . $site . '&key=' . rawurlencode($key));
    }

    public function newIntent(): bool
    {
        $name = $this->intentKey();
        $previous = get_option($name);
        $result = $this->payment();
        if (!$result || !($result['status'] === 'failed' || !empty($result['activated']) || $result['status'] === 'none')) {
            $this->error = 'purchase_pending';
            return false;
        }
        if (!is_array($previous)) return true;
        $current = get_option($name);
        if (is_array($current) && ($current['request_id'] ?? null) === ($previous['request_id'] ?? null)) $previous = $current;
        // Do not delete a newer purchase created in another admin tab while
        // this request was awaiting the status response.
        global $wpdb;
        $deleted = $wpdb->delete($wpdb->options, ['option_name' => $name,
            'option_value' => maybe_serialize($previous)], ['%s', '%s']);
        wp_cache_delete($name, 'options');
        return $deleted === 1;
    }
}
