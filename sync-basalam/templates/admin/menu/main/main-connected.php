<?php
if (! defined('ABSPATH')) exit;

global $sync_basalam_Auto_Connect_Product_Task;
global $sync_basalam_Update_Products_Task;

$count_of_published_woocamerce_products = sync_basalam_Admin_Asset::count_of_published_woocamerce_products();
$count_of_synced_basalam_products = sync_basalam_Admin_Asset::get_count_of_synced_basalam_products();

$count_of_chunk_create_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_chunk_create_products');

$create_product_job_exist = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product') ? 'not-allowed' : '';

$count_create_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product') > 1000 ? '+1000' : sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_create_product');

$count_of_chunk_update_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_chunk_update_products');

$update_product_job_exist = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product') ? 'not-allowed' : '';
$count_update_product_tasks = sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product') > 1000 ? '+1000' : sync_basalam_QueueManager::count_of_pending_tasks('sync_basalam_plugin_update_product');


$job_manager = new SyncBasalamJobManager();


$quick_update_job = $job_manager->get_job(['job_type' => 'sync_basalam_update_all_products', 'status' => 'pending']);
$quick_update_processing_job = $job_manager->get_job(['job_type' => 'sync_basalam_update_all_products', 'status' => 'processing']);
$count_quick_update_batches = $sync_basalam_Update_Products_Task ? $sync_basalam_Update_Products_Task->count_batches() : 0;


$full_update_job = $job_manager->get_job(['job_type' => 'sync_basalam_full_update_products', 'status' => 'pending']);
$full_update_processing_job = $job_manager->get_job(['job_type' => 'sync_basalam_full_update_products', 'status' => 'processing']);


$single_update_count = $job_manager->get_count_jobs(['job_type' => 'sync_basalam_update_single_product', 'status' => ['pending', 'processing']]);

$has_active_update_jobs = ($quick_update_job || $quick_update_processing_job || $full_update_job || $full_update_processing_job || $single_update_count > 0);
$active_update_type = '';
if ($quick_update_job || $quick_update_processing_job) {
    $active_update_type = 'quick';
} elseif ($full_update_job || $full_update_processing_job || $single_update_count > 0) {
    $active_update_type = 'full';
}


$create_products_job = $job_manager->get_job(['job_type' => 'sync_basalam_create_products', 'status' => 'pending']);
$create_products_processing_job = $job_manager->get_job(['job_type' => 'sync_basalam_create_products', 'status' => 'processing']);


$single_create_count = $job_manager->get_count_jobs(['job_type' => 'sync_basalam_create_single_product', 'status' => ['pending', 'processing']]);

$has_active_create_jobs = ($create_products_job || $create_products_processing_job || $single_create_count > 0 || $count_of_chunk_create_product_tasks > 0 || $create_product_job_exist);

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