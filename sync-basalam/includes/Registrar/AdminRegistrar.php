<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Actions\RegisterActions;
use SyncBasalam\Admin\Pages;
use SyncBasalam\Registrar\Contracts\RegistrarInterface;
use SyncBasalam\Admin\Product\elements\ProductList\StatusColumn;
use SyncBasalam\Admin\Product\elements\ProductList\MetaBox;
use SyncBasalam\Admin\Product\elements\SingleProduct\Tab;
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

defined("ABSPATH") || exit;

class AdminRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        // Initialize Admin Actions
        new RegisterActions();

        // Admin Menu
        \add_action("admin_menu", [new Pages(), "registerMenus"]);

        // Admin Scripts & Styles
        \add_action("admin_enqueue_scripts", [self::class, "adminEnqueueStyles"]);
        \add_action("admin_enqueue_scripts", [self::class, "adminEnqueueScripts"]);
        \add_action("admin_footer", [AnnouncementCenter::class, 'renderPanel']);

        // Product Columns
        \add_filter("manage_edit-product_columns", [StatusColumn::class, "registerStatusColumn"]);
        \add_action("manage_product_posts_custom_column", [StatusColumn::class, "renderStatusColumnContent"], 10, 2);

        // Product Meta Boxes
        \add_action("add_meta_boxes_product", [MetaBox::class, "registerMetaBox"]);

        // Basalam Product Settings Tab
        \add_filter('woocommerce_product_data_tabs', [Tab::class, 'registerTab']);
        \add_action('woocommerce_product_data_panels', [Tab::class, 'renderTabContent']);
        \add_action('woocommerce_process_product_meta', [Tab::class, 'saveTabData']);

        // Product Filter
        \add_action("restrict_manage_posts", [new Filter(), "renderFilterDropdown"]);
        \add_action("pre_get_posts", [new Filter(), "applyFilterToQuery"]);

        // Order Columns (HPOS)
        \add_action("manage_woocommerce_page_wc-orders_custom_column", [new OrderColumn(), "renderColumn"], 10, 2);
        \add_filter("manage_woocommerce_page_wc-orders_columns", [new OrderColumn(), "addColumn"]);

        // Order Columns (Traditional CPT - non-HPOS)
        \add_action("manage_shop_order_posts_custom_column", [new OrderColumn(), "renderColumn"], 10, 2);
        \add_filter("manage_edit-shop_order_columns", [new OrderColumn(), "addColumn"]);

        // Order Meta Boxes
        \add_action("add_meta_boxes", [new OrderMetaBox(), "registerMetaBox"], 10);

        // Order Meta Boxes (Traditional CPT - non-HPOS)
        \add_action("add_meta_boxes_shop_order", [new OrderMetaBox(), "registerMetaBox"], 10);

        // Order Statuses
        \add_filter("wc_order_statuses", [new OrderStatuses(), "registerorderStatuses"]);

        // Order Stock Levels
        \add_action("woocommerce_order_status_bslm-rejected", "wc_maybe_increase_stock_levels");
        \add_action("woocommerce_order_status_bslm-preparation", "wc_maybe_reduce_stock_levels");
        \add_action("woocommerce_order_status_bslm-shipping", "wc_maybe_reduce_stock_levels");
        \add_action("woocommerce_order_status_bslm-completed", "wc_maybe_reduce_stock_levels");

        // Bulk Actions
        \add_filter("bulk_actions-edit-product", [new Actions(), "registerBulkActions"]);
        \add_filter("handle_bulk_actions-edit-product", [new Actions(), "handleBulkAction"], 10, 3);

        // Product Duplicate
        \add_action("woocommerce_product_duplicate", function ($newProduct) {
            ProductOperations::disconnectProduct($newProduct->get_id());
        }, 10, 1);

        // Order Check Buttons (HPOS)
        \add_action("woocommerce_order_list_table_extra_tablenav", [OrderPageComponents::class, "renderCheckOrdersButton"], 20, 1);

        // Order Check Buttons (Traditional CPT - uses same hook as product filter)
        \add_action("restrict_manage_posts", [OrderPageComponents::class, "renderCheckOrdersButtonTraditional"]);

        // Initialize AJAX handlers
        $connectProduct = new ConnectProduct();
        add_action('wp_ajax_sync_basalam_connect_product', [$connectProduct, 'handleConnectProduct']);
        add_action('wp_ajax_basalam_search_products', [$connectProduct, 'handleSearchProducts']);
        add_action('wp_ajax_sync_basalam_mark_pointer_onboarding_completed', [PointerTour::class, 'markPointerOnboardingCompleted']);
        add_action('wp_ajax_' . AnnouncementCenter::MARK_SEEN_ACTION, [AnnouncementCenter::class, 'markAllSeen']);
        add_action('wp_ajax_' . AnnouncementCenter::FETCH_PAGE_ACTION, [AnnouncementCenter::class, 'fetchPage']);

        // Tasks per minute calculation handler
        add_action('wp_ajax_basalam_calculate_tasks_per_minute', function () {
            check_ajax_referer('basalam_update_setting_nonce', 'nonce', true);

            $monitor = SystemResourceMonitor::getInstance();
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
        wp_enqueue_style(
            "basalam-admin-style",
            self::assetsUrl("css/style.css"),
        );
        wp_enqueue_style(
            "basalam-admin-font-style",
            self::assetsUrl("css/font.css"),
        );
        wp_enqueue_style(
            "basalam-admin-social-style",
            self::assetsUrl("css/social.css"),
        );
        wp_enqueue_style(
            "basalam-admin-logs-style",
            self::assetsUrl("css/logs.css"),
        );
        wp_enqueue_style(
            "basalam-admin-onboarding-style",
            self::assetsUrl("css/onboarding.css"),
        );

        if (PointerTour::shouldLoadPointerTour((string) $hook)) {
            wp_enqueue_style('wp-pointer');
        }
    }

    public static function adminEnqueueScripts($hook = '')
    {
        $shouldLoadPointerTour = PointerTour::shouldLoadPointerTour((string) $hook);

        if ($shouldLoadPointerTour) {
            wp_enqueue_script('wp-pointer');
        }

        wp_enqueue_script(
            "basalam-admin-logs-script",
            self::assetsUrl("js/logs.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-admin-help-script",
            self::assetsUrl("js/help.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-admin-product-fields-script",
            self::assetsUrl("js/product-fields.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-admin-manage-box-script",
            self::assetsUrl("js/manage-box.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-admin-connect-modal-script",
            self::assetsUrl("js/connect-modal.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-round-script",
            self::assetsUrl("js/round.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-get-category-script",
            self::assetsUrl("js/get-category.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-order-script",
            self::assetsUrl("js/order.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-admin-script",
            self::assetsUrl("js/admin.js"),
            $shouldLoadPointerTour ? ["jquery", "wp-pointer"] : ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-check-sync-script",
            self::assetsUrl("js/check-sync.js"),
            ["jquery"],
            true
        );
        wp_enqueue_script(
            "basalam-map-category-option-script",
            self::assetsUrl("js/map-category-option.js"),
            ["jquery"],
            true
        );

        wp_enqueue_script(
            "basalam-generate-product-variation-script",
            self::assetsUrl("js/generate-product-variation.js"),
            ["jquery"],
            true
        );

        wp_enqueue_script(
            "basalam-ticket-script",
            self::assetsUrl("js/ticket.js"),
            [],
            true
        );

        if ($shouldLoadPointerTour) {
            wp_localize_script('basalam-admin-script', 'basalamPointerTour', PointerTour::getPointerTourConfig());
        }

        if (AnnouncementCenter::shouldLoadOnCurrentPage()) {
            wp_localize_script('basalam-admin-script', 'basalamAnnouncements', AnnouncementCenter::getConfig());
        }
    }
}
