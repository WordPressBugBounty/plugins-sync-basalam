<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

require_once __DIR__ . '/BasalamOrderContext.php';
require_once __DIR__ . '/BasalamGoodsValue.php';
require_once __DIR__ . '/BasalamOrderState.php';

/** Fresh original-order checks; never confirms, charges, splits, or posts an order. */
final class BasalamShippingPreflight
{
    // Native Basalam order form limits; existing API drafts retain the broader API contract.
    public const MIN_DECLARED_VALUE_RIAL = 20000;
    public const MAX_DECLARED_VALUE_RIAL = 5000000000;

    /** @param \WC_Order|BasalamParcelOrder $order Called before creation, changes or payment. */
    public static function check(object $order, bool $requireShippable = true, ?array &$snapshots = null): array
    {
        OrderContext::assertSupportedOrder($order);
        if ($requireShippable && ($reason = self::localStatusBlockReason($order)) !== null) {
            throw self::error($reason);
        }
        $orderId = (int) $order->get_id();
        $invoiceId = BasalamOrderContext::getInvoiceId($orderId);
        $vendorId = function_exists('syncBasalamSettings')
            ? self::positiveInteger(syncBasalamSettings()->getSettings('vendor_id')) : null;
        if ($invoiceId === null || $vendorId === null) {
            throw self::error('ارتباط این سفارش با غرفه باسلام مشخص نیست؛ ابتدا اتصال و سفارش را بررسی کنید.');
        }
        $snapshots = null;
        if ($order instanceof BasalamParcelOrder) {
            $snapshots = BasalamParcelSet::load($order->getRoot(), $order->getNativeParcelId());
            $parcelId = $order->getNativeParcelId();
            if ($snapshots['allocations'] !== $order->getAllocation()) {
                throw self::error('اقلام این مرسوله هنگام ارسال تغییر کرده‌اند؛ دوباره آن را انتخاب کنید.');
            }
        } else {
            $parcelId = BasalamOrderContext::resolveParcelId($orderId, true, $snapshots);
        }
        if (!$order instanceof BasalamParcelOrder) {
            BasalamOrderState::remember($order, $snapshots['order'], $vendorId, $invoiceId);
        }
        $settings = SalamGateSettings::instance();
        return self::fromSnapshots(
            $order,
            $snapshots['order'],
            $snapshots['parcel'],
            $vendorId,
            $invoiceId,
            $parcelId,
            [
                'default_net_weight_grams' => $settings->get(SalamGateSettings::KEY_DEFAULT_NET_WEIGHT_GRAMS),
            ],
            $requireShippable
        );
    }

    /**
     * Pure normalization of authenticated snapshots, deliberately independent of Woo units.
     * order_data can be passed directly to ParcelPayloadBuilder::assemble().
     * Reported financial totals are NOT the available amount for paying postage.
     * @param \WC_Order|BasalamParcelOrder|null $order
     */
    public static function fromSnapshots(
        ?object $order,
        array $orderDetail,
        array $parcel,
        int $vendorId,
        int $invoiceId,
        int $parcelId,
        array $settings = [],
        bool $requireShippable = true,
        bool $nativeSubset = false
    ): array {
        if ($order !== null) { OrderContext::assertSupportedOrder($order); }
        if ($requireShippable && $order !== null && ($reason = self::localStatusBlockReason($order)) !== null) {
            throw self::error($reason);
        }
        $scoped = $order instanceof BasalamParcelOrder || $nativeSubset;
        $primaryId = self::positiveInteger($orderDetail['parcel_detail']['parcel_id'] ?? null);
        $hasParentId = is_array($parcel['order'] ?? null) && array_key_exists('id', $parcel['order']);
        if ($vendorId < 1 || $invoiceId < 1 || $parcelId < 1
            || self::positiveInteger($orderDetail['id'] ?? null) !== $invoiceId
            || (!$scoped && self::positiveInteger($orderDetail['parcel_detail']['parcel_id'] ?? null) !== $parcelId)
            || self::positiveInteger($parcel['id'] ?? null) !== $parcelId
            || self::positiveInteger($parcel['vendor']['id'] ?? null) !== $vendorId
            || (isset($parcel['order']) && !is_array($parcel['order']))
            || (($parcelId !== $primaryId || $hasParentId) && self::positiveInteger($parcel['order']['id'] ?? null) !== $invoiceId)
            || (isset($orderDetail['vendor']['id']) && self::positiveInteger($orderDetail['vendor']['id']) !== $vendorId)
            || (isset($parcel['order']['id']) && self::positiveInteger($parcel['order']['id']) !== $invoiceId)) {
            throw self::error('مرسوله دریافت‌شده با این سفارش و غرفه متصل مطابقت ندارد؛ ارسال انجام نشد.');
        }
        if ($requireShippable) {
            self::assertEligible($orderDetail, $parcel, $scoped);
        }

        $originalItems = self::itemIndex($orderDetail['items'] ?? null);
        $parcelItems = self::itemIndex($parcel['items'] ?? null);
        if ((!$scoped && count($originalItems) !== count($parcelItems)) || array_diff_key($parcelItems, $originalItems) !== []) {
            throw self::error('اقلام سفارش با مرسوله باسلام تطبیق ندارند؛ ارسال را از پنل باسلام بررسی کنید.');
        }
        $localItems = [];
        if ($order === null) {
            // Read-only preflight before the user explicitly imports a menu selection.
            // Original item IDs remain original IDs; no local Woo identity is invented.
            foreach ($scoped ? array_intersect_key($originalItems, $parcelItems) : $originalItems as $externalId => $original) {
                $localItems[$externalId] = ['item_id' => $externalId,
                    'quantity' => self::positiveQuantity($scoped ? ($parcelItems[$externalId]['quantity'] ?? null) : ($original['quantity'] ?? null))];
            }
        } else {
            foreach ($order->get_items() as $item) {
                $externalId = self::positiveInteger($order->get_meta('_sync_basalam_item_id_' . $item->get_id()));
                $quantity = self::positiveQuantity($item->get_quantity());
                if ($externalId === null || $quantity === null || isset($localItems[$externalId])) {
                    throw self::error('ارتباط یا تعداد بعضی اقلام سفارش با باسلام مشخص نیست؛ ابتدا سفارش را بررسی کنید.');
                }
                $localItems[$externalId] = ['item_id' => (int) $item->get_id(), 'quantity' => $quantity];
            }
        }
        $selectedOriginal = $scoped ? array_intersect_key($originalItems, $parcelItems) : $originalItems;
        if (count($localItems) !== count($selectedOriginal) || array_diff_key($selectedOriginal, $localItems) !== []) {
            throw self::error('همه اقلام مرسوله باسلام در این سفارش موجود نیستند؛ ارسال را از پنل باسلام انجام دهید.');
        }

        $defaultNet = self::positiveInteger($settings['default_net_weight_grams'] ?? 100) ?? 100;
        $items = [];
        $missingWeight = false;
        $missingItemCount = 0;
        $missingQuantity = 0;
        foreach ($localItems as $externalId => $local) {
            $original = $originalItems[$externalId];
            $remote = $parcelItems[$externalId];
            $parentQuantity = self::positiveQuantity($original['quantity'] ?? null);
            $quantity = $scoped ? self::positiveQuantity($remote['quantity'] ?? null) : $parentQuantity;
            if ($quantity === null || $quantity !== $local['quantity']
                || $parentQuantity === null || $quantity > $parentQuantity
                || self::positiveQuantity($remote['quantity'] ?? null) !== $quantity) {
                throw self::error('تعداد اقلام این سفارش با باسلام تغییر کرده است؛ قبل از ارسال، سفارش را همگام‌سازی کنید.');
            }
            $productId = self::positiveInteger($original['product']['id'] ?? null);
            if ($productId === null || self::positiveInteger($remote['product']['id'] ?? null) !== $productId) {
                throw self::error('محصول یکی از اقلام با مرسوله اصلی باسلام مطابقت ندارد؛ ارسال انجام نشد.');
            }
            $variationId = self::positiveInteger($original['variation']['id'] ?? null);
            if (self::positiveInteger($remote['variation']['id'] ?? null) !== $variationId) {
                throw self::error('تنوع یکی از اقلام با مرسوله اصلی باسلام مطابقت ندارد؛ ارسال انجام نشد.');
            }
            $unitPrice = self::unitPrice($original, $parentQuantity);
            if ($unitPrice < 10000) {
                throw self::error('ارزش یکی از اقلام سفارش باسلام کمتر از حداقل قابل ارسال با سلام‌گیت (۱٬۰۰۰ تومان) است؛ قیمت اصلی سفارش تغییر داده نمی‌شود.');
            }
            if (self::positiveInteger($remote['price'] ?? null) !== $unitPrice) {
                throw self::error('قیمت سفارش در باسلام تغییر کرده است؛ دوباره مشخصات سفارش را دریافت کنید.');
            }
            $title = self::text($remote['title'] ?? null);
            if ($title === '') {
                throw self::error('عنوان یکی از کالاهای سفارش باسلام دریافت نشد؛ دوباره بررسی کنید.');
            }
            // These weights are already grams in order-processing; never use wc_get_weight().
            $weight = self::optionalGramWeight($original['net_weight'] ?? null);
            $remoteWeight = self::optionalGramWeight($remote['net_weight'] ?? null);
            if ($weight !== $remoteWeight) {
                throw self::error('وزن اقلام در باسلام تغییر کرده است؛ دوباره مشخصات سفارش را دریافت کنید.');
            }
            $isMissing = $weight === null;
            $missingWeight = $missingWeight || $isMissing;
            if ($isMissing) {
                ++$missingItemCount;
                $missingQuantity += $quantity;
            }
            $items[] = [
                'item_id' => $local['item_id'], 'external_id' => $externalId,
                'title' => $title, 'quantity' => $quantity, 'unit_price' => $unitPrice,
                'net_weight' => null, '_form_net_weight' => $weight ?? $defaultNet,
                'sku' => self::text($original['product']['sku'] ?? null) ?: null,
                'product_id' => $productId, 'variation_id' => $variationId ?? 0,
                'dimensions' => null, '_weight_was_missing' => $isMissing,
                '_weight_source' => $isMissing ? 'default' : 'basalam_order',
            ];
        }

        $customer = is_array($orderDetail['customer_data'] ?? null) ? $orderDetail['customer_data'] : [];
        $recipient = is_array($customer['recipient'] ?? null) ? $customer['recipient'] : [];
        $city = is_array($customer['city'] ?? null) ? $customer['city'] : [];
        $receiver = [
            'name' => self::text($recipient['name'] ?? null),
            'mobile' => Digits::normalizeMobile(self::text($recipient['mobile'] ?? null)),
            'address' => self::text($recipient['postal_address'] ?? null),
            'postal_code' => Digits::normalizePostalCode(self::text($recipient['postal_code'] ?? null)),
            'city_id' => self::positiveInteger($city['id'] ?? null),
            'state' => self::text($city['parent']['title'] ?? null) ?: null,
            'city_title' => self::text($city['title'] ?? null) ?: null,
        ] + self::receiverCoordinates($recipient);
        // Source identity/items/status are authoritative; an incomplete recipient is editable
        // in the native shipping form and must not prevent opening that form.
        $reportedTotals = [];
        foreach (['product_cost', 'product_submit', 'shipping_cost', 'shipping_submit', 'product_balance', 'shipping_balance'] as $key) {
            $amount = self::integer($orderDetail['financial_report'][$key]['total']['amount'] ?? null);
            if ($amount !== null) {
                $reportedTotals[$key] = $amount;
            }
        }
        $goodsValue = $scoped ? self::selectedGoodsValue($selectedOriginal, $parcelItems) : BasalamGoodsValue::fromItems($originalItems);
        return [
            'identity' => ['order_id' => $order === null ? null : (int) $order->get_id(), 'invoice_id' => $invoiceId, 'vendor_id' => $vendorId, 'parcel_id' => $parcelId],
            'order_data' => ['currency' => 'IRR', 'items' => $items, 'receiver' => $receiver,
                'default_declared_value_rial' => $goodsValue['value_rial']],
            'receiver_errors' => self::receiverInputErrors($receiver),
            'has_missing_item_weights' => $missingWeight,
            'default_net_weight_grams' => $defaultNet,
            '_weight_estimate' => ['has_missing_item_weights' => $missingWeight,
                'missing_item_count' => $missingItemCount, 'missing_quantity' => $missingQuantity,
                'default_weight_grams' => $defaultNet],
            'financial_summary' => ['reported_totals_rial' => $reportedTotals,
                'available_shipping_balance_rial' => null, 'goods_value' => $goodsValue],
            'source_state' => BasalamOrderState::fromSnapshot($orderDetail, $vendorId, $invoiceId),
            'shipping_blocked_reason' => self::localStatusBlockReasonForNullable($order)
                ?? BasalamOrderState::blockedReason($orderDetail, $parcel, $scoped),
        ];
    }

    /** Validate the effective, editable destination immediately before an upsert/payment. */
    public static function receiverInputErrors(array $receiver): array
    {
        $errors = [];
        if (self::text($receiver['name'] ?? null) === '') { $errors['name'] = 'نام گیرنده را وارد کنید.'; }
        if (self::text($receiver['address'] ?? null) === '') { $errors['address'] = 'نشانی گیرنده را وارد کنید.'; }
        if (!Digits::isValidMobile(Digits::normalizeMobile(self::text($receiver['mobile'] ?? null)))) {
            $errors['mobile'] = 'شماره همراه معتبر گیرنده را وارد کنید.';
        }
        if (Digits::normalizePostalCode(self::text($receiver['postal_code'] ?? null)) === null) {
            $errors['postal_code'] = 'کد پستی ۱۰ رقمی گیرنده را وارد کنید.';
        }
        $city = $receiver['city_id'] ?? null;
        if (is_string($city)) { $city = Digits::toEnglish(trim($city)); }
        $cityId = self::positiveInteger($city);
        if ($cityId === null || $cityId > 2147483647) { $errors['city_id'] = 'استان و شهر گیرنده را انتخاب کنید.'; }
        return $errors;
    }

    /** Optional trusted snapshot coordinates are preserved as a valid pair, never coerced from arrays/bools. */
    public static function receiverCoordinates(array $receiver): array
    {
        $latitude = $receiver['latitude'] ?? null;
        $longitude = $receiver['longitude'] ?? null;
        $number = static fn ($value): bool => (is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^-?(?:\d+\.?\d*|\.\d+)$/D', $value))) && is_finite((float) $value);
        if (!$number($latitude) || !$number($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return ['latitude' => null, 'longitude' => null];
        }
        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    public static function isValidDeclaredValue($value, bool $existing = false): bool
    {
        $amount = self::positiveInteger($value);
        return $amount !== null && $amount >= ($existing ? 10000 : self::MIN_DECLARED_VALUE_RIAL)
            && $amount <= ($existing ? 10000000000 : self::MAX_DECLARED_VALUE_RIAL);
    }

    /** @param \WC_Order|BasalamParcelOrder $order One policy for selection and shipping mutations. */
    public static function localStatusBlockReason(object $order): ?string
    {
        OrderContext::assertSupportedOrder($order);
        $terminal = [
            'trash' => 'زباله‌دان', 'refunded' => 'مستردشده', 'cancelled' => 'لغوشده',
            'failed' => 'ناموفق', 'bslm-rejected' => 'ردشده',
        ];
        $status = $order->get_status();
        if (!isset($terminal[$status])) { return null; }
        return 'سفارش متناظر در ووکامرس در وضعیت «' . $terminal[$status]
            . '» است؛ ابتدا وضعیت سفارش را بررسی کنید. ثبت و پرداخت انجام نشد.';
    }

    /** @param \WC_Order|BasalamParcelOrder|null $order */
    private static function localStatusBlockReasonForNullable(?object $order): ?string
    {
        return $order === null ? null : self::localStatusBlockReason($order);
    }

    /** The detail DTO omits original item IDs; compare financial rows without guessing an ID. */
    public static function assertOriginalFinancials(array $preflight, array $detail): void
    {
        $expectedRows = [];
        foreach ($preflight['order_data']['items'] ?? [] as $item) {
            $price = self::positiveInteger($item['unit_price'] ?? null);
            $quantity = self::positiveInteger($item['quantity'] ?? null);
            if ($price === null || $quantity === null) {
                throw self::error('ارزش سفارش باسلام در محدوده قابل ارسال نیست؛ مبلغ اصلی سفارش تغییر داده نمی‌شود.');
            }
            $key = $price . ':' . $quantity;
            $expectedRows[$key] = ($expectedRows[$key] ?? 0) + 1;
        }
        $actualRows = [];
        foreach (is_array($detail['items'] ?? null) ? $detail['items'] : [] as $item) {
            $price = is_array($item) ? self::positiveInteger($item['price'] ?? null) : null;
            $quantity = is_array($item) ? self::positiveInteger($item['quantity'] ?? null) : null;
            if ($price === null || $quantity === null) {
                throw self::error('مبلغ و تعداد اقلام مرسوله موجود تأیید نشد؛ پرداخت انجام نشد.');
            }
            $key = $price . ':' . $quantity;
            $actualRows[$key] = ($actualRows[$key] ?? 0) + 1;
        }
        ksort($expectedRows);
        ksort($actualRows);
        if ($expectedRows === [] || $actualRows !== $expectedRows) {
            throw self::error('اقلام مرسوله موجود با سفارش اصلی باسلام مطابقت ندارند؛ پیش از پرداخت، مرسوله را در پنل باسلام بررسی و اصلاح کنید. پرداخت انجام نشد.');
        }
        if (!self::isValidDeclaredValue($detail['value'] ?? null, true)) {
            throw self::error('ارزش اظهارشده مرسوله موجود معتبر نیست؛ پیش از پرداخت، مشخصات مرسوله را اصلاح کنید. پرداخت انجام نشد.');
        }
    }

    private static function assertEligible(array $order, array $parcel, bool $scoped = false): void
    {
        if (($reason = BasalamOrderState::blockedReason($order, $parcel, $scoped)) !== null) { throw self::error($reason); }
    }

    /** Never prorate a parent's signed line discount onto a partial native quantity. */
    private static function selectedGoodsValue(array $parentItems, array $parcelItems): array
    {
        $gross = 0; $value = 0; $review = false;
        foreach ($parentItems as $id => $item) {
            $quantity = self::positiveQuantity($parcelItems[$id]['quantity'] ?? null);
            $parentQuantity = self::positiveQuantity($item['quantity'] ?? null);
            $price = self::unitPrice($item, $parentQuantity);
            if ($price > intdiv(PHP_INT_MAX, $quantity)) { throw self::error('ارزش اقلام مرسوله خارج از محدوده مجاز است.'); }
            $lineGross = $price * $quantity;
            if ($quantity === $parentQuantity) {
                $lineValue = BasalamGoodsValue::fromItems([$item])['value_rial'];
            } else {
                // This is a visible goods-value proposal, not a fictional financial allocation.
                // Actual postage debiting remains entirely with order-processing/method 19.
                $lineValue = $lineGross;
                $review = true;
            }
            if ($gross > PHP_INT_MAX - $lineGross || $value > PHP_INT_MAX - $lineValue) {
                throw self::error('ارزش اقلام مرسوله خارج از محدوده مجاز است.');
            }
            $gross += $lineGross; $value += $lineValue;
        }
        return ['gross_rial' => $gross, 'value_rial' => $value, 'vendor_discount_rial' => $value - $gross,
            'needs_review' => $review];
    }

    private static function itemIndex($items): array
    {
        if (!is_array($items) || $items === []) {
            throw self::error('اقلام سفارش باسلام دریافت نشد؛ دوباره بررسی کنید.');
        }
        $indexed = [];
        foreach ($items as $item) {
            $id = is_array($item) ? self::positiveInteger($item['id'] ?? null) : null;
            if ($id === null || isset($indexed[$id])) {
                throw self::error('شناسه اقلام سفارش باسلام معتبر نیست؛ ارسال انجام نشد.');
            }
            $indexed[$id] = $item;
        }
        return $indexed;
    }

    private static function unitPrice(array $item, int $quantity): int
    {
        $price = self::positiveInteger($item['price'] ?? null);
        if ($price === null) {
            throw self::error('قیمت اصلی یکی از اقلام باسلام مشخص نیست؛ دوباره سفارش را دریافت کنید.');
        }
        // The base importer stores this report amount as the complete Woo line subtotal.
        // When present it independently verifies that item.price is the per-unit amount.
        $verifiedLineTotal = false;
        $reports = $item['financial_report']['report_items'] ?? [];
        foreach ((is_array($reports) ? $reports : []) as $report) {
            if (!is_array($report) || self::text($report['title'] ?? null) !== 'قیمت محصول') {
                continue;
            }
            $total = self::positiveInteger($report['amount'] ?? null);
            if ($total === null || $total % $quantity !== 0 || intdiv($total, $quantity) !== $price) {
                throw self::error('قیمت اقلام سفارش باسلام با گزارش اصلی سفارش مطابقت ندارد؛ ارسال انجام نشد.');
            }
            $verifiedLineTotal = true;
        }
        if ($quantity > 1 && !$verifiedLineTotal) {
            throw self::error('مبلغ اقلام چندتعدادی این سفارش در باسلام قابل تأیید نیست؛ ارسال را از پنل باسلام بررسی کنید.');
        }
        return $price;
    }

    private static function positiveQuantity($value): ?int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value <= PHP_INT_MAX) {
            $value = (int) $value;
        }
        return self::positiveInteger($value);
    }

    private static function optionalGramWeight($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $weight = self::positiveInteger($value);
        if ($weight === null) {
            throw self::error('وزن یکی از اقلام سفارش باسلام معتبر نیست؛ دوباره مشخصات سفارش را دریافت کنید.');
        }
        return $weight;
    }

    private static function positiveInteger($value): ?int
    {
        $number = self::integer($value);
        return $number !== null && $number > 0 ? $number : null;
    }

    private static function integer($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value))) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number === false ? null : $number;
    }

    private static function text($value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
