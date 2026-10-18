<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderState;

defined('ABSPATH') || exit;

/** Cached presentation only; opening the workflow still performs its own fresh preflight. */
final class OrderListAction
{
    /** @return array{mode:string,label:string,market:string,description:string,url:string,awaiting_payment:bool} */
    public static function forOrder(\WC_Order $order): array
    {
        $market = OrderContext::marketFor((int) $order->get_id());
        $url = (string) $order->get_edit_order_url();
        if (self::positiveId($order->get_id()) === null || $order->get_type() !== 'shop_order'
            || in_array($order->get_status(), ['trash', 'checkout-draft'], true)) {
            return self::state('blocked', 'ثبت مرسوله', $market, $url, 'این سفارش برای ثبت مرسوله در دسترس نیست.');
        }
        // Cancellation takes priority in this list only. Historical receipts
        // and any identity inconsistencies remain inspectable inside the order.
        if ($market === OrderContext::MARKET_BASALAM) {
            require_once dirname(__DIR__) . '/Order/BasalamOrderState.php';
            $source = BasalamOrderState::cached($order);
            if ($source !== null && $source['cancelled']) {
                return self::state('cancelled', 'لغوشده', $market, $url, 'این سفارش در باسلام لغو شده است و امکان شروع ارسال ندارد.');
            }
        }
        if (in_array($order->get_status(), ['cancelled', 'bslm-rejected'], true)) {
            return self::state('cancelled', 'لغوشده', $market, $url, 'این سفارش لغو شده است و امکان شروع ارسال ندارد.');
        }
        $savedMarket = $order->get_meta('_salam_gate_market');
        if ((!self::emptyMeta($savedMarket) && $savedMarket !== $market) || self::duplicateState($order)) {
            return self::review($market, $url, 'ارتباط ذخیره‌شده مرسوله با این سفارش نیاز به بررسی دارد.');
        }
        if ($market === OrderContext::MARKET_BASALAM) {
            return self::native($order, $url);
        }
        foreach ($order->get_meta_data() as $meta) {
            if (preg_match('/^_salam_gate_(bp_|native_)/', (string) ($meta->get_data()['key'] ?? ''))) {
                return self::review($market, $url, 'ارتباط مبدأ سفارش و مرسوله‌های ذخیره‌شده را بررسی کنید.');
            }
        }
        if (!self::emptyMeta($order->get_meta('_salam_gate_basalam_mapping'))) {
            return self::review($market, $url, 'ارتباط این سفارش با سفارش اصلی باسلام باید بررسی شود.');
        }

        $rawExternal = $order->get_meta('_salam_gate_external_id');
        $external = self::positiveId($rawExternal);
        $identity = get_option('salam_gate_website_order_identity_' . $order->get_id(), null);
        $savedIdentity = self::positiveId($identity);
        if ((!self::emptyMeta($rawExternal) && $external === null)
            || ($identity !== null && $savedIdentity === null)
            || ($external !== null && $savedIdentity !== null && $external !== $savedIdentity)) {
            return self::review($market, $url, 'شناسه ثبت قبلی این سفارش قابل تأیید نیست؛ ارسال و پرداخت قبلی را بررسی کنید.');
        }
        $parcelId = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        // Legacy drafts used the local number. Read it only for guards; never
        // allocate a website identity or persist anything during list rendering.
        if ($parcelId !== null && $external === null) {
            if ($savedIdentity !== null && $savedIdentity !== (int) $order->get_id()) {
                return self::review($market, $url, 'شناسه مرسوله قبلی با شناسه ثبت سفارش مطابقت ندارد.');
            }
            $external = (int) $order->get_id();
        } else {
            $external = $external ?? $savedIdentity;
        }
        $savedParcelIdentity = $parcelId !== null
            ? get_option('salam_gate_website_parcel_identity_' . $parcelId, null) : null;
        if ($savedParcelIdentity !== null && (!is_array($savedParcelIdentity)
            || self::positiveId($savedParcelIdentity['external_id'] ?? null) !== $external
            || (($savedParcelIdentity['order_id'] ?? null) !== null
                && self::positiveId($savedParcelIdentity['order_id']) !== (int) $order->get_id()))) {
            return self::review($market, $url, 'ارتباط مرسوله با سفارش انتخاب‌شده نیاز به بررسی دارد.');
        }

        return self::parcel($order, $market, $url, $external, (int) $order->get_id());
    }

    public static function render(\WC_Order $order, ?array $state = null): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $state = $state ?? self::forOrder($order);
        $mode = $state['mode'];
        $source = OrderShippingStatus::sourceOrderStatus($order);
        $sourceAttributes = $source !== null ? ' data-sg-source-order-label="' . esc_attr($source['label'])
            . '" data-sg-source-order-tone="' . esc_attr($source['tone'])
            . '" data-sg-source-order-description="' . esc_attr($source['description']) . '"' : '';
        if ($mode === 'cancelled') {
            // Inspect the purchased order in our Persian read-only preview,
            // with its editor URL as a fallback. Never enter the shipping workflow.
            echo '<a class="sg-order-list-action sg-order-list-action--cancelled" data-sg-order-details-open="" data-order-id="'
                . esc_attr((string) $order->get_id()) . '"' . $sourceAttributes . ' href="' . esc_url($state['url'])
                . '" aria-label="' . esc_attr('مشاهده جزئیات، سفارش ' . $order->get_order_number()) . '">'
                . '<span class="dashicons dashicons-visibility" aria-hidden="true"></span><span>مشاهده جزئیات</span></a>';
            return;
        }
        $icon = $mode === 'edit' ? 'plus-alt2' : ($mode === 'payment' ? 'controls-repeat'
            : ($mode === 'receipt' ? 'visibility' : ($mode === 'native' ? 'archive' : 'info-outline')));
        $content = '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>'
            . '<span>' . esc_html($state['label']) . '</span>';
        $attributes = ' class="sg-order-list-action sg-order-list-action--' . esc_attr($mode)
            . '" title="' . esc_attr($state['description']) . '" data-order-id="'
            . esc_attr((string) $order->get_id()) . '"' . $sourceAttributes;
        if ($mode === 'blocked') {
            echo '<span' . $attributes . ' aria-disabled="true" tabindex="-1">' . $content . '</span>';
            return;
        }
        if (in_array($mode, ['edit', 'payment', 'native', 'receipt'], true)) {
            $attributes .= ' data-sg-order-list-open="" data-sg-order-list-mode="' . esc_attr($mode)
                . '"';
        }
        echo '<a' . $attributes . ' href="' . esc_url($state['url']) . '" aria-label="'
            . esc_attr($state['label'] . '، سفارش ' . $order->get_order_number()) . '">' . $content . '</a>';
    }

    /** Always select an exact native child; a parent order is never a financial parcel. */
    private static function native(\WC_Order $order, string $url): array
    {
        $market = OrderContext::MARKET_BASALAM;
        $scopedIds = [];
        $links = [];
        foreach ($order->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $key = (string) ($data['key'] ?? '');
            if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_/', $key, $match)) {
                $nativeId = self::positiveId($match[1]);
                if ($nativeId === null) { return self::review($market, $url); }
                $scopedIds[$nativeId] = true;
            } elseif (preg_match('/^_salam_gate_native_(link|origin)_([1-9][0-9]*)$/D', $key, $match)) {
                $links[] = ['kind' => $match[1], 'id' => self::positiveId($match[2]),
                    'value' => self::positiveId($data['value'] ?? null)];
            } elseif (preg_match('/^_salam_gate_(bp_|native_)/', $key)) {
                return self::review($market, $url);
            }
        }
        if ($scopedIds !== []) {
            if (!class_exists(BasalamParcelOrder::class)) {
                return self::review($market, $url, 'برای بررسی مرسوله‌های مستقل این سفارش، نسخه جدید افزونه لازم است.');
            }
            $entries = BasalamParcelOrder::entries($order);
            $registered = [];
            $states = [];
            $allocations = [];
            $quantities = [];
            foreach ($order->get_items() as $item) { $quantities[(int) $item->get_id()] = (int) $item->get_quantity(); }
            foreach ($scopedIds as $nativeId => $_) {
                if (!isset($entries[$nativeId])) { return self::review($market, $url); }
                $scope = $entries[$nativeId];
                $parcelId = self::positiveId($scope->get_meta('_salam_gate_parcel_id'));
                if ($parcelId !== null) {
                    if (isset($registered[$parcelId])
                        || self::positiveId($order->get_meta('_salam_gate_native_link_' . $parcelId)) !== $nativeId
                        || self::positiveId($order->get_meta('_salam_gate_native_origin_' . $nativeId)) !== $parcelId) {
                        return self::review($market, $url);
                    }
                    $registered[$parcelId] = $nativeId;
                }
                foreach ($scope->getAllocation() as $line) {
                    $id = $line['item_id'];
                    $allocations[$id] = ($allocations[$id] ?? 0) + $line['quantity'];
                    if (!isset($quantities[$id]) || $allocations[$id] > $quantities[$id]) {
                        return self::review($market, $url, 'تعداد اقلام مرسوله‌های ذخیره‌شده با سفارش مطابقت ندارد.');
                    }
                }
                $state = self::parcel($scope, $market, $url, $nativeId, null);
                if ($state['mode'] === 'review') { return $state; }
                $states[] = $state['mode'];
            }
            foreach ($links as $link) {
                $nativeId = $link['kind'] === 'origin' ? $link['id'] : $link['value'];
                $parcelId = $link['kind'] === 'link' ? $link['id'] : $link['value'];
                if ($nativeId === null || $parcelId === null || ($registered[$parcelId] ?? null) !== $nativeId) {
                    return self::review($market, $url);
                }
            }
            // A migrated legacy receipt/unknown marker must still belong to
            // exactly the same child; it cannot disappear behind valid siblings.
            $legacyId = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
            $legacyNative = self::nativeMapping($order);
            $legacyEvidence = !self::emptyMeta($order->get_meta('_salam_gate_parcel_id'));
            foreach (['_salam_gate_pay_unknown', '_salam_gate_paid_at', '_salam_gate_invoice_id',
                '_salam_gate_status', '_salam_gate_tracking_code'] as $key) {
                $legacyEvidence = $legacyEvidence || !self::emptyMeta($order->get_meta($key));
            }
            if ($legacyEvidence) {
                if ($legacyId === null || $legacyNative === null
                    || ($registered[$legacyId] ?? null) !== $legacyNative) {
                    return self::review($market, $url);
                }
                $legacy = self::parcel($order, $market, $url, $legacyNative, null);
                if ($legacy['mode'] === 'review') { return $legacy; }
            }
            $awaitingPayment = in_array('payment', $states, true);
            $label = $awaitingPayment ? 'تکمیل و پرداخت'
                : (in_array('edit', $states, true) ? 'ثبت مرسوله' : 'مشاهده مرسوله');
            return self::state('native', $label, $market, $url,
                'مرسوله موردنظر این سفارش باسلام را انتخاب کنید؛ ثبت، پرداخت و رهگیری هر مرسوله مستقل است.', $awaitingPayment);
        }
        if ($links !== []) { return self::review($market, $url); }
        $mapping = $order->get_meta('_salam_gate_basalam_mapping');
        $nativeId = self::nativeMapping($order);
        if (!self::emptyMeta($mapping) && $nativeId === null) { return self::review($market, $url); }
        $hasParcel = !self::emptyMeta($order->get_meta('_salam_gate_parcel_id'));
        if ($hasParcel && $nativeId === null) {
            return self::review($market, $url, 'مرسوله قبلی به یک مرسوله معتبر باسلام متصل نیست؛ ارتباط ارسال قبلی را بررسی کنید.');
        }
        $state = self::parcel($order, $market, $url, $nativeId, null);
        if ($state['mode'] === 'blocked' && in_array($order->get_status(), ['bslm-shipping', 'bslm-completed'], true)) {
            return self::state('native', 'مشاهده مرسوله', $market, $url, 'مرسوله‌های این سفارش باسلام را برای مشاهده وضعیت و رهگیری انتخاب کنید.');
        }
        if (in_array($state['mode'], ['review', 'blocked'], true)) { return $state; }
        return self::state('native', $state['label'], $market, $url,
            'مرسوله باسلام را از سفارش اصلی انتخاب کنید؛ اطلاعات و هزینه هر مرسوله پیش از پرداخت دوباره بررسی می‌شود.', $state['awaiting_payment']);
    }

    /** Leaf state from saved metadata/options only, for website or one exact native child. */
    private static function parcel($order, string $market, string $url, ?int $externalId, ?int $websiteOrderId): array
    {
        $actionable = OrderMetabox::isActionableStatus($market, $order->get_status());
        if ($order instanceof BasalamParcelOrder) {
            // Parent shipment status cannot hide an independently preparing child.
            $actionable = !in_array($order->get_status(),
                ['trash', 'checkout-draft', 'refunded', 'cancelled', 'failed', 'bslm-rejected'], true);
        }
        $rawId = $order->get_meta('_salam_gate_parcel_id');
        $id = self::positiveId($rawId);
        $rawStatus = $order->get_meta('_salam_gate_status');
        $status = self::positiveId($rawStatus);
        $rawExternal = $order->get_meta('_salam_gate_external_id');
        $savedMarket = $order->get_meta('_salam_gate_market');
        if ((!self::emptyMeta($rawId) && $id === null)
            || (!self::emptyMeta($rawStatus) && ($status === null || !ParcelStatuses::isValid($status)))
            || (!self::emptyMeta($rawExternal) && self::positiveId($rawExternal) !== $externalId)
            || (!self::emptyMeta($savedMarket) && $savedMarket !== $market)) {
            return self::review($market, $url);
        }
        $draftId = $order->get_meta('_salam_gate_order_draft_parcel_id');
        if (!self::emptyMeta($draftId) && self::positiveId($draftId) !== $id) {
            return self::review($market, $url, 'اطلاعات پیش‌نویس با مرسوله ذخیره‌شده مطابقت ندارد.');
        }
        if ($externalId !== null) {
            $creation = get_option('salam_gate_create_order_' . hash('sha256', $market . ':' . $externalId), null);
            if ($creation !== null && (!is_array($creation) || ($creation['state'] ?? '') !== 'created'
                || ($creation['market'] ?? null) !== $market
                || self::positiveId($creation['external_id'] ?? null) !== $externalId
                || $id === null || self::positiveId($creation['parcel_id'] ?? null) !== $id)) {
                return self::review($market, $url, 'نتیجه ثبت قبلی مرسوله باید بررسی شود؛ مرسوله تازه ثبت نکنید.');
            }
        }
        $edit = $id !== null ? get_option('salam_gate_parcel_edit_' . $id, null) : null;
        if ($edit !== null && (!is_array($edit) || ($edit['state'] ?? '') !== 'selection_required'
            || (isset($edit['market']) && $edit['market'] !== $market))) {
            return self::review($market, $url, 'این پیش‌نویس جایگزین شده یا نتیجه ویرایش آن هنوز تأیید نشده است.');
        }
        if ($id !== null && $status !== null && ParcelStatuses::hasReceipt($status)) {
            return self::state('receipt', 'مشاهده مرسوله', $market, $url,
                'هزینه ارسال قبلاً پرداخت شده است؛ اطلاعات مرسوله، رهگیری و برچسب را مشاهده کنید.');
        }
        if ($id !== null && $status === ParcelStatuses::VOIDED) {
            return self::review($market, $url, 'مرسوله ابطال شده است؛ پیش از ارسال مجدد، ارسال قبلی را بررسی کنید.');
        }
        $paymentKeys = $id !== null ? ['salam_gate_parcel_payment_' . $id] : [];
        if ($websiteOrderId !== null) {
            $paymentKeys[] = 'salam_gate_website_payment_order_' . $websiteOrderId;
            if ($externalId !== null) { $paymentKeys[] = 'salam_gate_website_payment_external_' . $externalId; }
        }
        foreach ($paymentKeys as $key) {
            if (get_option($key, null) !== null) {
                return self::review($market, $url, 'پرداخت قبلی ثبت شده یا در حال بررسی است؛ نتیجه آن را بررسی کنید و دوباره پرداخت نکنید.');
            }
        }
        foreach (['_salam_gate_pay_unknown', '_salam_gate_paid_at', '_salam_gate_invoice_id',
            '_salam_gate_tracking_code', '_basalam_order_tracking_code'] as $key) {
            if (!self::emptyMeta($order->get_meta($key))) {
                return self::review($market, $url, 'ارسال یا پرداخت قبلی نیاز به بررسی دارد؛ ثبت و پرداخت مجدد انجام ندهید.');
            }
        }
        if ($id !== null) {
            if ($status !== ParcelStatuses::NEW || !$actionable) {
                return self::review($market, $url, 'پیش از ادامه پرداخت، وضعیت مرسوله و سفارش را بررسی کنید.');
            }
            return self::state('payment', 'تکمیل و پرداخت', $market, $url,
                'شیوه ارسال و مبلغ نهایی این مرسوله را بررسی کنید و پرداخت را ادامه دهید.');
        }
        if ($status !== null || !self::emptyMeta($order->get_meta('_salam_gate_created_at'))) {
            return self::review($market, $url);
        }
        if (!$actionable) {
            return self::state('blocked', 'ثبت مرسوله', $market, $url,
                'این سفارش در وضعیت قابل ارسال نیست؛ ابتدا وضعیت سفارش را بررسی کنید.');
        }
        return self::state('edit', 'ثبت مرسوله', $market, $url,
            'اطلاعات گیرنده و بسته را بررسی و وزن مرسوله را تأیید کنید.');
    }

    private static function duplicateState(\WC_Order $order): bool
    {
        $seen = [];
        foreach ($order->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if (strpos($key, '_salam_gate_') !== 0) { continue; }
            if (isset($seen[$key])) { return true; }
            $seen[$key] = true;
        }
        return false;
    }

    /** Reuse the row object; validating its original mapping must not reload/migrate it. */
    private static function nativeMapping(\WC_Order $order): ?int
    {
        $mapping = $order->get_meta('_salam_gate_basalam_mapping');
        if (!is_array($mapping)) { return null; }
        $invoice = BasalamOrderContext::getInvoiceId((int) $order->get_id());
        $vendor = function_exists('syncBasalamSettings')
            ? self::positiveId(syncBasalamSettings()->getSettings('vendor_id')) : null;
        if ($invoice === null || $vendor === null
            || self::positiveId($mapping['order_id'] ?? null) !== (int) $order->get_id()
            || self::positiveId($mapping['invoice_id'] ?? null) !== $invoice
            || self::positiveId($mapping['vendor_id'] ?? null) !== $vendor) { return null; }
        return self::positiveId($mapping['parcel_id'] ?? null);
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

    private static function state(string $mode, string $label, string $market, string $url, string $description, bool $awaitingPayment = false): array
    {
        return compact('mode', 'label', 'market', 'description', 'url')
            + ['awaiting_payment' => $mode === 'payment' || $awaitingPayment];
    }

    private static function review(string $market, string $url, string $description = 'ارتباط یکی از مرسوله‌های این سفارش نیاز به بررسی دارد.'): array
    {
        return self::state('review', 'بررسی مرسوله', $market, $url, $description);
    }
}
