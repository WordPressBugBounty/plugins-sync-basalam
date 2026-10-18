<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Admin\OrderListAction;
use SyncBasalam\SalamGate\Admin\OrderShippingStatus;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderState;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamParcelReader;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\JalaliDate;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** Viewing is separate from preparation: no import, repair, sync, quote or payment. */
final class OrderPreviewActions
{
    public static function preview(): void
    {
        if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'اجازه مشاهده سفارش را ندارید.'], 403);
        }
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است؛ صفحه را تازه‌سازی کنید.'], 403);
        }
        try {
            $result = self::read($_POST);
        } catch (SalamGateException $error) {
            wp_send_json_error(['message' => $error->firstMessage()]);
        } catch (\Throwable $error) {
            wp_send_json_error(['message' => 'جزئیات سفارش دریافت نشد؛ دوباره تلاش کنید.']);
        }
        wp_send_json_success($result);
    }

    /** Authenticated local identity remains distinct from native invoice/parcel IDs. */
    public static function read(array $input): array
    {
        $id = self::id($input['order_id'] ?? null);
        $order = $id !== null && function_exists('wc_get_order') ? wc_get_order($id) : null;
        if (!$order instanceof \WC_Order || $order->get_type() !== 'shop_order'
            || in_array($order->get_status(), ['trash', 'checkout-draft'], true)) {
            throw self::invalid('این سفارش در دسترس نیست؛ فهرست سفارش‌ها را تازه کنید.');
        }
        $market = OrderContext::marketFor($id);
        $action = OrderListAction::forOrder($order);
        $readOnlyCancellation = ($input['view'] ?? null) === 'cancelled-order';
        if (isset($input['view']) && !$readOnlyCancellation) {
            throw self::invalid('نوع نمایش سفارش معتبر نیست.');
        }
        // The list's cancellation preview reads the purchased local order only.
        // It must not discover a native child, import an order, or prepare shipping.
        if ($readOnlyCancellation && ($action['mode'] !== 'cancelled'
            || isset($input['basalam_order_id']) || isset($input['basalam_parcel_id']))) {
            throw self::invalid('وضعیت سفارش تغییر کرده است؛ فهرست سفارش‌ها را تازه کنید.');
        }
        if (!$readOnlyCancellation && ($input['market'] ?? null) !== $market) {
            throw self::invalid('مبدأ این سفارش تغییر کرده است؛ سفارش را دوباره از فهرست انتخاب کنید.');
        }
        $selection = ['order_id' => $id, 'market' => $market];
        if ($readOnlyCancellation) { $selection['view'] = 'cancelled-order'; }
        $scope = null;
        if (!$readOnlyCancellation && $market === OrderContext::MARKET_BASALAM) {
            $invoiceId = self::id($input['basalam_order_id'] ?? null);
            $nativeId = self::id($input['basalam_parcel_id'] ?? null);
            if ($invoiceId === null || $nativeId === null || BasalamOrderContext::getInvoiceId($id) !== $invoiceId) {
                throw self::invalid('ارتباط این سفارش با سفارش اصلی باسلام تأیید نشد.');
            }
            $scope = BasalamParcelOrder::stored($order, $nativeId);
            if ($scope === null && BasalamParcelOrder::hasScopes($order)) {
                throw self::invalid('ارتباط مرسوله انتخاب‌شده با اقلام سفارش را بررسی کنید.');
            }
            if ($scope === null && BasalamOrderContext::getParcelId($id) !== $nativeId) {
                // An unprepared imported order may have no cached child identity.
                // Verify using a fixed GET; never discover/migrate/persist a scope here.
                $vendorId = function_exists('syncBasalamSettings') ? self::id(syncBasalamSettings()->getSettings('vendor_id')) : null;
                if ($vendorId === null) { throw self::invalid('غرفه متصل برای این سفارش مشخص نیست.'); }
                $connection = ConnectionCache::identityHash();
                $parcel = BasalamParcelReader::parcel($nativeId);
                ConnectionCache::assertCurrentIdentity($connection);
                if (self::id($parcel['id'] ?? null) !== $nativeId
                    || self::id($parcel['vendor']['id'] ?? null) !== $vendorId
                    || self::id($parcel['order']['id'] ?? null) !== $invoiceId
                    || self::id(syncBasalamSettings()->getSettings('vendor_id')) !== $vendorId
                    || BasalamOrderContext::getInvoiceId($id) !== $invoiceId) {
                    throw self::invalid('مرسوله با سفارش و غرفه متصل مطابقت ندارد.');
                }
            }
            $selection += ['basalam_order_id' => $invoiceId, 'basalam_parcel_id' => $nativeId];
        } elseif (isset($input['basalam_order_id']) || isset($input['basalam_parcel_id'])) {
            throw self::invalid('شناسه سفارش ووکامرس معتبر نیست.');
        }
        $shipping = OrderShippingStatus::forOrder($order);
        if (!empty($action['awaiting_payment']) && !in_array($shipping['tone'], ['sent', 'delivered', 'returned'], true)) {
            $shipping = ['label' => 'در انتظار پرداخت', 'tone' => 'waiting'];
        }
        if ($market === OrderContext::MARKET_BASALAM) {
            $sourceState = BasalamOrderState::cached($order);
            if ($sourceState !== null) {
                if ($sourceState['cancelled'] && !in_array($shipping['tone'], ['sent', 'delivered', 'returned'], true)) {
                    $shipping = ['label' => 'ارسال لغو شده', 'tone' => 'cancelled'];
                }
            }
        }
        $summaryState = $shipping;
        if ($readOnlyCancellation) {
            // Cached native rejection/cancellation overrides a stale local
            // Woo preparation status, without saving a new financial status.
            $sourceOrderState = OrderShippingStatus::sourceOrderStatus($order);
            $summaryState = $sourceOrderState !== null && $sourceOrderState['tone'] === 'cancelled' ? $sourceOrderState
                : ['label' => $order->get_status() === 'bslm-rejected' ? 'لغوشده در باسلام' : 'لغوشده', 'tone' => 'cancelled'];
            $cancellationMessage = 'این سفارش لغو شده است و امکان ثبت مرسوله ندارد.';
        }
        // This previews the purchased order, like Woo's editor. Keep its original
        // quantities/totals; a selected native child is only a checkout identity.
        $items = $order->get_items();
        $money = static function ($amount) use ($order): string {
            $rial = Units::orderAmountToRial($amount, $order->get_currency());
            if ($rial !== null) { return Units::formatToman(Units::toToman($rial)) . ' تومان'; }
            return wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()]));
        };
        $recipient = trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name());
        $address = $order->get_address($order->get_shipping_address_1() !== '' ? 'shipping' : 'billing');
        $states = WC()->countries->get_states($address['country'] ?? 'IR');
        $provinceTitle = is_array($states) ? ($states[$address['state'] ?? ''] ?? ($address['state'] ?? '')) : ($address['state'] ?? '');
        if (($address['country'] ?? 'IR') === 'IR' && is_string($provinceTitle)
            && preg_match('/\(([\x{0600}-\x{06FF}\s‌]+)\)/u', $provinceTitle, $provinceName)) {
            $provinceTitle = trim($provinceName[1]);
        }
        $destinationText = implode('، ', array_filter([
            $provinceTitle,
            $address['city'] ?? '',
        ], static fn ($value): bool => is_string($value) && trim($value) !== ''));
        $streetAddressText = implode('، ', array_filter([
            $address['address_1'] ?? '', $address['address_2'] ?? '',
        ], static fn ($value): bool => is_string($value) && trim($value) !== ''));
        $number = Digits::toPersian((string) ($selection['basalam_order_id'] ?? $order->get_order_number()));
        $created = $order->get_date_created();
        $createdIso = $created ? $created->date('Y-m-d') : '';
        $createdDisplay = '';
        if ($created) {
            // Read Woo's local calendar day directly, without date_i18n filters
            // that may have already converted it through another date plugin.
            $jalali = JalaliDate::toJalali((int) $created->date('Y'), (int) $created->date('n'), (int) $created->date('j'));
            $months = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
            $createdDisplay = Digits::toPersian($jalali['jd'] . ' ' . $months[$jalali['jm']] . ' ' . $jalali['jy']);
        }
        ob_start();
        try {
            include dirname(__DIR__, 4) . '/templates/SalamGate/partials/order-preview-details.php';
            $html = (string) ob_get_contents();
        } finally { ob_end_clean(); }
        return ['selection' => $selection, 'title' => 'جزئیات سفارش ' . $number, 'html' => $html,
            'shipping_allowed' => !$readOnlyCancellation && in_array($action['mode'], ['edit', 'payment', 'native', 'receipt'], true),
            'shipping_reason' => !$readOnlyCancellation && in_array($action['mode'], ['cancelled', 'blocked', 'review'], true) ? $action['description'] : ''];
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function invalid(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
