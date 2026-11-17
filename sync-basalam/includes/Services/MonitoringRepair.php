<?php
namespace SyncBasalam\Services;

use SyncBasalam\Services\Products\UpdateSingleProductService;

defined('ABSPATH') || exit;

/** Execute one scoped command using fresh WooCommerce calculations. */
class MonitoringRepair
{
    private $source;
    private $authority;
    private $updater;

    public function __construct($source = null, $authority = null, $updater = null)
    {
        $this->source = $source ?: new MonitoringProducts();
        $this->authority = $authority ?: new MonitoringClient();
        $this->updater = $updater;
    }

    public function execute(array $command)
    {
        $start = microtime(true);
        $result = $this->perform($command);
        try {
            $error = is_wp_error($result);
            $reason = $error && method_exists($result, 'get_error_code') ? $result->get_error_code() : ($error ? 'monitoring_update_failed' : 'ok');
            $event = MonitoringDiagnostics::event([
                'command_id' => $command['command_id'] ?? '', 'request_id' => $command['request_id'] ?? '',
                'scan_id' => $command['scan_id'] ?? null, 'duration_ms' => (microtime(true) - $start) * 1000,
                'outcome' => $error ? ($reason === 'monitoring_update_failed' ? 'unknown' : 'rejected') : 'accepted',
                'reason_code' => $reason,
            ]);
            if (!$error) $result['diagnostic'] = $event;
            elseif (method_exists($result, 'add_data')) {
                $data = $result->get_error_data();
                $result->add_data(array_merge(is_array($data) ? $data : [], ['diagnostic' => $event]));
            }
        } catch (\Throwable $ignored) {}
        return $result;
    }

    private function perform(array $command)
    {
        global $wpdb;
        $id = (int) ($command['woo_product_id'] ?? 0);
        if ($id <= 0 || !preg_match('/^[a-f0-9]{32}$/', (string) ($command['command_id'] ?? ''))
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($command['revision'] ?? ''))
                || strlen((string) ($command['grant'] ?? '')) < 40 || strlen($command['grant']) > 100) {
            return new \WP_Error('monitoring_invalid_command', 'درخواست اصلاح معتبر نیست.', ['status' => 422]);
        }
        $lock = 'woosalam_monitoring_' . $id;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return new \WP_Error('monitoring_busy', 'اصلاح محصول در حال انجام است.', ['status' => 409]);
        }
        try {
            $proof = $this->authority->repairPermission($command['grant']);
            if (!$proof || ($proof['authorized'] ?? false) !== true || ($proof['expires_at'] ?? 0) <= time()
                    || ($proof['coverage_selected'] ?? false) !== true
                    || ($proof['capacity_unit'] ?? '') !== 'parent_product'
                    || !is_string($proof['coverage_member'] ?? null)
                    || !preg_match('/^[a-f0-9]{32}$/D', $proof['coverage_member'])
                    || ($proof['site_url'] ?? '') !== untrailingslashit(get_site_url())
                    || ($proof['woo_product_id'] ?? 0) !== $id
                    || ($proof['basalam_product_id'] ?? 0) !== (int) ($command['basalam_product_id'] ?? 0)
                    || ($proof['command_id'] ?? '') !== $command['command_id']
                    || ($proof['revision'] ?? '') !== $command['revision']) {
                return new \WP_Error('monitoring_permission_inactive', 'مجوز اصلاح فعال یا معتبر نیست.', ['status' => 403]);
            }
            clean_post_cache($id);
            $page = $this->source->page(0, $id);
            $item = $page['items'][0] ?? [];
            if (empty($page['can_update']) || $page['vendor_id'] !== $proof['vendor_id']
                    || ($page['capacity_unit'] ?? '') !== 'parent_product'
                    || ($page['coverage_supported'] ?? false) !== true || empty($item['eligible'])
                    || $item['revision'] !== $proof['revision']
                    || $item['basalam_product_id'] !== $proof['basalam_product_id']) {
                return new \WP_Error('monitoring_source_changed', 'اطلاعات یا مجوز محصول تغییر کرده است.', ['status' => 409]);
            }
            $target = null;
            foreach ($item['targets'] as $candidate) {
                if ($candidate['variation_id'] === $proof['variation_id']) { $target = $candidate; break; }
            }
            $fields = $proof['fields'] ?? [];
            if (!$target || !$fields || array_diff($fields, ['primary_price', 'stock'])
                    || array_diff($fields, array_keys($target))) {
                return new \WP_Error('monitoring_mapping_changed', 'نگاشت محصول معتبر نیست.', ['status' => 409]);
            }
            $payload = array_intersect_key($target, array_flip(array_merge(['variation_id'], $fields)));
            if ($proof['expires_at'] <= time()) {
                return new \WP_Error('monitoring_permission_expired', 'مجوز اصلاح پایان یافته است.', ['status' => 403]);
            }
            $updater = $this->updater ?: new UpdateSingleProductService();
            $updater->updateMonitoringTarget($id, $item['basalam_product_id'], $payload);
            return ['success' => true, 'command_id' => $command['command_id'], 'woo_product_id' => $id];
        } catch (\Throwable $error) {
            if ((int) $error->getCode() === 429) {
                return new \WP_Error('monitoring_rate_limited', 'باسلام درخواست‌ها را محدود کرده است؛ اصلاح با فاصله دوباره بررسی می‌شود.', ['status' => 429]);
            }
            return new \WP_Error('monitoring_update_failed', 'همگام‌سازی محصول تکمیل نشد؛ نتیجه در پایش بعدی بررسی می‌شود.', ['status' => 503]);
        } finally {
            try { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } catch (\Throwable $ignored) {}
        }
    }
}
