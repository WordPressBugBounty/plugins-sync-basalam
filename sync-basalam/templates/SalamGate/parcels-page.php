<?php

if (! defined('ABSPATH')) exit;

use SyncBasalam\SalamGate\Admin\AccountStateRouter;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;

$data = AccountStateRouter::resolveForPage();
$available = $data['state'] === AccountStateRouter::STATE_ACTIVE;
$sgView = 'parcels';

$queryText = static function (string $key, string $default = ''): string {
    return isset($_GET[$key]) && is_scalar($_GET[$key])
        ? sanitize_text_field(wp_unslash((string) $_GET[$key]))
        : $default;
};
$rawMarkets = wp_unslash($_GET['sg_market'] ?? $_GET['sg_markets'] ?? $_GET['market_names'] ?? []);
$rawMarkets = is_array($rawMarkets) ? $rawMarkets : explode(',', (string) $rawMarkets);
$selectedMarkets = array_values(array_unique(array_intersect(
    array_map('sanitize_key', array_filter($rawMarkets, 'is_scalar')),
    ParcelsListService::ALL_MARKETS
)));
$requestedTab = $queryText('sg_tab', 'all');
$filters = [
    'tab' => in_array($requestedTab, ['all', 'pending', 'new', 'barcode'], true) ? $requestedTab : 'all',
    'market_name' => $selectedMarkets,
    'status' => $queryText('sg_status'),
    'sort' => $queryText('sg_sort') === 'asc' ? 'asc' : 'desc',
    'cursor' => $queryText('sg_cursor'),
    'search' => mb_substr($queryText('sg_search'), 0, 120),
];

// تب‌ها وضعیت مرسوله را فیلتر می‌کنند؛ ثبت مرسوله اقدام جداگانه است.
if ($filters['tab'] === 'pending') {
    $filters['status'] = '7';
} elseif ($filters['tab'] === 'new') {
    $filters['status'] = '8';
} elseif ($filters['tab'] === 'barcode') {
    $filters['status'] = '9';
}
$statusId = (int) Digits::toEnglish($filters['status']);
$filters['status'] = ParcelStatuses::isValid($statusId) ? (string) $statusId : '';
$statusLabels = ParcelStatuses::TITLES;
$statusLabels[ParcelStatuses::NEW] = 'در انتظار تکمیل ثبت و پرداخت';
$hasActiveFilters = $filters['market_name'] !== [] || $filters['status'] !== '';
$marketControlLabel = $filters['market_name'] !== []
    ? implode('، ', array_map(static fn (string $market): string => ParcelsListService::MARKET_LABELS[$market] ?? $market, $filters['market_name']))
    : 'همه بازارها';
$sortControlLabel = $filters['sort'] === 'asc' ? 'قدیمی‌ترین' : 'جدیدترین';
$sendUrl = admin_url('admin.php?page=salam_gate&view=send');

// Render the usable navigation immediately; the list is one asynchronous read.

$labelTemplates = [
    'invoice' => 'فاکتور کامل',
    'invoice_new' => 'فاکتور کامل (طراحی جدید)',
    'summary' => 'برچسب پستی خلاصه',
    'label_10x15' => 'برچسب پستی ۱۰×۱۵',
];
$defaultTemplate = (string) SalamGateSettings::instance()->get(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT);

$markets = ParcelsListService::ALL_MARKETS;
$tabUrl = static function (string $tab) use ($filters): string {
    return add_query_arg([
        'page' => 'salam_gate_parcels',
        'sg_tab' => $tab,
        'sg_market' => $filters['market_name'] ?: null,
        'sg_sort' => $filters['sort'],
        'sg_status' => $tab === 'all' && $filters['tab'] === 'all' ? ($filters['status'] ?: null) : null,
        'sg_search' => $filters['search'] ?: null,
    ], admin_url('admin.php'));
};

?>
<!-- Keep admin notices outside the SalamGate styling boundary on the parcels screen too. -->
<hr class="wp-header-end">
<div class="wrap salam-gate-page sg-router" data-sg-account-state="<?php echo esc_attr($data['state']); ?>" <?php echo $data['state'] === 'loading' ? 'data-sg-account-deferred="true"' : ''; ?>>
    <?php include __DIR__ . '/page-header.php'; ?>
    <div id="sg-state-container" class="salam-gate-parcels-page sg-router__body<?php echo $available ? ' sg-load-region' : ''; ?>"<?php if ($available) : ?> data-sg-load data-sg-load-state="loading" aria-busy="true"<?php endif; ?>>
    <?php if ($available) : ?>
        <?php include __DIR__ . '/partials/loading-region.php'; ?>
        <div data-sg-load-content inert>
    <?php endif; ?>
    <?php if ($data['state'] === 'loading') : ?>
        <?php echo AccountStateRouter::renderStatePartial($data); ?>
    <?php elseif (!$available) : ?>
        <div class="basalam-section salam-gate-section">
            <p class="basalam-p">برای مشاهده مرسوله‌ها ابتدا اتصال باسلام و حساب سلام‌گیت را از <a href="<?php echo esc_url(admin_url('admin.php?page=salam_gate')); ?>">صفحه سلام‌گیت</a> فعال کنید.</p>
        </div>
    <?php else : ?>

        <section class="sg-card sgp-section" aria-labelledby="sgp-list-title">
        <div class="sgp-head sgp-toolbar">
            <h2 class="sgp-title" id="sgp-list-title">فهرست مرسوله‌ها</h2>
            <div class="sgp-toolbar__actions">
                <div class="sgp-bulk-print" data-sgp-print-menu>
                    <button type="button" class="sgp-print-btn sg-button sg-button--secondary" data-sgp-print-toggle aria-expanded="false" aria-controls="sgp-print-options">
                        <span class="dashicons dashicons-printer" aria-hidden="true"></span><span>چاپ گروهی</span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                    </button>
                    <div id="sgp-print-options" class="sgp-print-options" data-sgp-print-options role="group" aria-label="گزینه‌های چاپ گروهی" hidden>
                        <button type="button" data-sgp-print-modal data-sgp-print-kind="label" aria-haspopup="dialog"><span class="dashicons dashicons-printer" aria-hidden="true"></span><span>چاپ گروهی برچسب‌های پستی</span></button>
                        <button type="button" data-sgp-print-modal data-sgp-print-kind="barcode" aria-haspopup="dialog"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V4h4m8 0h4v4M4 16v4h4m8 0h4v-4M8 8v8m3-8v6m3-6v8m3-8v6"/></svg><span>چاپ گروهی بارکدها</span></button>
                    </div>
                </div>
                <button type="button" class="sg-button sg-button--secondary" data-sgp-retry>به‌روزرسانی فهرست</button>
                <div class="sgp-toolbar__filters" role="group" aria-label="فیلتر و ترتیب فهرست مرسوله‌ها">
                    <button type="button" class="sgp-tool sgp-tool--market <?php echo $filters['market_name'] !== [] ? 'is-active' : ''; ?>" data-sgp-filter-modal aria-haspopup="dialog" aria-label="<?php echo esc_attr('فیلتر بازار: ' . $marketControlLabel); ?>" title="<?php echo esc_attr('فیلتر بازار: ' . $marketControlLabel); ?>" data-sgp-tooltip="<?php echo esc_attr('فیلتر بازار: ' . $marketControlLabel); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8Z"/></svg>
                        <span class="screen-reader-text" id="sgp-market-value" data-sgp-filter-label><?php echo esc_html($marketControlLabel); ?></span>
                    </button>
                    <div class="sgp-tool sgp-tool--sort <?php echo $filters['sort'] === 'asc' ? 'is-active' : ''; ?>" data-sgp-sort-control data-sort="<?php echo esc_attr($filters['sort']); ?>" title="<?php echo esc_attr('ترتیب نمایش: ' . $sortControlLabel); ?>" data-sgp-tooltip="<?php echo esc_attr('ترتیب نمایش: ' . $sortControlLabel); ?>">
                        <label class="screen-reader-text" for="sgp-sort">ترتیب نمایش مرسوله‌ها</label>
                        <select id="sgp-sort" class="sgp-sort" data-sgp-sort aria-label="ترتیب نمایش مرسوله‌ها">
                            <option value="desc" <?php selected($filters['sort'], 'desc'); ?>>جدیدترین</option>
                            <option value="asc" <?php selected($filters['sort'], 'asc'); ?>>قدیمی‌ترین</option>
                        </select>
                        <svg class="sgp-tool__sort-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h11M4 12h8M4 18h5m10-13v14m-3-3 3 3 3-3"/></svg>
                    </div>
                </div>
            </div>
        </div>

        <form class="sgp-search" data-sgp-search-form role="search" aria-label="جستجو در مرسوله‌ها">
            <label class="screen-reader-text" for="sgp-search">نام گیرنده، شماره سفارش یا محتویات</label>
            <input id="sgp-search" class="sg-field__input" type="search" dir="rtl" data-sgp-search maxlength="120" value="<?php echo esc_attr($filters['search']); ?>" placeholder="نام گیرنده، شماره سفارش یا محتویات…" autocomplete="off">
            <button type="submit" class="sg-button sg-button--secondary">جستجو</button>
            <button type="button" class="sgp-search__clear" data-sgp-search-clear aria-label="پاک کردن جستجو" <?php echo $filters['search'] === '' ? 'hidden' : ''; ?>>×</button>
        </form>
        <p class="sgp-search-status" data-sgp-search-status role="status" aria-live="polite" hidden></p>
        <p class="screen-reader-text" data-sgp-copy-status role="status" aria-live="polite"></p>
        <div class="sgp-label-status salam-gate-label-status" style="display:none;" aria-live="polite" role="status"></div>

        <!-- تب‌ها -->
        <div class="sgp-tabs">
            <a class="sgp-tab <?php echo $filters['tab'] === 'all' ? 'is-active' : ''; ?>" data-sgp-tab="all" href="<?php echo esc_url($tabUrl('all')); ?>">همه</a>
            <a class="sgp-tab <?php echo $filters['tab'] === 'pending' ? 'is-active' : ''; ?>" data-sgp-tab="pending" href="<?php echo esc_url($tabUrl('pending')); ?>">تکمیل ثبت و پرداخت</a>
            <a class="sgp-tab <?php echo $filters['tab'] === 'new' ? 'is-active' : ''; ?>" data-sgp-tab="new" href="<?php echo esc_url($tabUrl('new')); ?>">آماده تحویل به پست</a>
            <a class="sgp-tab <?php echo $filters['tab'] === 'barcode' ? 'is-active' : ''; ?>" data-sgp-tab="barcode" href="<?php echo esc_url($tabUrl('barcode')); ?>">ارسال‌شده</a>
        </div>

        <div class="sgp-filter-chips" data-sgp-filter-chips aria-label="فیلترهای فعال" <?php echo $hasActiveFilters ? '' : 'hidden'; ?>>
            <?php foreach ($filters['market_name'] as $market) : ?>
                <?php $marketLabel = ParcelsListService::MARKET_LABELS[$market] ?? $market; ?>
                <button type="button" class="sgp-filter-chip" data-sgp-remove-market="<?php echo esc_attr($market); ?>" aria-label="<?php echo esc_attr('حذف فیلتر بازار ' . $marketLabel); ?>"><span>بازار: <?php echo esc_html($marketLabel); ?></span><span aria-hidden="true">×</span></button>
            <?php endforeach; ?>
            <?php if ($filters['status'] !== '') : ?>
                <button type="button" class="sgp-filter-chip" data-sgp-remove-status aria-label="حذف فیلتر وضعیت"><span>وضعیت: <?php echo esc_html($statusLabels[$statusId]); ?></span><span aria-hidden="true">×</span></button>
            <?php endif; ?>
        </div>

        <!-- جدول -->
        <div class="sgp-table-wrap">
            <table class="sgp-table">
                <thead>
                    <tr>
                        <th>مرسوله / بازار</th>
                        <th>گیرنده</th>
                        <th>محتویات</th>
                        <th>تاریخ</th>
                        <th>وضعیت ارسال و پرداخت</th>
                        <th>روش ارسال</th>
                        <th>کد رهگیری</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="sgp-body" data-sgp-deferred-list>
                    <tr><td colspan="8" class="sgp-empty" role="status">در حال دریافت مرسوله‌ها…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="sgp-pagination">
            <button type="button" class="button sgp-prev" data-cursor="" disabled>قبلی</button>
            <button type="button" class="button sgp-next" data-cursor="" disabled>بعدی</button>
        </div>

        </section>

    <?php endif; ?>
    <?php if ($available) : ?></div><?php endif; ?>
    </div>

<?php if ($available) : ?>

    <!-- مودال فیلتر بازار (رادیویی با تأیید/انصراف) -->
    <div class="sg-modal-overlay" data-sgp-filter-modal style="display:none;">
        <div class="sg-modal" role="dialog" aria-modal="true" aria-labelledby="sgp-filter-title" tabindex="-1">
            <div class="sg-modal__header"><h4 id="sgp-filter-title">فیلتر بازار</h4><button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">&times;</button></div>
            <div class="sg-modal__body">
                <?php foreach ($markets as $marketValue) : ?>
                    <label class="sg-check">
                        <input type="radio" name="sgp-market" value="<?php echo esc_attr($marketValue); ?>" <?php checked($filters['market_name'][0] ?? '', $marketValue); ?>>
                        <span><?php echo esc_html(ParcelsListService::MARKET_LABELS[$marketValue] ?? $marketValue); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="sg-modal__footer">
                <button type="button" class="sg-button sg-button--secondary" data-sgp-filter-clear>حذف فیلتر</button>
                <button type="button" class="sg-button sg-button--primary" data-sgp-filter-apply>تأیید</button>
            </div>
        </div>
    </div>

    <!-- Shared batch-print dialog: labels or barcodes, by date or parcel selection. -->
    <div class="sg-modal-overlay" data-sgp-print-modal-el style="display:none;">
        <div class="sg-modal sgp-print-dialog" role="dialog" aria-modal="true" aria-labelledby="sgp-print-title" tabindex="-1">
            <div class="sg-modal__header"><h4 id="sgp-print-title">چاپ گروهی برچسب‌های پستی</h4><button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">&times;</button></div>
            <div class="sg-modal__body">
                <div class="sgp-print-modes" role="radiogroup" aria-label="روش انتخاب مرسوله‌ها برای چاپ">
                    <label><input type="radio" name="sgp-print-mode" value="date" checked><span class="dashicons dashicons-clock" aria-hidden="true"></span><span>انتخاب بازه زمانی</span></label>
                    <label><input type="radio" name="sgp-print-mode" value="selection"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><span>انتخاب سفارش مورد نظر</span></label>
                </div>
                <div class="sg-wizard__row2" data-sgp-print-date-view>
                    <div class="sg-field">
                        <label class="sg-field__label" for="sgp-from">از تاریخ <span class="sg-req">*</span></label>
                        <input class="sg-field__input" id="sgp-from" data-sgp-from placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr" autocomplete="off">
                    </div>
                    <div class="sg-field">
                        <label class="sg-field__label" for="sgp-to">تا تاریخ <span class="sg-req">*</span></label>
                        <input class="sg-field__input" id="sgp-to" data-sgp-to placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr" autocomplete="off">
                    </div>
                </div>
                <section class="sgp-print-picker" data-sgp-print-selection-view hidden aria-label="انتخاب مرسوله‌های پرداخت‌شده">
                    <div class="sgp-print-picker__heading">
                        <span data-sgp-selected-count role="status" aria-live="polite">۰ مرسوله انتخاب شده</span>
                        <button type="button" class="sg-button sg-button--secondary" data-sgp-clear-selection disabled>لغو انتخاب‌ها</button>
                    </div>
                    <div class="sgp-print-picker__items" data-sgp-label-candidates role="group" aria-label="مرسوله‌های قابل چاپ"></div>
                    <div class="sgp-print-picker__more">
                        <span data-sgp-label-list-state role="status" aria-live="polite"></span>
                        <button type="button" class="sg-button sg-button--secondary" data-sgp-label-list-more hidden>نمایش مرسوله‌های بیشتر</button>
                    </div>
                </section>
                <div class="sg-field" data-sgp-label-options>
                    <span class="sg-field__label">اندازه برچسب</span>
                    <div class="sg-wizard__radios" data-sgp-templates>
                        <?php foreach ($labelTemplates as $tpl => $tplTitle) : ?>
                            <label class="sg-radio"><input type="radio" name="sgp-template" value="<?php echo esc_attr($tpl); ?>" <?php checked($tpl, $defaultTemplate); ?>> <span><?php echo esc_html($tplTitle); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="sg-card__detail">چاپ برچسب یا بارکد فقط برای مرسوله‌هایی با پرداخت تکمیل‌شده و کد رهگیری امکان‌پذیر است.</p>
                <div class="sg-wizard__notice" data-sgp-print-notice style="display:none;" role="alert"></div>
            </div>
            <div class="sg-modal__footer">
                <button type="button" class="sg-button sg-button--secondary" data-close-modal>انصراف</button>
                <button type="button" class="sg-button sg-button--primary" data-sgp-print-submit><span class="dashicons dashicons-printer" aria-hidden="true"></span><span data-sgp-print-submit-label>چاپ برچسب‌های پستی</span></button>
            </div>
        </div>
    </div>

    <div class="sg-modal-overlay" id="sgp-checkout-modal" style="display:none;">
        <div class="sg-modal sgp-checkout" role="dialog" aria-modal="true" aria-labelledby="sgp-checkout-title" tabindex="-1">
            <div class="sg-modal__header"><h4 id="sgp-checkout-title">تکمیل پرداخت مرسوله #<span data-sgpc-id></span></h4><button type="button" class="sg-modal__close" data-sgpc-close aria-label="بستن">&times;</button></div>
            <div class="sg-modal__body sg-load-region" data-sg-load data-sg-load-state="ready">
                <?php include __DIR__ . '/partials/loading-region.php'; ?>
                <div data-sg-load-content>
                <div class="sgp-checkout__feedback" data-sgpc-feedback role="status" aria-live="polite"></div>
                <dl class="sg-wizard__review" data-sgpc-summary></dl>
                <div class="sgp-checkout__choices">
                <fieldset class="sgp-checkout__quotes" data-sgpc-quotes><legend>انتخاب روش ارسال</legend><div data-sgpc-quote-options></div></fieldset>
                <div class="sgp-checkout__payment" data-sgpc-methods>
                    <h4>شیوه پرداخت</h4>
                    <?php
                    $sgPaymentSelectClass = 'sgpc-method';
                    $sgPaymentSelectId = 'sgpc-method';
                    $sgPaymentRadioName = 'sgpc-payment-method';
                    $sgPaymentDisabled = true;
                    $sgPaymentDefault = '20';
                    include __DIR__ . '/partials/payment-methods.php';
                    ?>
                </div>
                </div>
                <p data-sgpc-basalam hidden>هزینه این مرسوله از تراز سفارش باسلام پرداخت می‌شود.</p>
                <p class="sgp-checkout__total" data-sgpc-total></p>
                </div>
            </div>
            <div class="sg-modal__footer">
                <button type="button" class="sg-button sg-button--secondary" data-sgpc-close>بستن</button>
                <button type="button" class="sg-button sg-button--secondary" data-sgpc-recheck>بررسی دوباره وضعیت</button>
                <button type="button" class="sg-button sg-button--primary" data-sgpc-pay disabled>تأیید و پرداخت هزینه ارسال</button>
            </div>
        </div>
    </div>

    <!-- مودال جزئیات -->
    <div class="sg-modal-overlay" id="sgp-detail-modal" style="display:none;">
        <div class="sg-modal" role="dialog" aria-modal="true" aria-labelledby="sgp-detail-title" tabindex="-1">
            <div class="sg-modal__header"><h4 id="sgp-detail-title">جزئیات مرسوله #<span class="sgd-id"></span></h4><button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">&times;</button></div>
            <div class="sg-modal__body sg-load-region" data-sg-load data-sg-load-state="ready">
                <?php include __DIR__ . '/partials/loading-region.php'; ?>
                <div data-sg-load-content>
                <div class="sg-wizard__notice" data-sgd-error style="display:none;" role="alert"></div>
                <div class="sgd-info"></div>
                <details class="sgd-disclosure" data-sgd-items-panel hidden><summary>اقلام مرسوله <span data-sgd-items-count></span></summary><dl class="sgd-items"></dl></details>
                <section class="sgd-cancel-review" data-sgd-cancel-review hidden aria-labelledby="sgd-cancel-title">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <div><h5 id="sgd-cancel-title">مرسوله لغو شود؟</h5>
                    <p>کد رهگیری و برچسب این مرسوله باطل می‌شود و بازگشت هزینه را سلام‌گیت انجام می‌دهد. خود سفارش لغو نمی‌شود.</p>
                    <div class="sgd-cancel-review__actions"><button type="button" class="sg-button sg-button--secondary" data-sgd-cancel-back>انصراف</button><button type="button" class="sg-button sgd-cancel-danger" data-sgd-cancel-confirm>تأیید لغو مرسوله</button></div></div>
                </section>
                </div>
            </div>
            <div class="sg-modal__footer"><button type="button" class="sg-button sg-button--secondary" data-close-modal>بستن</button><button type="button" class="sg-button sg-button--secondary" data-sgd-refresh>به‌روزرسانی جزئیات</button><button type="button" class="sg-button sgd-cancel-danger" data-sgd-cancel hidden>لغو مرسوله</button><button type="button" class="sg-button sg-button--secondary" data-sgd-cancel-check hidden>بررسی وضعیت لغو</button></div>
        </div>
    </div>

    <script type="application/json" id="sgp-config"><?php echo wp_json_encode([
        'filters' => $filters,
        'deferredList' => true,
        'markets' => $markets,
        'marketLabels' => ParcelsListService::MARKET_LABELS,
        'statusLabels' => $statusLabels,
        'sendUrl' => $sendUrl,
        'userId' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
    ]); ?></script>
<?php endif; ?>
</div>
