<?php
namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

/** Explicit browser contract: never serialize arbitrary Gateway response fields. */
class MonitoringPresentation
{
    private static function pick(array $source, string $keys): array
    {
        return array_intersect_key($source, array_flip(explode(' ', $keys)));
    }

    private static function object($value): array { return is_array($value) ? $value : []; }

    public static function plan($value): array
    {
        return self::pick(self::object($value), 'code title product_limit price_toman price_irr period_days probe_interval_minutes terms_version starts_at can_start_early');
    }

    public static function summary($value): array
    {
        return self::pick(self::object($value), 'processed_products checked_products mismatched_products updated_products healthy_products failed_products skipped_products needs_attention');
    }

    public static function scan($value): array
    {
        $source = self::object($value);
        return self::pick($source, 'id started_at completed_at status kind error recorded_changes comparison_products before_match_percent after_match_percent') +
            ['summary' => self::summary($source['summary'] ?? [])];
    }

    public static function status(array $result): array
    {
        $data = self::object($result['data'] ?? []);
        $sub = self::object($data['subscription'] ?? []);
        $products = self::object($sub['products'] ?? []);
        $history = self::object($products['history'] ?? []);
        $public = self::pick($sub, 'active health reason message guidance expires_at checked_at connected_units capacity_exceeded capacity_unit');
        $public['plan'] = self::plan($sub['plan'] ?? []);
        $public['next_plan'] = isset($sub['next_plan']) ? self::plan($sub['next_plan']) : null;
        $public['coverage'] = self::coverageSummary($sub['coverage'] ?? []);
        $public['products'] = self::pick($products, 'auto_repair_enabled verified scanning scan_started_at scan_completed_at oldest_checked_at scan_error') + [
            'summary' => isset($products['summary']) ? self::summary($products['summary']) : null,
            'benefits' => self::pick(self::object($products['benefits'] ?? []), 'correction_events restored_products price_corrections stock_corrections underpriced_gap_irr overpriced_gap_irr last_correction_at'),
            'history' => ['aggregate' => self::pick(self::object($history['aggregate'] ?? []), 'total_scans completed_scans running_scans failed_scans since processed_products checked_products updated_products mismatched_products healthy_products failed_products skipped_products needs_attention comparison_products before_match_percent after_match_percent')],
            'recent_issues' => array_map([self::class, 'issue'], array_slice(self::object($products['recent_issues'] ?? []), 0, 20)),
        ];
        return ['site_url' => get_site_url(), 'available' => is_array($result['data'] ?? null), 'connected' => !empty($result['connected']),
            'stale' => !empty($result['stale']), 'error' => $result['error'] ?? '',
            'fetched_at' => !empty($result['fetched_at']) ? gmdate('c', $result['fetched_at']) : null,
            // Opaque scope changes on vendor/site/credential changes, never exposing credentials.
            'scope' => self::scope(), 'onboarding_scope' => self::scope(false), 'subscription' => $public];
    }

    public static function matchesScope($scope): bool
    {
        return is_string($scope) && hash_equals(self::scope(), $scope);
    }

    public static function coverageSummary($value): array
    {
        return self::pick(self::object($value), 'capacity_unit product_limit connected_products selected_products available_capacity uncovered_products revision selection_method preparing active');
    }

    public static function coverage(array $data): array
    {
        return ['scope' => self::scope(), 'summary' => self::coverageSummary($data['summary'] ?? []),
            'has_more' => ($data['has_more'] ?? false) === true,
            'next_cursor' => isset($data['next_cursor']) && is_int($data['next_cursor']) && $data['next_cursor'] > 0 ? $data['next_cursor'] : null,
            'items' => array_map(static function ($item) {
                return self::pick(self::object($item), 'woo_product_id basalam_product_id title image_url eligible reason selected selection_reason excluded');
            }, array_slice(self::object($data['items'] ?? []), 0, 20))];
    }

    public static function scope(bool $credentials = true): string
    {
        $settings = \SyncBasalam\Admin\Settings\SettingsManager::getSettings();
        $config = \SyncBasalam\Admin\Settings\SettingsConfig::class;
        $identity = get_site_url() . '|' . ($settings[$config::VENDOR_ID] ?? '') . '|' . get_current_user_id();
        if ($credentials) $identity .= '|' . ($settings[$config::TOKEN] ?? '') . '|' . ($settings[$config::REFRESH_TOKEN] ?? '');
        return hash_hmac('sha256', $identity, wp_salt('nonce'));
    }

    public static function issue($value): array
    {
        $item = self::object($value);
        $out = self::pick($item, 'woo_product_id title reason outcome checked_at targets_total targets_truncated');
        foreach (['desired', 'observed'] as $key) {
            $out[$key] = array_map(static function ($v) { return self::pick(self::object($v), 'variation_id primary_price stock'); }, array_slice(self::object($item[$key] ?? []), 0, 10));
        }
        $id = $item['woo_product_id'] ?? null;
        if (is_int($id) && $id > 0 && get_post_type($id) === 'product' && current_user_can('edit_post', $id)) {
            $out['edit_url'] = get_edit_post_link($id, 'raw');
        }
        return $out;
    }

    public static function page(string $part, array $data): array
    {
        $out = self::pick($data, 'next_cursor scan_id started_at total_changes total_products summary_updated_products currency');
        $out['scope'] = self::scope();
        $out['data'] = array_map(static function ($row) use ($part) {
            if ($part === 'plans') return self::plan($row);
            if ($part === 'history') return self::scan($row);
            $row = self::object($row);
            return self::pick($row, 'id woo_product_id basalam_product_id variation_id title title_is_snapshot verified_at') + [
                'fields' => array_map(static function ($field) { return self::pick(self::object($field), 'field source before after'); }, self::object($row['fields'] ?? [])),
            ];
        }, array_slice($data['data'], 0, 100));
        return $out;
    }

    public static function payment(array $data): array
    {
        $out = self::pick($data, 'status activated scheduled idempotency_key plan_code amount customer_fee total_amount expires_at expired sandbox needs_review review_reason processing last_checked_at next_check_at superseded replaceable replacement_key purchase_blocked failure_code failure_message');
        $out['scope'] = self::scope();
        $out['payment_url'] = MonitoringCheckoutClient::validPaymentUrl($data['payment_url'] ?? null) ? $data['payment_url'] : null;
        $out['subscription'] = self::pick(self::object($data['subscription'] ?? []), 'status expires_at starts_at');
        return $out;
    }
}
