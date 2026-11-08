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
    public const ACTIONABLE_STATUSES = \SyncBasalam\SalamGate\Domain\OrderShippingPolicy::ACTIONABLE_STATUSES;
    public const WEBSITE_ACTIONABLE_STATUSES = \SyncBasalam\SalamGate\Domain\OrderShippingPolicy::WEBSITE_ACTIONABLE_STATUSES;

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);
        add_action('add_meta_boxes_shop_order', [self::class, 'registerMetaBox']);
    }

    /** A bounded authenticated source-state GET, independent of SalamGate activation. */
    public static function sourceState(): void
    {
        Ajax\ActionGuard::forController(self::class);
        $id = $_POST['order_id'] ?? null;
        $id = (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)))
            ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $order = $id !== false ? wc_get_order($id) : null;
        if (!$order instanceof \WC_Order || !BasalamOrderContext::isBasalamOrder((int) $id)) {
            \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::error(['message' => 'ارتباط این سفارش با سفارش باسلام تأیید نشد.'], 422);
        }
        try {
            $state = BasalamOrderState::refresh($order);
        } catch (SalamGateException $exception) {
            \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::error(['message' => $exception->firstMessage(), 'code' => 'source_state_unavailable',
                'shipping_allowed' => false]);
        } catch (\Throwable $exception) {
            \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::error(['message' => 'وضعیت سفارش باسلام دریافت نشد؛ قبل از ارسال، دوباره وضعیت را بررسی کنید.',
                'code' => 'source_state_unavailable', 'shipping_allowed' => false]);
        }
        \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::success(['source_state' => $state]);
    }

    /**
     * آیا سفارش در وضعیتی است که ارسال با سلام‌گیت معنی دارد؟
     */
    public static function isActionableStatus(string $market, string $status): bool
    {
        return \SyncBasalam\SalamGate\Domain\OrderShippingPolicy::isActionableStatus($market, $status);
    }

    public static function registerMetaBox(): void
    {
        if (!current_user_can('manage_woocommerce') || !FeatureAvailability::isVisible()) return;

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
        if (!FeatureAvailability::isVisible()) return;
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
