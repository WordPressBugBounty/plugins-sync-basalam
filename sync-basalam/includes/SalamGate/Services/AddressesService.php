<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;

if (! defined('ABSPATH')) exit;

/**
 * آدرس‌های فرستنده (GET /v1/addresses + PATCH /v1/addresses/{address.id}).
 *
 * نکته قرارداد: PATCH با address.id (نه active_version_id) است.
 * تغییر آدرس نسخه جدید می‌سازد؛ تغییر کدپستی غرفه‌ها را به pending می‌برد.
 * only PATCH keys: city_id/full_name/address/postal_code/phone/latitude/longitude/is_default.
 */
class AddressesService
{
    /**
     * @return array{items: array<int, array>, next_cursor: ?string}
     * @throws SalamGateException
     */
    public function list(int $perPage = 30): array
    {
        $response = ClientFactory::make()->get('/v1/addresses', ['per_page' => min(30, max(1, $perPage))]);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];

        return [
            'items' => array_values(array_filter((array) ($body['data'] ?? []), 'is_array')),
            'next_cursor' => isset($body['next_cursor']) && $body['next_cursor'] !== null ? (string) $body['next_cursor'] : null,
        ];
    }

    /**
     * ویرایش آدرس با address.id — فقط فیلدهای مجاز قرارداد ارسال می‌شوند.
     *
     * @param int $addressId address.id (نه active_version_id)
     * @param array{city_id?:int, full_name?:string, address?:string, postal_code?:string, phone?:string, latitude?:?float, longitude?:?float, is_default?:bool} $fields
     * @return array پاسخ نرمال‌شده آدرس جدید
     * @throws SalamGateException
     */
    public function update(int $addressId, array $fields): array
    {
        $body = self::buildUpdateBody($fields);

        if ($body === []) {
            throw new SalamGateException('هیچ فیلد قابل ویرایشی ارسال نشده است.', 422, [
                ['location' => 'body', 'message' => 'هیچ فیلد قابل ویرایشی ارسال نشده است.'],
            ], SalamGateException::KIND_VALIDATION);
        }

        $response = ClientFactory::make()->patch(sprintf('/v1/addresses/%d', $addressId), $body);

        // تغییر آدرس/کدپستی می‌تواند داده یا وضعیت غرفه‌های مرتبط را عوض کند.
        (new ShopsService())->flushCache();

        return is_array($response['body'] ?? null) ? $response['body'] : [];
    }

    /**
     * ساخت body مطابق UpdateAddressRequest — فقط کلیدهای معتبر و فقط تغییرات واقعی.
     * (خالص و تست‌پذیر)
     *
     * @return array<string, mixed>
     */
    public static function buildUpdateBody(array $fields): array
    {
        $body = [];

        if (isset($fields['city_id']) && (int) $fields['city_id'] > 0) {
            $body['city_id'] = (int) $fields['city_id'];
        }
        if (isset($fields['full_name']) && is_string($fields['full_name']) && trim($fields['full_name']) !== '') {
            $body['full_name'] = trim($fields['full_name']);
        }
        if (isset($fields['address']) && is_string($fields['address']) && trim($fields['address']) !== '') {
            $body['address'] = trim($fields['address']);
        }
        if (isset($fields['postal_code'])) {
            $postal = Digits::normalizePostalCode((string) $fields['postal_code']);
            if ($postal !== null) {
                $body['postal_code'] = $postal;
            }
        }
        if (isset($fields['phone'])) {
            $phone = Digits::toEnglish(trim((string) $fields['phone']));
            if ($phone !== '') {
                $body['phone'] = $phone;
            }
        }
        if (array_key_exists('latitude', $fields)) {
            // null مجاز است (حذف مختصات)؛ عدد باید محدوده معتبر داشته باشد.
            $body['latitude'] = $fields['latitude'] === null ? null : (float) $fields['latitude'];
        }
        if (array_key_exists('longitude', $fields)) {
            $body['longitude'] = $fields['longitude'] === null ? null : (float) $fields['longitude'];
        }
        if (isset($fields['is_default']) && is_bool($fields['is_default'])) {
            $body['is_default'] = $fields['is_default'];
        }

        return $body;
    }
}
