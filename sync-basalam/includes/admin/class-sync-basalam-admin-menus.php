<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Admin_Menus
{

    public static function register_menus()
    {
        add_menu_page(
            'ووسلام',
            'ووسلام',
            'manage_options',
            'sync_basalam',
            array(__CLASS__, 'render_main_page'),
            plugin_dir_url(__FILE__) . '../../assets/images/logowoosalam.png',
            4
        );

        add_submenu_page(
            'sync_basalam',
            'خانه',
            Sync_Basalam_Admin_UI::render_icon('dashicons-admin-home') . 'خانه',
            'manage_options',
            'sync_basalam',
            array(__CLASS__, 'render_main_page')
        );


        add_submenu_page(
            'sync_basalam',
            'لاگ ها',
            Sync_Basalam_Admin_UI::render_icon('dashicons-list-view') . 'لاگ ها',
            'manage_options',
            'sync_basalam_logs',
            array(__CLASS__, 'render_logs_submenu_content'),
        );

        add_submenu_page(
            'sync_basalam',
            'اطلاعات',
            Sync_Basalam_Admin_UI::render_icon('dashicons-info') . 'اطلاعات',
            'manage_options',
            'sync_basalam_vendor_info',
            array(__CLASS__, 'render_vendor_info_submenu_content'),
        );

        add_submenu_page(
            'sync_basalam',
            'راهنما',
            Sync_Basalam_Admin_UI::render_icon('dashicons-book-alt') . 'راهنما',
            'manage_options',
            'sync_basalam_help',
            array(__CLASS__, 'render_help_submenu_content'),
        );
        add_submenu_page(
            'آنبوردینگ',
            'آنبوردینگ باسلام',
            'آموزش باسلام',
            'manage_options',
            'basalam-onboarding',
            array(new Sync_Basalam_Admin_Onboarding, 'sync_basalam_render_onboarding_page')
        );

        add_submenu_page(
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'manage_options',
            'basalam-save-token',
            array(new Sync_Basalam_Admin_Settings, 'save_oauth_data')
        );

        add_submenu_page(
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'manage_options',
            'basalam-show-products',
            array(__CLASS__, 'render_show_sync_basalam_unsync_products_submenu_content'),
        );
    }

    public static function render_main_page()
    {
        $template = sync_basalam_configure()->template_path("admin/menu/main-page.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }

    public static function render_show_sync_basalam_unsync_products_submenu_content()
    {
        $template = sync_basalam_configure()->template_path("admin/menu/basalam-uncync-product-page.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }

    public static function render_logs_submenu_content()
    {
        $template = sync_basalam_configure()->template_path("admin/menu/log-page.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }

    public static function render_vendor_info_submenu_content()
    {
        $template = sync_basalam_configure()->template_path("admin/menu/info-page.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }

    public static function render_help_submenu_content()
    {
        $template = sync_basalam_configure()->template_path("admin/menu/help-page.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }
}
