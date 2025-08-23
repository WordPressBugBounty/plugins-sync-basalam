<?php
if (! defined('ABSPATH')) exit;

global $sync_basalam_Auto_Connect_Product_Task;

$count_of_published_woocamerce_products = sync_basalam_Admin_Asset::count_of_published_woocamerce_products();
$count_of_synced_basalam_products = sync_basalam_Admin_Asset::get_count_of_synced_basalam_products();

$count_of_chunk_create_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_chunk_create_products');

$create_product_job_exist = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product') ? 'not-allowed' : '';

$count_create_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product') > 1000 ? '+1000' : sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product');

$count_of_chunk_update_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_chunk_update_products');

$update_product_job_exist = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product') ? 'not-allowed' : '';
$count_update_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product') > 1000 ? '+1000' : sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product');

$auto_connect_product_job_exist = $sync_basalam_Auto_Connect_Product_Task->is_active() ? 'not-allowed' : '';
$auto_connect_product_job_exist_status = !empty($auto_connect_product_job_exist);
?>

<div class="basalam-dashboard">
    <?php
    require_once(sync_basalam_configure()->template_path("admin/menu/main/modal/add-product.php"));
    require_once(sync_basalam_configure()->template_path("admin/menu/main/modal/update-product.php"));
    require_once(sync_basalam_configure()->template_path("admin/menu/main/modal/auto-connect-product.php"));
    require_once(sync_basalam_configure()->template_path("admin/menu/main/section/status.php"));
    ?>
    <div class="basalam-action-cards">
        <?php
        require_once(sync_basalam_configure()->template_path("admin/menu/main/section/product.php"));
        require_once(sync_basalam_configure()->template_path("admin/menu/main/section/order.php"));
        require_once(sync_basalam_configure()->template_path("admin/menu/main/section/setting.php"));
        ?>
    </div>
</div>