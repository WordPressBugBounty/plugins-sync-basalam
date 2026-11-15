<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

/**
 * بررسی یک صفحه از محصولات غرفه در باسلام و ثبت موارد بدون مپینگ ووکامرس.
 *
 * هر فراخوانی فقط «یک» صفحهٔ ۳۰تایی را می‌خواند تا اجرا در صف سریالی jobها
 * فشاری به سایت و API نیاورد؛ ادامهٔ پیمایش با cursor به job بعدی سپرده می‌شود.
 */
class UnmappedProductsScanner
{
    public const PER_PAGE = 30;
    public const STATUS_ACTIVE = 2976;

    private $api;
    private $repository;

    public function __construct($api = null, $repository = null)
    {
        $this->api = $api;
        $this->repository = $repository ?: new UnmappedProductsRepository();
    }

    /**
     * @return array{has_more: bool, next_cursor: ?string, scanned: int, found: int}
     * @throws \SyncBasalam\Jobs\Exceptions\JobException
     */
    public function scanPage(string $vendorId, ?string $cursor = null): array
    {
        $page = $this->fetchPage($vendorId, $cursor);

        $active = self::activeProducts($page['data']);
        $found = 0;

        if ($active) {
            $ids = array_map(static function ($product) {
                return (int) $product['id'];
            }, $active);

            $mapped = $this->repository->filterMappedIds($ids);
            $unmapped = array_filter($active, static function ($product) use ($mapped) {
                return !in_array((int) $product['id'], $mapped, true);
            });

            if ($unmapped) {
                $rows = array_map([self::class, 'toRow'], array_values($unmapped));
                $found = $this->repository->insertUnmapped($rows, $vendorId);
            }
        }

        return [
            'has_more' => !empty($page['has_more']) && !empty($page['next_cursor']),
            'next_cursor' => $page['next_cursor'],
            'scanned' => count($page['data']),
            'found' => $found,
        ];
    }

    /**
     * فقط محصولات «فعال» باسلام موضوع این گزارش هستند؛ آیتم بدون فیلد وضعیت
     * حذف نمی‌شود تا پاسخ قدیمی/ناقص API باعث جاافتادن محصول نشود.
     *
     * @param array<int, mixed> $products
     * @return array<int, array>
     */
    public static function activeProducts(array $products): array
    {
        return array_values(array_filter($products, static function ($product) {
            if (!is_array($product) || empty($product['id'])) return false;

            $status = self::statusId($product);

            return $status === null || $status === self::STATUS_ACTIVE;
        }));
    }

    /** وضعیت در پاسخ‌های مختلف API عدد خام یا آبجکت {id|value} است. */
    public static function statusId(array $product): ?int
    {
        if (!array_key_exists('status', $product)) return null;

        $status = $product['status'];

        if (is_numeric($status)) return (int) $status;

        if (is_array($status)) {
            if (isset($status['id']) && is_numeric($status['id'])) return (int) $status['id'];
            if (isset($status['value']) && is_numeric($status['value'])) return (int) $status['value'];
        }

        return null;
    }

    /** @return array{basalam_product_id: int, title: string, price: int, photo: string} */
    public static function toRow(array $product): array
    {
        $photo = '';
        if (isset($product['photo'])) {
            if (is_array($product['photo'])) {
                $photo = (string) ($product['photo']['md'] ?? $product['photo']['original'] ?? $product['photo']['sm'] ?? '');
            } elseif (is_string($product['photo'])) {
                $photo = $product['photo'];
            }
        }

        return [
            'basalam_product_id' => (int) $product['id'],
            'title' => mb_substr(trim((string) ($product['title'] ?? '')), 0, 500),
            'price' => (int) ($product['price'] ?? 0),
            'photo' => $photo,
        ];
    }

    /**
     * برخلاف FetchProductsData که خطا را به «صفحهٔ خالی» تبدیل می‌کند، اینجا خطا باید
     * بالا بیاید؛ وگرنه شکست موقت API، «پایان بررسی» با نتیجهٔ ناقص جا زده می‌شود.
     *
     * @return array{data: array, has_more: bool, next_cursor: ?string}
     */
    private function fetchPage(string $vendorId, ?string $cursor): array
    {
        $query = [
            'per_page' => self::PER_PAGE,
            'vendor_ids' => $vendorId,
        ];
        if ($cursor !== null && $cursor !== '') $query['cursor'] = $cursor;

        $api = $this->api ?: syncBasalamContainer()->get(ApiServiceManager::class);
        $response = $api->get(Endpoints::PRODUCTS_DATA . '?' . http_build_query($query));

        $body = isset($response['body']) && is_string($response['body'])
            ? json_decode($response['body'], true)
            : null;

        // صفحهٔ پایانی فهرست core به‌جای آرایهٔ خالی، data:null برمی‌گرداند
        // ({"data":null,"next_cursor":null,"result_count":0})؛ این پایان بررسی است، نه خطا.
        if (is_array($body) && array_key_exists('data', $body) && $body['data'] === null) {
            $body['data'] = [];
        }

        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            throw RetryableException::temporary('پاسخ فهرست محصولات باسلام قابل خواندن نبود.');
        }

        $nextCursor = isset($body['next_cursor']) && $body['next_cursor'] !== '' ? (string) $body['next_cursor'] : null;

        return [
            'data' => $body['data'],
            'has_more' => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ];
    }

    public static function currentVendorId(): string
    {
        return (string) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
    }
}
