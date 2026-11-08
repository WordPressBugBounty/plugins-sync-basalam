<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamOrderProducts;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderShippingPreference;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\CategoriesService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** PrepareShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class PrepareShipment
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function prepareParcel(bool $refreshOrderReceiver = false): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        $orderId = (int) $order->get_id();
        (new CreateOrderShipment($this->input, $this->context))->recoverConfirmedOrderReplacement($order);

        $shopId = FeatureAvailability::activeShopId();
        if ($shopId === null) {
            return OperationResult::failure(['message' => 'غرفه سلام‌گیت فعالی یافت نشد؛ از صفحه سلام‌گیت وضعیت را بررسی کنید.']);
        }

        $market = OrderContext::marketFor($orderId);
        // Only an explicit Woo order refresh replaces an unpaid draft's saved
        // recipient. Normal reopening and native Basalam sources retain theirs.
        $refreshOrderReceiver = $refreshOrderReceiver && $market === OrderContext::MARKET_WEBSITE;
        $preflight = null;
        $sourceSnapshots = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $preflight = BasalamOrderProducts::checkAndRepair($order, false, $sourceSnapshots); }
            catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)); }
        }
        $externalId = OrderContext::externalIdForOrder($order, $market);
        if ($externalId === null) {
            return OperationResult::failure(['message' => 'شناسه معتبر مرسوله برای این سفارش تأیید نشد؛ دوباره بررسی کنید.']);
        }
        $recovered = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $recovered = $this->recoverBasalamParcel($order, $externalId); }
            catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'basalam_recovery_failed']); }
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
            'city_path'   => $this->cityPath((int) ($payload['receiver']['city_id'] ?? 0) ?: $cityId),
            'product_links' => $this->productLinksForOrder($order),
            'sender' => $this->senderDisplay(FeatureAvailability::activeShop() ?? []),
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
        foreach (\SyncBasalam\SalamGate\Order\OrderContext::shipment($order)->items() as $item) {
            $orderItemIds[] = (int) $item->get_id();
        }
        foreach ($payload['items'] as $index => &$item) {
            $item['_order_item_id'] = is_array($storedPayload) && (int) $order->get_meta('_salam_gate_order_draft_parcel_id') === $parcelId
                ? $this->storedOrderItemId($item) : ($orderItemIds[$index] ?? 0);
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
                        $payload['_display']['sender'] = $this->senderDisplay(FeatureAvailability::activeShop() ?? [], $detail['shipping_info']['sender']);
                    }
                    if (!$refreshOrderReceiver || $existing['paid']) {
                        $payload['receiver'] = $this->receiverFromDetail($detail, $payload['receiver']);
                    }
                    if ($market === OrderContext::MARKET_WEBSITE && isset($detail['items']) && is_array($detail['items'])) {
                        $payload['items'] = $this->associateRemoteItems($detail['items'], $payload['items']);
                    }
                    if ($market === OrderContext::MARKET_WEBSITE) { (new CreateOrderShipment($this->input, $this->context))->saveOrderDraft($order, $parcelId, $payload); }
                }
            } catch (SalamGateException $exception) {
                if ($market === OrderContext::MARKET_BASALAM) {
                    return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'basalam_recovery_failed']);
                }
                // مرسوله از سمت سرور در دسترس نیست — مودال با payload پیش‌فرض باز می‌شود
            }
        }

        $payload['_display']['value_toman'] = Units::toToman((int) $payload['parcel']['value']);
        $payload['_display']['city_path'] = $this->cityPath((int) ($payload['receiver']['city_id'] ?? 0));
        $payload['_display']['weight_confirmation'] = OrderWeightConfirmation::describe($payload, $remoteWeightLoaded);
        if ($market === OrderContext::MARKET_BASALAM) {
            $payload['_display']['receiver_errors'] = BasalamShippingPreflight::receiverInputErrors($payload['receiver']);
        }
        unset($payload['_weight_estimate']);

        return OperationResult::success([
            'payload'  => $payload,
            'existing' => $existing,
            'parcel_id' => $parcelId ?: null,
        ]);
    }

    public function recoverBasalamParcel(object $order, int $externalId): ?array
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

    public function receiverFromDetail(array $detail, array $fallback = []): array
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

    public function associateRemoteItems(array $remoteItems, array $localItems): array
    {
        $externalIds = [];
        $skus = [];
        foreach ($localItems as $localItem) {
            if (!is_array($localItem)) continue;
            $localId = $this->canonicalItemId($localItem['_order_item_id'] ?? null);
            if ($localId === null) continue;
            $externalId = $this->canonicalItemId($localItem['external_id'] ?? null);
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
            $key = $this->remoteItemIdentityKey($remoteItem);
            if ($key !== null) $remoteIdentityCounts[$key] = ($remoteIdentityCounts[$key] ?? 0) + 1;
        }
        $items = [];
        foreach ($remoteItems as $remoteItem) {
            if (!is_array($remoteItem)) continue;
            $key = $this->remoteItemIdentityKey($remoteItem);
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

    public function remoteItemIdentityKey(array $item): ?string
    {
        if (array_key_exists('external_id', $item)) {
            $id = $this->canonicalItemId($item['external_id']);
            return $id === null ? null : 'external:' . $id;
        }
        $sku = $item['sku'] ?? null;
        return is_string($sku) && trim($sku) !== '' ? 'sku:' . $sku : null;
    }

    public function canonicalItemId($value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    public function storedOrderItemId(array $item): int
    {
        return $this->canonicalItemId(array_key_exists('_order_item_id', $item)
            ? $item['_order_item_id'] : ($item['external_id'] ?? null)) ?? 0;
    }

    public function productLinksForOrder($order): array
    {
        $links = [];
        foreach (\SyncBasalam\SalamGate\Order\OrderContext::shipment($order)->items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product || !method_exists($product, 'get_id')) continue;
            if (method_exists($product, 'get_status') && $product->get_status() === 'trash') continue;
            $targetId = $this->canonicalItemId($product->get_id());
            $parentId = method_exists($product, 'get_parent_id') ? $this->canonicalItemId($product->get_parent_id()) : null;
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

    public function senderDisplay(array $shop, ?array $savedSender = null): array
    {
        $address = $savedSender ?? (is_array($shop['address'] ?? null) ? $shop['address'] : []);
        $text = fn ($value): string => is_scalar($value) ? sanitize_text_field((string) $value) : '';
        return [
            'name' => $text($address['full_name'] ?? ''),
            'city' => $text($address['city']['title'] ?? ''),
            'address' => $text($address['full_address'] ?? $address['address'] ?? ''),
            'mobile' => $text($address['phone'] ?? $shop['mobile'] ?? ''),
            'postal_code' => $text($address['postal_code'] ?? ''),
            'center' => $text($address['provider_node']['title'] ?? $shop['provider_node']['title'] ?? ''),
        ];
    }

    public function cityPath(?int $cityId): ?array
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
}
