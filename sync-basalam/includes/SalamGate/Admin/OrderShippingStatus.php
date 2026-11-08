<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\Order\BasalamOrderState;

defined('ABSPATH') || exit;

/** Cached shipment state for both Woo order tables; rendering never contacts or changes a parcel. */
final class OrderShippingStatus
{
    public const COLUMN = 'salam_gate_shipping';
    public const ACTION_COLUMN = 'salam_gate_action';

    public static function register(): void
    {
        add_filter('manage_edit-shop_order_columns', [self::class, 'columns'], 20);
        add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'columns'], 20);
        add_action('manage_shop_order_posts_custom_column', [self::class, 'render'], 20, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'render'], 20, 2);
        add_action('admin_footer', [self::class, 'renderListHost']);
        add_filter('wc_order_statuses', [self::class, 'orderStatusLabels'], 30);
    }

    public static function columns(array $columns): array
    {
        if (!\SyncBasalam\SalamGate\FeatureAvailability::isVisible()) return $columns;
        unset($columns[self::COLUMN], $columns[self::ACTION_COLUMN], $columns['basalam_order']);
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $key === 'order_status' ? 'وضعیت سفارش' : $label;
            if ($key === 'order_status') { $result[self::COLUMN] = 'وضعیت ارسال'; }
        }
        if (!isset($result[self::COLUMN])) { $result[self::COLUMN] = 'وضعیت ارسال'; }
        $result[self::ACTION_COLUMN] = 'ارسال مرسوله';
        return $result;
    }

    /** Presentation only on Woo's order lists; stored statuses and hooks stay intact. */
    public static function orderStatusLabels(array $statuses): array
    {
        if (!\SyncBasalam\SalamGate\FeatureAvailability::isVisible()) return $statuses;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)
            || ($screen->id === 'woocommerce_page_wc-orders' && !empty($_GET['action']))) { return $statuses; }
        $labels = ['wc-pending' => 'در انتظار پرداخت', 'wc-processing' => 'در حال انجام',
            'wc-on-hold' => 'در انتظار بررسی', 'wc-completed' => 'تکمیل‌شده', 'wc-cancelled' => 'لغوشده',
            'wc-refunded' => 'مستردشده', 'wc-failed' => 'ناموفق', 'wc-checkout-draft' => 'پیش‌نویس',
            'wc-bslm-rejected' => 'لغوشده در باسلام', 'wc-bslm-preparation' => 'در حال آماده‌سازی سفارش',
            'wc-bslm-wait-vendor' => 'در انتظار تأیید غرفه', 'wc-bslm-shipping' => 'ارسال‌شده در باسلام',
            'wc-bslm-completed' => 'تکمیل‌شده در باسلام'];
        foreach ($labels as $key => $label) { if (isset($statuses[$key])) { $statuses[$key] = $label; } }
        return $statuses;
    }

    /** Display the verified native state without changing Woo status or financial hooks. */
    public static function sourceOrderStatus(\WC_Order $order): ?array
    {
        if (!\SyncBasalam\SalamGate\Order\BasalamOrderContext::isBasalamOrder((int) $order->get_id())) return null;
        $source = BasalamOrderState::cached($order);
        if ($source === null) return null;
        $states = [
            3067 => ['label' => 'لغوشده در باسلام', 'tone' => 'cancelled'],
            3233 => ['label' => 'لغوشده در باسلام', 'tone' => 'cancelled'],
            3739 => ['label' => 'منتظر تأیید فروشنده', 'tone' => 'on-hold'],
            3237 => ['label' => 'در حال آماده‌سازی سفارش', 'tone' => 'processing'],
            3238 => ['label' => 'ارسال‌شده در باسلام', 'tone' => 'completed'],
            3195 => ['label' => 'تکمیل‌شده در باسلام', 'tone' => 'completed'],
        ];
        return ($states[$source['status_id']] ?? ['label' => 'نیاز به بررسی وضعیت باسلام', 'tone' => 'on-hold'])
            + ['description' => 'آخرین وضعیت دریافت‌شده از باسلام؛ وضعیت مالی ووکامرس تغییر نکرده است.'];
    }

    /** HPOS supplies WC_Order; the legacy screen supplies a post ID. */
    public static function render(string $column, $orderOrId): void
    {
        if (!\SyncBasalam\SalamGate\FeatureAvailability::isVisible()) return;
        if (!in_array($column, [self::COLUMN, self::ACTION_COLUMN], true)) { return; }
        $order = $orderOrId instanceof \WC_Order ? $orderOrId
            : (function_exists('wc_get_order') ? wc_get_order($orderOrId) : false);
        if (!$order instanceof \WC_Order) { echo '&mdash;'; return; }
        $action = OrderListAction::forOrder($order);
        if ($column === self::ACTION_COLUMN) {
            OrderListAction::render($order, $action);
            return;
        }
        $state = self::forOrder($order);
        // Keep the detailed source state for other consumers and the tooltip.
        // This table presents only the three requested shipping milestones.
        if ($action['mode'] === 'cancelled') {
            $state = self::state('لغوشده', 'cancelled', $action['description']);
            $path = '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>';
        } elseif (in_array($state['tone'], ['sent', 'delivered', 'returned'], true)) {
            $state = self::state('ارسال شده', 'sent', $state['description']);
            $path = '<path d="m5 12 4 4L19 6"/>';
        } elseif ($action['awaiting_payment']) {
            $state = self::state('در انتظار پرداخت', 'waiting', 'مرسوله ثبت شده است و هزینه ارسال هنوز پرداخت نشده است.');
            $path = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';
        } else {
            $state = self::state('ارسال نشده', 'pending', $state['description']);
            $path = '<path d="m12 3-9 5v9l9 5 9-5V8l-9-5ZM3 8l9 5 9-5M12 13v9M8 5l9 5"/>';
        }
        $mark = '<svg class="sg-order-shipping__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $path . '</svg>';
        echo '<span class="sg-order-shipping-statuses"><span class="sg-order-shipping sg-order-shipping--' . esc_attr($state['tone']) . '" title="'
            . esc_attr($state['description']) . '">' . $mark
            . esc_html($state['label']) . '</span>';
        echo '</span>';
    }

    /** @return array{label:string,tone:string,description:string} */
    public static function forOrder(\WC_Order $order): array
    {
        return \SyncBasalam\SalamGate\Presentation\OrderShippingPresenter::forOrder($order);
    }

    private static function state(string $label, string $tone, string $description): array
    {
        return compact('label', 'tone', 'description');
    }

    public static function enqueueStyles(): void
    {
        Assets\AssetRegistrar::enqueue('');
    }

    /** One reusable modal outside the Woo bulk-action form, on HPOS and CPT lists. */
    public static function renderListHost(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)
            || !current_user_can('manage_woocommerce') || !\SyncBasalam\SalamGate\FeatureAvailability::isVisible()) { return; }
        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] !== '') { return; }
        include dirname(__DIR__, 3) . '/templates/SalamGate/order-list-host.php';
    }
}
