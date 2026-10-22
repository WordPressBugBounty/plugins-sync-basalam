<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/** A local vendor suffix is not evidence that the remote product belongs to it. */
class ProductOwnership
{
    private $api;

    public function __construct($api = null)
    {
        $this->api = $api;
    }

    public function inspect($basalamProductId, $vendorId = null, bool $fetch = true): array
    {
        $productId = (int) $basalamProductId;
        $vendorId = (int) ($vendorId ?? syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID));
        if ($productId <= 0 || $vendorId <= 0) {
            return $this->state('unconnected', 'این محصول به غرفهٔ فعلی متصل نیست.');
        }

        $key = self::cacheKey($productId, $vendorId);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['status'], $cached['message'])) return $cached;

        $state = $this->state('unverified', 'در حال حاضر امکان بررسی اتصال محصول به غرفه وجود ندارد. دوباره تلاش کنید.');
        if (!$fetch) return $state;
        try {
            $api = $this->api ?: syncBasalamContainer()->get(ApiServiceManager::class);
            $response = $api->get(sprintf(Endpoints::PRODUCT_INFO, $productId), ['Prefer' => 'return=representation']);
            $body = $response['body'] ?? null;
            if (is_string($body)) $body = json_decode($body, true);
            $ownerId = is_array($body) ? (int) ($body['vendor']['id'] ?? 0) : 0;

            if ((int) ($response['status_code'] ?? 0) === 200
                && (int) ($body['id'] ?? 0) === $productId && $ownerId > 0) {
                $state = $ownerId === $vendorId
                    ? $this->state('owned', 'این محصول به غرفهٔ فعلی متصل است.', $ownerId)
                    : $this->state('mismatch', 'این محصول متعلق به غرفهٔ دیگری است و به غرفهٔ فعلی متصل نیست.', $ownerId);
            } elseif ((int) ($response['status_code'] ?? 0) === 404) {
                $state = $this->state('missing', 'محصول متصل‌شده در باسلام پیدا نشد. اتصال نیاز به بررسی دارد.');
            }
        } catch (\Throwable $exception) {
            if ((int) $exception->getCode() === 404) {
                $state = $this->state('missing', 'محصول متصل‌شده در باسلام پیدا نشد. اتصال نیاز به بررسی دارد.');
            }
            // In particular, a generic 403 or timeout does not prove a vendor mismatch.
        }

        set_transient($key, $state, in_array($state['status'], ['owned', 'mismatch'], true) ? 6 * HOUR_IN_SECONDS : MINUTE_IN_SECONDS);
        return $state;
    }

    public function assertOwned($basalamProductId, $vendorId = null): void
    {
        $state = $this->inspect($basalamProductId, $vendorId);
        if ($state['status'] === 'owned') return;
        if ($state['status'] === 'unverified') throw RetryableException::temporary($state['message']);
        throw new NonRetryableException($state['message'], 409);
    }

    /** Old queued updates are harmless when their current-vendor link is absent or foreign. */
    public function prepareQueuedUpdate(int $wooProductId, $vendorId = null): bool
    {
        $vendorId = (int) ($vendorId ?? syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID));
        $key = ProductMetaKey::basalamProductId($vendorId);
        $remoteId = get_post_meta($wooProductId, $key, true);
        $state = $this->inspect($remoteId, $vendorId);
        if ($state['status'] === 'unconnected') return false;
        if ($state['status'] === 'mismatch') {
            $this->forgetRejectedConnection($wooProductId, $vendorId, $remoteId, $state);
            return false;
        }

        // Preserve retry/failure behavior for an unavailable check or missing
        // remote product. Neither establishes a foreign-vendor connection.
        $this->assertOwned($remoteId, $vendorId);
        return (string) get_post_meta($wooProductId, $key, true) === (string) $remoteId;
    }

    /** Retain the rejected association, but let ordinary unconnected flows run. */
    public function forgetForeignConnection(int $wooProductId, bool $fetch = true): bool
    {
        $vendorId = (int) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
        $key = ProductMetaKey::basalamProductId($vendorId);
        $remoteId = get_post_meta($wooProductId, $key, true);
        $state = $this->inspect($remoteId, $vendorId, $fetch);
        if ($state['status'] !== 'mismatch') return false;

        return $this->forgetRejectedConnection($wooProductId, $vendorId, $remoteId, $state);
    }

    private function forgetRejectedConnection(int $wooProductId, int $vendorId, $remoteId, array $state): bool
    {
        $key = ProductMetaKey::basalamProductId($vendorId);

        $historyKey = '_sync_basalam_foreign_connection_' . $vendorId . '_' . (int) $remoteId;
        $history = ['product_id' => (int) $remoteId, 'owner_vendor_id' => $state['owner_vendor_id']];
        if (get_post_meta($wooProductId, $historyKey, true) !== $history
            && !update_post_meta($wooProductId, $historyKey, $history)) return false;

        // Compare the old value so a concurrent reconnect is not removed.
        // Status/variation metadata and other vendors' mappings stay intact.
        return delete_post_meta($wooProductId, $key, $remoteId);
    }

    /** The vendor-scoped create endpoint has just returned this product id. */
    public static function rememberCreated($basalamProductId, $vendorId): void
    {
        if ((int) $basalamProductId <= 0 || (int) $vendorId <= 0) return;
        set_transient(self::cacheKey((int) $basalamProductId, (int) $vendorId), [
            'status' => 'owned',
            'message' => 'این محصول به غرفهٔ فعلی متصل است.',
            'owner_vendor_id' => (int) $vendorId,
        ], 6 * HOUR_IN_SECONDS);
    }

    public static function cacheKey(int $productId, int $vendorId): string
    {
        return 'sync_basalam_product_owner_' . $vendorId . '_' . $productId;
    }

    private function state(string $status, string $message, int $ownerId = 0): array
    {
        return ['status' => $status, 'message' => $message, 'owner_vendor_id' => $ownerId];
    }
}
