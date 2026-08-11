<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/**
 * اتصال محصول ووکامرس به محصول باسلام.
 *
 * هر محصول باسلام باید فقط به یک محصول ووکامرس متصل باشد. هر روشی که از یک محصول
 * نسخه کپی می‌سازد (کپی ووکامرس، افزونه‌های کپی محصول، درون‌ریزی csv، کپی مستقیم دیتابیس)
 * شناسه اتصال را روی محصول جدید هم می‌نویسد و از آن به بعد بروزرسانی محصول جدید،
 * محصول قدیمی را در باسلام تغییر می‌دهد.
 */
class ProductConnection
{
    /**
     * حذف کامل اتصال محصول به باسلام (شامل کلیدهای مربوط به غرفه‌های قبلی و همه تنوع‌ها).
     */
    public static function purge($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0) return;

        foreach (self::storedConnectionMetaKeys($productId) as $metaKey) {
            delete_post_meta($productId, $metaKey);
        }

        foreach (self::variationIds($productId) as $variationId) {
            delete_post_meta($variationId, ProductMetaKey::basalamVariationId());
        }
    }

    /**
     * پاک کردن کامل هر چیزی که ووسلام روی محصول و تنوع‌هایش ذخیره کرده است.
     *
     * نسخه کپی یک محصول تازه است: نه اتصال محصول اصلی، نه هیچ تنظیم ووسلامی (ویدیو،
     * تغییر قیمت اختصاصی، فیلدهای طلا/موبایل، نوع و مقدار محصول، عمده‌فروشی، وضعیت تخفیف)
     * نباید از محصول قبلی به آن ارث برسد.
     */
    public static function purgeAll($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0) return;

        $postIds = array_merge([$productId], self::variationIds($productId));

        foreach ($postIds as $postId) {
            $metaKeys = apply_filters('sync_basalam_duplicate_purge_meta_keys', self::storedPluginMetaKeys($postId), $postId, $productId);

            foreach ((array) $metaKeys as $metaKey) {
                delete_post_meta($postId, $metaKey);
            }
        }
    }

    /**
     * پاک کردن کامل فقط در صورتی که این محصول نسخه کپی باشد؛ یعنی محصول قدیمی‌تری با همان اتصال وجود داشته باشد.
     *
     * برای هوک افزونه‌های کپی محصول استفاده می‌شود، جایی که مطمئن نیستیم شناسه‌ی داده شده
     * محصول جدید است یا محصول اصلی؛ اینطوری داده‌های محصول اصلی هیچ‌وقت پاک نمی‌شود.
     */
    public static function purgeIfCopy($productId): bool
    {
        $productId = (int) $productId;

        if ($productId <= 0) return false;

        $conflicts = self::conflictMap([$productId]);
        $olderOwners = $conflicts[$productId] ?? [];

        if (!$olderOwners || $productId < min($olderOwners)) return false;

        self::purgeAll($productId);

        return true;
    }

    /**
     * آیا این کلید متا متعلق به ووسلام است؟
     */
    public static function isPluginMetaKey($metaKey): bool
    {
        $metaKey = (string) $metaKey;

        foreach (self::pluginMetaKeyPrefixes() as $prefix) {
            if (strpos($metaKey, $prefix) === 0) return true;
        }

        return false;
    }

    /**
     * همه کلیدهای ووسلام که هم‌اکنون روی یک پست (محصول یا تنوع) ذخیره شده‌اند.
     */
    public static function storedPluginMetaKeys(int $postId): array
    {
        return self::storedMetaKeysByPrefix($postId, self::pluginMetaKeyPrefixes());
    }

    /**
     * پیشوند همه متاهای ووسلام روی محصول و تنوع (اتصال، وضعیت، تخفیف و همه فیلدهای تنظیمات).
     */
    public static function pluginMetaKeyPrefixes(): array
    {
        return ['sync_basalam_', '_sync_basalam_'];
    }

    /**
     * کلیدهای اتصالی که هم‌اکنون روی محصول ذخیره شده‌اند؛ بدون توجه به شناسه غرفه انتهای کلید.
     */
    public static function storedConnectionMetaKeys(int $productId): array
    {
        return self::storedMetaKeysByPrefix($productId, ProductMetaKey::basalamProductMetaKeyPrefixes());
    }

    private static function storedMetaKeysByPrefix(int $postId, array $prefixes): array
    {
        global $wpdb;

        $keys = [];

        foreach ($prefixes as $prefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Meta keys are vendor suffixed, so a LIKE lookup on core postmeta is required; results are not cacheable per key.
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                    $postId,
                    $wpdb->esc_like($prefix) . '%'
                )
            );

            if ($rows) $keys = array_merge($keys, $rows);
        }

        return array_values(array_unique($keys));
    }

    /**
     * شناسه محصولات دیگری که به همان محصول باسلام متصل هستند.
     *
     * @return array<int, int[]> [شناسه محصول ووکامرس => شناسه محصولات متعارض]
     */
    public static function conflictMap(array $productIds): array
    {
        global $wpdb;

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$productIds) return [];

        $metaKey = ProductMetaKey::basalamProductId();
        $idPlaceholders = implode(',', array_fill(0, count($productIds), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from an integer list and passed to prepare().
        $ownedRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND post_id IN ({$idPlaceholders})",
                array_merge([$metaKey], $productIds)
            )
        );

        if (!$ownedRows) return [];

        $basalamIds = array_values(array_unique(wp_list_pluck($ownedRows, 'meta_value')));
        $valuePlaceholders = implode(',', array_fill(0, count($basalamIds), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from the value list and passed to prepare().
        $ownerRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value IN ({$valuePlaceholders}) AND p.post_type = 'product'",
                array_merge([$metaKey], $basalamIds)
            )
        );

        $ownersByBasalamId = [];

        foreach ($ownerRows as $row) {
            $ownersByBasalamId[(string) $row->meta_value][] = (int) $row->post_id;
        }

        $conflicts = [];

        foreach ($ownedRows as $row) {
            $productId = (int) $row->post_id;
            $owners = $ownersByBasalamId[(string) $row->meta_value] ?? [];
            $others = array_values(array_diff(array_unique($owners), [$productId]));

            if ($others) $conflicts[$productId] = $others;
        }

        return $conflicts;
    }

    /**
     * آیا این محصول با محصول دیگری روی یک محصول باسلام مشترک است؟
     */
    public static function hasConflict($productId): bool
    {
        return self::conflictingProductIds($productId) !== [];
    }

    public static function conflictingProductIds($productId): array
    {
        $productId = (int) $productId;

        if ($productId <= 0) return [];

        $conflicts = self::conflictMap([$productId]);

        if (empty($conflicts[$productId])) return [];

        self::reportConflict($productId, $conflicts[$productId]);

        return $conflicts[$productId];
    }

    /**
     * جلوگیری از ارسال هر درخواستی به باسلام وقتی اتصال محصول قابل اعتماد نیست.
     *
     * @throws NonRetryableException
     */
    public static function assertUnique($productId, $basalamProductId = null): void
    {
        $productId = (int) $productId;
        $basalamProductId = $basalamProductId !== null && $basalamProductId !== ''
            ? $basalamProductId
            : get_post_meta($productId, ProductMetaKey::basalamProductId(), true);

        if (empty($basalamProductId)) {
            throw NonRetryableException::invalidData('این محصول به هیچ محصولی در باسلام متصل نیست.');
        }

        $conflictingIds = self::conflictingProductIds($productId);

        if (!$conflictingIds) return;

        throw NonRetryableException::permanent(esc_html(self::conflictMessage($basalamProductId, $conflictingIds)));
    }

    public static function conflictMessage($basalamProductId, array $conflictingIds): string
    {
        return sprintf(
            'این محصول با محصول(های) %s روی یک محصول باسلام (شناسه %s) اتصال مشترک دارد. برای جلوگیری از تغییر اشتباه محصول در باسلام، عملیات انجام نشد؛ ابتدا اتصال محصول تکراری (معمولا نسخه کپی شده) را قطع کنید.',
            implode('، ', array_map('strval', $conflictingIds)),
            (string) $basalamProductId
        );
    }

    /**
     * همه اتصال‌های تکراری سایت.
     *
     * @return array<int, array{basalam_product_id: string, product_ids: int[]}>
     */
    public static function duplicateConnections(): array
    {
        global $wpdb;

        $metaKey = ProductMetaKey::basalamProductId();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off integrity scan over core postmeta; there is no cache to read from.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS basalam_product_id, GROUP_CONCAT(pm.post_id ORDER BY pm.post_id ASC) AS product_ids
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type = 'product'
                 GROUP BY pm.meta_value
                 HAVING COUNT(DISTINCT pm.post_id) > 1",
                $metaKey
            )
        );

        $duplicates = [];

        foreach ($rows as $row) {
            $productIds = array_values(array_unique(array_map('intval', explode(',', (string) $row->product_ids))));

            sort($productIds);

            $duplicates[] = [
                'basalam_product_id' => (string) $row->basalam_product_id,
                'product_ids'        => $productIds,
            ];
        }

        return $duplicates;
    }

    /**
     * قطع اتصال نسخه‌های کپی و نگه داشتن قدیمی‌ترین محصول (محصول اصلی).
     *
     * @return array<int, array{basalam_product_id: string, kept: int, disconnected: int[]}>
     */
    public static function repairDuplicateConnections(): array
    {
        $repairs = [];

        foreach (self::duplicateConnections() as $duplicate) {
            $productIds = $duplicate['product_ids'];
            $kept = array_shift($productIds);

            if (!$productIds) continue;

            foreach ($productIds as $productId) {
                self::purge($productId);
            }

            $repair = [
                'basalam_product_id' => $duplicate['basalam_product_id'],
                'kept'               => (int) $kept,
                'disconnected'       => $productIds,
            ];

            Logger::warning('اتصال تکراری به یک محصول باسلام پیدا و اصلاح شد.', [
                'شناسه_محصول_باسلام' => $repair['basalam_product_id'],
                'محصول_باقی_مانده'   => $repair['kept'],
                'اتصال_قطع_شده'      => implode('، ', array_map('strval', $repair['disconnected'])),
                'عملیات'             => 'اصلاح اتصال تکراری',
            ]);

            $repairs[] = $repair;
        }

        if ($repairs) DuplicateConnectionReport::recordRepairs($repairs);

        return $repairs;
    }

    /**
     * ثبت اتصال مشترک در گزارش مدیر و لاگ (فقط بار اول برای هر مورد).
     */
    public static function reportConflict(int $productId, array $conflictingIds): void
    {
        $isNew = DuplicateConnectionReport::recordConflict($productId, $conflictingIds);

        if (!$isNew) return;

        Logger::error('همگام‌سازی متوقف شد: اتصال این محصول با محصول دیگری مشترک است.', [
            'product_id'      => $productId,
            'محصولات_متعارض' => implode('، ', array_map('strval', $conflictingIds)),
            'عملیات'          => 'بررسی اتصال محصول',
        ]);
    }

    private static function variationIds(int $productId): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Children are read directly so that variations are cleaned even before the parent object is loadable as a variable product.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $productId
            )
        );

        return array_map('intval', $ids ?: []);
    }
}
