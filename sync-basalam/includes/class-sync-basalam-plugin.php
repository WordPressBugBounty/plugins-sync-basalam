<?php
defined('ABSPATH') || exit;

class Sync_basalam_Plugin
{

    /**
     * Plugin version
     */
    const VERSION = '1.3.11';

    /**
     * Plugin singleton instance
     */

    protected static $instance = null;

    /**
     * Get Singleton instance
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Class constructor - private to ensure Singleton
     */
    private function __construct()
    {
        $this->define_constants();
        $this->migrate();
        $this->includes();
        $this->init_tasks();
        $this->init_hooks();
        $this->init_listener();
        $this->init_wp_bg_process();
    }

    /**
     * Define plugin constants
     */
    private function define_constants()
    {
        if (!defined('SYNC_BASALAM_PLUGIN_DIR')) {
            define('SYNC_BASALAM_PLUGIN_DIR', str_replace("includes", "", plugin_dir_path(__FILE__)));
        }

        if (!defined('SYNC_BASALAM_PLUGIN_INCLUDES_DIR')) {
            define('SYNC_BASALAM_PLUGIN_INCLUDES_DIR', plugin_dir_path(__FILE__));
        }

        if (!defined('SYNC_BASALAM_PLUGIN_VERSION')) {
            define('SYNC_BASALAM_PLUGIN_VERSION', self::VERSION);
        }
    }

    private function migrate()
    {
        require_once __DIR__ . '/class-sync-basalam-plugin-activator.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-interface.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-V-1-3-0.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-V-1-3-2.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-V-1-3-8.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-V-1-3-9.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/class-sync-basalam-migration-manager.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'migration/service/class-sync-basalam-migrator-service.php';

        $current_version = get_option('sync_basalam_version') ?: '0.0.0';
        $manager = new Sync_Basalam_Migration_Manager();
        $manager->runMigrations($current_version, self::VERSION);
    }

    private function includes()
    {
        // Logger file
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'log/interface-sync-basalam-logger-interface.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'log/class-sync-basalam-woo-logger.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'log/class-sync-basalam-error-logger.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'log/class-sync-basalam-logger.php';

        // Utility files
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'utilities/class-sync-basalam-text-cleaner.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'utilities/class-sync-basalam-exception.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'utilities/class-sync-basalam-convet-fa-num.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'utilities/class-sync-basalam-iran-provinces-code.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'utilities/class-sync-basalam-order-manager.php';

        // Listener file
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/trait-sync-basalam-check-product-status.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/class-sync-basalam-listener.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/interface-sync-basalam-listener-interface.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/class-sync-basalam-update-product-listener.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/class-sync-basalam-create-product-listener.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/class-sync-basalam-restore-product-listener.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'listeners/class-sync-basalam-archive-product-listener.php';

        // Queue related files
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'queue/class-sync-basalam-queue-manager.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'queue/class-sync-basalam-abstract-task.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-product-queue-manager.php';

        // Services
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-external-api-service.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-date-converter.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-create-product-service.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-update-product-service.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-category-id.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-upload-file.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-commission.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-order-manager.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-product-data.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-check-unsync-basalam-products.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-check-photos-ban-status.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-category-attr.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-auto-connect-products.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-like-woosalam.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-basalam-orders.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-unsync-orders-detection.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-image-sizes.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-plugin-data.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-get-shipping-methods.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-connect-product-service.php';

        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-webhook-service.php';

        // Order Services
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/orders/class-sync-basalam-confirm-order.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/orders/class-sync-basalam-cancel-order.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/orders/class-sync-basalam-cancel-req-order.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/orders/class-sync-basalam-tracking-code-order.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/orders/class-sync-basalam-delay-req-order.php';

        // Admin section files
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-menus.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-manage-category-options.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-product-status.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-filter-woo-products.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-bulk-action-woo-products.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-onboarding.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-ui.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-settings.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/class-sync-basalam-admin-help.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-product-mobile-category-fields.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-product-type.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-get-product-data-json.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-asset.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-product-operations.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-single-product-box.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/product/class-sync-basalam-admin-product-wholesale.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/order/class-sync-basalam-admin-order-is-basalam.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/order/class-sync-basalam-order-box.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/order/class-sync-basalam-admin-order-statuses.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'admin/order/class-sync-basalam-admin-check-basalam-order.php';
        require_once $this->template_path("admin/utilities/connect-ajax-single-product-page.php");

        // REST API files
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'api/class-sync-basalam-rest-controller.php';

        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'route/class-sync-basalam-route-action.php';
    }

    private function init_hooks()
    {
        // Initialize admin menus
        add_action('admin_menu', array('Sync_basalam_Admin_Menus', 'register_menus'));

        // Register REST API routes
        add_action('rest_api_init', array('Sync_basalam_REST_Controller', 'register_routes'));

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        add_filter('manage_edit-product_columns', array('Sync_basalam_Admin_Product_Status', 'add_Sync_basalam_status_column'));
        add_action('manage_product_posts_custom_column', array('Sync_basalam_Admin_Product_Status', 'add_Sync_basalam_status_column_content'), 10, 2);

        add_action('add_meta_boxes_product', array('Sync_basalam_Admin_Single_Product_Box', 'Sync_basalam_single_product_manage_box'));

        add_action('woocommerce_product_options_inventory_product_data', array('Sync_basalam_Admin_Mobile_Category_Fields', 'mobile_category_require_fildes_var'));
        add_action('woocommerce_product_options_inventory_product_data', array('Sync_basalam_Admin_Mobile_Category_Fields', 'mobile_category_require_fildes_var'));
        add_action('woocommerce_process_product_meta', array('Sync_basalam_Admin_Mobile_Category_Fields', 'save_Sync_basalam_is_mobile_checkbox_field'));


        add_action('woocommerce_product_options_inventory_product_data', array('Sync_basalam_Admin_Product_Type', 'add_Sync_basalam_is_product_type_checkbox_to_product_page'));

        add_action('woocommerce_product_options_inventory_product_data', array('Sync_basalam_Admin_Product_Wholesale', 'Sync_basalam_wholesale_button'));
        add_action('woocommerce_process_product_meta', array('Sync_basalam_Admin_Product_Wholesale', 'save_Sync_basalam_product_wholesale'));

        add_action('woocommerce_process_product_meta', array('Sync_basalam_Admin_Product_Type', 'save_Sync_basalam_product_units'));

        add_action('manage_woocommerce_page_wc-orders_custom_column', array('Sync_basalam_Admin_Order_Is_Basalam', 'populate_Sync_basalam_order_column_content'), 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', array('Sync_basalam_Admin_Order_Is_Basalam', 'add_Sync_basalam_order_column_to_wc_orders'));

        add_action('init', [new Sync_basalam_Admin_Order_Statuses(), 'register_custom_order_statuses'], 20);
        add_filter('wc_order_statuses', [new Sync_basalam_Admin_Order_Statuses(), 'add_custom_order_statuses']);
        add_filter('bulk_actions-edit-shop_order', [new Sync_basalam_Admin_Order_Statuses(), 'add_custom_status_to_bulk_actions']);

        add_action('wp_ajax_basalam_search_products', 'Sync_basalam_handle_search_products_ajax');


        add_filter('bulk_actions-edit-product', [Sync_basalam_Admin_Bulk_Action_Woo_Products::get_instance(), 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [Sync_basalam_Admin_Bulk_Action_Woo_Products::get_instance(), 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [Sync_basalam_Admin_Bulk_Action_Woo_Products::get_instance(), 'show_admin_notices']);

        add_action('woocommerce_duplicate_product', ['Sync_basalam_Admin_Asset', 'remove_Sync_basalam_meta_on_duplicate_product'], 10, 2);

        add_action('woocommerce_order_list_table_extra_tablenav', array('Sync_basalam_Admin_Check_Sync_basalam_Order', 'show_button_on_top_list'), 20, 1);
        add_action('restrict_manage_posts', ['Sync_basalam_Admin_Check_Sync_basalam_Order', 'show_button_on_top_list']);

        add_action('add_meta_boxes', array(new Sync_Basalam_Order_Box, 'add_custom_order_tracking_box'), 10);
        add_action('restrict_manage_posts', [
            Sync_basalam_Admin_Filter_Woo_Products::get_instance(),
            'filter_by_exist_on_basalam'
        ]);

        add_action('pre_get_posts', [
            Sync_basalam_Admin_Filter_Woo_Products::get_instance(),
            'filter_query_for_basalam'
        ]);
    }

    private function init_listener()
    {
        $listeners = [
            'woocommerce_update_product' => new Sync_basalam_Update_Product_Listener(),
            'save_post' => new Sync_basalam_Create_Product_Listener(),
            'untrashed_post' => new Sync_basalam_Restore_Product_Listener(),
            'wp_trash_post' => new Sync_basalam_Archive_Product_Listener(),
        ];

        foreach ($listeners as $event => $listener) {
            add_action($event, function ($data) use ($listener, $event) {
                $listener->init_hook($event, $data);
            }, 10, 2);
        }
    }

    private function init_wp_bg_process()
    {
        global $sync_basalam_Auto_Connect_Product_Task;

        $sync_basalam_Auto_Connect_Product_Task = new sync_basalam_Auto_Connect_Product_Task();
        new sync_basalam_Create_Product_wp_bg_proccess_Task();
        new sync_basalam_Update_Product_wp_bg_proccess_Task();
    }

    private function init_tasks()
    {
        $task_files = glob(SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'queue/tasks/class-sync-basalam-*-task.php');
        foreach ($task_files as $task_file) {
            require_once $task_file;

            $filename = basename($task_file, '.php');
            $parts = explode('-', str_replace('class-', '', $filename));
            $parts = array_map('ucfirst', $parts);
            $class_name = 'Sync_' . implode('_', array_slice($parts, 1));

            if (class_exists($class_name) && is_subclass_of($class_name, 'sync_basalam_AbstractTask')) {
                $task = new $class_name();
                $task->register_hooks();
            }
        }
    }

    /**
     * Get the plugin url.
     *
     * @return string
     */
    public function plugin_url()
    {
        return plugin_dir_url(SYNC_BASALAM_PLUGIN_INCLUDES_DIR);
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path()
    {
        return untrailingslashit(SYNC_BASALAM_PLUGIN_DIR);
    }

    /**
     * Get the template path.
     *
     * @return string
     */
    public function template_path($path = null)
    {
        /**
         * Filter to adjust the base templates path.
         */
        $path = $path ? "/" . $path : null;

        return $this->plugin_path() . "/templates" . $path;
    }

    /**
     * Get the template path.
     *
     * @return string
     */
    public function assets_url($path = null)
    {
        /**
         * Filter to adjust the base templates path.
         */

        $path = $path ? "/" . $path : null;

        return $this->plugin_url() . "assets" . $path;
    }

    /**
     * Add styles to admin page
     */
    public function admin_enqueue_styles()
    {
        wp_enqueue_style(
            'basalam-admin-style',
            $this->assets_url("css/style.css"),
            array(),
            SYNC_BASALAM_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'basalam-admin-font-style',
            $this->assets_url("css/font.css"),
            array(),
            SYNC_BASALAM_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'basalam-admin-social-style',
            $this->assets_url("css/social.css"),
            array(),
            SYNC_BASALAM_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'basalam-admin-logs-style',
            $this->assets_url("css/logs.css"),
            array(),
            SYNC_BASALAM_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'basalam-admin-onboarding-style',
            $this->assets_url("css/onboarding.css"),
            array(),
            SYNC_BASALAM_PLUGIN_VERSION
        );
    }

    /**
     * Add scripts to admin page
     */
    public function admin_enqueue_scripts()
    {
        wp_enqueue_script(
            'basalam-admin-logs-script',
            $this->assets_url("js/logs.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-admin-help-script',
            $this->assets_url("js/help.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-admin-product-fields-script',
            $this->assets_url("js/product-fields.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-admin-manage-box-script',
            $this->assets_url("js/manage-box.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-admin-connect-modal-script',
            $this->assets_url("js/connect-modal.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-round-script',
            $this->assets_url("js/round.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-get-category-script',
            $this->assets_url("js/get-category.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-order-script',
            $this->assets_url("js/order.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-admin-script',
            $this->assets_url("js/admin.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-check-sync-script',
            $this->assets_url("js/check-sync.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
        wp_enqueue_script(
            'basalam-map-category-option-script',
            $this->assets_url("js/map-category-option.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );

        wp_enqueue_script(
            'basalam-generate-product-variation-script',
            $this->assets_url("js/generate-product-variation.js"),
            array('jquery'),
            SYNC_BASALAM_PLUGIN_VERSION,
            true
        );
    }
}
