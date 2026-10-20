<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/**
 * داده‌های مرجع سلام‌گیت.
 *
 * داده عمومی (بدون وابستگی به حساب): درخت استان/شهر و box-types — کش عمومی.
 * در خطای API اگر کش منقضی‌شده موجود باشد به‌عنوان stale برگردانده می‌شود (با نشان stale)
 * تا UI بدون داده/شناسه تخیلی نماند.
 *
 * مراقبت شناسه‌ها (هرگز جابه‌جا نشوند):
 * - city_id عمومی (locations) — برای آدرس گیرنده/فرستنده.
 * - provider_location_id (providers/locations) — شناسه منطقه پستی provider؛
 *   پارامتر city_id مسیر nodes در واقع همین شناسه را می‌خواهد.
 */
class ReferenceDataService
{
    public const LOCATIONS_TRANSIENT = 'salam_gate_locations';
    public const BOX_TYPES_TRANSIENT = 'salam_gate_box_types';
    public const PROVIDER_LOCATIONS_TRANSIENT = 'salam_gate_provider_locations';
    public const PROVIDER_NODES_TRANSIENT = 'salam_gate_provider_nodes';
    public const TRANSIENT_TTL = 86400; // ۲۴ ساعت
    public const SNAPSHOT_OPTION_PREFIX = 'salam_gate_reference_snapshot_';
    public const STALE_RETRY_TTL = 300;

    public const POST_PROVIDER_ID = 1;

    /**
     * درخت {id, title, parent_id, children[]} ریشه‌دار در سطح استان.
     *
     * @return array<int, array>
     */
    public function getLocations(): array
    {
        return $this->getProvinceTreeWithState()['data'];
    }

    /**
     * درخت استان/شهر مورد استفاده راه‌اندازی فرم‌های ثبت و ارسال.
     *
     * @return array<int, array>
     */
    public function getProvinceTree(): array
    {
        return $this->getLocations();
    }

    /**
     * Public location references, independent of account/profile availability.
     * An empty or malformed old cache must not keep the province selector empty.
     * A valid cached tree can still be used transparently when an explicit reload fails.
     *
     * @return array{data: array, stale: bool}
     */
    public function getProvinceTreeWithState(bool $fresh = false): array
    {
        $result = $this->fetchCached(
            self::LOCATIONS_TRANSIENT,
            SalamGateClient::PATH_LOCATIONS,
            false,
            [],
            false,
            $fresh,
            [self::class, 'isValidProvinceTree']
        );

        return ['data' => $result['data'], 'stale' => $result['stale']];
    }

    /**
     * Validate the real nested locations contract without guessing IDs or titles.
     * parent_id is optional in legacy caches, but must agree with the tree when present.
     */
    public static function isValidProvinceTree(array $tree): bool
    {
        if ($tree === [] || !Arrays::isList($tree)) {
            return false;
        }

        $seen = [];
        $positiveId = static function ($value): ?int {
            if (!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value))) {
                return null;
            }

            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $id === false ? null : $id;
        };
        $visit = static function ($node, ?int $parentId, int $depth) use (&$visit, &$seen, $positiveId): bool {
            if (!is_array($node) || $depth > 8 || count($seen) >= 10000) {
                return false;
            }

            $id = $positiveId($node['id'] ?? null);
            if ($id === null || isset($seen[$id]) || !is_string($node['title'] ?? null)
                || trim($node['title']) === '') {
                return false;
            }
            if ($parentId !== null && array_key_exists('parent_id', $node)
                && $positiveId($node['parent_id']) !== $parentId) {
                return false;
            }

            // Provinces carry their children; old leaf nodes may omit an empty list.
            if ($parentId === null && !array_key_exists('children', $node)) {
                return false;
            }
            $children = array_key_exists('children', $node) ? $node['children'] : [];
            if (!is_array($children) || !Arrays::isList($children)) {
                return false;
            }

            $seen[$id] = true;
            foreach ($children as $child) {
                if (!$visit($child, $id, $depth + 1)) {
                    return false;
                }
            }

            return true;
        };

        $hasCity = false;
        foreach ($tree as $province) {
            if (!$visit($province, null, 0)) {
                return false;
            }
            // Individual empty provinces are harmless; an entire tree without
            // direct city children cannot populate any shipping destination.
            $hasCity = $hasCity || $province['children'] !== [];
        }

        return $hasCity;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function getBoxTypes(): array
    {
        return $this->getBoxTypesWithState()['data'];
    }

    /**
     * همان داده‌ها + نشان stale (کش منقضی به‌دلیل خطای API) برای پیام شفاف UI.
     *
     * @return array{data: array, stale: bool}
     */
    public function getBoxTypesWithState(): array
    {
        $result = $this->fetchCached(
            self::BOX_TYPES_TRANSIENT,
            SalamGateClient::PATH_BOX_TYPES,
            false,
            [],
            false,
            false,
            [self::class, 'isValidNamedReferences']
        );

        return ['data' => $result['data'], 'stale' => $result['stale']];
    }

    public static function isValidNamedReferences(array $items): bool
    {
        if ($items === [] || !Arrays::isList($items) || count($items) > 1000) {
            return false;
        }
        $seen = [];
        foreach ($items as $item) {
            $rawId = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_int($rawId) && (!is_string($rawId) || !preg_match('/^[1-9][0-9]*$/D', $rawId))) {
                return false;
            }
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || isset($seen[$id]) || !is_string($item['title'] ?? null) || trim($item['title']) === '') {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    /**
     * منطقه‌های پستی provider به تفکیک استان (احرازشده).
     *
     * @return array<int, array{id:int, title:string}> ممکن است خالی باشد = این استان پوشش ندارد
     */
    public function getProviderLocations(int $provinceId, bool $fresh = false): array
    {
        $cacheKey = self::PROVIDER_LOCATIONS_TRANSIENT . '_' . self::POST_PROVIDER_ID . '_' . $provinceId;

        $result = $this->fetchCached(
            $cacheKey,
            sprintf('/v1/providers/%d/locations', self::POST_PROVIDER_ID),
            false,
            ['province_id' => $provinceId],
            true,
            $fresh
        );

        return $result['data'];
    }

    /**
     * مراکز تحویل یک منطقه پستی (احرازشده).
     *
     * نکته حیاتی قرارداد: پارامتر query این مسیر «city_id» نام دارد ولی طبق سورس سرویس
     * provider_location.id می‌خواهد — نه city_id عمومی باسلام. امضای متد عمداً
     * providerLocationId است تا جابه‌جایی رخ ندهد.
     * مسیر providers/nodes صفحه‌بندی ندارد و تمام مراکز فعال منطقه را در یک آرایه
     * برمی‌گرداند؛ هیچ سقف تعداد یا حذف بر اساس خالی‌بودن اطلاعات تماس اعمال نمی‌شود.
     *
     * @param int $providerLocationId شناسه از getProviderLocations()
     *
     * @return array<int, array{id:int, title:string, address:?string, postal_code:?string, phone:?string, owner:?string, provider_location:?array}>
     */
    public function getProviderNodes(int $providerLocationId, bool $fresh = false): array
    {
        $cacheKey = self::PROVIDER_NODES_TRANSIENT . '_' . self::POST_PROVIDER_ID . '_' . $providerLocationId;

        $result = $this->fetchCached(
            $cacheKey,
            sprintf('/v1/providers/%d/nodes', self::POST_PROVIDER_ID),
            false,
            // ← مقدار این پارامتر provider_location_id است؛ نام city_id فقط قرارداد مسیر است.
            ['city_id' => $providerLocationId],
            true,
            $fresh,
            [self::class, 'isValidProviderNodes']
        );

        return $result['data'];
    }

    /**
     * Keep the whole flat provider list. Missing optional contact/address data is
     * valid and must never remove a delivery centre. A title can use the UI's ID
     * fallback when an older cached record omitted it.
     */
    public static function isValidProviderNodes(array $nodes): bool
    {
        if (!Arrays::isList($nodes)) {
            return false;
        }

        foreach ($nodes as $node) {
            $rawId = is_array($node) ? ($node['id'] ?? null) : null;
            if (!is_int($rawId) && (!is_string($rawId) || !preg_match('/^[1-9][0-9]*$/D', $rawId))) {
                return false;
            }
            if (filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * اطلاعات cursor خود را بازگردانده نمی‌دهد؛ فقط BC برای صفحات فعلی.
     *
     * @return array{fetched_at: ?int, provinces: int, cities: int}
     */
    public function getLocationsCacheInfo(): array
    {
        $cache = $this->fetchCached(self::LOCATIONS_TRANSIENT, SalamGateClient::PATH_LOCATIONS, false, [], false, false, [self::class, 'isValidProvinceTree']);

        return [
            'fetched_at' => $cache['fetched_at'],
            'provinces'  => count($cache['data']),
            'cities'     => self::countCities($cache['data']),
        ];
    }

    public static function countCities(array $tree): int
    {
        $count = 0;

        foreach ($tree as $province) {
            if (is_array($province) && is_array($province['children'] ?? null)) {
                $count += count($province['children']);
            }
        }

        return $count;
    }

    public function flush(): void
    {
        delete_transient(self::LOCATIONS_TRANSIENT);
        delete_transient(self::BOX_TYPES_TRANSIENT);
    }

    /**
     * شناسه شهر با تطبیق نام استان + شهر روی درخت (نرمال‌شده).
     */
    public function findCityId(string $provinceTitle, string $cityTitle): ?int
    {
        return self::findCityIdInTree($this->getLocations(), $provinceTitle, $cityTitle);
    }

    /**
     * نسخه pure برای تست: جستجوی شهر داخل درخت داده‌شده.
     *
     * @param array<int, array> $tree
     */
    public static function findCityIdInTree(array $tree, string $provinceTitle, string $cityTitle): ?int
    {
        $needleProvince = self::normalize($provinceTitle);
        $needleCity = self::normalize($cityTitle);

        if ($needleProvince === '' || $needleCity === '') {
            return null;
        }

        foreach ($tree as $province) {
            if (!is_array($province)) {
                continue;
            }

            if (self::normalize((string) ($province['title'] ?? '')) !== $needleProvince) {
                continue;
            }

            foreach (($province['children'] ?? []) as $city) {
                if (!is_array($city)) {
                    continue;
                }

                if (self::normalize((string) ($city['title'] ?? '')) === $needleCity) {
                    $id = (int) ($city['id'] ?? 0);

                    return $id > 0 ? $id : null;
                }
            }
        }

        return null;
    }

    /**
     * پیدا کردن مسیر شهر در درخت: ['province' => title, 'city' => title] با شناسه شهر.
     *
     * @return array{province: string, city: string}|null
     */
    public static function findLocationPathInTree(array $tree, int $cityId): ?array
    {
        foreach ($tree as $province) {
            if (!is_array($province)) {
                continue;
            }

            foreach (($province['children'] ?? []) as $city) {
                if (is_array($city) && (int) ($city['id'] ?? 0) === $cityId) {
                    return [
                        'province' => (string) ($province['title'] ?? ''),
                        'city'     => (string) ($city['title'] ?? ''),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * شناسه شهر برای سفارش‌های ووکامرس: state می‌تواند نام فارسی، کد سه‌حرفی یا شناسه PWS باشد.
     * کد سه‌حرفی از کلاس پایه (در صورت وجود) به نام استان تبدیل می‌شود؛ حل نشد → null (انتخاب دستی).
     */
    public function findCityIdForAddress(?string $stateValue, ?string $cityTitle): ?int
    {
        if ($cityTitle === null || trim($cityTitle) === '') {
            return null;
        }

        $candidates = [];

        if ($stateValue !== null && trim($stateValue) !== '') {
            $candidates[] = trim($stateValue);

            if (class_exists(\SyncBasalam\Utilities\GetProvincesData::class)) {
                $byCode = \SyncBasalam\Utilities\GetProvincesData::getNameByCode(strtoupper(trim($stateValue)));
                if ($byCode !== null) {
                    $candidates[] = $byCode;
                }
            }
        }

        foreach ($candidates as $province) {
            $cityId = $this->findCityId($province, $cityTitle);
            if ($cityId !== null) {
                return $cityId;
            }
        }

        return null;
    }

    /**
     * نرمال‌سازی فارسی (مشابه GetProvincesData پایه): ي/ك عربی، نیم‌فاصله، فاصله تکراری، BOM.
     */
    public static function normalize(string $value): string
    {
        $value = str_replace(
            ["\xEF\xBB\xBF", "\xE2\x80\x8C", "\xC2\xA0", 'ي', 'ك', 'ﮐ', 'ﮏ', 'ﯼ', 'ﯽ'],
            ['', ' ', ' ', 'ی', 'ک', 'ک', 'ک', 'ی', 'ی'],
            $value
        );

        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }

    /**
     * خواندن/دریافت با کش ساختار {fetched_at, data}.
     *
     * در خطای API: اگر کش (حتی منقضی) موجود باشد به‌عنوان stale برگردانده می‌شود؛
     * در غیر این صورت exception بالا می‌رود — لیست تخیلی ساخته نمی‌شود.
     *
     * @return array{fetched_at: ?int, data: array, stale: bool}
     */
    private function fetchCached(
        string $transientKey,
        string $path,
        bool $isPost,
        array $queryOrBody = [],
        bool $authenticated = false,
        bool $fresh = false,
        ?callable $validator = null
    ): array {
        $cached = get_transient($transientKey);
        // Only stable public references are persisted. Sender/account data never is.
        $snapshotKey = !$authenticated && !$isPost && in_array($transientKey, [self::LOCATIONS_TRANSIENT, self::BOX_TYPES_TRANSIENT], true)
            ? self::SNAPSHOT_OPTION_PREFIX . $transientKey : null;
        $snapshot = null;
        if ($snapshotKey !== null) {
            $json = get_option($snapshotKey, null);
            $stored = is_string($json) && strlen($json) <= 2000000 ? json_decode($json, true) : null;
            if (is_array($stored) && ($stored['version'] ?? null) === 1
                && is_int($stored['fetched_at'] ?? null) && $stored['fetched_at'] > 0 && $stored['fetched_at'] <= time()
                && is_array($stored['data'] ?? null) && $validator !== null && $validator($stored['data'])) {
                $snapshot = ['fetched_at' => $stored['fetched_at'], 'data' => $stored['data']];
            }
        }

        $fromCache = static function () use ($cached, $validator): ?array {
            if (!is_array($cached)) {
                return null;
            }

            if (array_key_exists('data', $cached) && is_array($cached['data'])) {
                $candidate = [
                    'fetched_at' => isset($cached['fetched_at']) ? (int) $cached['fetched_at'] : null,
                    'data'       => $cached['data'],
                    'stale'      => !empty($cached['stale']),
                ];
            } else {
                // ساختار قدیمی (آرایه خام)
                $candidate = ['fetched_at' => null, 'data' => $cached, 'stale' => false];
            }

            return $validator !== null && !$validator($candidate['data']) ? null : $candidate;
        };

        if (!$fresh) {
            $valid = $fromCache();
            if ($valid !== null) {
                // Migrate a valid pre-existing transient without another network request.
                if ($snapshotKey !== null && $snapshot === null && !$valid['stale']
                    && is_int($valid['fetched_at']) && $valid['fetched_at'] > 0 && $valid['fetched_at'] <= time()) {
                    $this->saveSnapshot($snapshotKey, $valid['data'], $valid['fetched_at']);
                }
                return ['fetched_at' => $valid['fetched_at'], 'data' => $valid['data'], 'stale' => $valid['stale']];
            }
        }

        $client = ClientFactory::make();

        try {
            if ($isPost) {
                $response = $authenticated
                    ? $client->post($path, $queryOrBody)
                    : $client->publicPost($path, $queryOrBody);
            } else {
                $response = $authenticated
                    ? $client->get($path, $queryOrBody)
                    : $client->publicGet($path, $queryOrBody);
            }

            $data = is_array($response['body'] ?? null) ? $response['body'] : [];
            if ($validator !== null && !$validator($data)) {
                throw new SalamGateException(
                    $transientKey === self::BOX_TYPES_TRANSIENT
                        ? 'فهرست نوع بسته‌بندی سلام‌گیت نامعتبر یا خالی است؛ دوباره تلاش کنید.'
                        : (str_starts_with($transientKey, self::PROVIDER_NODES_TRANSIENT . '_')
                            ? 'فهرست مراکز تحویل سلام‌گیت نامعتبر است؛ دوباره تلاش کنید.'
                            : 'فهرست استان‌ها و شهرهای سلام‌گیت نامعتبر یا خالی است؛ دوباره تلاش کنید.'),
                    (int) ($response['code'] ?? 200),
                    [],
                    SalamGateException::KIND_UNEXPECTED_RESPONSE
                );
            }
        } catch (\Throwable $exception) {
            $stale = $fromCache() ?? $snapshot;
            if ($stale !== null) {
                // Brief backoff stops every page/retry hammering an unavailable API.
                set_transient($transientKey, [
                    'fetched_at' => $stale['fetched_at'], 'data' => $stale['data'], 'stale' => true,
                ], self::STALE_RETRY_TTL);
                return ['fetched_at' => $stale['fetched_at'], 'data' => $stale['data'], 'stale' => true];
            }

            throw $exception;
        }

        set_transient($transientKey, [
            'fetched_at' => time(),
            'data'       => $data,
        ], self::TRANSIENT_TTL);
        if ($snapshotKey !== null) {
            $this->saveSnapshot($snapshotKey, $data, time());
        }

        return ['fetched_at' => time(), 'data' => $data, 'stale' => false];
    }

    private function saveSnapshot(string $key, array $data, int $fetchedAt): void
    {
        $json = json_encode(['version' => 1, 'fetched_at' => $fetchedAt, 'data' => $data], JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) <= 2000000) {
            update_option($key, $json, false);
        }
    }
}
