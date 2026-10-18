<?php

namespace SyncBasalam\SalamGate\Parcel;

use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/**
 * ساخت payload مرسوله (UpsertParcelRequest) از سفارش ووکامرس.
 *
 * واحد ارسال به سرویس: وزن گرم، ابعاد سانتی‌متر، مبالغ ریال.
 * مبالغ سفارش از ارز خودِ سفارش (IRR/IRT) تبدیل می‌شوند؛ ورودی فرم تومان جداگانه
 * در لایه AJAX با Units::formTomanToRial تبدیل می‌شود.
 *
 * «محتویات مرسوله» خلاصه‌ای نمایشی است و عنوان واقعی اقلام فاکتور را بازنویسی نمی‌کند.
 */
class ParcelPayloadBuilder
{
    /**
     * ابعاد استاندارد بسته‌ها [width, length, height] سانتی‌متر — فقط برای «حدس» پیش‌فرض
     * وقتی کاربر انتخاب نکرده است (فرم/تنظیمات همیشه بر آن اولویت دارند).
     * مطابق BoxTypeDimensions سرویس؛ شناسه ۳۶ (سایز ۱۰) در پاسخ زنده box-types نیست و حذف شد.
     */
    public const BOX_DIMENSIONS = [
        25 => [15, 21, 1],
        26 => [30, 42, 1],
        27 => [10, 10, 15],
        28 => [10, 15, 20],
        29 => [15, 20, 20],
        30 => [20, 20, 30],
        31 => [20, 25, 35],
        32 => [20, 25, 45],
        33 => [25, 30, 40],
        34 => [30, 40, 45],
        35 => [35, 45, 55],
    ];

    public const LARGE_BOX_TYPE_ID = 37;
    public const FALLBACK_BOX_TYPE_ID = 29; // کارتن سایز ۳
    public const MIN_UNIT_PRICE_RIAL = 10000; // حداقل ارزش هر قلم (ریال)

    /**
     * ساخت payload کامل از سفارش (WP-facing) — منطق محاسباتی در assemble برای تست.
     *
     * @param array  $overrides ورودی‌های فرم مودال بر محاسبات پیش‌فرض اولویت دارند.
     * @param string $market   بازار مرسوله (basalam پیش‌فرض؛ برای سفارش سایت: website)
     */
    public function build(\WC_Order $order, int $providerShopId, array $overrides = [], string $market = OrderContext::MARKET_BASALAM): array
    {
        $orderData = $this->extractOrderData($order);

        if ($market === OrderContext::MARKET_WEBSITE && array_key_exists('items', $overrides)) {
            // Editing descriptive rows must not change the original parcel's
            // suggested weight/value, including when a caller omits overrides.
            $originalParcel = $this->assemble($orderData, $providerShopId)['parcel'];
            if (empty($overrides['weight'])) $overrides['weight'] = $originalParcel['weight'];
            if (empty($overrides['value'])) $overrides['value'] = $originalParcel['value'];
            $localItems = [];
            foreach ($orderData['items'] as $item) { $localItems[(int) $item['item_id']] = $item; }
            $edited = [];
            foreach ($overrides['items'] as $item) {
                $localId = (int) ($item['_order_item_id'] ?? 0);
                $original = $localItems[$localId] ?? [];
                $edited[] = [
                    'item_id' => $localId,
                    'external_id' => $localId > 0 ? $localId : null,
                    'title' => $item['title'], 'quantity' => $item['quantity'],
                    'unit_price' => Units::formTomanToRial($item['price_toman']),
                    'net_weight' => null, // Form weights are grams, independent of WooCommerce's weight unit.
                    'sku' => $original['sku'] ?? null,
                    'product_id' => $original['product_id'] ?? 0,
                    'variation_id' => $original['variation_id'] ?? 0,
                    'dimensions' => $original['dimensions'] ?? null,
                    '_form_net_weight' => $item['net_weight'],
                ];
            }
            $orderData['items'] = $edited;
            $orderData['currency'] = 'IRR';
        }

        if ($market === OrderContext::MARKET_BASALAM) {
            foreach ($orderData['items'] as &$item) {
                // لیبل باسلام اقلام را با شناسه اصلی سفارش باسلام تطبیق می‌دهد.
                $externalItemId = method_exists($order, 'get_meta')
                    ? (int) $order->get_meta('_sync_basalam_item_id_' . $item['item_id'])
                    : 0;
                $item['external_id'] = $externalItemId > 0 ? $externalItemId : null;
            }
            unset($item);
        }

        if (empty($overrides['city_id'])) {
            if ($market === OrderContext::MARKET_WEBSITE) {
                // سفارش سایت: شهر از استان/شهر ووکامرس با تطبیق نامی حل می‌شود
                $receiver = $orderData['receiver'];

                if (empty($receiver['city_id']) && $receiver['state'] !== null && $receiver['city_title'] !== null) {
                    try {
                        $orderData['receiver']['city_id'] = (new ReferenceDataService())
                            ->findCityIdForAddress($receiver['state'], $receiver['city_title']);
                    } catch (\Throwable $exception) {
                        $orderData['receiver']['city_id'] = null;
                    }
                }
            } elseif ($market === OrderContext::MARKET_BASALAM) {
                // سفارش بسالم: شناسه شهر ذخیره‌شده پلاگین پایه در جدول payments
                try {
                    $orderData['receiver']['city_id'] = BasalamOrderContext::getReceiverCityId((int) $order->get_id());
                } catch (\Throwable $exception) {
                    $orderData['receiver']['city_id'] = null;
                }
            }
        }

        return $this->assemble($orderData, $providerShopId, $overrides);
    }

    /**
     * استخراج داده خام سفارش به آرایه ساده (بدون وابستگی به WC در لایه محاسبات).
     */
    public function extractOrderData(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $quantity = max(1, (int) $item->get_quantity());

            $dimensions = null;
            if ($product && method_exists($product, 'get_length')) {
                $length = Units::dimensionToCm((float) $product->get_length());
                $width = Units::dimensionToCm((float) $product->get_width());
                $height = Units::dimensionToCm((float) $product->get_height());

                if ($length > 0 || $width > 0 || $height > 0) {
                    $dimensions = ['length' => $length, 'width' => $width, 'height' => $height];
                }
            }

            $weight = null;
            if ($product && method_exists($product, 'get_weight')) {
                $rawWeight = (float) $product->get_weight();
                if ($rawWeight > 0) {
                    $weight = $rawWeight;
                }
            }

            // variation_id از خود قلم سفارش (منبع معتبر) — نه از product (محذوف‌شدن محصول → 0).
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;

            $items[] = [
                'item_id'       => (int) $item->get_id(),
                'title'         => (string) $item->get_name(),
                'quantity'      => $quantity,
                'unit_price'    => ((float) $item->get_subtotal()) / $quantity,
                'net_weight'    => $weight,
                'sku'           => ($product && method_exists($product, 'get_sku')) ? (string) $product->get_sku() : null,
                'product_id'    => ($product && method_exists($product, 'get_id')) ? (int) $product->get_id() : 0,
                'variation_id'  => $variationId,
                'dimensions'    => $dimensions,
            ];
        }

        $shippingName = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $shippingAddress = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());

        return [
            'currency' => (string) $order->get_currency(),
            'items' => $items,
            'receiver' => [
                // مقصد ارسال با مشخصات پرداخت‌کننده جایگزین نمی‌شود؛ داده ناقص در فرم قابل تکمیل است.
                'name'        => $shippingName,
                'mobile'      => Digits::normalizeMobile((string) $order->get_shipping_phone()),
                'address'     => $shippingAddress,
                'city_id'     => null, // از کانتکست (payments) یا resolver بیرونی پر می‌شود
                'state'       => $order->get_shipping_state() ?: null,
                'city_title'  => $order->get_shipping_city() ?: null,
                'postal_code' => Digits::normalizePostalCode((string) $order->get_shipping_postcode()),
            ],
        ];
    }

    /**
     * منطق محاسباتی pure — قابل تست مستقیم.
     *
     * خروجی: ['parcel'=>.., 'packaging'=>.., 'items'=>.., 'receiver'=>.., '_warnings'=>[]]
     */
    public function assemble(array $orderData, int $providerShopId, array $overrides = []): array
    {
        $settings = SalamGateSettings::instance();
        $paddingGrams = max(0, (int) $settings->get(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS));
        $defaultNetGrams = max(1, (int) $settings->get(SalamGateSettings::KEY_DEFAULT_NET_WEIGHT_GRAMS));

        $warnings = [];
        $items = [];
        $totalValue = 0;
        $totalWeight = 0;
        $maxDims = [0.0, 0.0, 0.0];

        $currency = (string) ($orderData['currency'] ?? '');
        $unsupportedCurrency = !in_array(strtoupper($currency), ['IRR', 'IRT'], true) && $currency !== '';

        foreach (($orderData['items'] ?? []) as $item) {
            $netWeight = ($item['net_weight'] !== null && (float) $item['net_weight'] > 0)
                ? Units::weightToGrams((float) $item['net_weight'])
                : $defaultNetGrams;

            if (isset($item['_form_net_weight'])) { $netWeight = (int) $item['_form_net_weight']; }
            $overrideNet = $overrides['item_net_weights'][$item['item_id']] ?? null;
            if ($overrideNet !== null && (int) $overrideNet > 0) {
                $netWeight = (int) $overrideNet;
            }

            $netWeight = max(1, $netWeight);
            $packagedWeight = $netWeight + $paddingGrams;
            $quantity = max(1, (int) $item['quantity']);

            $unitPriceRial = Units::orderAmountToRial($item['unit_price'], $currency);

            if ($unsupportedCurrency) {
                $warnings[] = sprintf('ارز سفارش (%s) به ریال تبدیل نمی‌شود؛ قیمت اقلام را در فرم بررسی کنید.', $currency);
                $unitPriceRial = self::MIN_UNIT_PRICE_RIAL;
            } elseif ($unitPriceRial === null) {
                $unitPriceRial = 0;
            }

            if ($unitPriceRial < self::MIN_UNIT_PRICE_RIAL) {
                $unitPriceRial = self::MIN_UNIT_PRICE_RIAL;
                $warnings[] = sprintf('قیمت قلم «%s» کافی نبود؛ حداقل مقدار (۱٬۰۰۰ تومان) جایگزین شد.', $item['title']);
            }

            $items[] = [
                'title'           => (string) $item['title'], // عنوان واقعی اقلم — «محتویات» آن را بازنویسی نمی‌کند
                'price'           => $unitPriceRial,
                'quantity'        => $quantity,
                'net_weight'      => $netWeight,
                'packaged_weight' => $packagedWeight,
                'sku'             => ($item['sku'] !== null && $item['sku'] !== '') ? (string) $item['sku'] : null,
                'product_id'      => (int) $item['product_id'],
                'variation_id'    => (int) $item['variation_id'],
                'external_id'     => array_key_exists('external_id', $item) ? $item['external_id'] : (int) $item['item_id'],
            ];

            $totalValue += $unitPriceRial * $quantity;
            $totalWeight += $packagedWeight * $quantity;

            if (is_array($item['dimensions'])) {
                $itemDims = [
                    (float) ($item['dimensions']['length'] ?? 0),
                    (float) ($item['dimensions']['width'] ?? 0),
                    (float) ($item['dimensions']['height'] ?? 0),
                ];
                rsort($itemDims);

                for ($i = 0; $i < 3; $i++) {
                    $maxDims[$i] = max($maxDims[$i], $itemDims[$i]);
                }
            }
        }

        $parcelWeight = max(10, $totalWeight);
        if (isset($overrides['weight']) && (int) $overrides['weight'] > 0) {
            $parcelWeight = (int) $overrides['weight'];
        }

        $parcelValue = $totalValue;
        // Only authenticated original Basalam data supplies this internal field.
        // It is independent of item prices and may be zero after a full goods discount.
        if (array_key_exists('default_declared_value_rial', $orderData)) {
            $trustedValue = $orderData['default_declared_value_rial'];
            if (strtoupper($currency) !== 'IRR' || !is_int($trustedValue) || $trustedValue < 0) {
                throw new SalamGateException('ارزش پیش‌فرض مرسوله باسلام معتبر نیست؛ دوباره اطلاعات سفارش را دریافت کنید.',
                    422, [], SalamGateException::KIND_VALIDATION);
            }
            $parcelValue = $trustedValue;
        }
        if (isset($overrides['value']) && (int) $overrides['value'] > 0) {
            $parcelValue = (int) $overrides['value'];
        }

        // بسته‌بندی: فرم > تنظیمات > حدس از ابعاد > پیش‌فرض
        $boxTypeId = $this->resolveBoxType($overrides, $maxDims);

        $packaging = ['box_type_id' => $boxTypeId, 'dimensions' => null];
        if ($boxTypeId === self::LARGE_BOX_TYPE_ID) {
            $packaging['dimensions'] = [
                'width'  => max(1, (int) ($overrides['dimensions']['width'] ?? ($maxDims[1] ?? 0))),
                'length' => max(1, (int) ($overrides['dimensions']['length'] ?? ($maxDims[0] ?? 0))),
                'height' => max(1, (int) ($overrides['dimensions']['height'] ?? ($maxDims[2] ?? 0))),
            ];
        }

        $receiverData = $orderData['receiver'] ?? [];
        $receiver = [
            'name'        => $this->overrideString($overrides, 'name', (string) ($receiverData['name'] ?? '')),
            'mobile'      => Digits::normalizeMobile($this->overrideString($overrides, 'mobile', (string) ($receiverData['mobile'] ?? ''))),
            'address'     => $this->overrideString($overrides, 'address', (string) ($receiverData['address'] ?? '')),
            'city_id'     => (int) ($overrides['city_id'] ?? 0) ?: ((int) ($receiverData['city_id'] ?? 0) ?: null),
            'postal_code' => Digits::normalizePostalCode($this->overrideString($overrides, 'postal_code', (string) ($receiverData['postal_code'] ?? ''))),
        ] + self::receiverCoordinates($receiverData);

        $parcel = [
            'value'            => $parcelValue,
            'weight'           => $parcelWeight,
            'is_liquid'        => !empty($overrides['is_liquid']),
            'is_fragile'       => !empty($overrides['is_fragile']),
            'provider_shop_id' => $providerShopId,
        ];

        // دسته کالا (اختیاری) — شناسه از API زنده دسته‌ها، نه هاردکد.
        $categoryId = (int) Digits::toEnglish((string) ($overrides['category_id'] ?? 0));
        if ($categoryId > 0) {
            $parcel['category_id'] = $categoryId;
        }

        $payload = [
            'parcel'    => $parcel,
            'packaging' => $packaging,
            'items'     => $items,
            'receiver'  => $receiver,
            '_warnings' => $warnings,
        ];

        // خلاصه «محتویات» فقط نمایشی است — به اقلام تحمیل نمی‌شود.
        $contents = trim((string) ($overrides['contents'] ?? ''));
        if ($contents !== '') {
            $payload['_contents'] = $contents;
        }

        return $payload;
    }

    /**
     * حدس نوع بسته از ابعاد بزرگ‌ترین قلم (خالص و تست‌پذیر) — فقط پیشنهاد پیش‌فرض.
     *
     * @param array $maxDims [بزرگ‌ترین، میانی، کوچک‌ترین] سانتی‌متر
     */
    public static function guessBoxType(array $maxDims): ?int
    {
        $dims = array_map('floatval', $maxDims);
        rsort($dims);

        if ($dims[0] <= 0 && $dims[1] <= 0 && $dims[2] <= 0) {
            return null;
        }

        foreach (self::BOX_DIMENSIONS as $boxTypeId => $boxDims) {
            $box = array_map('floatval', $boxDims);
            rsort($box);

            if ($dims[0] <= $box[0] && $dims[1] <= $box[1] && $dims[2] <= $box[2]) {
                return $boxTypeId;
            }
        }

        return self::LARGE_BOX_TYPE_ID;
    }

    private function resolveBoxType(array $overrides, array $maxDims): int
    {
        $overrideBox = (int) Digits::toEnglish((string) ($overrides['box_type_id'] ?? 0));
        if ($overrideBox > 0) {
            return $overrideBox;
        }

        $settings = SalamGateSettings::instance();
        $defaultBox = $settings->get(SalamGateSettings::KEY_DEFAULT_BOX_TYPE_ID);
        if ($defaultBox !== null && (int) $defaultBox > 0) {
            return (int) $defaultBox;
        }

        return self::guessBoxType($maxDims) ?? self::FALLBACK_BOX_TYPE_ID;
    }

    private function overrideString(array $overrides, string $key, string $fallback): string
    {
        return isset($overrides[$key]) && is_scalar($overrides[$key]) && trim((string) $overrides[$key]) !== ''
            ? trim((string) $overrides[$key])
            : $fallback;
    }

    /** Keep optional trusted coordinates without depending on the Basalam-only preflight. */
    private static function receiverCoordinates(array $receiver): array
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
}
