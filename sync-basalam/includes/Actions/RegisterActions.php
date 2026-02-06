<?php

namespace SyncBasalam\Actions;

use SyncBasalam\Actions\Controller\ProductActions\CreateAllProducts;
use SyncBasalam\Actions\Controller\ProductActions\CancelCreateProducts;
use SyncBasalam\Actions\Controller\ProductActions\UpdateAllProducts;
use SyncBasalam\Actions\Controller\ProductActions\CancelUpdateProducts;
use SyncBasalam\Actions\Controller\ProductActions\ConnectAllProducts;
use SyncBasalam\Actions\Controller\ProductActions\CancelConnectAllProducts;
use SyncBasalam\Actions\Controller\OrderActions\FetchUnsyncOrders;
use SyncBasalam\Actions\Controller\OrderActions\ConfirmOrder;
use SyncBasalam\Actions\Controller\OrderActions\CancelOrder;
use SyncBasalam\Actions\Controller\OrderActions\RequestCancelOrder;
use SyncBasalam\Actions\Controller\OrderActions\DelayOrder;
use SyncBasalam\Actions\Controller\OrderActions\TrackingCodeOrder;
use SyncBasalam\Actions\Controller\OrderActions\AutoConfirmOrders;
use SyncBasalam\Actions\Controller\OptionActions\CreateMapOption;
use SyncBasalam\Actions\Controller\OptionActions\RemoveMapOption;
use SyncBasalam\Actions\Controller\ProductActions\CreateSingleProduct;
use SyncBasalam\Actions\Controller\ProductActions\UpdateSingleProduct;
use SyncBasalam\Actions\Controller\ProductActions\RestoreProduct;
use SyncBasalam\Actions\Controller\ProductActions\ArchiveProduct;
use SyncBasalam\Actions\Controller\ProductActions\DisconnectProduct;
use SyncBasalam\Actions\Controller\ProductActions\ConnectSingleProduct;
use SyncBasalam\Actions\Controller\ProductActions\DetectionProductCategories;
use SyncBasalam\Actions\Controller\ProductActions\GetCategoryAttributes;
use SyncBasalam\Actions\Controller\ProductActions\ClearLogs;
use SyncBasalam\Actions\Controller\UpdateSettings;
use SyncBasalam\Actions\Controller\CategoryActions\GetWooCategories;
use SyncBasalam\Actions\Controller\CategoryActions\FetchBasalamCategories;
use SyncBasalam\Actions\Controller\CategoryActions\GetCategoryMappings;
use SyncBasalam\Actions\Controller\CategoryActions\CreateCategoryMap;
use SyncBasalam\Actions\Controller\CategoryActions\RemoveCategoryMap;
use SyncBasalam\Actions\Controller\CategoryActions\GetMappingStats;
use SyncBasalam\Actions\Controller\TicketActions\CreateTicket;
use SyncBasalam\Actions\Controller\TicketActions\CreateTicketItem;

defined('ABSPATH') || exit;

class RegisterActions
{
    public function __construct()
    {
        $this->register();
    }

    private function register()
    {
        ActionHandler::postAjax('create_products_to_basalam', CreateAllProducts::class);
        ActionHandler::postAction('cancel_create_jobs', CancelCreateProducts::class);
        ActionHandler::postAction('cancel_update_jobs', CancelUpdateProducts::class);
        ActionHandler::postAjax('update_products_in_basalam', UpdateAllProducts::class);
        ActionHandler::postAction('cancel_update_products_in_basalam', CancelUpdateProducts::class);
        ActionHandler::postAjax('connect_products_with_basalam', ConnectAllProducts::class);
        ActionHandler::postAction('cancel_connect_products_with_basalam', CancelConnectAllProducts::class);
        ActionHandler::postAjax('add_unsync_orders_from_basalam', FetchUnsyncOrders::class);
        ActionHandler::postAjax('basalam_add_map_option', CreateMapOption::class);
        ActionHandler::postAjax('basalam_delete_mapped_option', RemoveMapOption::class);
        ActionHandler::postAction('create_product_basalam', CreateSingleProduct::class);
        ActionHandler::postAction('restore_exist_product_on_basalam', RestoreProduct::class);
        ActionHandler::postAction('archive_exist_product_on_basalam', ArchiveProduct::class);
        ActionHandler::postAction('disconnect_exist_product_on_basalam', DisconnectProduct::class);
        ActionHandler::postAction('basalam_update_setting', UpdateSettings::class);
        ActionHandler::postAjax('confirm_basalam_order', ConfirmOrder::class);
        ActionHandler::postAjax('cancel_basalam_order', CancelOrder::class);
        ActionHandler::postAjax('request_cancel_basalam_order', RequestCancelOrder::class);
        ActionHandler::postAjax('delay_req_basalam_order', DelayOrder::class);
        ActionHandler::postAjax('tracking_code_basalam_order', TrackingCodeOrder::class);
        ActionHandler::postAjax('basalam_connect_product', ConnectSingleProduct::class);
        ActionHandler::postAjax('basalam_get_category_ids', DetectionProductCategories::class);
        ActionHandler::postAction('update_product_in_basalam', UpdateSingleProduct::class);
        ActionHandler::postAjaxNoAuth('basalam_get_category_attrs', GetCategoryAttributes::class);
        ActionHandler::postAjax('basalam_clear_logs', ClearLogs::class);
        ActionHandler::postAjax('get_woocommerce_categories', GetWooCategories::class);
        ActionHandler::postAjax('get_basalam_categories', FetchBasalamCategories::class);
        ActionHandler::postAjax('get_category_mappings', GetCategoryMappings::class);
        ActionHandler::postAjax('create_category_mapping', CreateCategoryMap::class);
        ActionHandler::postAjax('delete_category_mapping', RemoveCategoryMap::class);
        ActionHandler::postAjax('get_mapping_stats', GetMappingStats::class);
        ActionHandler::postAction('auto_confirm_order_in_basalam', AutoConfirmOrders::class);
        ActionHandler::postAction('create_ticket', CreateTicket::class);
        ActionHandler::postAction('create_ticket_item', CreateTicketItem::class);
    }
}
