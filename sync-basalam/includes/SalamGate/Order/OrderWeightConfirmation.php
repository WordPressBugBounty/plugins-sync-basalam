<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** Explicit consent to the exact packaged weight, never a persisted blanket approval. */
final class OrderWeightConfirmation
{
    public static function describe(array $payload, bool $remoteWeight = false): array
    {
        $settings = SalamGateSettings::instance();
        $estimate = is_array($payload['_weight_estimate'] ?? null) ? $payload['_weight_estimate'] : [];
        // Basalam's validated snapshot can apply a fallback to legacy zero settings.
        // Explain the weight actually used, not a different settings-only estimate.
        $default = is_int($estimate['default_weight_grams'] ?? null) && $estimate['default_weight_grams'] > 0
            ? $estimate['default_weight_grams']
            : max(1, (int) $settings->get(SalamGateSettings::KEY_DEFAULT_NET_WEIGHT_GRAMS));
        $padding = max(0, (int) $settings->get(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS));
        return [
            'weight_grams' => (int) ($payload['parcel']['weight'] ?? 0),
            'source' => $remoteWeight ? 'saved' : (!empty($estimate['has_missing_item_weights']) ? 'default' : 'products'),
            // This is a per-unit net weight, not the final packaged parcel weight.
            'default_weight_grams' => $default,
            'weight_padding_grams' => $padding,
            'has_missing_item_weights' => !empty($estimate['has_missing_item_weights']),
            'missing_item_count' => max(0, (int) ($estimate['missing_item_count'] ?? 0)),
            'requires_confirmation' => true,
            'message' => $remoteWeight
                ? 'وزن ذخیره‌شده مرسوله نمایش داده شده است؛ آن را با وزن واقعی بسته بررسی و در صورت نیاز ویرایش کنید.'
                : (!empty($estimate['has_missing_item_weights'])
                    ? sprintf('وزن بعضی کالاها ثبت نشده است؛ برای هر عدد کالای بدون وزن، %s گرم و برای بسته‌بندی هر قلم %s گرم پیشنهاد شده است. وزن نهایی بسته را بررسی و در صورت نیاز ویرایش کنید.', $default, $padding)
                    : 'وزن پیشنهادی از کالاها و وزن بسته‌بندی محاسبه شده است؛ آن را با وزن واقعی بسته بررسی کنید.'),
        ];
    }

    /** Website weights use WooCommerce's configured unit; Basalam supplies its own gram provenance. */
    public static function estimateForOrder(\WC_Order $order): array
    {
        $missing = 0;
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $rawWeight = $product && method_exists($product, 'get_weight') ? $product->get_weight() : null;
            if (!is_numeric($rawWeight) || (float) $rawWeight <= 0 || Units::weightToGrams((float) $rawWeight) <= 0) {
                ++$missing;
            }
        }
        return ['has_missing_item_weights' => $missing > 0, 'missing_item_count' => $missing];
    }

    /** Called before remote creation/update and again against fresh remote weight before pay. */
    public static function assertConfirmed(array $request, $actualWeight): void
    {
        $actual = Units::parseBoundedFormInteger($actualWeight, 50, 30000);
        $confirmed = Units::parseBoundedFormInteger($request['confirmed_weight_grams'] ?? null, 50, 30000, true);
        $consent = $request['weight_confirmed'] ?? null;
        if ($actual === null || $confirmed === null || ($consent !== '1' && $consent !== 1) || $confirmed !== $actual) {
            throw new SalamGateException(
                'وزن نهایی بسته را بررسی و تأیید کنید؛ اگر وزن تغییر کرده، دوباره آن را تأیید کنید. ارسال و پرداخت انجام نشد.',
                422,
                [['location' => 'confirmed_weight_grams', 'message' => 'وزن تأییدشده باید با وزن فعلی مرسوله یکسان باشد.']],
                SalamGateException::KIND_VALIDATION
            );
        }
    }
}
