<?php

namespace SyncBasalam\Admin;

use SyncBasalam\Admin\Pages\CategoryMappingPage;
use SyncBasalam\Admin\Pages\HelpPage;
use SyncBasalam\Admin\Pages\InfoPage;
use SyncBasalam\Admin\Pages\LogsPage;
use SyncBasalam\Admin\Pages\MainPage;
use SyncBasalam\Admin\Pages\OnboardingPage;
use SyncBasalam\Admin\Pages\UnsyncedProductsPage;
use SyncBasalam\Admin\Pages\TicketListPage;
use SyncBasalam\Admin\Pages\CreateTicketPage;
use SyncBasalam\Admin\Pages\SingleTicketPage;
use SyncBasalam\Admin\Settings;
class Pages
{
    private $renderUi;

    public function __construct()
    {
        $this->renderUi = new Components();
    }

    public function registerMenus()
    {
        add_menu_page(
            'ووسلام',
            'ووسلام',
            'manage_options',
            'sync_basalam',
            [new MainPage(), 'render'],
            plugin_dir_url(__FILE__) . '../../assets/images/woosalam.png',
            4
        );

        add_submenu_page(
            'sync_basalam',
            'خانه',
            $this->renderUi->renderIcon('dashicons-admin-home') . 'خانه',
            'manage_options',
            'sync_basalam',
            [new MainPage(), 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'لاگ ها',
            $this->renderUi->renderIcon('dashicons-list-view') . 'لاگ ها',
            'manage_options',
            'sync_basalam_logs',
            [new LogsPage(), 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'اطلاعات',
            $this->renderUi->renderIcon('dashicons-info') . 'اطلاعات',
            'manage_options',
            'sync_basalam_vendor_info',
            [new InfoPage(), 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'راهنما',
            $this->renderUi->renderIcon('dashicons-book-alt') . 'راهنما',
            'manage_options',
            'sync_basalam_help',
            [new HelpPage(), 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'اتصال دسته‌بندی‌ها',
            $this->renderUi->renderIcon('dashicons-category') . 'اتصال دسته‌بندی‌ها',
            'manage_options',
            'sync_basalam_category_mapping',
            [new CategoryMappingPage(), 'render']
        );

        if (class_exists('Digikala\Admin\Menus')) \Digikala\Admin\Menus::register();

        add_submenu_page(
            'آنبوردینگ',
            'آنبوردینگ باسلام',
            'آموزش باسلام',
            'manage_options',
            'basalam-onboarding',
            [new OnboardingPage(), 'render']
        );

        add_submenu_page(
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'manage_options',
            'basalam-save-token',
            [Settings::class, 'saveOauthData']
        );

        add_submenu_page(
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'manage_options',
            'basalam-show-products',
            [new UnsyncedProductsPage(), 'render']
        );


        // Ticket
        add_submenu_page(
            'sync_basalam',
            'پشتیبانی',
            $this->renderUi->renderIcon('dashicons-book-alt') . 'پشتیبانی',
            'manage_options',
            'sync_basalam_tickets',
            [new TicketListPage(), 'render']
        );

        add_submenu_page(
            'اطلاعات تیکت',
            'اطلاعات تیکت',
            'اطلاعات تیکت',
            'manage_options',
            'sync_basalam_ticket',
            [new SingleTicketPage(), 'render']
        );

        add_submenu_page(
            'ایجاد تیکت',
            'ایجاد تیکت',
            'ایجاد تیکت',
            'manage_options',
            'sync_basalam_new_ticket',
            [new CreateTicketPage(), 'render']
        );
    }
}
