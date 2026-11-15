<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;

if (! defined('ABSPATH')) exit;

/**
 * ماشین‌حساب برآورد هزینه ارسال (POST /v1/base/calculator — عمومی، بدون احراز هویت).
 *
 * این مسیر هیچ اثر نوشتنی ندارد (بدون مرسوله/بدون hash/selected) — برخلاف
 * GET /v1/parcels/{id}/quotes که نرخ انتخابی را روی مرسوله تغییر می‌دهد.
 * برای پیش‌نمایش بدون ساخت مرسوله فقط همین مسیر مجاز است.
 */
class CalculatorService
{
    /**
     * @param int $senderCityId   شناسه شهر عمومی فرستنده
     * @param int $receiverCityId شناسه شهر عمومی گیرنده
     * @param int $weightGrams    وزن (گرم)
     * @param int $valueRial      ارزش (ریال)
     * @param int $boxTypeId      شناسه بسته‌بندی از box-types زنده
     * @param int|null $serviceType شناسه سرویس پست (1 پیشتاز، 3 ویژه، 5 اکسپرس) یا null = پیش‌فرض
     *
     * @return array{quotes: array<int, array>} پاسخ خام quotes — بدون نرخ انتخابی
     */
    public function estimate(
        int $senderCityId,
        int $receiverCityId,
        int $weightGrams,
        int $valueRial,
        int $boxTypeId,
        ?int $serviceType = null
    ): array {
        $body = [
            'sender_city_id'   => $senderCityId,
            'receiver_city_id' => $receiverCityId,
            'weight'           => $weightGrams,
            'value'            => $valueRial,
            'box_type'         => $boxTypeId,
        ];

        if ($serviceType !== null) {
            $body['service_type'] = $serviceType;
        }

        // Public estimates contain no account data. Repeated identical clicks can
        // reuse a short-lived estimate; shipping/payment always obtains its own quote.
        $cacheKey = 'salam_gate_estimate_' . hash('sha256', (string) wp_json_encode($body));
        $cached = get_transient($cacheKey);
        if (is_array($cached) && is_array($cached['quotes'] ?? null) && $cached['quotes'] !== []) return $cached;

        $response = ClientFactory::make()->publicPost(SalamGateClient::PATH_CALCULATOR, $body);
        $decoded = is_array($response['body'] ?? null) ? $response['body'] : [];

        $result = ['quotes' => is_array($decoded['quotes'] ?? null) ? $decoded['quotes'] : []];
        if ($result['quotes'] !== [] && array_filter($result['quotes'], static fn ($quote): bool =>
            is_array($quote) && empty($quote['error']) && isset($quote['costs']['total'])) !== []) {
            set_transient($cacheKey, $result, 60);
        }
        return $result;
    }
}
