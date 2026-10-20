<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Contracts\UpsertIdentity;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamParcelSet;
use SyncBasalam\SalamGate\Order\BasalamOrderProducts;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderShippingPreference;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\PaymentRejection;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\CreationAttemptService;
use SyncBasalam\SalamGate\Services\CategoriesService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\StatusSyncService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\OptionLock;
use SyncBasalam\SalamGate\Utils\Units;

if (! defined('ABSPATH')) exit;

require_once __DIR__ . '/../../Order/BasalamShippingPreflight.php';
require_once __DIR__ . '/../../Order/BasalamOrderProducts.php';
require_once __DIR__ . '/../../Order/OrderShippingPreference.php';
require_once __DIR__ . '/../../Order/OrderWeightConfirmation.php';
require_once __DIR__ . '/../../Services/CreationAttemptService.php';

/**
 * اکشن‌های AJAX ساخت/بروزرسانی مرسوله، نرخ‌ها، پرداخت و لیبل.
 *
 * قواعد قرارداد پارت ۲:
 * - هویت upsert: بسالم همیشه external_id؛ ویرایش بازارهای دیگر فقط با id سلام‌گیت.
 * - ورودی مبلغ فرم تومان است و همیشه ×۱۰ (مستقل از ارز فروشگاه).
 * - نتیجه پرداخت نامعلوم (پاسخ خالی/عدم تطبیق) موفقیت جعلی نمی‌سازد؛
 *   کد رهگیری/وضعیت فقط از پاسخ معتبر لیست با market صریح نوشته می‌شود.
 */
class ParcelActions
{
    /**
     * پیش‌پر کردن مودال: payload پیش‌فرض + وضعیت مرسوله موجود.
     */
    public static function prepareParcel(bool $refreshOrderReceiver = false): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;
        $orderId = (int) $order->get_id();
        self::recoverConfirmedOrderReplacement($order);

        $shopId = FeatureAvailability::activeShopId();
        if ($shopId === null) {
            wp_send_json_error(['message' => 'غرفه سلام‌گیت فعالی یافت نشد؛ از صفحه سلام‌گیت وضعیت را بررسی کنید.']);
        }

        $market = OrderContext::marketFor($orderId);
        // Only an explicit Woo order refresh replaces an unpaid draft's saved
        // recipient. Normal reopening and native Basalam sources retain theirs.
        $refreshOrderReceiver = $refreshOrderReceiver && $market === OrderContext::MARKET_WEBSITE;
        $preflight = null;
        $sourceSnapshots = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $preflight = BasalamOrderProducts::checkAndRepair($order, false, $sourceSnapshots); }
            catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }
        }
        $externalId = OrderContext::externalIdForOrder($order, $market);
        if ($externalId === null) {
            wp_send_json_error(['message' => 'شناسه معتبر مرسوله برای این سفارش تأیید نشد؛ دوباره بررسی کنید.']);
        }
        $recovered = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $recovered = self::recoverBasalamParcel($order, $externalId); }
            catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception) + ['code' => 'basalam_recovery_failed']); }
        }

        $cityId = $preflight !== null
            ? $preflight['order_data']['receiver']['city_id']
            : null;

        $builder = new ParcelPayloadBuilder();
        $initialOverrides = [
            'external_id' => $externalId,
            'city_id' => $cityId,
        ];
        $payload = $preflight !== null
            ? $builder->assemble($preflight['order_data'], $shopId, $initialOverrides)
            : $builder->build($order, $shopId, $initialOverrides, $market);
        if ($recovered !== null && ($recovered['parcel']['category_id'] ?? null) !== null) {
            $payload['parcel']['category_id'] = $recovered['parcel']['category_id'];
        }
        $payload['_weight_estimate'] = $preflight['_weight_estimate'] ?? OrderWeightConfirmation::estimateForOrder($order);

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        $storedPayload = $order->get_meta('_salam_gate_order_draft_payload');
        if ($market === OrderContext::MARKET_WEBSITE && is_array($storedPayload)
            && (int) $order->get_meta('_salam_gate_order_draft_parcel_id') === $parcelId && $parcelId > 0) {
            foreach (['parcel', 'packaging', 'receiver', 'items'] as $key) {
                if ($key === 'receiver' && $refreshOrderReceiver) { continue; }
                if (is_array($storedPayload[$key] ?? null)) { $payload[$key] = $storedPayload[$key]; }
            }
        }
        try { $categories = (new CategoriesService())->all(); } catch (\Throwable $exception) { $categories = []; }
        foreach ($categories as $category) {
            if (!isset($payload['parcel']['category_id']) && !empty($category['is_default'])) {
                $payload['parcel']['category_id'] = (int) $category['id'];
            }
        }

        // اطلاعات نمایشی مودال
        $payload['_display'] = [
            'external_id' => $externalId,
            'market'      => $market,
            'value_toman' => Units::toToman($payload['parcel']['value']),
            'city_path'   => self::cityPath((int) ($payload['receiver']['city_id'] ?? 0) ?: $cityId),
            'product_links' => self::productLinksForOrder($order),
            'sender' => self::senderDisplay(FeatureAvailability::activeShop() ?? []),
            'categories' => $categories,
            'goods_value_needs_review' => !empty($preflight['financial_summary']['goods_value']['needs_review']),
            'source_state' => $preflight['source_state'] ?? null,
            'shipping_blocked_reason' => $preflight['shipping_blocked_reason'] ?? null,
        ];
        $shippingPreference = OrderShippingPreference::forOrder($order, $market, $sourceSnapshots);
        if ($shippingPreference !== null) {
            $payload['_display']['customer_shipping_method'] = $shippingPreference;
        }

        // مرسوله موجود؟ (خروجی ParcelDetailResponse — فقط وضعیت معتبر است)
        $existing = null;
        $remoteWeightLoaded = false;
        $payload['_items_editable'] = false;
        $orderItemIds = [];
        foreach ($order->get_items() as $item) {
            $orderItemIds[] = (int) $item->get_id();
        }
        foreach ($payload['items'] as $index => &$item) {
            $item['_order_item_id'] = is_array($storedPayload) && (int) $order->get_meta('_salam_gate_order_draft_parcel_id') === $parcelId
                ? self::storedOrderItemId($item) : ($orderItemIds[$index] ?? 0);
        }
        unset($item);
        if ($parcelId > 0) {
            try {
                $detail = $recovered['detail'] ?? (new ParcelService())->getDetail($parcelId);
                if (ParcelService::positiveId($detail['id'] ?? null) === $parcelId) {
                    $statusId = (int) ($detail['status']['id'] ?? 0);
                    $existing = [
                        'parcel_id'    => $parcelId,
                        'status_id'    => $statusId,
                        'status_title' => (string) ($detail['status']['title'] ?? ParcelStatuses::title($statusId)),
                        'paid'         => ParcelStatuses::hasReceipt($statusId),
                        'tracking_code' => (string) $order->get_meta('_salam_gate_tracking_code'),
                    ];
                    if ($recovered !== null) {
                        $existing['paid'] = $existing['paid'] || ($recovered['parcel']['invoice_status'] ?? null) === 2
                            || !empty($recovered['parcel']['tracking_code']);
                        $existing['tracking_code'] = $recovered['parcel']['tracking_code'];
                        $existing['invoice_status'] = $recovered['parcel']['invoice_status'];
                    }
                    $existing['can_print_label'] = $existing['paid'] && LabelEligibility::hasPrintableReceipt($parcelId, $statusId, $existing['tracking_code']);
                    foreach (['weight', 'value', 'is_liquid', 'is_fragile', 'category_id'] as $field) {
                        if (array_key_exists($field, $detail)) {
                            $payload['parcel'][$field] = $detail[$field];
                        }
                    }
                    $remoteWeightLoaded = Units::parseBoundedFormInteger($detail['weight'] ?? null, 50, 30000) !== null;
                    if (isset($detail['box_type']['id'])) {
                        $payload['packaging'] = [
                            'box_type_id' => (int) $detail['box_type']['id'],
                            'dimensions' => $detail['dimensions'] ?? null,
                        ];
                    }
                    if (is_array($detail['shipping_info']['sender'] ?? null)) {
                        $payload['_display']['sender'] = self::senderDisplay(FeatureAvailability::activeShop() ?? [], $detail['shipping_info']['sender']);
                    }
                    if (!$refreshOrderReceiver || $existing['paid']) {
                        $payload['receiver'] = self::receiverFromDetail($detail, $payload['receiver']);
                    }
                    if ($market === OrderContext::MARKET_WEBSITE && isset($detail['items']) && is_array($detail['items'])) {
                        $payload['items'] = self::associateRemoteItems($detail['items'], $payload['items']);
                    }
                    if ($market === OrderContext::MARKET_WEBSITE) { self::saveOrderDraft($order, $parcelId, $payload); }
                }
            } catch (SalamGateException $exception) {
                if ($market === OrderContext::MARKET_BASALAM) {
                    wp_send_json_error(self::errorPayload($exception) + ['code' => 'basalam_recovery_failed']);
                }
                // مرسوله از سمت سرور در دسترس نیست — مودال با payload پیش‌فرض باز می‌شود
            }
        }

        $payload['_display']['value_toman'] = Units::toToman((int) $payload['parcel']['value']);
        $payload['_display']['city_path'] = self::cityPath((int) ($payload['receiver']['city_id'] ?? 0));
        $payload['_display']['weight_confirmation'] = OrderWeightConfirmation::describe($payload, $remoteWeightLoaded);
        if ($market === OrderContext::MARKET_BASALAM) {
            $payload['_display']['receiver_errors'] = BasalamShippingPreflight::receiverInputErrors($payload['receiver']);
        }
        unset($payload['_weight_estimate']);

        wp_send_json_success([
            'payload'  => $payload,
            'existing' => $existing,
            'parcel_id' => $parcelId ?: null,
        ]);
    }

    /** @param \WC_Order|BasalamParcelOrder $order Recovery never repairs conflicting metadata. */
    private static function recoverBasalamParcel(object $order, int $externalId): ?array
    {
        OrderContext::assertSupportedOrder($order);
        $rawId = $order->get_meta('_salam_gate_parcel_id');
        $parcelId = $rawId === '' || $rawId === null ? null : ParcelService::positiveId($rawId);
        $savedMarket = $order->get_meta('_salam_gate_market');
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        if (($rawId !== '' && $rawId !== null && $parcelId === null)
            || ($savedMarket !== '' && $savedMarket !== 'basalam')
            || ($savedExternal !== '' && ParcelService::positiveId($savedExternal) !== $externalId)) {
            throw new SalamGateException('ارتباط مرسوله ذخیره‌شده با سفارش باسلام تأیید نشد؛ وضعیت ارسال را بررسی کنید.', 422);
        }
        $recovered = (new ParcelService())->findByExternalId('basalam', $externalId, $parcelId);
        if ($recovered !== null && ($parcelId === null || $savedMarket === '' || $savedExternal === '')) {
            $order->update_meta_data('_salam_gate_parcel_id', $recovered['parcel']['id']);
            $order->update_meta_data('_salam_gate_external_id', $externalId);
            $order->update_meta_data('_salam_gate_market', 'basalam');
            $order->save();
        }
        return $recovered;
    }

    /** Detail destinations are editable; optional current coordinates override original ones only when supplied. */
    private static function receiverFromDetail(array $detail, array $fallback = []): array
    {
        $remote = is_array($detail['shipping_info']['receiver'] ?? null) ? $detail['shipping_info']['receiver'] : [];
        foreach (['name' => 'full_name', 'mobile' => 'phone', 'address' => 'address', 'postal_code' => 'postal_code'] as $localKey => $remoteKey) {
            if (array_key_exists($remoteKey, $remote)) { $fallback[$localKey] = is_scalar($remote[$remoteKey]) ? (string) $remote[$remoteKey] : ''; }
        }
        $cityId = ParcelService::positiveId($remote['city']['id'] ?? null) ?? ParcelService::positiveId($remote['city_id'] ?? null);
        if ($cityId !== null || array_key_exists('city', $remote) || array_key_exists('city_id', $remote)) { $fallback['city_id'] = $cityId; }
        if (array_key_exists('latitude', $remote) || array_key_exists('longitude', $remote)) {
            $fallback = array_replace($fallback, BasalamShippingPreflight::receiverCoordinates($remote));
        }
        return $fallback;
    }

    /**
     * Attach a local display identity without guessing from remote IDs, titles or row order.
     * The detail DTO omits external_id today; an exact unique SKU can still identify a local item.
     * A present but invalid/unknown external_id is not silently replaced with another identity.
     */
    public static function associateRemoteItems(array $remoteItems, array $localItems): array
    {
        $externalIds = [];
        $skus = [];
        foreach ($localItems as $localItem) {
            if (!is_array($localItem)) continue;
            $localId = self::canonicalItemId($localItem['_order_item_id'] ?? null);
            if ($localId === null) continue;
            $externalId = self::canonicalItemId($localItem['external_id'] ?? null);
            if ($externalId !== null) {
                $key = 'external:' . $externalId;
                $externalIds[$key] = array_key_exists($key, $externalIds) ? null : $localId;
            }
            $sku = $localItem['sku'] ?? null;
            if (is_string($sku) && trim($sku) !== '') {
                $key = 'sku:' . $sku;
                $skus[$key] = array_key_exists($key, $skus) ? null : $localId;
            }
        }

        // Repeated remote identities are ambiguous too; do not attach one local row twice.
        $remoteIdentityCounts = [];
        foreach ($remoteItems as $remoteItem) {
            if (!is_array($remoteItem)) continue;
            $key = self::remoteItemIdentityKey($remoteItem);
            if ($key !== null) $remoteIdentityCounts[$key] = ($remoteIdentityCounts[$key] ?? 0) + 1;
        }
        $items = [];
        foreach ($remoteItems as $remoteItem) {
            if (!is_array($remoteItem)) continue;
            $key = self::remoteItemIdentityKey($remoteItem);
            $localId = 0;
            if ($key !== null && ($remoteIdentityCounts[$key] ?? 0) === 1) {
                $localId = array_key_exists('external_id', $remoteItem)
                    ? ($externalIds[$key] ?? 0) : ($skus[$key] ?? 0);
            }
            $remoteItem['_order_item_id'] = $localId;
            $items[] = $remoteItem;
        }
        return $items;
    }

    private static function remoteItemIdentityKey(array $item): ?string
    {
        if (array_key_exists('external_id', $item)) {
            $id = self::canonicalItemId($item['external_id']);
            return $id === null ? null : 'external:' . $id;
        }
        $sku = $item['sku'] ?? null;
        return is_string($sku) && trim($sku) !== '' ? 'sku:' . $sku : null;
    }

    private static function canonicalItemId($value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** A saved explicit zero is an intentionally unmatched row, never an absent association. */
    private static function storedOrderItemId(array $item): int
    {
        return self::canonicalItemId(array_key_exists('_order_item_id', $item)
            ? $item['_order_item_id'] : ($item['external_id'] ?? null)) ?? 0;
    }

    /** Editable local WooCommerce products only; variations open their parent's editor. */
    public static function productLinksForOrder($order): array
    {
        $links = [];
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product || !method_exists($product, 'get_id')) continue;
            if (method_exists($product, 'get_status') && $product->get_status() === 'trash') continue;
            $targetId = self::canonicalItemId($product->get_id());
            $parentId = method_exists($product, 'get_parent_id') ? self::canonicalItemId($product->get_parent_id()) : null;
            $isVariation = method_exists($product, 'is_type') && $product->is_type('variation');
            if ($parentId !== null || $isVariation) {
                if ($parentId === null || !function_exists('wc_get_product')) continue;
                $parent = wc_get_product($parentId);
                if (!$parent || !method_exists($parent, 'get_status') || $parent->get_status() === 'trash') continue;
                $targetId = $parentId;
            }
            if ($targetId === null || !current_user_can('edit_post', $targetId)) continue;
            $url = (string) get_edit_post_link($targetId, 'raw');
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) continue;
            $urlParts = parse_url($url);
            if (!is_array($urlParts) || isset($urlParts['user']) || isset($urlParts['pass'])) continue;
            $links[(string) $item->get_id()] = $url;
        }
        return $links;
    }

    /**
     * ساخت/بروزرسانی مرسوله + دریافت نرخ‌ها در یک مرحله.
     */
    public static function upsertParcel(): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;
        $orderId = (int) $order->get_id();

        $eligibility = WebsiteShippingEligibility::blockForOrder($order);
        if ($eligibility !== null) { self::sendOutcome($eligibility); }

        $market = OrderContext::marketFor($orderId);

        // Shipping an existing order cannot add, remove or rewrite its items.
        // Enforce this server-side too, before a forged/stale browser can touch
        // the remote draft. The independent send form has its own endpoint.
        if (array_key_exists('items', $_POST) || !empty($_POST['item_net_weights'])) {
            wp_send_json_error(['message' => 'اقلام از سفارش اصلی خوانده می‌شوند و در فرم ارسال قابل تغییر نیستند.',
                'code' => 'items_locked', 'kind' => 'validation']);
        }

        $inputErrors = self::numericInputErrors(wp_unslash($_POST), $market);
        if ($inputErrors !== []) {
            wp_send_json_error(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $inputErrors, 'code' => 'validation', 'kind' => 'validation']);
        }

        $shopId = FeatureAvailability::activeShopId();
        if ($shopId === null) {
            wp_send_json_error(['message' => 'غرفه سلام‌گیت فعالی یافت نشد.']);
        }

        $preflight = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $preflight = BasalamShippingPreflight::check($order); }
            catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }
        }
        $externalId = OrderContext::externalIdForOrder($order, $market);
        if ($externalId === null) {
            wp_send_json_error(['message' => 'شناسه معتبر مرسوله این سفارش تأیید نشد؛ دوباره بررسی کنید.']);
        }

        self::recoverConfirmedOrderReplacement($order);
        $recovered = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $recovered = self::recoverBasalamParcel($order, $externalId); }
            catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception) + ['code' => 'basalam_recovery_failed']); }
            if ($recovered !== null && (ParcelStatuses::hasReceipt($recovered['parcel']['status_id'])
                || ($recovered['parcel']['invoice_status'] ?? null) === 2 || !empty($recovered['parcel']['tracking_code']))) {
                wp_send_json_error(['message' => 'این مرسوله قبلاً پرداخت یا ارسال شده است؛ ثبت مجدد انجام ندهید.', 'code' => 'already_paid']);
            }
        }
        $overrides = self::overridesFromRequest($market);
        $existingParcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($preflight !== null && $recovered !== null) {
            try { BasalamShippingPreflight::assertOriginalFinancials($preflight, $recovered['detail']); }
            catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception) + ['code' => 'basalam_parcel_mismatch']); }
        }
        $builder = new ParcelPayloadBuilder();
        if ($preflight !== null && $recovered !== null) {
            $preflight['order_data']['receiver'] = self::receiverFromDetail($recovered['detail'], $preflight['order_data']['receiver']);
            // When omitted, preserve an existing independent declared value instead of resetting it to item totals.
            if (!array_key_exists('value_toman', $_POST)) { $overrides['value'] = $recovered['detail']['value'] ?? 0; }
            foreach (['is_liquid', 'is_fragile'] as $flag) {
                if (!array_key_exists($flag, $_POST)) { $overrides[$flag] = $recovered['detail'][$flag]; }
            }
            if (!array_key_exists('category_id', $_POST) && ($recovered['parcel']['category_id'] ?? null) !== null) {
                $overrides['category_id'] = $recovered['parcel']['category_id'];
            }
            if (!array_key_exists('box_type_id', $_POST)) {
                $overrides['box_type_id'] = $recovered['detail']['box_type']['id'];
                if ($overrides['box_type_id'] === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) { $overrides['dimensions'] = $recovered['detail']['dimensions']; }
            }
        }
        if ($market === OrderContext::MARKET_BASALAM && empty($overrides['city_id'])) {
            $overrides['city_id'] = $preflight['order_data']['receiver']['city_id'];
        }
        $payload = $preflight !== null
            ? $builder->assemble($preflight['order_data'], $shopId, $overrides)
            : $builder->build($order, $shopId, $overrides, $market);
        if ($market === OrderContext::MARKET_WEBSITE && $existingParcelId > 0) {
            $saved = $order->get_meta('_salam_gate_order_draft_payload');
            if ((int) $order->get_meta('_salam_gate_order_draft_parcel_id') === $existingParcelId
                && is_array($saved['items'] ?? null) && $saved['items'] !== []) {
                // Previously confirmed remote draft contents stay authoritative
                // when only receiver, packaging or parcel details are edited.
                $payload['items'] = $saved['items'];
            }
        }
        if ($preflight !== null) {
            $submittedReceiver = $payload['receiver'];
            // Explicit empty/malformed form fields must not be hidden by builder fallback defaults.
            foreach (['name', 'mobile', 'address', 'postal_code', 'city_id'] as $field) {
                if (array_key_exists($field, $_POST)) { $submittedReceiver[$field] = wp_unslash($_POST[$field]); }
            }
            $errors = BasalamShippingPreflight::receiverInputErrors($submittedReceiver);
            if (!BasalamShippingPreflight::isValidDeclaredValue($payload['parcel']['value'] ?? null)) {
                $errors['value_toman'] = 'ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان باشد.';
            }
            if ($errors !== []) {
                wp_send_json_error(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $errors, 'code' => 'validation', 'kind' => 'validation']);
            }
        }
        // Automatic valuation must obey the same declared-value floor as an explicit input.
        if (($payload['parcel']['value'] ?? 0) < 20000) {
            wp_send_json_error(['message' => 'حداقل ارزش مرسوله ۲٬۰۰۰ تومان است.',
                'errors' => ['value_toman' => 'حداقل ارزش مرسوله ۲٬۰۰۰ تومان است.'], 'code' => 'validation', 'kind' => 'validation']);
        }
        try { OrderWeightConfirmation::assertConfirmed(wp_unslash($_POST), $payload['parcel']['weight'] ?? null); }
        catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception) + ['code' => 'weight_confirmation_required']); }

        // شهر گیرنده برای هر دو بازار الزامی است
        if (empty($payload['receiver']['city_id'])) {
            wp_send_json_error(['message' => 'استان و شهر گیرنده را انتخاب کنید.']);
        }

        $warnings = $payload['_warnings'];
        unset($payload['_warnings'], $payload['_display'], $payload['_contents']);

        // هویت: بسالم همیشه external_id (ساخت و ویرایش)؛ ویرایش بازارهای دیگر با id سلام‌گیت.
        $identity = $existingParcelId > 0
            ? UpsertIdentity::forUpdate($market, $existingParcelId, $externalId)
            : UpsertIdentity::forCreate($market, $externalId);

        $work = static function (callable $renew) use ($order, $shopId, $existingParcelId, $market, $identity, $payload, $externalId, $warnings): array {
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            if ($existingParcelId > 0) {
                $blocked = self::quoteMutationBlock($order, $existingParcelId);
                if ($blocked !== null) return $blocked;
                $blocked = self::basalamQuoteMutationBlock($order, $existingParcelId);
                if ($blocked !== null) return $blocked;
                if (!$renew()) return self::quoteLeaseUnavailable();
            }
            try {
                $reusedCreation = false;
                if ($existingParcelId === 0) {
                    $creation = (new CreationAttemptService())->runForOrder($shopId, $market, $externalId, $payload,
                        static function (int $id) use ($order, $market, $payload): ?int {
                            WebsiteShippingEligibility::assertForOrder($order);
                            $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market),
                                UpsertIdentity::forCreate($market, $id) + $payload);
                            return ParcelService::positiveId($response['body']['parcel_id'] ?? null);
                        }, [CreationAttemptService::class, 'recoverOrderParcel']);
                    if (!$creation['ok']) {
                        $recoveredId = ParcelService::positiveId($creation['payload']['parcel_id'] ?? null);
                        if ($recoveredId !== null && ($creation['payload']['creation_state'] ?? '') === 'created') {
                            $order->update_meta_data('_salam_gate_parcel_id', $recoveredId);
                            $order->update_meta_data('_salam_gate_external_id', $externalId);
                            $order->update_meta_data('_salam_gate_market', $market);
                            $order->save();
                            // No quote/POST here. Reopening prepareParcel loads actual remote fields.
                            $creation['payload']['code'] = 'create_recovered';
                            $creation['payload']['message'] = 'مرسوله قبلی پیدا شد و اطلاعات تازه ثبت نشد؛ این پنجره را ببندید و ارسال با سلام‌گیت را دوباره باز کنید تا اطلاعات ذخیره‌شده را بررسی کنید.';
                        }
                        return $creation;
                    }
                    $parcelId = $creation['payload']['parcel_id'];
                    $reusedCreation = !empty($creation['payload']['reused']);
                } else {
                    WebsiteShippingEligibility::assertForOrder($order);
                    $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), $identity + $payload);
                    $parcelId = ParcelService::positiveId($response['body']['parcel_id'] ?? null);
                }
                if ($parcelId === null || ($existingParcelId > 0 && $parcelId !== $existingParcelId)) {
                    return ['ok' => false, 'payload' => ['message' => 'نتیجه ثبت مرسوله نامعلوم است؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید.', 'code' => 'create_unknown']];
                }

                // ذخیره متاها (HPOS-safe)
                $order->update_meta_data('_salam_gate_parcel_id', $parcelId);
                $order->update_meta_data('_salam_gate_external_id', $externalId);
                $order->update_meta_data('_salam_gate_market', $market);
                if (empty((int) $order->get_meta('_salam_gate_created_at'))) {
                    $order->update_meta_data('_salam_gate_created_at', current_time('mysql'));
                }
                // Recovery is read-only: do not pretend the incoming form was saved remotely.
                if ($market === OrderContext::MARKET_WEBSITE && !$reusedCreation) { self::saveOrderDraft($order, $parcelId, $payload); }
                else { $order->save(); }

                if ($reusedCreation) {
                    // Even an identical request hash does not prove the remote recipient
                    // stayed unchanged in the native panel. Require a fresh prepare/review.
                    return ['ok' => false, 'payload' => ['code' => 'create_recovered', 'parcel_id' => $parcelId,
                        'message' => 'مرسوله قبلی پیدا شد؛ این پنجره را ببندید و ارسال با سلام‌گیت را دوباره باز کنید تا اطلاعات واقعی آن را بررسی و تأیید کنید. ثبت یا پرداخت دوباره انجام نشد.']];
                }

                // بلافاصله نرخ‌ها (توجه: این GET اثر انتخاب نرخ در سرویس دارد)
                $quoteOutcome = $existingParcelId > 0
                    ? self::quotesOutcome($order, $parcelId, $renew)
                    : self::withQuoteLease($parcelId, static fn (callable $quoteRenew): array => self::quotesOutcome($order, $parcelId, $quoteRenew));
                $quotes = $quoteOutcome['ok'] ? $quoteOutcome['payload']['quotes']
                    : ['parcel_id' => $parcelId, 'quotes' => [], 'errors' => [$quoteOutcome['payload']['message']]];

                return ['ok' => true, 'payload' => [
                    'parcel_id' => $parcelId,
                    'quotes'    => $quotes,
                    'warnings'  => $warnings,
                ] + ($market === OrderContext::MARKET_WEBSITE && !$reusedCreation
                    ? ['parcel_payload' => self::orderFormSnapshot($payload)] : [])];
            } catch (SalamGateException $exception) {
                $error = self::errorPayload($exception);
                if (ParcelService::isResultUnknown($exception)) {
                    $error['code'] = 'create_unknown';
                    $error['message'] = 'نتیجه ثبت مرسوله نامعلوم است؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید.';
                }
                return ['ok' => false, 'payload' => $error];
            }
        };
        self::sendOutcome($existingParcelId > 0 ? self::withQuoteLease($existingParcelId, $work) : $work(static fn (): bool => true));
    }

    /**
     * دریافت نرخ‌های مرسوله سفارش.
     */
    public static function getQuotes(): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            wp_send_json_error(['message' => 'برای این سفارش هنوز مرسوله سلام‌گیت ساخته نشده است.']);
        }

        self::sendOutcome(self::withQuoteLease($parcelId, static fn (callable $renew): array => self::quotesOutcome($order, $parcelId, $renew)));
    }

    /**
     * انتخاب روش ارسال (hash نرخ).
     */
    public static function selectQuote(): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        $hash = isset($_POST['hash']) ? sanitize_text_field(wp_unslash($_POST['hash'])) : '';

        if ($parcelId <= 0 || $hash === '') {
            wp_send_json_error(['message' => 'پارامترهای انتخاب نرخ نامعتبر است.']);
        }

        self::sendOutcome(self::withQuoteLease($parcelId, static function (callable $renew) use ($order, $parcelId, $hash): array {
            $blocked = self::quoteMutationBlock($order, $parcelId);
            if ($blocked !== null) return $blocked;
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            $blocked = self::basalamQuoteMutationBlock($order, $parcelId);
            if ($blocked !== null) return $blocked;
            if (!$renew()) return self::quoteLeaseUnavailable();
            try {
                WebsiteShippingEligibility::assertForOrder($order);
                if (OrderContext::marketFor((int) $order->get_id()) === OrderContext::MARKET_WEBSITE
                    && get_option('salam_gate_parcel_edit_' . $parcelId, null) !== null) {
                    $outcome = SendActions::selectWebsiteOrderQuote($parcelId, $hash, $renew);
                    if (!$outcome['ok']) return $outcome;
                    $normalized = $outcome['payload']['quotes'];
                    $canonical = $outcome['payload']['parcel_payload'] ?? null;
                    $storedDraft = $order->get_meta('_salam_gate_order_draft_payload');
                    if (is_array($canonical) && is_array($storedDraft)) {
                        // Saved snapshots already carry the verified local display identity.
                        $localItems = $storedDraft['items'] ?? [];
                        foreach ($localItems as &$localItem) {
                            $localItem['_order_item_id'] = self::storedOrderItemId($localItem);
                        }
                        unset($localItem);
                        $canonical['items'] = self::associateRemoteItems($canonical['items'], $localItems);
                    }
                } else {
                    $response = ClientFactory::make()->put(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId), ['hash' => $hash]);
                    $normalized = self::checkedPaymentQuotes($response['body'] ?? null, $parcelId);
                }

                // A PUT response must confirm the exact requested selection before
                // storing a local service name or announcing successful selection.
                $selected = array_values(array_filter($normalized['quotes'],
                    static fn (array $quote): bool => $quote['selected'] === true && !$quote['error']));
                if (count($selected) !== 1 || $selected[0]['hash_id'] !== $hash) {
                    return ['ok' => false, 'payload' => ['message' => 'نرخ انتخابی تأیید نشد؛ دوباره نرخ‌ها را دریافت و انتخاب کنید.',
                        'code' => 'quote_changed', 'request_dispatched' => true]];
                }

                // سرویس انتخابی را ذخیره کن
                foreach ($normalized['quotes'] as $quote) {
                    if ($quote['selected'] && $quote['hash_id'] === $hash) {
                        $order->update_meta_data('_salam_gate_selected_service', $quote['service_name'] !== '' ? $quote['service_name'] : $quote['service_type_label']);
                        $order->save();
                        break;
                    }
                }

                return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'quotes' => $normalized]
                    + (isset($canonical) ? ['parcel_payload' => $canonical, 'requires_quote_selection' => false] : [])];
            } catch (SalamGateException $exception) {
                return ['ok' => false, 'payload' => self::errorPayload($exception)];
            }
        }));
    }

    /**
     * پرداخت و ثبت ارسال.
     * بسالم: فقط روش ۱۹ (کسر از مبلغ سفارش).
     * وب‌سایت: روش ۲۰/۲۱ از انتخاب کاربر/تنظیمات.
     *
     * نتیجه پرداخت «نامعلوم» (timeout بعد از pay، پاسخ لیست بدون تطبیق) هرگز
     * موفقیت جعلی نمی‌سازد — وضعیت بعداً از لیست/جزئیات reconcile می‌شود.
     */
    public static function payParcel(): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;
        $orderId = (int) $order->get_id();

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            wp_send_json_error(['message' => 'ابتدا مرسوله سلام‌گیت این سفارش را بسازید.']);
        }

        $market = OrderContext::marketFor($orderId);
        $method = self::resolvePayMethod($market, $_POST['method'] ?? $_POST['pay_method'] ?? null);

        $expectedExternal = OrderContext::externalIdForOrder($order, $market);
        $storedExternal = $order->get_meta('_salam_gate_external_id');
        $storedMarket = $order->get_meta('_salam_gate_market');
        if ($expectedExternal === null || ($storedMarket !== '' && $storedMarket !== $market)
            || ($storedExternal !== '' && ParcelService::positiveId($storedExternal) !== $expectedExternal)) {
            wp_send_json_error(['message' => 'ارتباط مرسوله با این سفارش تأیید نشد؛ ابتدا اطلاعات مرسوله را بررسی کنید.', 'code' => 'identity_mismatch']);
        }

        if (!PayMethods::isValidParcelMethodForMarket($method, $market)) {
            wp_send_json_error(['message' => 'روش پرداخت برای این بازار مجاز نیست.']);
        }

        $known = self::knownPaymentOutcome($order, $parcelId, $market);
        if ($known !== null) self::sendOutcome($known);
        $selectionBlock = self::quoteSelectionBlock($parcelId);
        if ($selectionBlock !== null) self::sendOutcome($selectionBlock);

        // Pin the service and amount the user reviewed; refreshing quotes can change selection.
        $expectedHash = isset($_POST['expected_hash']) && is_string($_POST['expected_hash'])
            ? trim(wp_unslash($_POST['expected_hash'])) : '';
        $rawTotal = $_POST['expected_total_rial'] ?? null;
        $expectedTotal = (is_int($rawTotal) || (is_string($rawTotal) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawTotal)))
            ? filter_var($rawTotal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($expectedHash === '' || strlen($expectedHash) > 256 || $expectedTotal === false) {
            wp_send_json_error(['message' => 'ابتدا نرخ ارسال و مبلغ نهایی را بررسی و تأیید کنید.', 'code' => 'quote_changed', 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => []]);
        }

        self::sendOutcome(self::withQuoteLease($parcelId, static function (callable $renew) use ($order, $parcelId, $market, $method, $expectedHash, $expectedTotal, $expectedExternal): array {
            // The previous operation may have settled between our first check and acquiring the lease.
            $known = self::knownPaymentOutcome($order, $parcelId, $market, true, $renew);
            if ($known !== null) return $known;
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            // An edit may have completed after the initial check while this payment waited for its lease.
            $selectionBlock = self::quoteSelectionBlock($parcelId);
            if ($selectionBlock !== null) return $selectionBlock;
            if (!$renew()) return self::quoteLeaseUnavailable();

            // Read current original-order eligibility under the same shipping lease.
            // Known paid/unknown outcomes above always take precedence over a new attempt.
            if ($market === OrderContext::MARKET_BASALAM) {
                try {
                    $preflight = BasalamShippingPreflight::check($order);
                    if ($preflight['identity']['parcel_id'] !== $expectedExternal) {
                        throw new SalamGateException('ارتباط مرسوله با سفارش اصلی باسلام تغییر کرده است؛ پرداخت انجام نشد.', 422);
                    }
                } catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => self::errorPayload($exception)];
                }
                if (!$renew()) return self::quoteLeaseUnavailable();
            }

            // A failed preflight is safe to retry and must never create an unknown-payment marker.
            try {
                $baseline = (new ParcelService())->fetchAfterPay($parcelId, $market,
                    $market === OrderContext::MARKET_BASALAM ? $expectedExternal : null);
                if ($baseline === null || ($baseline['status_id'] ?? null) !== ParcelStatuses::NEW
                    || !in_array($baseline['invoice_status'] ?? null, [null, 3, 5], true)
                    || !empty($baseline['tracking_code'])
                    || ($market === OrderContext::MARKET_BASALAM && ($baseline['external_id'] ?? null) !== $expectedExternal)
                    || ($market === OrderContext::MARKET_WEBSITE && ($baseline['external_id'] ?? null) !== null && $baseline['external_id'] !== $expectedExternal)) {
                    throw new SalamGateException('وضعیت مرسوله برای پرداخت تأیید نشد؛ ابتدا وضعیت را بررسی کنید.', 422);
                }
                try { OrderWeightConfirmation::assertConfirmed(wp_unslash($_POST), $baseline['weight'] ?? null); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => self::errorPayload($exception) + [
                        'code' => 'weight_confirmation_required',
                        'current_weight_grams' => Units::parseBoundedFormInteger($baseline['weight'] ?? null, 50, 30000),
                    ]];
                }
                if ($market === OrderContext::MARKET_BASALAM) {
                    try {
                        $detail = (new ParcelService())->getDetail($parcelId);
                        if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId) {
                            throw new SalamGateException('مشخصات مرسوله موجود برای پرداخت تأیید نشد.', 422);
                        }
                        BasalamShippingPreflight::assertOriginalFinancials($preflight, $detail);
                        $receiverErrors = BasalamShippingPreflight::receiverInputErrors(self::receiverFromDetail($detail));
                        if ($receiverErrors !== []) {
                            return ['ok' => false, 'payload' => ['message' => 'مشخصات گیرنده مرسوله را پیش از پرداخت کامل کنید.',
                                'errors' => $receiverErrors, 'code' => 'validation', 'kind' => 'validation']];
                        }
                    } catch (SalamGateException $exception) {
                        return ['ok' => false, 'payload' => self::errorPayload($exception) + ['code' => 'basalam_parcel_mismatch']];
                    }
                    if (!$renew()) return self::quoteLeaseUnavailable();
                }
                WebsiteShippingEligibility::assertForOrder($order);
                $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
                $quotes = self::checkedPaymentQuotes($response['body'] ?? null, $parcelId);
            } catch (SalamGateException $exception) {
                if ($exception->errorsAt('order.status') !== []) {
                    return ['ok' => false, 'payload' => self::errorPayload($exception)];
                }
                return ['ok' => false, 'payload' => [
                    'message' => 'تأیید نرخ ارسال انجام نشد؛ دوباره نرخ‌ها را بررسی کنید. پرداخت انجام نشد.',
                    'code' => 'quote_check_failed', 'kind' => SalamGateException::KIND_VALIDATION,
                    'errors' => $exception->getErrors(),
                ]];
            }
            $selected = array_values(array_filter($quotes['quotes'], static fn (array $quote): bool => $quote['selected'] && !$quote['error']));
            if (count($selected) !== 1 || $selected[0]['hash_id'] !== $expectedHash || $selected[0]['total_rial'] !== $expectedTotal) {
                return ['ok' => false, 'payload' => [
                    'message' => 'نرخ یا مبلغ ارسال تغییر کرده است؛ دوباره انتخاب و تأیید کنید. پرداخت انجام نشد.',
                    'code' => 'quote_changed', 'quotes' => $quotes, 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
                ]];
            }
            if (!$renew()) return self::quoteLeaseUnavailable();

            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }

            try {
                $service = new ParcelService();
                $payBody = $service->pay($parcelId, $method, $baseline);

                try {
                    $parcelItem = $service->fetchAfterPay($parcelId, $market,
                        $market === OrderContext::MARKET_BASALAM ? $expectedExternal : null);
                } catch (SalamGateException $fetchException) {
                    // pay موفق بود اما وضعیت خوانده نشد — نتیجه نهایی نامعلوم، نه شکست قطعی.
                    $parcelItem = null;
                }

                return ['ok' => true, 'payload' => self::applyPaySuccess($order, $payBody, $parcelItem, $market)];
            } catch (SalamGateException $exception) {
                $notDispatched = PaymentRejection::notDispatchedPayload($exception, $parcelId);
                if ($notDispatched !== null) { return ['ok' => false, 'payload' => $notDispatched]; }
                $rejection = PaymentRejection::insufficientFundsPayload($exception, $parcelId, $method, $baseline);
                if ($rejection !== null) {
                    return ['ok' => false, 'payload' => $rejection + ['order_id' => (int) $order->get_id()]];
                }
                if (ParcelService::isResultUnknown($exception)) {
                    // درخواست pay قطع شد — ممکن است انجام شده باشد؛ کورکورانه تکرار ممنوع.
                    $order->update_meta_data('_salam_gate_pay_unknown', current_time('mysql'));
                    $order->save();
                    return ['ok' => false, 'payload' => [
                        'message' => 'پرداخت در حال بررسی است؛ تأیید آن هنوز دریافت نشده است. «بررسی وضعیت» را بزنید و پرداخت را تکرار نکنید.',
                        'code'    => 'pay_unknown',
                    ]];
                }

                return ['ok' => false, 'payload' => self::errorPayload($exception)];
            }
        }));
    }

    /**
     * روش پرداخت مجاز هر بازار — بسالم فقط ۱۹؛ وب‌سایت ۲۰/۲۱ (وگرنه پیش‌فرض تنظیمات).
     */
    public static function resolvePayMethod(string $market, $raw): int
    {
        if ($market === OrderContext::MARKET_BASALAM) {
            return PayMethods::PARCEL_ORDER_BALANCE;
        }

        $method = (int) Digits::toEnglish((string) $raw);

        return PayMethods::isValidParcelMethodForMarket($method, $market)
            ? $method
            : (int) SalamGateSettings::instance()->get(SalamGateSettings::KEY_PAY_METHOD_DEFAULT);
    }

    /**
     * اثر پرداخت موفق روی سفارش وو (متا/یادداشت/وضعیت) — جدا برای تست.
     *
     * @param array|null $parcelItem رکورد لیست نرمال‌شده یا null = وضعیت نامعلوم
     * @param \WC_Order|BasalamParcelOrder $order
     *
     * @return array خلاصه برای پاسخ AJAX
     */
    public static function applyPaySuccess(object $order, array $payBody, ?array $parcelItem, string $market = OrderContext::MARKET_BASALAM): array
    {
        OrderContext::assertSupportedOrder($order);
        $statusId = (int) ($parcelItem['status_id'] ?? 0);
        $trackingCode = (string) ($parcelItem['tracking_code'] ?? '');

        // وضعیت معتبر فقط از پاسخ لیست با رسید پستی — پیش‌فرض/جعل ممنوع.
        $confirmed = $parcelItem !== null && ParcelStatuses::hasReceipt($statusId);

        $invoiceId = isset($payBody['invoice']['id']) ? (int) $payBody['invoice']['id'] : 0;
        if ($confirmed && !ParcelService::recordReceiptOutcome($parcelItem, $invoiceId > 0 ? $invoiceId : null)) {
            $confirmed = false;
        }
        $deliveryNode = (string) ($payBody['delivery_node']['title'] ?? '');
        if ($deliveryNode === '') {
            $deliveryNode = (string) ($parcelItem['delivery_node'] ?? '');
        }

        // رسید پرداخت همیشه ثبت می‌شود (ناشی از پاسخ خود pay):
        if ($invoiceId > 0) {
            $order->update_meta_data('_salam_gate_invoice_id', $invoiceId);
        }
        $invoiceStatus = $payBody['invoice']['status'] ?? null;
        $shippingCostRial = $invoiceStatus === null || ParcelService::positiveId($invoiceStatus) === 2
            ? ParcelViewNormalizer::invoiceAmountRial($payBody) : null;
        // A reconciled timeout has no pay body. Use its paid raw invoice charge, never a quote
        // or a reverse conversion of display toman; otherwise preserve the existing metadata.
        if ($shippingCostRial === null && $confirmed && ($parcelItem['invoice_status'] ?? null) === 2
            && is_int($parcelItem['shipping_cost_rial'] ?? null) && $parcelItem['shipping_cost_rial'] >= 0) {
            $shippingCostRial = $parcelItem['shipping_cost_rial'];
        }
        if ($shippingCostRial !== null) { $order->update_meta_data('_salam_gate_shipping_cost_rial', $shippingCostRial); }
        $order->update_meta_data('_salam_gate_paid_at', current_time('mysql'));

        if ($confirmed) {
            $order->update_meta_data('_salam_gate_pay_unknown', '');
            $order->update_meta_data('_salam_gate_tracking_code', $trackingCode);
            $order->update_meta_data('_salam_gate_status', $statusId);
            $order->update_meta_data('_salam_gate_delivery_node', $deliveryNode);

            if ($market === OrderContext::MARKET_BASALAM) {
                // سازگاری با پلاگین پایه (نمایش کد رهگیری در بقیه UIهای ووسلام)
                $order->update_meta_data('_basalam_order_tracking_code', $trackingCode);
            }
        }

        $order->save();

        $settings = SalamGateSettings::instance();
        $autoShip = (int) $settings->get(SalamGateSettings::KEY_AUTO_SET_SHIPPED) === 1;
        $currentStatus = $order->get_status();

        if ($confirmed && $trackingCode !== ''
            && $market === OrderContext::MARKET_BASALAM
            && $autoShip
            && in_array($currentStatus, ['bslm-wait-vendor', 'bslm-preparation'], true)
        ) {
            $order->update_status('bslm-shipping', 'ارسال با سلام‌گیت ثبت شد. کد رهگیری: ' . $trackingCode);
        } elseif ($confirmed && $trackingCode !== '') {
            $order->add_order_note('پرداخت ارسال با سلام‌گیت انجام شد. کد رهگیری: ' . $trackingCode);
        } else {
            // پرداخت انجام شد اما وضعیت پستی هنوز نامشخص است — هیچ موفقیتی جعل نمی‌شود.
            $order->add_order_note('پرداخت هزینه ارسال سلام‌گیت ثبت شد'
                . ($invoiceId > 0 ? ' (رسید #' . $invoiceId . ')' : '')
                . ' اما وضعیت بارکد پستی هنوز تأیید نشد؛ از فهرست مرسولات دوباره بررسی کنید.');
        }

        return [
            'parcel_id'         => (int) ($parcelItem['id'] ?? ((int) ($order->get_meta('_salam_gate_parcel_id')))),
            'tracking_code'     => $confirmed ? $trackingCode : '',
            'status_id'         => $confirmed ? $statusId : 0,
            'status_title'      => $confirmed ? (string) ($parcelItem['status_title'] ?? '') : 'در حال بررسی',
            'confirmed'         => $confirmed,
            'invoice_id'        => $invoiceId,
            'final_total_rial'  => $shippingCostRial,
            'final_total_toman' => $shippingCostRial !== null ? Units::toToman($shippingCostRial) : null,
            'delivery_node'     => $deliveryNode,
            'post_tracking_url' => $confirmed && $trackingCode !== '' ? ParcelService::postTrackingUrl($trackingCode) : null,
        ];
    }

    /**
     * سازگاری مسیر قدیمی: لینک رهگیری پست، بدون درخواست سابقه از سلام‌گیت.
     */
    public static function getTrack(): void
    {
        self::guard();

        $order = self::orderOrError();
        if (!$order) return;

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            wp_send_json_error(['message' => 'مرسوله‌ای برای پیگیری وجود ندارد.']);
            return;
        }

        try {
            $code = $order->get_meta('_salam_gate_tracking_code');
            wp_send_json_success((new \SyncBasalam\SalamGate\Services\TrackingService())->lookup(is_string($code) ? $code : ''));
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * ساخت لیبل برای مرسوله سفارش (قالب پیش‌فرض از تنظیمات).
     */
    public static function getLabel(): void
    {
        self::guard();

        // Printing an existing receipt is not a new shipping workflow. In
        // particular, a delivered/cancelled native order must not reopen the
        // source-order registration preflight or migrate another child receipt.
        $order = self::labelOrderOrError();
        if (!$order) return;

        $parcelId = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $market = OrderContext::marketFor((int) $order->get_id());
        $externalId = OrderContext::externalIdForOrder($order, $market);
        $savedMarket = $order->get_meta('_salam_gate_market');
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        if ($parcelId === null || $externalId === null
            || ($savedMarket !== '' && $savedMarket !== $market)
            || ($savedExternal !== '' && ParcelService::positiveId($savedExternal) !== $externalId)) {
            wp_send_json_error(['message' => 'ارتباط مرسوله این سفارش برای چاپ تأیید نشد؛ وضعیت ارسال را بررسی کنید.',
                'code' => 'label_not_ready', 'request_dispatched' => false]);
        }

        $template = is_string($_POST['template'] ?? null) ? sanitize_text_field(wp_unslash($_POST['template'])) : '';
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) {
            $template = (string) SalamGateSettings::instance()->get(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT);
        }
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) { $template = 'invoice'; }

        $requestDispatched = false;
        try {
            $service = new ParcelService();
            // The local badge may predate a refund/void. Verify the exact
            // authenticated parcel and market. Legacy website receipts may
            // omit external_id; a present conflicting ID is still rejected.
            // Basalam must always match its exact original child identity.
            $parcel = $service->fetchAfterPay(
                $parcelId,
                $market,
                $externalId,
                $market === OrderContext::MARKET_WEBSITE
            );
            if ($parcel === null || !LabelEligibility::canPrint($parcel)) {
                wp_send_json_error(['message' => 'برچسب پستی پس از پرداخت موفق و صدور کد رهگیری در دسترس است؛ رسید ابطال‌شده یا هزینه عودت‌شده قابل چاپ نیست.',
                    'code' => 'label_not_ready', 'request_dispatched' => false]);
            }
            $requestDispatched = true;
            $result = $service->getLabelUrl([$parcelId], $template);

            if ($result['status'] === 'completed') {
                $order->update_meta_data('_salam_gate_label', [
                    'url'       => $result['url'],
                    'expire_at' => $result['expire_at'] ?? null,
                ]);
                $order->save();
            }

            wp_send_json_success($result);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception) + ['request_dispatched' => $requestDispatched]);
        }
    }

    /**
     * پیگیری وضعیت درخواست لیبل.
     */
    public static function labelStatus(): void
    {
        self::guard();

        $rawRequestId = $_POST['request_id'] ?? 0;
        $requestId = in_array($rawRequestId, [0, '0', ''], true) ? 0 : ParcelService::positiveId($rawRequestId);
        if ($requestId === null) {
            wp_send_json_error(['message' => 'شناسه درخواست برچسب معتبر نیست.']);
        }

        try {
            wp_send_json_success((new ParcelService())->getLabelStatus($requestId));
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * رندر مجدد بدنه متاباکس بعد از عملیات (بدون مودال).
     */
    public static function refreshMetabox(): void
    {
        self::guard();

        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order) {
            wp_send_json_error(['message' => 'سفارش یافت نشد.']);
        }

        // Backfill an absent status from the authenticated, identity-checked list only.
        // The status read cannot create a parcel, select a rate or retry a payment.
        (new StatusSyncService())->syncMissingOrder($orderId);
        $order = wc_get_order($orderId);

        ob_start();
        $template = dirname(__DIR__, 4) . '/templates/SalamGate/order-metabox-box.php';
        if (file_exists($template)) {
            include $template;
        }
        $html = (string) ob_get_clean();

        // List actions reuse this guarded read; cached rendering must not create
        // a parcel or infer a payable state from the physical-shipping badge.
        $rowHtml = '';
        ob_start();
        \SyncBasalam\SalamGate\Admin\OrderShippingStatus::render(
            \SyncBasalam\SalamGate\Admin\OrderShippingStatus::COLUMN, $order
        );
        \SyncBasalam\SalamGate\Admin\OrderShippingStatus::render(
            \SyncBasalam\SalamGate\Admin\OrderShippingStatus::ACTION_COLUMN, $order
        );
        $rowHtml = (string) ob_get_clean();
        wp_send_json_success(['html' => $html, 'order_id' => $orderId,
            'list_action' => \SyncBasalam\SalamGate\Admin\OrderListAction::forOrder($order), 'row_html' => $rowHtml]);
    }

    /** Explicit read-only discovery. Never creates, imports, selects rates or pays. */
    public static function nativeParcels(): void
    {
        self::guard();
        $orderId = ParcelService::positiveId($_POST['order_id'] ?? null);
        $root = $orderId !== null ? wc_get_order($orderId) : null;
        if (!$root instanceof \WC_Order || !BasalamOrderContext::isBasalamOrder($orderId)) {
            wp_send_json_error(['message' => 'این سفارش، سفارش معتبر باسلام نیست.']);
        }
        $cursor = $_POST['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) { wp_send_json_error(['message' => 'صفحه بعد مرسوله‌ها معتبر نیست.']); }
        try {
            require_once dirname(__DIR__, 2) . '/Order/BasalamParcelSet.php';
            require_once dirname(__DIR__, 2) . '/Order/BasalamParcelOrder.php';
            $result = BasalamParcelSet::discover($root, $cursor);
            $legacy = $root->get_meta('_salam_gate_basalam_mapping');
            $legacyId = is_array($legacy) ? ParcelService::positiveId($legacy['parcel_id'] ?? null) : null;
            $legacyMatches = $legacyId !== null && $legacy === ['order_id' => $orderId,
                'invoice_id' => BasalamOrderContext::getInvoiceId($orderId),
                'vendor_id' => (int) syncBasalamSettings()->getSettings('vendor_id'), 'parcel_id' => $legacyId]
                && in_array($root->get_meta('_salam_gate_market'), ['', 'basalam'], true)
                && in_array($root->get_meta('_salam_gate_external_id'), ['', $legacyId, (string) $legacyId], true);
            foreach ($result['parcels'] as &$row) {
                $scope = BasalamParcelOrder::stored($root, $row['basalam_parcel_id']);
                if ($scope === null && $legacyMatches && $legacyId === $row['basalam_parcel_id']) { $scope = $root; }
                $row['has_local'] = $scope !== null && (int) $scope->get_meta('_salam_gate_parcel_id') > 0;
                $row['local_status_title'] = $scope !== null ? ParcelStatuses::title((int) $scope->get_meta('_salam_gate_status')) : '';
                $row['tracking_code'] = $scope !== null ? (string) $scope->get_meta('_salam_gate_tracking_code') : '';
            }
            unset($row);
            wp_send_json_success($result);
        } catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }
    }

    // ---------------------------------------------------------------

    /** @return \WC_Order|BasalamParcelOrder|null Stored identity plus fresh ownership proof. */
    private static function labelOrderOrError(): ?object
    {
        $orderId = ParcelService::positiveId($_POST['order_id'] ?? null);
        $root = $orderId !== null ? wc_get_order($orderId) : null;
        if (!$root instanceof \WC_Order) {
            wp_send_json_error(['message' => 'سفارش برای چاپ برچسب یافت نشد.', 'request_dispatched' => false]);
        }
        $isNative = BasalamOrderContext::isBasalamOrder($orderId);
        $rawNative = $_POST['basalam_parcel_id'] ?? null;
        if (!in_array($rawNative, [null, '', 0, '0'], true)) {
            $nativeId = ParcelService::positiveId($rawNative);
            if (!$isNative || $nativeId === null) {
                wp_send_json_error(['message' => 'مرسوله انتخاب‌شده برای چاپ معتبر نیست.', 'request_dispatched' => false]);
            }
            $scope = BasalamParcelOrder::stored($root, $nativeId);
            if ($scope !== null) { return $scope; }
            // Preserve explicitly mapped pre-migration single parcels only.
            if (BasalamParcelOrder::hasScopes($root) || BasalamOrderContext::getParcelId($orderId) !== $nativeId) {
                wp_send_json_error(['message' => 'ارتباط ذخیره‌شده این مرسوله با سفارش تأیید نشد.', 'request_dispatched' => false]);
            }
        } elseif ($isNative && BasalamParcelOrder::hasScopes($root)) {
            wp_send_json_error(['message' => 'مرسوله موردنظر را برای چاپ برچسب انتخاب کنید.',
                'code' => 'parcel_selection_required', 'request_dispatched' => false]);
        }
        return $root;
    }

    /** Same lease namespace as standalone SendActions: rate GETs can mutate selection too. */
    private static function withQuoteLease(int $parcelId, callable $work): array
    {
        $key = 'salam_gate_quote_workflow_' . $parcelId;
        $lease = OptionLock::acquire($key);
        if ($lease === null) return self::quoteLeaseUnavailable();
        try {
            $renew = static function () use ($key, &$lease): bool {
                $next = OptionLock::renew($key, $lease);
                if ($next === null) return false;
                $lease = $next;
                return true;
            };
            return $work($renew);
        } finally {
            // wp_send_json terminates PHP; emit JSON only after this scope releases its lease.
            OptionLock::release($key, $lease);
        }
    }

    private static function quoteLeaseUnavailable(): array
    {
        return ['ok' => false, 'payload' => [
            'message' => 'عملیات دیگری روی این مرسوله در حال اجراست؛ کمی بعد دوباره تلاش کنید. درخواست پرداخت ارسال نشد.',
            'code' => 'quote_busy', 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
        ]];
    }

    private static function sendOutcome(array $outcome): void
    {
        if ($outcome['ok']) wp_send_json_success($outcome['payload']);
        wp_send_json_error($outcome['payload']);
    }

    private static function quoteSelectionBlock(int $parcelId): ?array
    {
        $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
        if (!is_array($edit) || ($edit['state'] ?? '') !== 'selection_required') return null;
        return ['ok' => false, 'payload' => [
            'message' => 'اطلاعات مرسوله تغییر کرده است؛ روش ارسال را دوباره انتخاب کنید.',
            'code' => 'quote_selection_required', 'requires_quote_selection' => true,
            'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
        ]];
    }

    /** Do not mutate quotes or parcel data while a financial outcome is unresolved or settled. */
    private static function quoteMutationBlock($order, int $parcelId): ?array
    {
        // Standalone edits may have changed the parcel's market before a timeout.
        // Preserve its durable uncertainty across the order and standalone entry points.
        $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
        if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
            return ['ok' => false, 'payload' => [
                'message' => 'نتیجه ویرایش مرسوله تأیید نشد؛ همان مرسوله را در فهرست بررسی کنید و مرسوله تازه ثبت نکنید.',
                'code' => 'update_unknown', 'parcel_id' => $parcelId,
                'market' => $edit['original_market'] ?? $edit['market'] ?? '',
                'attempted_market' => $edit['market'] ?? '', 'requires_quote_selection' => true,
            ]];
        }
        $stored = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        if ($order->get_meta('_salam_gate_pay_unknown') || (is_array($stored) && ($stored['state'] ?? '') !== 'paid')) {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت در حال بررسی است؛ برای دریافت تأیید، وضعیت را از فهرست مرسوله‌ها بررسی کنید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
        }
        if (is_array($stored) && ($stored['state'] ?? '') === 'paid'
            || $order->get_meta('_salam_gate_paid_at') || (int) $order->get_meta('_salam_gate_invoice_id') > 0) {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت هزینه ارسال این مرسوله قبلاً ثبت شده است؛ وضعیت را از فهرست مرسولات بررسی کنید.', 'code' => 'already_paid']];
        }
        return null;
    }

    private static function knownPaymentOutcome($order, int $parcelId, string $market, bool $leaseHeld = false, ?callable $renew = null): ?array
    {
        $blocked = self::quoteMutationBlock($order, $parcelId);
        if ($blocked === null || $blocked['payload']['code'] !== 'pay_unknown') return $blocked;
        $expectedExternal = null;
        try {
            if ($market === OrderContext::MARKET_BASALAM) {
                $expectedExternal = OrderContext::externalIdForOrder($order, $market);
                $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
                if ($expectedExternal === null || (is_array($previous)
                    && !ParcelService::matchesPaymentIdentity(['market' => $market, 'external_id' => $expectedExternal], $previous))) {
                    return $blocked;
                }
            }
            $item = (new ParcelService())->fetchAfterPay($parcelId, $market, $expectedExternal);
        } catch (SalamGateException $exception) {
            $item = null;
        }
        if ($item !== null && ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            && ($market !== OrderContext::MARKET_BASALAM || (($item['invoice_status'] ?? null) === 2
                && is_string($item['tracking_code'] ?? null) && trim($item['tracking_code']) !== ''))) {
            return ['ok' => true, 'payload' => self::applyPaySuccess($order, [], $item, $market)];
        }
        if ($item !== null && ($item['invoice_status'] ?? null) === 3) {
            $work = static function (callable $refresh) use ($order, $parcelId, $market, $blocked, $expectedExternal): array {
                try { $fresh = (new ParcelService())->fetchAfterPay($parcelId, $market, $expectedExternal); }
                catch (SalamGateException $exception) { return $blocked; }
                if ($fresh !== null && $refresh() && ParcelService::reconcileFailedPayment($fresh)) {
                    if (!$order->get_meta('_salam_gate_paid_at') && !ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id'))) {
                        $order->update_meta_data('_salam_gate_pay_unknown', '');
                        $order->save();
                    }
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی ناموفق بود؛ نرخ ارسال و روش پرداخت را دوباره بررسی و تأیید کنید.', 'code' => 'payment_failed']];
                }
                return $blocked;
            };
            return $leaseHeld && $renew !== null ? $work($renew) : self::withQuoteLease($parcelId, $work);
        }
        return $blocked;
    }

    private static function quotesOutcome($order, int $parcelId, callable $renew): array
    {
        $blocked = self::quoteMutationBlock($order, $parcelId);
        if ($blocked !== null) return $blocked;
        $eligibility = WebsiteShippingEligibility::blockForOrder($order);
        if ($eligibility !== null) { return $eligibility; }
        $blocked = self::basalamQuoteMutationBlock($order, $parcelId);
        if ($blocked !== null) return $blocked;
        if (!$renew()) return self::quoteLeaseUnavailable();
        try {
            WebsiteShippingEligibility::assertForOrder($order);
            return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'quotes' => self::fetchNormalizedQuotes($parcelId)]];
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => self::errorPayload($exception)];
        }
    }

    /**
     * Cross-panel financial state is checked under the shared lease before stateful quotes/upsert.
     * Keep this separate from knownPaymentOutcome: successful payment reconciliation must still work.
     * @param \WC_Order|BasalamParcelOrder $order
     */
    private static function basalamQuoteMutationBlock(object $order, int $parcelId): ?array
    {
        OrderContext::assertSupportedOrder($order);
        if (OrderContext::marketFor((int) $order->get_id()) !== OrderContext::MARKET_BASALAM) { return null; }
        try {
            // Rate GET/selection changes the remote draft; source cancellation must
            // stop these calls too, not just the final upsert/payment POST.
            BasalamShippingPreflight::check($order);
            $externalId = OrderContext::externalIdForOrder($order, OrderContext::MARKET_BASALAM);
            $remote = (new ParcelService())->fetchAfterPay($parcelId, OrderContext::MARKET_BASALAM, $externalId);
            if ($externalId === null || $remote === null || ($remote['external_id'] ?? null) !== $externalId) {
                throw new SalamGateException('ارتباط مرسوله موجود با سفارش باسلام تأیید نشد؛ وضعیت ارسال را دوباره بررسی کنید.', 422);
            }
            if (ParcelStatuses::hasReceipt($remote['status_id']) || ($remote['invoice_status'] ?? null) === 2 || !empty($remote['tracking_code'])) {
                return ['ok' => false, 'payload' => ['message' => 'این مرسوله قبلاً پرداخت یا ارسال شده است؛ وضعیت ارسال را بررسی کنید.', 'code' => 'already_paid']];
            }
            if ($remote['status_id'] !== ParcelStatuses::NEW || !in_array($remote['invoice_status'] ?? null, [null, 3, 5], true)) {
                return ['ok' => false, 'payload' => ['message' => 'وضعیت پرداخت این مرسوله در سلام‌گیت هنوز قابل تغییر نیست؛ کمی بعد وضعیت ارسال را دوباره بررسی کنید.',
                    'code' => 'payment_check_required', 'kind' => 'validation', 'errors' => []]];
            }
            return null;
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => self::errorPayload($exception) + ['code' => 'payment_check_required']];
        }
    }

    private static function fetchNormalizedQuotes(int $parcelId): array
    {
        $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));

        return QuoteNormalizer::normalize(is_array($response['body'] ?? null) ? $response['body'] : []);
    }

    /** Validate financial fields before normalization can coerce malformed response values. */
    private static function checkedPaymentQuotes($body, int $parcelId): array
    {
        $invalid = static fn (): SalamGateException => new SalamGateException(
            'پاسخ نرخ ارسال معتبر نیست؛ انتخاب و مبلغ تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE
        );
        if (!is_array($body) || ParcelService::positiveId($body['parcel_id'] ?? null) !== $parcelId
            || !is_array($body['quotes'] ?? null)) {
            throw $invalid();
        }
        foreach ($body['quotes'] as $quote) {
            if (!is_array($quote) || !is_bool($quote['selected'] ?? null)
                || (isset($quote['error']) && !is_string($quote['error']))) {
                throw $invalid();
            }
            if (($quote['error'] ?? '') !== '' && ($quote['error'] ?? null) !== null) {
                if ($quote['selected']) throw $invalid();
                continue;
            }
            if (!is_int($quote['service_type']['id'] ?? null) || $quote['service_type']['id'] < 1
                || !is_string($quote['hash_id'] ?? null) || $quote['hash_id'] === '' || strlen($quote['hash_id']) > 256
                || !is_int($quote['costs']['total'] ?? null) || $quote['costs']['total'] < 0) {
                throw $invalid();
            }
        }
        return QuoteNormalizer::normalize($body);
    }

    /**
     * ورودی‌های فرم مودال → overrides سازنده payload.
     *
     * ورودی مبلغ فرم «تومان» است و همیشه ×۱۰ تبدیل می‌شود (مستقل از ارز فروشگاه) —
     * تبدیل مبالغ خود سفارش ووکامرس جداگانه در ParcelPayloadBuilder انجام می‌شود.
     */
    public static function numericInputErrors(array $input, ?string $market = null): array
    {
        $errors = [];
        // Missing fields may use the order's defaults; explicitly invalid fields may not.
        if (array_key_exists('weight', $input) && Units::parseBoundedFormInteger($input['weight'], 50, 30000) === null) {
            $errors['weight'] = 'وزن باید عدد صحیح بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.';
        }
        $nonStandard = isset($input['is_non_standard']) && is_scalar($input['is_non_standard']) && trim((string) $input['is_non_standard']) !== '';
        $basalam = $market === OrderContext::MARKET_BASALAM;
        if ($basalam && (array_key_exists('is_non_standard', $input) || array_key_exists('proposed_price', $input))) {
            $errors['value_toman'] = 'برای سفارش باسلام، ارزش مرسوله را در فیلد ارزش وارد کنید؛ قیمت پیشنهادی کاربرد ندارد.';
        }
        $valueKey = $nonStandard ? 'proposed_price' : 'value_toman';
        if (array_key_exists($valueKey, $input) || $nonStandard) {
            $value = $input[$valueKey] ?? null;
            $parsedValue = $basalam
                ? Units::parseFormTomanToRial($value, BasalamShippingPreflight::MIN_DECLARED_VALUE_RIAL,
                    BasalamShippingPreflight::MAX_DECLARED_VALUE_RIAL)
                : Units::parseBoundedFormInteger($value, 2000, 1000000000, true);
            if ($parsedValue === null) {
                $errors[$valueKey] = $basalam ? 'ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان، با حداکثر یک رقم اعشار باشد.'
                    : 'ارزش مرسوله باید عدد صحیح بین ۲٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.';
            }
        }
        if (Units::parseBoundedFormInteger($input['box_type_id'] ?? null, 1, 2147483647) === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            foreach (['length', 'width', 'height'] as $dimension) {
                $key = 'dimension_' . $dimension;
                if (Units::parseBoundedFormInteger($input[$key] ?? null, $basalam ? 10 : 1, $basalam ? 100 : 2147483647) === null) {
                    $errors[$key] = $basalam ? 'بُعد بسته باید عدد صحیح بین ۱۰ تا ۱۰۰ سانتی‌متر باشد.'
                        : 'بُعد بسته باید عدد صحیح و حداقل ۱ سانتی‌متر باشد.';
                }
            }
        }
        if (array_key_exists('item_net_weights', $input)) {
            if (!is_array($input['item_net_weights'])) {
                $errors['item_net_weights'] = 'وزن اقلام معتبر نیست.';
            } else {
                foreach ($input['item_net_weights'] as $id => $weight) {
                    if (Units::parseBoundedFormInteger($weight, 1, 2147483647) === null) {
                        $errors['item_net_weights.' . $id] = 'وزن خالص باید عدد صحیح و حداقل ۱ گرم باشد.';
                    }
                }
            }
        }
        if (array_key_exists('items', $input)) {
            if (!is_array($input['items']) || $input['items'] === []) {
                $errors['items'] = 'حداقل یک قلم معتبر وارد کنید.';
            } else {
                $padding = max(0, (int) SalamGateSettings::instance()->get(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS));
                foreach ($input['items'] as $index => $item) {
                    $prefix = 'items.' . $index . '.';
                    if (!is_array($item)) { $errors['items'] = 'اطلاعات اقلام معتبر نیست.'; continue; }
                    if (!isset($item['title']) || !is_scalar($item['title']) || trim(sanitize_text_field((string) $item['title'])) === '') {
                        $errors[$prefix . 'title'] = 'عنوان قلم را وارد کنید.';
                    }
                    $quantity = Units::parseBoundedFormInteger($item['quantity'] ?? null, 1, 2147483647);
                    $netWeight = Units::parseBoundedFormInteger($item['net_weight'] ?? null, 1, max(1, 2147483647 - $padding));
                    $priceInput = $item['price_toman'] ?? '';
                    $price = is_string($priceInput) && trim($priceInput) === '' ? 1000
                        : Units::parseBoundedFormInteger($priceInput, 1000, 1000000000, true);
                    if ($quantity === null) { $errors[$prefix . 'quantity'] = 'تعداد باید عدد صحیح و حداقل ۱ باشد.'; }
                    if ($netWeight === null) { $errors[$prefix . 'net_weight'] = 'وزن خالص قلم معتبر نیست.'; }
                    if ($price === null) { $errors[$prefix . 'price_toman'] = 'قیمت قلم باید حداقل ۱٬۰۰۰ تومان باشد.'; }
                    $localId = $item['_order_item_id'] ?? 0;
                    if ($localId !== 0 && $localId !== '0' && self::canonicalItemId($localId) === null) {
                        $errors[$prefix . '_order_item_id'] = 'شناسه قلم سفارش معتبر نیست.';
                    }
                }
            }
            if (Units::parseBoundedFormInteger($input['category_id'] ?? null, 1, 2147483647) === null) {
                $errors['category_id'] = 'نوع مرسوله را انتخاب کنید.';
            }
        }
        return $errors;
    }

    /** Validated form fields override the order defaults without numeric truncation. */
    private static function overridesFromRequest(?string $market = null): array
    {
        $post = static fn (string $key) => isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';

        $nonStandard = $post('is_non_standard') !== '';
        $valueToman = Units::parseBoundedFormInteger($nonStandard ? $post('proposed_price') : $post('value_toman'), 2000, 1000000000, true) ?? 0;
        $valueRial = $market === OrderContext::MARKET_BASALAM
            ? (Units::parseFormTomanToRial($post('value_toman'), BasalamShippingPreflight::MIN_DECLARED_VALUE_RIAL,
                BasalamShippingPreflight::MAX_DECLARED_VALUE_RIAL) ?? 0)
            : ($valueToman > 0 ? Units::formTomanToRial($valueToman) : 0);
        $boxTypeId = (int) Digits::toEnglish($post('box_type_id'));

        $overrides = [
            'weight'         => (int) Digits::toEnglish($post('weight')),
            'value'          => $valueRial,
            'contents'       => $post('contents'),
            'category_id'    => (int) Digits::toEnglish($post('category_id')),
            'box_type_id'    => $boxTypeId,
            'is_liquid'      => $post('is_liquid') !== '',
            'is_fragile'     => $post('is_fragile') !== '',
            'name'           => $post('name'),
            'mobile'         => $post('mobile'),
            'address'        => $post('address'),
            'postal_code'    => $post('postal_code'),
            'city_id'        => (int) Digits::toEnglish($post('city_id')),
            'item_net_weights' => [],
        ];

        if ($boxTypeId === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            $overrides['dimensions'] = [
                'length' => (int) Digits::toEnglish($post('dimension_length')),
                'width'  => (int) Digits::toEnglish($post('dimension_width')),
                'height' => (int) Digits::toEnglish($post('dimension_height')),
            ];
        }

        if (isset($_POST['item_net_weights']) && is_array($_POST['item_net_weights'])) {
            foreach (wp_unslash($_POST['item_net_weights']) as $itemId => $netWeight) {
                $overrides['item_net_weights'][(int) $itemId] = (int) Digits::toEnglish((string) $netWeight);
            }
        }

        if (array_key_exists('items', $_POST)) {
            $overrides['items'] = [];
            foreach (wp_unslash($_POST['items']) as $item) {
                $price = $item['price_toman'] ?? '';
                $overrides['items'][] = [
                    'title' => sanitize_text_field(trim((string) $item['title'])),
                    'quantity' => Units::parseBoundedFormInteger($item['quantity'], 1, 2147483647),
                    'net_weight' => Units::parseBoundedFormInteger($item['net_weight'], 1, 2147483647),
                    'price_toman' => is_string($price) && trim($price) === '' ? 1000
                        : Units::parseBoundedFormInteger($price, 1000, 1000000000, true),
                    '_order_item_id' => self::canonicalItemId($item['_order_item_id'] ?? null) ?? 0,
                ];
            }
        }
        return $overrides;
    }

    /** Display only public sender information; provider identity and credentials stay server-side. */
    private static function senderDisplay(array $shop, ?array $savedSender = null): array
    {
        $address = $savedSender ?? (is_array($shop['address'] ?? null) ? $shop['address'] : []);
        $text = static fn ($value): string => is_scalar($value) ? sanitize_text_field((string) $value) : '';
        return [
            'name' => $text($address['full_name'] ?? ''),
            'city' => $text($address['city']['title'] ?? ''),
            'address' => $text($address['full_address'] ?? $address['address'] ?? ''),
            'mobile' => $text($address['phone'] ?? $shop['mobile'] ?? ''),
            'postal_code' => $text($address['postal_code'] ?? ''),
            'center' => $text($address['provider_node']['title'] ?? $shop['provider_node']['title'] ?? ''),
        ];
    }

    private static function editableRemoteItems($items): bool
    {
        if (!is_array($items) || $items === []) return false;
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null)) return false;
            foreach (['quantity', 'price', 'net_weight'] as $field) {
                if (ParcelService::positiveId($item[$field] ?? null) === null) return false;
            }
            if ((int) $item['price'] % 10 !== 0) return false;
        }
        return true;
    }

    private static function orderFormSnapshot(array $payload): array
    {
        $parcel = $payload['parcel'];
        $packaging = $payload['packaging'];
        $items = [];
        foreach ($payload['items'] as $item) {
            $items[] = ['title' => $item['title'], 'quantity' => $item['quantity'], 'net_weight' => $item['net_weight'],
                'price_toman' => isset($item['price']) ? Units::toToman((int) $item['price']) : null,
                '_order_item_id' => self::storedOrderItemId($item)];
        }
        return [
            'provider_shop_id' => $parcel['provider_shop_id'], 'category_id' => $parcel['category_id'] ?? null,
            'weight' => $parcel['weight'], 'value_toman' => Units::toToman((int) $parcel['value']),
            'box_type_id' => $packaging['box_type_id'],
            'dimension_length' => $packaging['dimensions']['length'] ?? '',
            'dimension_width' => $packaging['dimensions']['width'] ?? '',
            'dimension_height' => $packaging['dimensions']['height'] ?? '',
            'is_liquid' => !empty($parcel['is_liquid']), 'is_fragile' => !empty($parcel['is_fragile']),
            'receiver' => $payload['receiver'], 'items' => $items,
        ];
    }

    private static function saveOrderDraft($order, int $parcelId, array $payload): void
    {
        $snapshot = [];
        foreach (['parcel', 'packaging', 'receiver', 'items'] as $key) {
            if (is_array($payload[$key] ?? null)) { $snapshot[$key] = $payload[$key]; }
        }
        $providerId = ParcelService::positiveId($snapshot['parcel']['provider_shop_id'] ?? null);
        $categoryId = ParcelService::positiveId($snapshot['parcel']['category_id'] ?? null);
        if ($providerId !== null && $categoryId !== null) {
            update_option('salam_gate_parcel_context_' . $parcelId, ['provider_shop_id' => $providerId, 'category_id' => $categoryId], false);
        }
        $order->update_meta_data('_salam_gate_order_draft_parcel_id', $parcelId);
        $order->update_meta_data('_salam_gate_order_draft_payload', $snapshot);
        $order->save();
    }

    /** Restore only confirmed website replacements; unknown attempts never create another parcel. */
    private static function recoverConfirmedOrderReplacement($order): void
    {
        if (OrderContext::marketFor((int) $order->get_id()) !== OrderContext::MARKET_WEBSITE) return;
        $originalId = (int) $order->get_meta('_salam_gate_parcel_id');
        $currentId = $originalId;
        $seen = [];
        $resolved = false;
        for ($hop = 0; $hop < 20 && $currentId > 0; ++$hop) {
            if (isset($seen[$currentId])) return;
            $seen[$currentId] = true;
            $marker = get_option('salam_gate_parcel_edit_' . $currentId, null);
            if (!is_array($marker) || ($marker['state'] ?? '') !== 'replaced') { $resolved = true; break; }
            if (($marker['market'] ?? '') !== OrderContext::MARKET_WEBSITE
                || ($marker['original_market'] ?? '') !== OrderContext::MARKET_WEBSITE) return;
            $nextId = ParcelService::positiveId($marker['replacement_parcel_id'] ?? null);
            if ($nextId === null) return;
            $currentId = $nextId;
        }
        // An unfinished chain must not link the order to an already-retired intermediate draft.
        if (!$resolved) return;
        if ($currentId > 0 && $currentId !== $originalId) {
            $order->update_meta_data('_salam_gate_parcel_id', $currentId);
            $order->update_meta_data('_salam_gate_selected_service', '');
            $order->save();
        }
    }

    /**
     * @return array{province: string, city: string}|null
     */
    private static function cityPath(?int $cityId): ?array
    {
        if ($cityId === null || $cityId <= 0) {
            return null;
        }

        try {
            return ReferenceDataService::findLocationPathInTree((new ReferenceDataService())->getProvinceTree(), $cityId);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /** @return \WC_Order|BasalamParcelOrder|null */
    private static function orderOrError(): ?object
    {
        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order) {
            wp_send_json_error(['message' => 'سفارش یافت نشد.']);
        }

        if (array_key_exists('basalam_parcel_id', $_POST) && $_POST['basalam_parcel_id'] !== ''
            && $_POST['basalam_parcel_id'] !== null && $_POST['basalam_parcel_id'] !== 0 && $_POST['basalam_parcel_id'] !== '0') {
            $nativeId = ParcelService::positiveId($_POST['basalam_parcel_id']);
            if ($nativeId === null || !BasalamOrderContext::isBasalamOrder($orderId)) {
                wp_send_json_error(['message' => 'مرسوله انتخاب‌شده برای این سفارش معتبر نیست.']);
            }
            try {
                require_once dirname(__DIR__, 2) . '/Order/BasalamParcelOrder.php';
                return BasalamParcelOrder::open($order, $nativeId);
            } catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }
        }

        // Once a native scope exists, never silently fall back to the old single
        // parcel fields. An explicit native identity is required on every action.
        if (BasalamOrderContext::isBasalamOrder($orderId)) {
            require_once dirname(__DIR__, 2) . '/Order/BasalamParcelOrder.php';
            if (BasalamParcelOrder::hasScopes($order)) {
                wp_send_json_error(['message' => 'ابتدا مرسوله موردنظر این سفارش را انتخاب کنید.', 'code' => 'parcel_selection_required']);
            }
        }

        return $order;
    }

    /**
     * خطای قابل نمایش + کدهای قابل‌اقدام UI (already_paid / quote_changed) با نام فیلد.
     */
    private static function errorPayload(SalamGateException $exception): array
    {
        $payload = [
            'message' => $exception->firstMessage(),
            'errors'  => $exception->getErrors(),
            'kind'    => $exception->getKind(),
        ];

        if ($exception->errorsAt('order.status') !== []) {
            $payload['code'] = 'order_not_actionable';
            $payload['request_dispatched'] = false;
        } elseif (self::isAlreadyPaid($exception)) {
            $payload['code'] = 'already_paid';
            $payload['message'] = 'این مرسوله قبلاً پرداخت شده است.';
        } elseif ($exception->errorsAt('parcel.rate') !== []) {
            $payload['code'] = 'quote_changed';
            $payload['message'] = 'نرخ‌های ارسال تغییر کرده‌اند؛ دوباره نرخ بگیرید و روش را انتخاب کنید.';
        } elseif ($exception->errorsAt('vendor_balance') !== []) {
            $payload['message'] = 'موجودی تراز غرفه‌دار کافی نیست؛ از پنل باسلام شارژ کنید.';
        } elseif ($exception->errorsAt('user_wallet') !== []) {
            $payload['message'] = 'موجودی کیف پول کافی نیست؛ روش پرداخت دیگری را انتخاب کنید.';
        } elseif ($exception->isKind(SalamGateException::KIND_RATE_LIMITED)) {
            $payload['message'] = 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.';
        }

        return $payload;
    }

    private static function isAlreadyPaid(SalamGateException $exception): bool
    {
        foreach ($exception->getErrors() as $error) {
            $haystack = $error['message'] . ' ' . $error['location'];
            if (str_contains($haystack, 'already-paid') || str_contains($haystack, 'قبلاً پرداخت')) {
                return true;
            }
        }

        return false;
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }
    }
}
