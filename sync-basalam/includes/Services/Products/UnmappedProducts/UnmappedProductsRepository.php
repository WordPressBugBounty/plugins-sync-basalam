<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/**
 * نگهداری نتیجهٔ بررسی: محصولات فعال باسلام که در ووکامرس مپینگی ندارند.
 *
 * هر ردیف تا زمان تعیین‌تکلیف (آرشیو/اتصال/نادیده‌گرفتن) باز می‌ماند و قفل
 * اجرای مجدد بررسی را نگه می‌دارد.
 */
class UnmappedProductsRepository
{
    public const TABLE = 'sync_basalam_unmapped_products';
    public const TABLE_VERSION_OPTION = 'sync_basalam_unmapped_table_version';
    public const TABLE_VERSION = '1';

    public const STATE_OPEN = 'open';
    public const STATE_ARCHIVING = 'archiving';
    public const STATE_ARCHIVED = 'archived';
    public const STATE_CONNECTED = 'connected';
    public const STATE_IGNORED = 'ignored';
    public const STATE_FAILED = 'failed';

    /** آیتم‌هایی که هنوز منتظر اقدام کاربر هستند. */
    public const UNRESOLVED_STATES = [self::STATE_OPEN, self::STATE_ARCHIVING, self::STATE_FAILED];

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * جدول باید روی نصب‌های موجود هم ساخته شود؛ به نسخهٔ انتشار گره نمی‌خوریم و
     * با یک option نسخه‌دار، dbDelta فقط یک بار اجرا می‌شود.
     */
    public static function ensureTable(): void
    {
        if (\get_option(self::TABLE_VERSION_OPTION) === self::TABLE_VERSION) return;

        self::createTable();

        \update_option(self::TABLE_VERSION_OPTION, self::TABLE_VERSION, false);
    }

    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id VARCHAR(64) NOT NULL DEFAULT '',
            basalam_product_id BIGINT(20) UNSIGNED NOT NULL,
            title TEXT NULL,
            price BIGINT(20) NOT NULL DEFAULT 0,
            photo VARCHAR(2083) NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'open',
            error_message TEXT NULL,
            created_at BIGINT(20) NOT NULL DEFAULT 0,
            resolved_at BIGINT(20) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_basalam_product (basalam_product_id),
            KEY idx_vendor_state (vendor_id, state)
        ) $charsetCollate;";

        dbDelta($sql);
    }

    /** شروع بررسی تازه یعنی گزارش جدید؛ ردیف‌های گزارش قبلیِ همین غرفه پاک می‌شوند. */
    public function clearForVendor(string $vendorId): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
        $wpdb->delete(self::tableName(), ['vendor_id' => $vendorId]);
    }

    /**
     * @param array<int, array{basalam_product_id: int, title: string, price: int, photo: string}> $rows
     * @return int تعداد ردیف‌های تازه ثبت‌شده
     */
    public function insertUnmapped(array $rows, string $vendorId): int
    {
        global $wpdb;

        if (!$rows) return 0;

        $now = time();
        $values = [];
        $placeholders = [];

        foreach ($rows as $row) {
            $placeholders[] = '(%s, %d, %s, %d, %s, %s, %d)';
            $values[] = $vendorId;
            $values[] = (int) $row['basalam_product_id'];
            $values[] = (string) $row['title'];
            $values[] = (int) $row['price'];
            $values[] = (string) $row['photo'];
            $values[] = self::STATE_OPEN;
            $values[] = $now;
        }

        $table = self::tableName();
        // یک retry از صفحهٔ قبلاً ثبت‌شده نباید خطا بدهد یا وضعیت ردیف را بازنویسی کند.
        $sql = "INSERT IGNORE INTO {$table}
                (vendor_id, basalam_product_id, title, price, photo, state, created_at)
                VALUES " . implode(', ', $placeholders);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $inserted = $wpdb->query($wpdb->prepare($sql, $values));

        return $inserted === false ? 0 : (int) $inserted;
    }

    /**
     * از بین شناسه‌های باسلام داده‌شده، آن‌هایی را برمی‌گرداند که در ووکامرس مپ شده‌اند.
     *
     * پیشوندی مقایسه می‌شود تا کلیدهای قدیمیِ بدون پسوند غرفه هم اتصال حساب شوند
     * و محصول متصل، به‌اشتباه «متصل‌نشده» گزارش نشود.
     *
     * @param array<int> $basalamProductIds
     * @return array<int>
     */
    public function filterMappedIds(array $basalamProductIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $basalamProductIds))));
        if (!$ids) return [];

        $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
        $values = array_merge(
            [$wpdb->esc_like(ProductMetaKey::PRODUCT_ID) . '%'],
            array_map('strval', $ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core postmeta lookup; placeholder list built locally, values prepared.
        $mapped = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s AND meta_value IN ($placeholders)",
            $values
        ));

        return array_map('intval', (array) $mapped);
    }

    public function isMapped(int $basalamProductId): bool
    {
        return in_array($basalamProductId, $this->filterMappedIds([$basalamProductId]), true);
    }

    /** @return array{total:int, open:int, archiving:int, archived:int, connected:int, ignored:int, failed:int, unresolved:int} */
    public function counts(string $vendorId): array
    {
        global $wpdb;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT state, COUNT(*) AS total FROM {$table} WHERE vendor_id = %s GROUP BY state",
            $vendorId
        ));

        $counts = [
            'total' => 0,
            self::STATE_OPEN => 0,
            self::STATE_ARCHIVING => 0,
            self::STATE_ARCHIVED => 0,
            self::STATE_CONNECTED => 0,
            self::STATE_IGNORED => 0,
            self::STATE_FAILED => 0,
        ];

        foreach ((array) $rows as $row) {
            if (!isset($counts[$row->state])) continue;
            $counts[$row->state] = (int) $row->total;
            $counts['total'] += (int) $row->total;
        }

        $counts['unresolved'] = $counts[self::STATE_OPEN] + $counts[self::STATE_ARCHIVING] + $counts[self::STATE_FAILED];

        return $counts;
    }

    public function unresolvedCount(string $vendorId): int
    {
        return $this->counts($vendorId)['unresolved'];
    }

    /** @return array<array{id:int, title:string}> پنج آرشیو موفق آخر همین گزارش و غرفه. */
    public function latestArchived(string $vendorId): array
    {
        global $wpdb;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; values prepared and result bounded.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT basalam_product_id, title FROM {$table}
             WHERE vendor_id = %s AND state = %s
             ORDER BY resolved_at DESC, id DESC LIMIT 5",
            $vendorId,
            self::STATE_ARCHIVED
        ));

        return array_map(static function ($row): array {
            return ['id' => (int) $row->basalam_product_id, 'title' => (string) $row->title];
        }, (array) $rows);
    }

    /** @return array<object> آرشیوشده‌ها در گزارش می‌مانند، اما از فهرست قابل نمایش حذف می‌شوند. */
    public function paginate(string $vendorId, int $page, int $perPage): array
    {
        global $wpdb;

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE vendor_id = %s AND state <> %s
             ORDER BY FIELD(state, %s, %s, %s) DESC, id ASC
             LIMIT %d OFFSET %d",
            $vendorId,
            self::STATE_ARCHIVED,
            self::STATE_FAILED,
            self::STATE_ARCHIVING,
            self::STATE_OPEN,
            $perPage,
            $offset
        ));
    }

    public function find(int $id): ?object
    {
        global $wpdb;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        return $row ?: null;
    }

    /**
     * انتقال اتمیک وضعیت؛ اقدام همزمان کاربر و صف آرشیو روی یک ردیف دو بار اعمال نمی‌شود.
     *
     * @param array<string> $fromStates
     */
    public function transition(int $id, array $fromStates, string $toState, ?string $errorMessage = null): bool
    {
        global $wpdb;

        if (!$fromStates) return false;

        $terminal = in_array($toState, [self::STATE_ARCHIVED, self::STATE_CONNECTED, self::STATE_IGNORED], true);
        $placeholders = implode(', ', array_fill(0, count($fromStates), '%s'));
        $table = self::tableName();

        $values = array_merge(
            [$toState, $errorMessage ?? '', $terminal ? time() : 0, $id],
            $fromStates
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET state = %s, error_message = %s, resolved_at = %d
             WHERE id = %d AND state IN ($placeholders)",
            $values
        ));

        return $updated !== false && $updated > 0;
    }

    /** @return array<object> ردیف‌هایی که برای آرشیو گروهی قفل (claim) شدند. */
    public function claimOpenForArchive(string $vendorId, int $limit): array
    {
        global $wpdb;

        $limit = max(1, min(20, $limit));
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE vendor_id = %s AND state = %s ORDER BY id ASC LIMIT %d",
            $vendorId,
            self::STATE_OPEN,
            $limit
        ));

        $claimed = [];
        foreach ($rows as $row) {
            if ($this->transition((int) $row->id, [self::STATE_OPEN], self::STATE_ARCHIVING)) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function hasOpen(string $vendorId): bool
    {
        return $this->counts($vendorId)[self::STATE_OPEN] > 0;
    }

    /**
     * اگر job آرشیو گروهی برای همیشه شکست بخورد، ردیف‌های claim‌شده در وضعیت
     * archiving می‌مانند و از هیچ مسیری قابل تعیین‌تکلیف نیستند؛ وقتی job فعالی
     * وجود ندارد باید به open برگردند.
     */
    public function releaseStaleArchiving(string $vendorId): int
    {
        global $wpdb;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = %s WHERE vendor_id = %s AND state = %s",
            self::STATE_OPEN,
            $vendorId,
            self::STATE_ARCHIVING
        ));

        return $updated === false ? 0 : (int) $updated;
    }

    /**
     * ردیف‌های بازی که بیرون از این گزارش متصل شده‌اند (مثلاً از صفحهٔ ویرایش محصول)
     * خودکار بسته می‌شوند تا قفل بی‌دلیل نماند و آرشیوِ محصولِ حالا-متصل پیشنهاد نشود.
     *
     * @return int تعداد ردیف‌های بسته‌شده
     */
    public function resolveRowsThatBecameMapped(string $vendorId): int
    {
        global $wpdb;

        $table = self::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
        $openIds = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT basalam_product_id FROM {$table}
             WHERE vendor_id = %s AND state IN (%s, %s)
             ORDER BY id ASC LIMIT 2000",
            $vendorId,
            self::STATE_OPEN,
            self::STATE_FAILED
        ));

        if (!$openIds) return 0;

        $resolved = 0;
        foreach (array_chunk(array_map('intval', $openIds), 100) as $chunk) {
            $mapped = $this->filterMappedIds($chunk);
            if (!$mapped) continue;

            $placeholders = implode(', ', array_fill(0, count($mapped), '%d'));
            $values = array_merge(
                [self::STATE_CONNECTED, time(), $vendorId, self::STATE_OPEN, self::STATE_FAILED],
                $mapped
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, values prepared.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET state = %s, error_message = '', resolved_at = %d
                 WHERE vendor_id = %s AND state IN (%s, %s) AND basalam_product_id IN ($placeholders)",
                $values
            ));

            if ($updated !== false) $resolved += (int) $updated;
        }

        return $resolved;
    }
}
