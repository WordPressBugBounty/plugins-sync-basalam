<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\AccountService;
use SyncBasalam\SalamGate\Services\AddressesService;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

if (! defined('ABSPATH')) exit;

/**
 * داشبورد و تنظیمات محیط فعال (پارت ۵) — همه به API واقعی وصل‌اند؛ هیچ آمار/آدرس نمایشی ساختگی نیست.
 *
 * - آمار: شناسه کاربر از اتصال احرازشده (AccountService::profile)؛ نه ورودی مرورگر.
 *   فقط avg_shipping_cost و total_profit ریال→تومان؛ order_count عدد صحیح.
 * - آدرس‌ها: PATCH با address.id؛ انتخاب پیش‌فرض فقط با اقدام صریح کاربر.
 * - ویرایش غرفه: فقط provider_node_id و national_code_serial (محدودیت قرارداد PATCH /shops/{id}).
 */
class DashboardActions
{
    /**
     * آمار عملکرد — user_id از پروفایل اتصال احرازشده.
     */
    public static function stats(): void
    {
        self::guard();

        try {
            $stats = (new AccountService())->stats();
            wp_send_json_success([
                'order_count'       => (int) $stats['order_count'],
                // فقط مبالغ ریال→تومان؛ order_count دست‌نخورده
                'avg_shipping_cost_toman' => $stats['avg_shipping_cost'] !== null
                    ? Units::toToman((int) round((float) $stats['avg_shipping_cost']))
                    : null,
                'total_profit_toman' => $stats['total_profit'] !== null
                    ? Units::toToman((int) round((float) $stats['total_profit']))
                    : null,
            ]);
        } catch (SalamGateException $exception) {
            // صفر واقعی ≠ خطا ≠ loading — خطا با kind جدا برمی‌گردد.
            wp_send_json_error([
                'message' => $exception->firstMessage(),
                'kind'    => $exception->getKind(),
            ]);
        }
    }

    /**
     * داده تنظیمات: غرفه‌ها (بدون انتخاب کورکورانه)، آدرس‌ها، درخت شهر، هزینه فعال‌سازی.
     */
    public static function settingsData(): void
    {
        self::guard();

        try {
            $account = new AccountService();
            $senderProfile = $account->profile();
            $shops = (new ShopsService())->getShops();
            $account->assertSnapshot($senderProfile);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }

        try {
            $addresses = (new AddressesService())->list();
        } catch (SalamGateException $exception) {
            $addresses = null; // خطا ≠ خالی
        }
        try { $account->assertSnapshot($senderProfile); }
        catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }

        try {
            $providerResponse = ClientFactory::make()->get('/v1/providers/1');
            $activationCost = isset($providerResponse['body']['activation_cost'])
                ? (int) $providerResponse['body']['activation_cost']
                : null;
        } catch (SalamGateException $exception) {
            $activationCost = null;
        }

        try {
            $account->assertSnapshot($senderProfile);
            $senderIdentity = is_array($senderProfile['user'] ?? null) ? [
                'national_code' => $senderProfile['user']['national_code'] ?? null,
                'national_card_serial' => $senderProfile['user']['national_card_serial'] ?? null,
            ] : null;
        } catch (SalamGateException $exception) {
            $senderIdentity = null;
        }

        $vendor = self::connectedVendor();
        try { $account->assertSnapshot($senderProfile); }
        catch (SalamGateException $exception) { wp_send_json_error(self::errorPayload($exception)); }
        wp_send_json_success([
            'shops'     => $shops,      // همه غرفه‌ها — انتخاب اولین active ممنوع؛ UI وضعیت هرکدام را نشان می‌دهد
            'addresses' => $addresses, // null = خطا؛ [] = بدون آدرس؛ items = آدرس‌های واقعی
            'activation_cost_rial' => $activationCost,
            'connected_vendor' => $vendor,
            'sender_identity' => $senderIdentity,
        ]);
    }

    /** Fetch only the real connected vendor's display identity, never postal IDs or credentials. */
    private static function connectedVendor(): ?array
    {
        if (!class_exists(\SyncBasalam\Services\VendorInfoService::class)) {
            return null;
        }

        $vendorId = (int) syncBasalamSettings()->getSettings('vendor_id');
        if ($vendorId <= 0) {
            return null;
        }

        try {
            $cache = ConnectionCache::get('settings_vendor_identity');
            if (isset($cache['data']['vendor']) && (int) ($cache['data']['vendor']['id'] ?? 0) === $vendorId) {
                return $cache['data']['vendor'];
            }
            $identity = ConnectionCache::identityHash();
            $raw = (new \SyncBasalam\Services\VendorInfoService())->FetchVendorInfo();
            ConnectionCache::assertCurrentIdentity($identity);
            $vendor = self::normalizeConnectedVendor(is_array($raw) ? $raw : [], $vendorId);
            if ($vendor !== null) {
                ConnectionCache::setForIdentity('settings_vendor_identity', ['vendor' => $vendor], 600, $identity);
            }
        } catch (\Throwable $exception) {
            return null;
        }

        return $vendor;
    }

    /** @internal Pure normalizer; a different account's title must never label this connection. */
    public static function normalizeConnectedVendor(array $vendor, int $connectedId): ?array
    {
        if ($connectedId <= 0 || (int) ($vendor['id'] ?? 0) !== $connectedId) {
            return null;
        }

        $title = $vendor['title'] ?? $vendor['name'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            return null;
        }

        return ['id' => $connectedId, 'title' => trim($title)];
    }

    /**
     * ویرایش آدرس فرستنده — فقط با اقدام صریح کاربر (دکمه ذخیره در مودال).
     */
    public static function updateAddress(): void
    {
        self::guard();

        // address.id — نه active_version_id
        $addressId = isset($_POST['address_id']) ? (int) Digits::toEnglish((string) wp_unslash($_POST['address_id'])) : 0;
        if ($addressId <= 0) {
            wp_send_json_error(['message' => 'شناسه آدرس نامعتبر است.']);
        }

        $post = static fn (string $key): string => isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';

        $fields = [];
        if ($post('full_name') !== '') {
            $fields['full_name'] = $post('full_name');
        }
        if ($post('address') !== '') {
            $fields['address'] = $post('address');
        }
        if ($post('postal_code') !== '') {
            $fields['postal_code'] = $post('postal_code');
        }
        if ($post('phone') !== '') {
            $fields['phone'] = $post('phone');
        }
        if ($post('city_id') !== '') {
            $fields['city_id'] = (int) Digits::toEnglish($post('city_id'));
        }
        if ($post('is_default') === '1') {
            $fields['is_default'] = true;
        }
        // Map coordinates are optional. Sending either key is an explicit paired update.
        // Absence preserves existing coordinates; empty/null values must never become zero.
        if (array_key_exists('latitude', $_POST) || array_key_exists('longitude', $_POST)) {
            $coordinates = [];
            foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
                $raw = $_POST[$key] ?? null;
                if ((!is_string($raw) && !is_int($raw) && !is_float($raw))) {
                    wp_send_json_error(['message' => 'موقعیت آدرس را به‌صورت کامل و معتبر روی نقشه انتخاب کنید.', 'code' => 'invalid_coordinates']);
                }
                $normalized = str_replace('٫', '.', Digits::toEnglish(trim((string) wp_unslash($raw))));
                if ($normalized === '' || !is_numeric($normalized)) {
                    wp_send_json_error(['message' => 'موقعیت آدرس را به‌صورت کامل و معتبر روی نقشه انتخاب کنید.', 'code' => 'invalid_coordinates']);
                }
                $coordinate = (float) $normalized;
                if (!is_finite($coordinate) || $coordinate < -$limit || $coordinate > $limit) {
                    wp_send_json_error(['message' => 'موقعیت انتخاب‌شده روی نقشه معتبر نیست؛ نقطه دیگری انتخاب کنید.', 'code' => 'invalid_coordinates']);
                }
                $coordinates[$key] = $coordinate;
            }
            $fields += $coordinates;
        }

        try {
            $address = (new AddressesService())->update($addressId, $fields);
            wp_send_json_success(['address' => $address]);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * ویرایش غرفه — فقط provider_node_id و national_code_serial.
     * birth_date یا فیلدهای ثبت اولیه به این مسیر نمی‌روند (محدودیت قرارداد).
     */
    public static function updateShop(): void
    {
        self::guard();

        $shopId = isset($_POST['shop_id']) ? (int) Digits::toEnglish((string) wp_unslash($_POST['shop_id'])) : 0;
        if ($shopId <= 0) {
            wp_send_json_error(['message' => 'شناسه غرفه نامعتبر است.']);
        }

        $post = static fn (string $key): string => isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';

        $nodeId = (int) Digits::toEnglish($post('provider_node_id'));
        $serial = strtoupper(Digits::toEnglish($post('national_code_serial')));

        if ($nodeId <= 0 && $serial === '') {
            wp_send_json_error(['message' => 'چیزی برای ویرایش ارسال نشده است (فقط مرکز تحویل و سریال کارت ملی قابل ویرایش‌اند).']);
        }

        if ($serial !== '' && !preg_match('/^[A-Z0-9]{10}$/', $serial)) {
            wp_send_json_error([
                'message' => 'سریال کارت ملی باید دقیقاً ۱۰ کاراکتر لاتین حرف/عدد باشد.',
                'errors'  => [['location' => 'national_code_serial', 'message' => 'سریال نامعتبر است.']],
            ]);
        }

        $body = [];
        if ($nodeId > 0) {
            $body['provider_node_id'] = $nodeId;
        }
        if ($serial !== '') {
            $body['national_code_serial'] = $serial;
        }

        try {
            // Sender settings can change the postal center, but must retain the
            // address and select a real node from one of that province's regions.
            // Legacy consumers without sender scope keep the same PATCH contract.
            if (array_key_exists('sender_address_id', $_POST)) {
                foreach (['shop_id', 'sender_address_id', 'provider_location_id', 'provider_node_id'] as $field) {
                    $rawId = Digits::toEnglish($post($field));
                    if (!preg_match('/^[1-9][0-9]*$/D', $rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false) {
                        throw new SalamGateException('شناسه فرستنده یا مرکز تحویل نامعتبر است.', 422, [], SalamGateException::KIND_VALIDATION);
                    }
                }
                $addressId = (int) Digits::toEnglish($post('sender_address_id'));
                $locationId = (int) Digits::toEnglish($post('provider_location_id'));
                $senderShop = null;
                foreach ((new ShopsService())->getShops() as $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $shopId) {
                        $senderShop = $candidate;
                        break;
                    }
                }
                $provinceId = (int) ($senderShop['address']['city']['parent']['id'] ?? 0);
                if ($addressId <= 0 || $locationId <= 0 || $nodeId <= 0 || $provinceId <= 0
                    || (int) ($senderShop['address']['id'] ?? 0) !== $addressId || $serial !== '') {
                    throw new SalamGateException('اطلاعات فرستنده یا منطقه پستی معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION);
                }
                $references = new ReferenceDataService();
                $locations = $references->getProviderLocations($provinceId);
                if (!self::containsReferenceId($locations, $locationId)
                    || !self::containsReferenceId($references->getProviderNodes($locationId), $nodeId)) {
                    throw new SalamGateException('مرکز تحویل را از منطقه پستی انتخاب‌شده انتخاب کنید.', 422, [], SalamGateException::KIND_VALIDATION);
                }
            }
            $shop = (new ShopsService())->update($shopId, $body['provider_node_id'] ?? null, $body['national_code_serial'] ?? null);
            wp_send_json_success([
                'shop'     => $shop,
                // تغییر واقعی → وضعیت سرور به pending برمی‌گردد؛ برچسب «فعال» قبلی نگه داشته نمی‌شود.
                'status_id' => $shop['status_id'] ?? ShopStatuses::PENDING_APPROVAL,
            ]);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /** @internal Strict reference membership; arbitrary IDs must not become sender centers. */
    public static function containsReferenceId(array $references, int $id): bool
    {
        foreach ($references as $reference) {
            if (is_array($reference) && isset($reference['id'])
                && (is_int($reference['id']) || (is_string($reference['id']) && preg_match('/^[1-9][0-9]*$/D', $reference['id'])))
                && (int) $reference['id'] === $id && $id > 0) {
                return true;
            }
        }

        return false;
    }

    private static function errorPayload(SalamGateException $exception): array
    {
        return [
            'message' => $exception->firstMessage(),
            'errors'  => $exception->getErrors(),
            'kind'    => $exception->getKind(),
        ];
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }
    }
}
