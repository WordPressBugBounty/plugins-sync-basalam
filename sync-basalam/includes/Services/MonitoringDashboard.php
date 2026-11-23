<?php
namespace SyncBasalam\Services;

use SyncBasalam\Admin\Pages\MonitoringPage;

defined('ABSPATH') || exit;

/** WordPress admin boundary for the seller's own monitoring dashboard. */
class MonitoringDashboard
{
    public function registerHooks(): void
    {
        add_action('wp_ajax_sync_basalam_monitoring', [$this, 'ajax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    public function enqueue(): void
    {
        if (($_GET['page'] ?? '') !== MonitoringPage::SLUG || !current_user_can('manage_options')) return;
        // The legacy chat widget embeds the Basalam token in its script tag.
        // This page's server-only credential boundary excludes that widget.
        wp_dequeue_script('basalam-chat-widget-script');
        foreach (['icons', 'tour', 'view', 'coverage', 'feedback'] as $asset) {
            wp_enqueue_script('sync-basalam-monitoring-' . $asset, syncBasalamPlugin()->assetsUrl('js/monitoring-' . $asset . '.js'),
                $asset === 'tour' ? ['sync-basalam-monitoring-icons'] : [],
                (string) filemtime(syncBasalamPlugin()->pluginPath() . '/assets/js/monitoring-' . $asset . '.js'), true);
        }
        wp_enqueue_script('sync-basalam-monitoring-dashboard', syncBasalamPlugin()->assetsUrl('js/monitoring-runtime.js'), ['sync-basalam-monitoring-icons', 'sync-basalam-monitoring-tour', 'sync-basalam-monitoring-view', 'sync-basalam-monitoring-coverage', 'sync-basalam-monitoring-feedback'],
            syncBasalamPlugin()->getVersion() . '-' . filemtime(syncBasalamPlugin()->pluginPath() . '/assets/js/monitoring-runtime.js'), true);
        wp_localize_script('sync-basalam-monitoring-dashboard', 'woosalamMonitoring', [
            'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sync_basalam_monitoring_dashboard'),
            'timezone' => wp_timezone_string(),
            'connectionUrl' => admin_url('admin.php?page=sync_basalam'),
            'supportUrl' => admin_url('admin.php?page=sync_basalam_tickets'),
            'initial' => MonitoringPresentation::status((new MonitoringDashboardClient())->cached()),
        ]);
    }

    public function ajax(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی مدیریتی لازم است.'], 403);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_send_json_error(['message' => 'درخواست معتبر نیست.'], 405);
        if (!check_ajax_referer('sync_basalam_monitoring_dashboard', 'nonce', false)) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است؛ صفحه را دوباره باز کنید.'], 403);
        }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key($_POST['operation']) : '';
        if (in_array($operation, ['manual_repair', 'settings', 'coverage_update', 'coverage_start_next', 'purchase_prepare', 'purchase_preflight', 'purchase_checkout', 'purchase_new'], true)
                && !MonitoringPresentation::matchesScope($_POST['scope'] ?? null)) {
            wp_send_json_error(['message' => 'اتصال غرفه تغییر کرده است؛ گزارش را دوباره دریافت کنید.', 'refresh' => true], 409);
        }
        if (in_array($operation, ['purchase_prepare', 'purchase_preflight', 'purchase_checkout', 'purchase_status', 'purchase_check', 'purchase_new'], true)) {
            $this->purchase($operation);
            return;
        }
        $client = new MonitoringDashboardClient();
        $cursor = $this->positiveId($_POST['cursor'] ?? '');
        if ($cursor === false) wp_send_json_error(['message' => 'نشانگر صفحه معتبر نیست.'], 422);
        if (in_array($operation, ['coverage', 'coverage_update', 'coverage_start_next'], true)) {
            nocache_headers();
            if ($operation === 'coverage') {
                $view = $_POST['view'] ?? 'selected';
                if (!is_string($view) || !in_array($view, ['selected', 'catalog', 'all', 'monitored', 'unmonitored'], true)) {
                    wp_send_json_error(['message' => 'فهرست پوشش معتبر نیست.'], 422);
                }
                $query = $_POST['query'] ?? '';
                if (!is_string($query) || mb_strlen($query) > 160) wp_send_json_error(['message' => 'جستجو معتبر نیست.'], 422);
                $data = $client->coverage($view, $cursor, sanitize_text_field(wp_unslash($query)));
            } elseif ($operation === 'coverage_start_next') {
                $revision = $_POST['revision'] ?? null;
                if (!is_string($revision) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision)) {
                    wp_send_json_error(['message' => 'وضعیت اشتراک معتبر نیست؛ گزارش را تازه کنید.'], 422);
                }
                $data = $client->startNextCoveragePeriod((int) $revision);
            } else {
                $add = $this->coverageIds($_POST['add'] ?? null);
                $remove = $this->coverageIds($_POST['remove'] ?? null);
                $revision = $_POST['revision'] ?? null;
                if ($add === null || $remove === null || (!$add && !$remove) || array_intersect($add, $remove)
                        || !is_string($revision) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision)) {
                    wp_send_json_error(['message' => 'انتخاب محصولات معتبر نیست؛ فهرست را تازه کنید.'], 422);
                }
                $data = $client->updateCoverage($add, $remove, (int) $revision);
            }
            if (!is_array($data) || !is_array($data['summary'] ?? null)) {
                wp_send_json_error(['message' => MonitoringDashboardView::error($client->error()),
                    'code' => $client->error(), 'refresh' => true], 409);
            }
            wp_send_json_success(MonitoringPresentation::coverage($data));
        }
        if ($operation === 'status' || $operation === 'settings') {
            if ($operation === 'settings') {
                if (!isset($_POST['enabled']) || !in_array($_POST['enabled'], ['0', '1'], true)) {
                    wp_send_json_error(['message' => 'وضعیت اصلاح معتبر نیست.'], 422);
                }
                $result = $client->settings($_POST['enabled'] === '1', ($_POST['accepted'] ?? '') === '1');
                if (!$result['data']) wp_send_json_error(['message' => MonitoringDashboardView::error($result['error']), 'refresh' => true], 409);
            } else {
                $result = $client->status(($_POST['refresh'] ?? '') === '1');
            }
            wp_send_json_success(['model' => MonitoringPresentation::status($result),
                'available' => $result['data'] !== null, 'stale' => $result['stale'], 'error' => $result['error']]);
        }
        if ($operation === 'manual_repair') {
            $product = $this->positiveId($_POST['product'] ?? '');
            $requestId = $_POST['request_id'] ?? '';
            if (!$product || get_post_type($product) !== 'product' || !current_user_can('edit_post', $product)
                    || !MonitoringCheckoutClient::uuid($requestId)) {
                wp_send_json_error(['message' => 'درخواست اصلاح محصول معتبر نیست.'], 422);
            }
            nocache_headers();
            $data = $client->repairProduct($product, $requestId);
            if (!$data) wp_send_json_error(['message' => 'نتیجهٔ اصلاح تأیید نشد؛ تاریخچه را تازه کنید.'], 409);
            wp_send_json_success(array_intersect_key($data, array_flip(['scan_id', 'status', 'reason'])));
        }
        if ($operation === 'issues') {
            $data = $client->issues($cursor);
            if (!is_array($data) || !is_array($data['data'] ?? null)) {
                wp_send_json_error(['message' => 'فهرست رسیدگی دریافت نشد.'], 503);
            }
            wp_send_json_success(['scope' => MonitoringPresentation::scope(), 'next_cursor' => $data['next_cursor'] ?? null,
                'data' => array_map([MonitoringPresentation::class, 'issue'], $data['data'])]);
        }
        if ($operation === 'history') {
            $data = $client->history($cursor);
            $part = 'history';
        } elseif ($operation === 'changes') {
            $scan = $this->positiveId($_POST['scan'] ?? '');
            if (!$scan) wp_send_json_error(['message' => 'شناسهٔ دور معتبر نیست.'], 422);
            $data = $client->changes($scan, $cursor);
            $part = 'changes';
        } elseif ($operation === 'plans') {
            $data = $client->plans();
            $part = 'plans';
        } else {
            wp_send_json_error(['message' => 'درخواست معتبر نیست.'], 422);
        }
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            wp_send_json_error(['message' => MonitoringDashboardView::error($client->error())], 503);
        }
        wp_send_json_success(MonitoringPresentation::page($part, $data));
    }

    private function positiveId($value)
    {
        if ($value === '') return null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value)) return false;
        return (int) $value;
    }

    private function coverageIds($value): ?array
    {
        if (!is_string($value) || strlen($value) > 4096 || substr(ltrim($value), 0, 1) !== '[') return null;
        $ids = json_decode(wp_unslash($value), true);
        if (!is_array($ids) || count($ids) > 100 || array_values($ids) !== $ids) return null;
        foreach ($ids as $id) if (!is_int($id) || $id < 1 || $id > 9999999999) return null;
        if (count(array_unique($ids)) !== count($ids)) return null;
        return $ids;
    }

    private function purchase(string $operation): void
    {
        nocache_headers();
        $client = new MonitoringCheckoutClient();
        $plan = $_POST['plan'] ?? '';
        if (in_array($operation, ['purchase_preflight', 'purchase_checkout'], true)
                && !in_array($plan, ['monitor_2000', 'monitor_3500', 'monitor_6000', 'monitor_5000', 'monitor_5000_plus', 'monitor_10000'], true)) {
            wp_send_json_error(['message' => 'پلن معتبر انتخاب کنید.'], 422);
        }
        $key = $_POST['key'] ?? null;
        if ($key !== null && !MonitoringCheckoutClient::uuid($key)) {
            wp_send_json_error(['message' => 'شناسهٔ پرداخت معتبر نیست.'], 422);
        }
        if ($operation === 'purchase_prepare') $data = $client->prepare();
        elseif ($operation === 'purchase_preflight') $data = $client->preflight($plan);
        elseif ($operation === 'purchase_checkout') $data = $client->checkout($plan, ($_POST['accepted'] ?? '') === '1', $key);
        elseif ($operation === 'purchase_new') $data = $client->newIntent() ? ['status' => 'none'] : null;
        else $data = $client->payment($key, $operation === 'purchase_check');
        // Only purchase_status/purchase_check read an existing payment result.
        $starting = !in_array($operation, ['purchase_status', 'purchase_check'], true);
        if (!$data) wp_send_json_error(['message' => MonitoringDashboardView::error($client->error(), $starting),
            'code' => $client->error()], 409);
        if (in_array($operation, ['purchase_prepare', 'purchase_preflight'], true)) {
            $public = array_intersect_key($data, array_flip(['ready', 'eligible', 'message', 'guidance', 'connected_units', 'product_limit']));
            if (isset($data['plan'])) $public['plan'] = MonitoringPresentation::plan($data['plan']);
            if (isset($data['coverage_preview'])) $public['coverage_preview'] = MonitoringPresentation::coverageSummary($data['coverage_preview']);
            wp_send_json_success($public);
        }
        wp_send_json_success(MonitoringPresentation::payment($data));
    }
}
