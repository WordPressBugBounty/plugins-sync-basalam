<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderState;
use SyncBasalam\SalamGate\SalamGateException;

if (! defined('ABSPATH')) exit;

/**
 * متاباکس سلام‌گیت روی صفحه ویرایش سفارش (HPOS + legacy با هوک‌های دوگانه).
 * سفارش‌های بسالم و سفارش‌های سایت خودت (پارت ۴).
 */
class OrderMetabox
{
    // The base importer can intentionally store Basalam orders as Woo "processing".
    // This controls visibility only; fresh original-order eligibility is checked before sending.
    public const ACTIONABLE_STATUSES = ['bslm-wait-vendor', 'bslm-preparation', 'processing'];
    public const WEBSITE_ACTIONABLE_STATUSES = ['on-hold', 'processing', 'completed'];

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);
        add_action('add_meta_boxes_shop_order', [self::class, 'registerMetaBox']);
        add_action('wp_ajax_salam_gate_basalam_order_state', [self::class, 'sourceState']);
    }

    /** A bounded authenticated source-state GET, independent of SalamGate activation. */
    public static function sourceState(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'اجازه بررسی وضعیت این سفارش را ندارید.'], 403);
        }
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است؛ صفحه را تازه‌سازی کنید.'], 403);
        }
        $id = $_POST['order_id'] ?? null;
        $id = (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)))
            ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $order = $id !== false ? wc_get_order($id) : null;
        if (!$order instanceof \WC_Order || !BasalamOrderContext::isBasalamOrder((int) $id)) {
            wp_send_json_error(['message' => 'ارتباط این سفارش با سفارش باسلام تأیید نشد.'], 422);
        }
        try {
            require_once dirname(__DIR__) . '/Order/BasalamOrderState.php';
            $state = BasalamOrderState::refresh($order);
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => $exception->firstMessage(), 'code' => 'source_state_unavailable',
                'shipping_allowed' => false]);
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => 'وضعیت سفارش باسلام دریافت نشد؛ قبل از ارسال، دوباره وضعیت را بررسی کنید.',
                'code' => 'source_state_unavailable', 'shipping_allowed' => false]);
        }
        wp_send_json_success(['source_state' => $state]);
    }

    /**
     * آیا سفارش در وضعیتی است که ارسال با سلام‌گیت معنی دارد؟
     */
    public static function isActionableStatus(string $market, string $status): bool
    {
        $statuses = $market === OrderContext::MARKET_WEBSITE
            ? self::WEBSITE_ACTIONABLE_STATUSES
            : self::ACTIONABLE_STATUSES;

        return in_array($status, $statuses, true);
    }

    public static function registerMetaBox(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        // HPOS از id و CPT قدیمی از post استفاده می‌کند (الگوی OrderMetaBox پایه)
        $orderId = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$orderId) $orderId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$orderId) return;

        $order = wc_get_order($orderId);
        if (!$order) return;

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        $market = OrderContext::marketFor($orderId);
        $isActionable = self::isActionableStatus($market, $order->get_status());

        // خارج از وضعیت‌های قابل‌اقدام فقط وقتی متاباکس معنا دارد که مرسوله وجود داشته باشد
        // A parent may already be shipped while one independently preparing child remains.
        if (!$parcelId && !$isActionable && $market !== OrderContext::MARKET_BASALAM) return;

        add_meta_box(
            'salam_gate_order_box',
            'سلام‌گیت',
            [self::class, 'render'],
            ['woocommerce_page_wc-orders', 'shop_order'],
            'side',
            'high'
        );
    }

    /**
     * HPOS آبجکت WC_Order و CPT قدیمی WP_Post پاس می‌دهد.
     */
    public static function render($postOrOrder): void
    {
        if (is_object($postOrOrder) && method_exists($postOrOrder, 'get_id')) {
            $orderId = (int) $postOrOrder->get_id();
        } else {
            $orderId = (int) ($postOrOrder->ID ?? 0);
        }

        if ($orderId <= 0) return;

        $template = __DIR__ . '/../../../templates/SalamGate/order-metabox.php';
        if (file_exists($template)) {
            require_once $template;
        }
    }
}
