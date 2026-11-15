<?php

namespace SyncBasalam\Admin\Components;

use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedScanState;

defined('ABSPATH') || exit;

class ProductListComponents
{
    public static function renderSyncProductStatusSynced()
    {
        echo '<span class="dashicons dashicons-yes-alt basalam-status-success" title="محصول با باسلام سینک شده است."></span>';
    }

    public static function renderSyncProductStatusPending()
    {
        echo '<span class="dashicons dashicons-update basalam-status-warning" title="در حال سینک با باسلام"></span>';
    }

    public static function renderSyncProductStatusUnsync()
    {
        echo '<span class="dashicons dashicons-no-alt basalam-status-error" title="محصول در باسلام ثبت نشده است یا فرایند سینک موفق نبود"></span>';
    }

    public static function renderUnsyncBasalamProductsTable($unsync_products)
    {
        echo "<div class='basalam-flex-center-vertical basalam-flex-col'>";
        if (empty($unsync_products)) return null;

        echo "<h3 class='basalam-margin-bottom-15 basalam-font-bold'>📦 محصولات سینک‌نشده باسلام:</h3>";
        echo "<table class='basalam-p basalam-table-unsync'>";

        echo "<thead class='basalam-table-header'>
                <tr>
                    <th class='basalam-table-padding'>#</th>
                    <th class='basalam-table-padding'>تصویر</th>
                    <th class='basalam-table-padding'>عنوان</th>
                    <th class='basalam-table-padding'>قیمت (تومان)</th>
                    <th class='basalam-table-padding'>آیدی باسلام</th>
                    <th class='basalam-table-padding basalam-table-cell-center' style='width: 14%;'>محصول در باسلام</th>
                </tr>
              </thead>";

        echo "<tbody>";

        foreach ($unsync_products as $index => $product) {
            echo "<tr class='basalam-table-row'>
            <td class='basalam-table-padding'>" . esc_html($index + 1) . "</td>
            <td class='basalam-table-padding'><img src='" . esc_url($product['photo']['md']) . "' alt='Product Image' class='basalam-product-img-table'></td>
            <td class='basalam-table-padding'>" . esc_html($product['title']) . "</td>
            <td class='basalam-table-padding'>" . esc_html(number_format($product['price'])) . "</td>
            <td class='basalam-table-padding'>" . esc_html($product['id']) . "</td>
            <td class='basalam-table-padding basalam-table-cell-center'>
            <button class='basalam-button basalam-p basalam-button-table basalam-height-35 basalam-margin-auto'>
                <a class='basalam-a' href='https://basalam.com/p/" . esc_attr($product['id']) . "' target='_blank'>مشاهده محصول</a>
            </button>

            </td>
          </tr>";
        }

        echo "</tbody></table>";
        echo "</div>";
    }

    /** کارت وضعیت بررسی محصولات متصل‌نشده: وضعیت زنده، کاشی‌های آمار و اقدام‌ها. */
    public static function renderUnmappedScanSummary(array $summary): void
    {
        $status = (string) $summary['status'];
        $counts = $summary['counts'];
        $isArchiving = !empty($summary['archive_active']);
        $archiveStopping = $isArchiving && !empty($summary['archive_stopping']);
        $canStart = $status === UnmappedScanState::STATUS_IDLE && !$isArchiving;
        $isScanning = $status === UnmappedScanState::STATUS_SCANNING;
        $archiveProgress = $summary['archive_progress'] ?? ['archived' => 0, 'total' => 0, 'percent' => 0, 'failed' => 0, 'recent' => []];

        $statusLabel = 'آماده بررسی';
        $statusClass = 'is-idle';
        if ($isArchiving) {
            $statusLabel = $archiveStopping ? 'در حال توقف آرشیو' : 'در حال آرشیو';
            $statusClass = 'is-scanning';
        } elseif ($isScanning) {
            $statusLabel = 'در حال بررسی';
            $statusClass = 'is-scanning';
        } elseif ($status === UnmappedScanState::STATUS_AWAITING) {
            $statusLabel = 'در انتظار تعیین‌تکلیف';
            $statusClass = 'is-awaiting';
        }
        ?>
        <div class="basalam-status-card basalam-unmapped-card" id="basalam-unmapped-summary"
            data-status="<?php echo esc_attr($status); ?>"
            data-archive-active="<?php echo $isArchiving ? '1' : '0'; ?>"
            data-unresolved="<?php echo esc_attr((int) $counts['unresolved']); ?>">

            <div class="basalam-unmapped-head">
                <img class="basalam-unmapped-logo" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/images/basalam.svg'); ?>" alt="باسلام" width="48" height="60">
                <div class="basalam-unmapped-head-text">
                    <h1 class="basalam-h">محصولات متصل‌نشدهٔ باسلام</h1>
                    <p class="basalam-p basalam-unmapped-sub">
                        محصولات فعال غرفه که به ووکامرس متصل نیستند را بررسی و مدیریت کنید.
                    </p>
                </div>
            </div>

            <div class="basalam-unmapped-context">
                <p class="basalam-p basalam-unmapped-sub">
                    هر محصول را متصل کنید، آرشیو کنید یا نادیده بگیرید.
                    تا تعیین‌تکلیف همهٔ موارد، بررسی مجدد (دستی یا خودکار روزانه) اجرا نمی‌شود.
                </p>
                <span class="basalam-unmapped-status <?php echo esc_attr($statusClass); ?>" id="basalam-unmapped-status-badge">
                    <span class="basalam-unmapped-status-dot" aria-hidden="true"></span>
                    <?php echo esc_html($statusLabel); ?>
                </span>
            </div>

            <dl class="basalam-unmapped-tiles" aria-label="آمار گزارش محصولات">
                <?php
                self::renderUnmappedTile('در انتظار اقدام', (int) $counts['unresolved'], 'is-pending', 'basalam-unmapped-count-unresolved', 'clock');
                self::renderUnmappedTile('متصل‌شده', (int) $counts['connected'], 'is-connected', 'basalam-unmapped-count-connected', 'yes-alt');
                self::renderUnmappedTile('آرشیوشده', (int) $counts['archived'], 'is-archived', 'basalam-unmapped-count-archived', 'archive');
                self::renderUnmappedTile('نادیده گرفته‌شده', (int) $counts['ignored'], 'is-ignored', 'basalam-unmapped-count-ignored', 'hidden');
                ?>
            </dl>

            <div class="basalam-unmapped-progress<?php echo $isScanning ? '' : ' basalam-hidden'; ?>" id="basalam-unmapped-progress">
                <div class="basalam-unmapped-progress-row">
                    <span class="basalam-unmapped-spinner" aria-hidden="true"></span>
                    <p class="basalam-p basalam-unmapped-progress-text">
                        در حال پیمایش محصولات غرفه...
                        تا این لحظه
                        <strong id="basalam-unmapped-scanned"><?php echo esc_html(number_format_i18n((int) ($summary['scanned'] ?? 0))); ?></strong>
                        محصول بررسی و
                        <strong id="basalam-unmapped-found-live"><?php echo esc_html(number_format_i18n((int) $summary['total_found'])); ?></strong>
                        مورد متصل‌نشده پیدا شده است.
                    </p>
                </div>
                <div class="basalam-unmapped-progressbar" role="progressbar" aria-label="پیشرفت بررسی محصولات"><span></span></div>
                <p class="basalam-p basalam-unmapped-hint">
                    می‌توانید این صفحه را ببندید؛ پایان بررسی با اعلان پیشخوان اطلاع داده می‌شود.
                </p>
            </div>

            <div class="basalam-unmapped-progress is-archiving<?php echo $isArchiving ? '' : ' basalam-hidden'; ?>" id="basalam-unmapped-archive-progress">
                <div class="basalam-unmapped-progress-row">
                    <span class="basalam-unmapped-spinner" aria-hidden="true"></span>
                    <p class="basalam-p basalam-unmapped-progress-text" role="status" aria-live="polite" aria-atomic="true">
                        <strong id="basalam-unmapped-archive-completed"><?php echo esc_html(number_format_i18n((int) $archiveProgress['archived'])); ?></strong>
                        محصول از
                        <strong id="basalam-unmapped-archive-total"><?php echo esc_html(number_format_i18n((int) $archiveProgress['total'])); ?></strong>
                        محصول آرشیو شد
                    </p>
                    <button type="button" id="basalam-unmapped-archive-stop" class="basalam-unmapped-stop-button"
                        title="<?php echo $archiveStopping ? 'در حال توقف آرشیو' : 'توقف آرشیو'; ?>"
                        aria-label="<?php echo $archiveStopping ? 'در حال توقف آرشیو' : 'توقف آرشیو'; ?>"
                        <?php disabled($archiveStopping); ?>>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="5" y="5" width="14" height="14" rx="2" /></svg>
                    </button>
                </div>
                <div class="basalam-unmapped-progressbar" id="basalam-unmapped-archive-progressbar" role="progressbar"
                    aria-label="پیشرفت آرشیو محصولات غرفه" aria-valuemin="0" aria-valuemax="100"
                    aria-valuenow="<?php echo esc_attr((int) $archiveProgress['percent']); ?>">
                    <span style="width: <?php echo esc_attr((int) $archiveProgress['percent']); ?>%"></span>
                </div>
                <p id="basalam-unmapped-archive-hint" class="basalam-p basalam-unmapped-hint"><?php echo $archiveStopping ? 'درخواست توقف ثبت شد؛ پس از پایان درخواست جاری، آرشیو متوقف می‌شود.' : 'آرشیو در پس‌زمینه ادامه دارد؛ می‌توانید این صفحه را ببندید.'; ?></p>
                <p id="basalam-unmapped-archive-failures" class="basalam-p basalam-unmapped-hint<?php echo (int) $archiveProgress['failed'] > 0 ? '' : ' basalam-hidden'; ?>">
                    <strong id="basalam-unmapped-archive-failed-count"><?php echo esc_html(number_format_i18n((int) $archiveProgress['failed'])); ?></strong>
                    محصول با خطا روبه‌رو شده و آرشیو نشده است؛ جزئیات در فهرست محصولات قابل مشاهده است.
                </p>
                <div class="basalam-unmapped-archive-recent">
                    <h2 class="basalam-h">آخرین محصولات آرشیوشده</h2>
                    <ol id="basalam-unmapped-archive-recent-list">
                        <?php foreach ($archiveProgress['recent'] as $archivedProduct): ?>
                            <li data-product-id="<?php echo esc_attr((int) $archivedProduct['id']); ?>"><?php echo esc_html($archivedProduct['title'] !== '' ? $archivedProduct['title'] : 'محصول باسلام ' . $archivedProduct['id']); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p id="basalam-unmapped-archive-recent-empty" class="basalam-p basalam-unmapped-hint<?php echo $archiveProgress['recent'] ? ' basalam-hidden' : ''; ?>">هنوز محصولی در این گزارش آرشیو نشده است.</p>
                </div>
                <p id="basalam-unmapped-archive-update-error" class="basalam-p basalam-unmapped-hint basalam-hidden" role="status">دریافت پیشرفت تازه ناموفق بود؛ اطلاعات نمایش‌داده‌شده مربوط به آخرین دریافت موفق است. دوباره تلاش می‌کنیم.</p>
            </div>

            <div class="basalam-unmapped-foot">
                <div class="basalam-unmapped-actions-row">
                    <button type="button" id="basalam-unmapped-start"
                        class="basalam-primary-button basalam-p<?php echo $canStart ? '' : ' basalam-button-disabled-bg'; ?><?php echo $isArchiving ? ' basalam-hidden' : ''; ?>"
                        <?php disabled(!$canStart); ?>
                        title="<?php echo $canStart ? '' : esc_attr(self::unmappedBlockReason($status)); ?>">
                        بررسی محصولات غرفه
                    </button>

                    <button type="button" id="basalam-unmapped-archive-all"
                        class="basalam-secondary-button basalam-p<?php echo ((int) $counts['open'] > 0 && !$summary['archive_active']) ? '' : ' basalam-hidden'; ?>">
                        آرشیو همه محصولات متصل‌نشده
                    </button>

                </div>

                <p class="basalam-p basalam-unmapped-lastscan" id="basalam-unmapped-last-scan">
                    <?php if ($isScanning): ?>
                        شروع بررسی: <?php echo esc_html(wp_date('Y/m/d - H:i', (int) $summary['started_at'])); ?>
                    <?php elseif ((int) $summary['finished_at'] > 0): ?>
                        آخرین بررسی: <?php echo esc_html(wp_date('Y/m/d - H:i', (int) $summary['finished_at'])); ?>
                    <?php else: ?>
                        هنوز بررسی‌ای انجام نشده است؛ بررسی خودکار روزی یک‌بار اجرا می‌شود.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
    }

    private static function renderUnmappedTile(string $label, int $value, string $modifier, string $valueId, string $icon): void
    {
        ?>
        <div class="basalam-unmapped-tile <?php echo esc_attr($modifier); ?>">
            <dt class="basalam-unmapped-tile-heading">
                <span class="basalam-unmapped-tile-label"><?php echo esc_html($label); ?></span>
                <span class="basalam-unmapped-tile-icon" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
            </dt>
            <dd class="basalam-unmapped-tile-metric">
                <span class="basalam-unmapped-tile-value" id="<?php echo esc_attr($valueId); ?>"><?php echo esc_html(number_format_i18n($value)); ?></span>
                <span class="basalam-unmapped-tile-unit">محصول</span>
            </dd>
        </div>
        <?php
    }

    /**
     * @param array<object> $rows
     */
    public static function renderUnmappedProductsTable(array $rows, int $offset = 0, int $page = 1, int $pages = 0): void
    {
        echo "<section class='basalam-status-card basalam-unmapped-list' aria-labelledby='basalam-unmapped-list-title' data-page='" . esc_attr($page) . "' data-pages='" . esc_attr($pages) . "'>";
        echo "<div class='basalam-status-header'><h2 class='basalam-h' id='basalam-unmapped-list-title'>فهرست محصولات</h2>"
            . "<p class='basalam-p basalam-unmapped-list-hint'>برای هر محصول، اقدام مناسب را انتخاب کنید.</p></div>";
        echo "<table class='basalam-p basalam-table-unsync" . ($rows ? '' : ' basalam-hidden') . "' id='basalam-unmapped-table'>";
        echo "<caption class='screen-reader-text'>محصولات باسلام متصل‌نشده به ووکامرس و اقدام‌های هر محصول</caption>";

        echo "<thead class='basalam-table-header'>
                <tr>
                    <th scope='col' class='basalam-unmapped-number'>#</th>
                    <th scope='col' class='basalam-unmapped-product'>محصول</th>
                    <th scope='col' class='basalam-unmapped-price'>قیمت (تومان)</th>
                    <th scope='col' class='basalam-unmapped-state-cell'>وضعیت</th>
                    <th scope='col' class='basalam-unmapped-operations'>عملیات</th>
                </tr>
              </thead>";

        echo '<tbody>';

        self::renderUnmappedProductRows($rows, $offset);

        echo '</tbody></table>';
        echo '<p id="basalam-unmapped-list-empty" class="basalam-p basalam-unmapped-list-empty' . ($rows ? ' basalam-hidden' : '') . '" role="status">محصولی برای نمایش باقی نمانده است؛ محصولات آرشیوشده از فهرست حذف شده‌اند.</p>';
        echo '</section>';
    }

    public static function renderUnmappedProductRows(array $rows, int $offset = 0): void
    {
        foreach ($rows as $index => $row) {
            self::renderUnmappedProductRow($row, $offset + $index + 1);
        }
    }

    public static function renderUnmappedPagination(int $page, int $pages): void
    {
        if ($pages <= 1) return;

        echo '<div class="basalam-pagination basalam-pagination-flex">';
        if ($page > 1) {
            $previous = add_query_arg(['page' => 'basalam-show-products', 'unmapped_page' => $page - 1], admin_url('admin.php'));
            echo '<a href="' . esc_url($previous) . '">قبلی</a>';
        }
        echo '<span class="basalam-p">صفحه ' . esc_html(number_format_i18n($page))
            . ' از ' . esc_html(number_format_i18n($pages)) . '</span>';
        if ($page < $pages) {
            $next = add_query_arg(['page' => 'basalam-show-products', 'unmapped_page' => $page + 1], admin_url('admin.php'));
            echo '<a href="' . esc_url($next) . '">بعدی</a>';
        }
        echo '</div>';
    }

    private static function renderUnmappedProductRow(object $row, int $number): void
    {
        $state = (string) $row->state;
        // ردیف «در صف آرشیو» دست صف است؛ تا تعیین‌تکلیف نباید دکمهٔ اقدام بگیرد.
        $isUnresolved = in_array($state, [UnmappedProductsRepository::STATE_OPEN, UnmappedProductsRepository::STATE_FAILED], true);
        $badge = self::unmappedStateBadge($state);
        $productUrl = 'https://basalam.com/p/' . rawurlencode((string) $row->basalam_product_id);
        ?>
        <tr class="basalam-table-row" data-row-id="<?php echo esc_attr((int) $row->id); ?>" data-state="<?php echo esc_attr($state); ?>">
            <td class="basalam-unmapped-number"><?php echo esc_html(number_format_i18n($number)); ?></td>
            <td class="basalam-unmapped-product">
                <div class="basalam-unmapped-product-info">
                    <?php if (!empty($row->photo)): ?>
                        <img src="<?php echo esc_url($row->photo); ?>" alt="" class="basalam-product-img-table" width="56" height="56" loading="lazy">
                    <?php else: ?>
                        <span class="basalam-unmapped-product-placeholder" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
                    <?php endif; ?>
                    <div>
                        <span class="basalam-unmapped-product-title"><?php echo esc_html((string) $row->title); ?></span>
                        <span class="basalam-unmapped-product-id">شناسهٔ باسلام: <bdi><?php echo esc_html((string) $row->basalam_product_id); ?></bdi></span>
                    </div>
                </div>
            </td>
            <td class="basalam-unmapped-price" data-label="قیمت (تومان)"><bdi><?php echo esc_html(number_format_i18n((int) $row->price)); ?></bdi></td>
            <td class="basalam-unmapped-state-cell">
                <span class="basalam-badge <?php echo esc_attr($badge['class']); ?> basalam-p"
                    <?php if ($state === UnmappedProductsRepository::STATE_FAILED && !empty($row->error_message)): ?>
                        title="<?php echo esc_attr((string) $row->error_message); ?>"
                    <?php endif; ?>>
                    <?php echo esc_html($badge['label']); ?>
                </span>
            </td>
            <td class="basalam-unmapped-operations">
                <div class="basalam-flex basalam-gap-10 basalam-unmapped-actions">
                    <a class="basalam-secondary-button basalam-p basalam-padding-small" href="<?php echo esc_url($productUrl); ?>" target="_blank" rel="noopener noreferrer">
                        مشاهده در باسلام
                    </a>
                    <?php if ($isUnresolved): ?>
                        <button type="button" class="basalam-secondary-button basalam-p basalam-padding-small basalam-unmapped-connect"
                            data-row-id="<?php echo esc_attr((int) $row->id); ?>"
                            data-title="<?php echo esc_attr((string) $row->title); ?>">
                            اتصال به محصول ووکامرس
                        </button>
                        <button type="button" class="basalam-secondary-button basalam-p basalam-padding-small basalam-unmapped-archive"
                            data-row-id="<?php echo esc_attr((int) $row->id); ?>">
                            آرشیو
                        </button>
                        <button type="button" class="basalam-secondary-button basalam-p basalam-padding-small basalam-unmapped-ignore"
                            data-row-id="<?php echo esc_attr((int) $row->id); ?>">
                            نادیده گرفتن
                        </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    /** @return array{label: string, class: string} */
    public static function unmappedStateBadge(string $state): array
    {
        switch ($state) {
            case UnmappedProductsRepository::STATE_ARCHIVED:
                return ['label' => 'آرشیو شد', 'class' => 'basalam-badge-success'];
            case UnmappedProductsRepository::STATE_CONNECTED:
                return ['label' => 'متصل شد', 'class' => 'basalam-badge-success'];
            case UnmappedProductsRepository::STATE_IGNORED:
                return ['label' => 'نادیده گرفته شد', 'class' => 'basalam-badge-success'];
            case UnmappedProductsRepository::STATE_ARCHIVING:
                return ['label' => 'در صف آرشیو', 'class' => 'basalam-badge-warning'];
            case UnmappedProductsRepository::STATE_FAILED:
                return ['label' => 'خطا در آرشیو', 'class' => 'basalam-badge-danger'];
            default:
                return ['label' => 'در انتظار اقدام', 'class' => 'basalam-badge-warning'];
        }
    }

    private static function unmappedBlockReason(string $status): string
    {
        if ($status === UnmappedScanState::STATUS_SCANNING) {
            return 'بررسی در حال اجراست.';
        }
        if ($status === UnmappedScanState::STATUS_AWAITING) {
            return 'تا تعیین‌تکلیف همهٔ محصولات این گزارش، بررسی مجدد ممکن نیست.';
        }

        return '';
    }
}
