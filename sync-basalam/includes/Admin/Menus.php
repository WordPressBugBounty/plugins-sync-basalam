<?php

namespace SyncBasalam\Admin;

use SyncBasalam\Admin\Pages\CategoryMappingPage;
use SyncBasalam\Admin\Pages\HelpPage;
use SyncBasalam\Admin\Pages\InfoPage;
use SyncBasalam\Admin\Pages\LogsPage;
use SyncBasalam\Admin\Pages\MainPage;
use SyncBasalam\Admin\Pages\OnboardingPage;
use SyncBasalam\Admin\Pages\UnsyncedProductsPage;

class Menus
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
            [MainPage::class, 'render'],
            plugin_dir_url(__FILE__) . '../../assets/images/woosalam.png',
            4
        );

        add_submenu_page(
            'sync_basalam',
            'خانه',
            $this->renderUi->renderIcon('dashicons-admin-home') . 'خانه',
            'manage_options',
            'sync_basalam',
            [MainPage::class, 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'لاگ ها',
            $this->renderUi->renderIcon('dashicons-list-view') . 'لاگ ها',
            'manage_options',
            'sync_basalam_logs',
            [LogsPage::class, 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'اطلاعات',
            $this->renderUi->renderIcon('dashicons-info') . 'اطلاعات',
            'manage_options',
            'sync_basalam_vendor_info',
            [InfoPage::class, 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'راهنما',
            $this->renderUi->renderIcon('dashicons-book-alt') . 'راهنما',
            'manage_options',
            'sync_basalam_help',
            [HelpPage::class, 'render']
        );

        add_submenu_page(
            'sync_basalam',
            'اتصال دسته‌بندی‌ها',
            $this->renderUi->renderIcon('dashicons-category') . 'اتصال دسته‌بندی‌ها',
            'manage_options',
            'sync_basalam_category_mapping',
            [CategoryMappingPage::class, 'render']
        );

        if (class_exists('Digikala\Admin\Menus')) \Digikala\Admin\Menus::register();

        add_submenu_page(
            'آنبوردینگ',
            'آنبوردینگ باسلام',
            'آموزش باسلام',
            'manage_options',
            'basalam-onboarding',
            [OnboardingPage::class, 'render']
        );

        add_submenu_page(
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'ذخیره اطلاعات',
            'manage_options',
            'basalam-save-token',
            [\SyncBasalam\Admin\Settings::class, 'saveOauthData']
        );

        add_submenu_page(
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'محصولات باسلام سینک نشده با ووکامرس',
            'manage_options',
            'basalam-show-products',
            [UnsyncedProductsPage::class, 'render']
        );
    }
}
