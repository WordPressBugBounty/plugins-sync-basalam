<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\Paths;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
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
        add_action('admin_enqueue_scripts', [self::class, 'enqueueStyles']);
        add_action('admin_footer', [self::class, 'renderListHost']);
        add_filter('wc_order_statuses', [self::class, 'orderStatusLabels'], 30);
    }

    public static function columns(array $columns): array
    {
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
        // Scoped Basalam parcels can cover only part of a parent order. Never let
        // one child's receipt, or copied legacy metadata, mark the entire order sent.
        if (class_exists(BasalamParcelOrder::class) && BasalamParcelOrder::hasScopes($order)) {
            return self::scopedState($order);
        }
        if (!class_exists(BasalamParcelOrder::class)) {
            foreach ($order->get_meta_data() as $meta) {
                if (preg_match('/^_salam_gate_bp_[1-9][0-9]*_/', (string) ($meta->get_data()['key'] ?? ''))) {
                    return self::review('بررسی مرسوله‌های مستقل این سفارش به نسخه جدید افزونه نیاز دارد.');
                }
            }
        }

        $parcelId = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $rawStatus = $order->get_meta('_salam_gate_status');
        $status = self::positiveId($rawStatus);
        if ($parcelId !== null && $status !== null && ParcelStatuses::isValid($status)) {
            return self::parcelState($status);
        }
        if (!self::emptyMeta($rawStatus) && ($parcelId === null || $status === null)) {
            return self::review('ارتباط وضعیت ذخیره‌شده با مرسوله باید بررسی شود.');
        }

        // These are explicit shipment statuses synchronized from the native order,
        // unlike Woo's generic "completed" status (which also covers virtual goods).
        if (in_array($order->get_status(), ['bslm-shipping', 'bslm-completed'], true)) {
            return self::state('ارسال شده', 'sent', 'ارسال این سفارش در وضعیت سفارش باسلام ثبت شده است.');
        }
        if ($parcelId !== null) {
            if ($status !== null || !self::emptyMeta($order->get_meta('_salam_gate_tracking_code'))) {
                return self::review('وضعیت تحویل مرسوله به پست هنوز تأیید نشده است؛ بارکد به‌تنهایی نشانه ارسال نیست.');
            }
            return self::state('ارسال نشده', 'pending', $order->get_meta('_salam_gate_pay_unknown')
                ? 'نتیجه پرداخت این مرسوله در حال بررسی است.'
                : 'مرسوله ثبت شده است؛ تحویل آن به پست هنوز تأیید نشده است.');
        }
        return self::state('ارسال نشده', 'pending', 'ارسالی برای این سفارش ثبت نشده است.');
    }

    private static function parcelState(int $status): array
    {
        switch ($status) {
            case ParcelStatuses::SHIPPED:
            case ParcelStatuses::HANDED_TO_POSTMAN:
                return self::state('ارسال شده', 'sent', ParcelStatuses::title($status));
            case ParcelStatuses::DELIVERED:
                return self::state('تحویل شده', 'delivered', 'مرسوله به گیرنده تحویل داده شده است.');
            case ParcelStatuses::RETURNED_TO_SENDER:
                return self::state('مرجوع شده', 'returned', 'مرسوله ارسال شده و به فرستنده برگشته است.');
            case ParcelStatuses::VOIDED:
                return self::state('ارسال لغو شده', 'cancelled', 'مرسوله ابطال شده است.');
            case ParcelStatuses::POSTAL_BARCODE:
                return self::state('ارسال نشده', 'ready', 'بارکد صادر شده است؛ مرسوله هنوز به پست تحویل نشده است.');
            default:
                return self::state('ارسال نشده', 'pending', 'مرسوله در انتظار تکمیل پرداخت و تحویل به پست است.');
        }
    }

    private static function scopedState(\WC_Order $order): array
    {
        $entries = BasalamParcelOrder::entries($order);
        // A broken or stale child identity cannot disappear behind a valid sibling.
        foreach ($order->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_/', (string) ($data['key'] ?? ''), $match)
                && !isset($entries[$match[1]])) {
                return self::review('ارتباط یکی از مرسوله‌های این سفارش نیاز به بررسی دارد.');
            }
        }
        $expected = [];
        foreach ($order->get_items() as $item) {
            $expected[(int) $item->get_id()] = (int) $item->get_quantity();
        }
        $sent = [];
        $statuses = [];
        $uncertain = false;
        foreach ($entries as $scope) {
            $rawStatus = $scope->get_meta('_salam_gate_status');
            $status = self::positiveId($rawStatus);
            $parcelId = self::positiveId($scope->get_meta('_salam_gate_parcel_id'));
            if ($parcelId !== null && (self::positiveId($order->get_meta('_salam_gate_native_link_' . $parcelId)) !== $scope->getNativeParcelId()
                || self::positiveId($order->get_meta('_salam_gate_native_origin_' . $scope->getNativeParcelId())) !== $parcelId)) {
                return self::review('ارتباط رسید با مرسوله این سفارش نیاز به بررسی دارد.');
            }
            $known = $parcelId !== null && $status !== null && ParcelStatuses::isValid($status);
            $statuses[] = $known ? $status : null;
            $uncertain = $uncertain || (!$known && (!self::emptyMeta($rawStatus)
                || !self::emptyMeta($scope->get_meta('_salam_gate_tracking_code'))));
            if (!$known || !self::wasShipped($status)) { continue; }
            foreach ($scope->getAllocation() as $line) {
                $id = $line['item_id'];
                $sent[$id] = ($sent[$id] ?? 0) + $line['quantity'];
                if (!isset($expected[$id]) || $sent[$id] > $expected[$id]) {
                    return self::review('تعداد اقلام مرسوله‌ها با سفارش مطابقت ندارد.');
                }
            }
        }
        if ($uncertain) { return self::review('وضعیت یکی از مرسوله‌های این سفارش هنوز تأیید نشده است.'); }
        if ($sent !== []) {
            foreach ($expected as $id => $quantity) {
                if (($sent[$id] ?? 0) < $quantity) {
                    return self::state('بخشی ارسال شده', 'partial', 'بخشی از اقلام سفارش ارسال شده‌اند؛ ارسال همه اقلام هنوز تأیید نشده است.');
                }
            }
            if ($expected === []) { return self::review('اقلام سفارش برای بررسی ارسال کامل در دسترس نیست.'); }
            if (count(array_unique($statuses, SORT_REGULAR)) === 1) {
                return self::parcelState($statuses[0]);
            }
            return self::state('ارسال شده', 'sent', 'ارسال همه اقلام سفارش تأیید شده است؛ وضعیت هر مرسوله در صفحه سفارش نمایش داده می‌شود.');
        }
        if ($statuses !== [] && count(array_unique($statuses, SORT_REGULAR)) === 1
            && $statuses[0] !== null) {
            return self::parcelState($statuses[0]);
        }
        return self::state('ارسال نشده', 'pending', 'تحویل اقلام این سفارش به پست هنوز تأیید نشده است.');
    }

    private static function wasShipped(int $status): bool
    {
        return in_array($status, [ParcelStatuses::SHIPPED, ParcelStatuses::DELIVERED,
            ParcelStatuses::HANDED_TO_POSTMAN, ParcelStatuses::RETURNED_TO_SENDER], true);
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function emptyMeta($value): bool
    {
        return in_array($value, ['', null, false, 0, '0'], true);
    }

    private static function state(string $label, string $tone, string $description): array
    {
        return compact('label', 'tone', 'description');
    }

    private static function review(string $description): array
    {
        return self::state('نیاز به بررسی', 'review', $description);
    }

    public static function enqueueStyles(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)) { return; }
        // Woo's table does not inherit the SalamGate shell font. Reuse its
        // font definitions on both order lists, including the legacy screen.
        $fontHandle = 'basalam-admin-font-style';
        if (!wp_style_is($fontHandle, 'registered')
            && file_exists(Paths::root() . '/assets/css/font.css')) {
            wp_register_style($fontHandle, plugins_url('assets/css/font.css', Paths::root() . '/sync-basalam.php'), [], null);
        }
        $dependencies = wp_style_is($fontHandle, 'registered') ? [$fontHandle] : [];
        $root = dirname(__DIR__, 3);
        $file = $root . '/assets/salam-gate/styles/salam-gate-order-status.css';
        wp_enqueue_style('woosalam-sg-order-status', plugins_url('assets/salam-gate/styles/salam-gate-order-status.css',
            $root . '/sync-basalam.php'), $dependencies, (string) filemtime($file));
        if (current_user_can('manage_woocommerce')) {
            $previewStyle = $root . '/assets/salam-gate/styles/salam-gate-basalam-orders.css';
            wp_enqueue_style('woosalam-salam-gate-order-list-preview', plugins_url('assets/salam-gate/styles/salam-gate-basalam-orders.css',
                $root . '/sync-basalam.php'), ['woosalam-salam-gate-admin'], (string) filemtime($previewStyle));
            $script = $root . '/assets/salam-gate/js/salam-gate-order-list.js';
            $listDependencies = ['woosalam-salam-gate-script', 'woosalam-salam-gate-admin'];
            if (wp_script_is('woosalam-salam-gate-bulk-shipping', 'registered')) {
                $listDependencies[] = 'woosalam-salam-gate-bulk-shipping';
            }
            wp_enqueue_script('woosalam-sg-order-list', plugins_url('assets/salam-gate/js/salam-gate-order-list.js',
                $root . '/sync-basalam.php'), $listDependencies, (string) filemtime($script), true);
        }
    }

    /** One reusable modal outside the Woo bulk-action form, on HPOS and CPT lists. */
    public static function renderListHost(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)
            || !current_user_can('manage_woocommerce') || !\SyncBasalam\SalamGate\FeatureAvailability::isAvailable()) { return; }
        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] !== '') { return; }
        include dirname(__DIR__, 3) . '/templates/SalamGate/order-list-host.php';
    }
}
