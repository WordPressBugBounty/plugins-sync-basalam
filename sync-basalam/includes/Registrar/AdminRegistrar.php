<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Actions\RegisterActions;
use SyncBasalam\Admin\Pages;
use SyncBasalam\Registrar\Contracts\RegistrarInterface;
use SyncBasalam\Admin\Product\elements\ProductList\StatusColumn;
use SyncBasalam\Admin\Product\elements\ProductList\MetaBox;
use SyncBasalam\Admin\Product\elements\ProductList\BulkEdit;
use SyncBasalam\Admin\Product\elements\SingleProduct\Tab;
use SyncBasalam\Admin\Product\elements\SingleProduct\VideoField;
use SyncBasalam\Admin\Product\elements\ProductList\Filter;
use SyncBasalam\Admin\Product\elements\ProductList\Actions;
use SyncBasalam\Admin\Order\OrderColumn;
use SyncBasalam\Admin\Order\OrderMetaBox;
use SyncBasalam\Admin\Components\OrderPageComponents;
use SyncBasalam\Admin\Order\OrderStatuses;
use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Product\Operations\ConnectProduct;
use SyncBasalam\Admin\Announcement\AnnouncementCenter;
use SyncBasalam\Admin\Onboarding\PointerTour;
use SyncBasalam\Services\SystemResourceMonitor;
use SyncBasalam\Utilities\ChatWidget;
use SyncBasalam\Admin\FinancialManagement\Menu as FinancialManagementMenu;
use SyncBasalam\Admin\FinancialManagement\BalanceSettlement;
use SyncBasalam\Admin\FinancialManagement\FinancialManagementHistory;

defined("ABSPATH") || exit;

class AdminRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        $container = syncBasalamContainer();
        $pages = $container->get(Pages::class);
        $filter = $container->get(Filter::class);
        $orderColumn = $container->get(OrderColumn::class);
        $orderMetaBox = $container->get(OrderMetaBox::class);
        $orderStatuses = $container->get(OrderStatuses::class);
        $actions = $container->get(Actions::class);
        $bulkEdit = $container->get(BulkEdit::class);

        // Initialize Admin Actions
        $container->get(RegisterActions::class);

        // Admin Menu
        \add_action("admin_menu", [$pages, "registerMenus"]);
        \add_filter("script_loader_tag", [ChatWidget::class, "addTokenToWidgetScript"], 10, 3);

        // Admin Scripts & Styles
        \add_action("admin_enqueue_scripts", [self::class, "adminEnqueueStyles"]);
        \add_action("admin_enqueue_scripts", [self::class, "adminEnqueueScripts"]);
        \add_action("admin_enqueue_scripts", [self::class, "financialManagementEnqueueAssets"]);
        \add_action("admin_footer", [AnnouncementCenter::class, 'renderPanel']);

        // Financial Management (مدیریت مالی)
        FinancialManagementHistory::register();
        BalanceSettlement::register();

        // Product Columns
        \add_filter("manage_edit-product_columns", [StatusColumn::class, "registerStatusColumn"]);
        \add_action("manage_product_posts_custom_column", [StatusColumn::class, "renderStatusColumnContent"], 10, 2);

        // Product Meta Boxes
        \add_action("add_meta_boxes_product", [MetaBox::class, "registerMetaBox"]);

        // Basalam Product Settings Tab
        \add_filter('woocommerce_product_data_tabs', [Tab::class, 'registerTab']);
        \add_action('woocommerce_product_data_panels', [Tab::class, 'renderTabContent']);
        \add_action('woocommerce_process_product_meta', [Tab::class, 'saveTabData']);

        // Basalam Product Video Meta Box (sidebar, like product image/gallery)
        \add_action('add_meta_boxes_product', [VideoField::class, 'registerMetaBox']);
        \add_action('woocommerce_process_product_meta', [VideoField::class, 'save']);

        // Product Filter
        \add_action("restrict_manage_posts", [$filter, "renderFilterDropdown"]);
        \add_action("pre_get_posts", [$filter, "applyFilterToQuery"]);

        // Order Columns (HPOS)
        \add_action("manage_woocommerce_page_wc-orders_custom_column", [$orderColumn, "renderColumn"], 10, 2);
        \add_filter("manage_woocommerce_page_wc-orders_columns", [$orderColumn, "addColumn"]);

        // Order Columns (Traditional CPT - non-HPOS)
        \add_action("manage_shop_order_posts_custom_column", [$orderColumn, "renderColumn"], 10, 2);
        \add_filter("manage_edit-shop_order_columns", [$orderColumn, "addColumn"]);

        // Order Meta Boxes
        \add_action("add_meta_boxes", [$orderMetaBox, "registerMetaBox"], 10);

        // Order Meta Boxes (Traditional CPT - non-HPOS)
        \add_action("add_meta_boxes_shop_order", [$orderMetaBox, "registerMetaBox"], 10);

        // Order Statuses
        \add_filter("wc_order_statuses", [$orderStatuses, "registerorderStatuses"]);

        // Order Stock Levels
        \add_action("woocommerce_order_status_bslm-rejected", "wc_maybe_increase_stock_levels");
        \add_action("woocommerce_order_status_bslm-preparation", "wc_maybe_reduce_stock_levels");
        \add_action("woocommerce_order_status_bslm-shipping", "wc_maybe_reduce_stock_levels");
        \add_action("woocommerce_order_status_bslm-completed", "wc_maybe_reduce_stock_levels");

        // Bulk Actions
        \add_filter("bulk_actions-edit-product", [$actions, "registerBulkActions"]);
        \add_filter("handle_bulk_actions-edit-product", [$actions, "handleBulkAction"], 10, 3);
        \add_action('bulk_edit_custom_box', [$bulkEdit, 'renderFields'], 10, 2);
        \add_action('admin_action_sync_basalam_bulk_edit', [$bulkEdit, 'save']);

        // Product Duplicate
        \add_action("woocommerce_product_duplicate", function ($newProduct) {
            ProductOperations::disconnectProduct($newProduct->get_id());
        }, 10, 1);

        // Order Check Buttons (HPOS)
        \add_action("woocommerce_order_list_table_extra_tablenav", [OrderPageComponents::class, "renderCheckOrdersButton"], 20, 1);

        // Order Check Buttons (Traditional CPT - uses same hook as product filter)
        \add_action("restrict_manage_posts", [OrderPageComponents::class, "renderCheckOrdersButtonTraditional"]);

        // Initialize AJAX handlers
        $connectProduct = $container->get(ConnectProduct::class);
        add_action('wp_ajax_basalam_search_products', [$connectProduct, 'handleSearchProducts']);
        add_action('wp_ajax_sync_basalam_mark_pointer_onboarding_completed', [PointerTour::class, 'markPointerOnboardingCompleted']);
        add_action('wp_ajax_' . AnnouncementCenter::MARK_SEEN_ACTION, [AnnouncementCenter::class, 'markAllSeen']);
        add_action('wp_ajax_' . AnnouncementCenter::FETCH_PAGE_ACTION, [AnnouncementCenter::class, 'fetchPage']);

        // Tasks per minute calculation handler
        add_action('wp_ajax_basalam_calculate_tasks_per_minute', function () {
            check_ajax_referer('basalam_update_setting_nonce', 'nonce', true);

            $monitor = syncBasalamContainer()->get(SystemResourceMonitor::class);
            $optimal = $monitor->calculateOptimalTasksPerMinute();

            wp_send_json_success([
                'optimal_tasks_per_minute' => $optimal
            ]);
        });
    }


    public static function assetsUrl($path = null)
    {
        return plugin_dir_url(dirname(__FILE__, 2)) . "assets/" . $path;
    }

    public static function adminEnqueueStyles($hook = '')
    {
        $version = syncbasalamplugin()->getVersion();

        wp_enqueue_style(
            "basalam-admin-style",
            self::assetsUrl("css/style.css"),
            array(),
            $version
        );
        wp_enqueue_style(
            "basalam-admin-font-style",
            self::assetsUrl("css/font.css"),
            array(),
            $version
        );
        wp_enqueue_style(
            "basalam-admin-social-style",
            self::assetsUrl("css/social.css"),
            array(),
            $version
        );
        wp_enqueue_style(
            "basalam-admin-logs-style",
            self::assetsUrl("css/logs.css"),
            array(),
            $version
        );
        wp_enqueue_style(
            "basalam-admin-onboarding-style",
            self::assetsUrl("css/onboarding.css"),
            array(),
            $version
        );
        wp_enqueue_style(
            "basalam-admin-toast-style",
            self::assetsUrl("css/toast.css"),
            array(),
            $version
        );

        if (PointerTour::shouldLoadPointerTour((string) $hook)) {
            wp_enqueue_style('wp-pointer');
        }
    }

    public static function adminEnqueueScripts($hook = '')
    {
        $shouldLoadPointerTour = PointerTour::shouldLoadPointerTour((string) $hook);
        $version = syncbasalamplugin()->getVersion();

        if ($shouldLoadPointerTour) {
            wp_enqueue_script('wp-pointer');
        }

        wp_enqueue_script(
            "basalam-admin-toast-script",
            self::assetsUrl("js/toast.js"),
            [],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-logs-script",
            self::assetsUrl("js/logs.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-help-script",
            self::assetsUrl("js/help.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-product-fields-script",
            self::assetsUrl("js/product-fields.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-manage-box-script",
            self::assetsUrl("js/manage-box.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-connect-modal-script",
            self::assetsUrl("js/connect-modal.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-round-script",
            self::assetsUrl("js/round.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-get-category-script",
            self::assetsUrl("js/get-category.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-order-script",
            self::assetsUrl("js/order.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-admin-script",
            self::assetsUrl("js/admin.js"),
            $shouldLoadPointerTour ? ["jquery", "wp-pointer", "basalam-admin-toast-script"] : ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-check-sync-script",
            self::assetsUrl("js/check-sync.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );
        wp_enqueue_script(
            "basalam-map-category-option-script",
            self::assetsUrl("js/map-category-option.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );

        wp_enqueue_script(
            "basalam-generate-product-variation-script",
            self::assetsUrl("js/generate-product-variation.js"),
            ["jquery", "basalam-admin-toast-script"],
            $version,
            true
        );

        wp_enqueue_script(
            "basalam-ticket-script",
            self::assetsUrl("js/ticket.js"),
            ["basalam-admin-toast-script"],
            $version,
            true
        );

        if (ChatWidget::shouldLoadWidget()) {
            wp_enqueue_script(
                "basalam-chat-widget-script",
                self::assetsUrl("chat/widget-loader.js"),
                [],
                $version,
                true
            );
        }

        if ($shouldLoadPointerTour) {
            wp_localize_script('basalam-admin-script', 'basalamPointerTour', PointerTour::getPointerTourConfig());
        }

        if (AnnouncementCenter::shouldLoadAnnouncement()) {
            wp_localize_script('basalam-admin-script', 'basalamAnnouncements', AnnouncementCenter::getConfig());
        }
    }

    public static function financialManagementEnqueueAssets($hook = '')
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== FinancialManagementMenu::PAGE_SLUG) {
            return;
        }

        $version = syncbasalamplugin()->getVersion();

        wp_enqueue_style(
            'sync-basalam-finance-style',
            self::assetsUrl('css/finance.css'),
            [],
            $version
        );

        wp_enqueue_script(
            'sync-basalam-finance-history-pagination',
            self::assetsUrl('js/finance-history-pagination.js'),
            [],
            $version,
            true
        );

        wp_localize_script('sync-basalam-finance-history-pagination', 'syncBasalamHistoryPagination', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => FinancialManagementHistory::AJAX_ACTION,
            'nonce' => wp_create_nonce(FinancialManagementHistory::AJAX_ACTION),
            'pageSlug' => FinancialManagementMenu::PAGE_SLUG,
            'errorMessage' => 'بارگذاری تاریخچه تسویه انجام نشد.',
        ]);

        wp_enqueue_script(
            'sync-basalam-finance-balance-settlement',
            self::assetsUrl('js/finance-balance-settlement.js'),
            [],
            $version,
            true
        );

        wp_localize_script('sync-basalam-finance-balance-settlement', 'syncBasalamBalanceSettlement', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => BalanceSettlement::AJAX_ACTION,
            'bankAccountsAction' => BalanceSettlement::AJAX_ACTION_BANK_ACCOUNTS,
            'nonce' => wp_create_nonce(BalanceSettlement::AJAX_ACTION),
            'submitText' => 'ثبت درخواست',
            'loadingText' => 'در حال ارسال...',
            'errorMessage' => 'خطا در ثبت درخواست تسویه.',
            'successMessage' => 'درخواست تسویه با موفقیت ثبت شد.',
            'amountError' => 'لطفاً مبلغ معتبری وارد کنید.',
            'walletTitle' => 'انتقال به کیف پول',
            'bankTitle' => 'انتقال به حساب بانکی',
            'bankAccountsError' => 'خطا در دریافت لیست حساب‌های بانکی.',
        ]);
    }

}
