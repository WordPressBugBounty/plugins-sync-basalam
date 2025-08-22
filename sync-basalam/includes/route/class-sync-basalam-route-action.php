<?php
if (! defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . '../controller/class-sync-basalam-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-add-products-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-cancel-add-products-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-update-products-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-cancel-update-products-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-auto-connect-products-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-cancel-auto-connect-products-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/order-actions/class-sync-basalam-add-unsync-orders.php';
require_once plugin_dir_path(__FILE__) . '../controller/order-actions/class-sync-basalam-confirm-order.php';
require_once plugin_dir_path(__FILE__) . '../controller/order-actions/class-sync-basalam-delay-req-order.php';
require_once plugin_dir_path(__FILE__) . '../controller/order-actions/class-sync-basalam-tracking-code-order.php';

require_once plugin_dir_path(__FILE__) . '../controller/option-actions/class-sync-basalam-add-map-option-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/option-actions/class-sync-basalam-delete-map-option-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-add-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-update-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-restore-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-archive-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-disconnect-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-connect-product-controller.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-get-product-categories.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-get-category-attrs.php';
require_once plugin_dir_path(__FILE__) . '../controller/product-actions/class-sync-basalam-clear-logs-controller.php';

require_once plugin_dir_path(__FILE__) . '../controller/class-sync-basalam-update-settings-controller.php';

require_once plugin_dir_path(__FILE__) . '../services/routeHandler/class-sync-basalam-route.php';

sync_basalam_Route::postAjax('create_products_to_basalam', sync_basalam_Add_Products::class);
sync_basalam_Route::postAction('cancel_create_products_to_basalam', sync_basalam_Cancel_Add_Products::class);

sync_basalam_Route::postAjax('update_products_in_basalam', sync_basalam_Update_Products::class);
sync_basalam_Route::postAction('cancel_update_products_in_basalam', sync_basalam_Cancel_Update_Products::class);

sync_basalam_Route::postAjax('connect_products_with_basalam', sync_basalam_connect_products::class);
sync_basalam_Route::postAction('cancel_connect_products_with_basalam', sync_basalam_Cancel_Connect_Products::class);

sync_basalam_Route::postAjax('add_unsync_orders_from_basalam', sync_basalam_Add_Unsync_Orders::class);

sync_basalam_Route::postAjax('basalam_add_map_option', sync_basalam_Add_Map_Option::class);
sync_basalam_Route::postAjax('basalam_delete_mapped_option', sync_basalam_Delete_Map_Option::class);

sync_basalam_Route::postAction('create_product_basalam', sync_basalam_Add_Product::class);

sync_basalam_Route::postAction('update_product_in_basalam', sync_basalam_Update_Product::class);

sync_basalam_Route::postAction('restore_exist_product_on_basalam', sync_basalam_Restore_Product::class);

sync_basalam_Route::postAction('archive_exist_product_on_basalam', sync_basalam_Archive_Product::class);

sync_basalam_Route::postAction('disconnect_exist_product_on_basalam', sync_basalam_Disconnect_Product::class);

sync_basalam_Route::postAction('basalam_update_setting', sync_basalam_Update_Setting::class);

sync_basalam_Route::postAjax('confirm_basalam_order', sync_basalam_Confirm_Order::class);

sync_basalam_Route::postAjax('delay_req_basalam_order', sync_basalam_Delay_Req_Order::class);

sync_basalam_Route::postAjax('tracking_code_basalam_order', sync_basalam_Tracking_Code_Order::class);

sync_basalam_Route::postAjax('basalam_connect_product', sync_basalam_connect_product::class);

sync_basalam_Route::postAjax('basalam_get_category_ids', Sync_basalam_Get_Product_Categories::class);

sync_basalam_Route::postAjax('basalam_get_category_attrs', Sync_basalam_Get_Category_Attrs::class);
sync_basalam_Route::postAjax('basalam_clear_logs', Sync_basalam_Clear_Logs_Controller::class);
