<?php

use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

global $wpdb;

$count_of_published_woocommerce_products = wp_count_posts('product')->publish;
$count_of_synced_basalam_products = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'sync_basalam_product_id'"));

$job_manager = new JobManager();

$quick_update_processing_job = $job_manager->getJob(['job_type' => 'sync_basalam_bulk_update_products', 'status' => 'processing'])
    ?: $job_manager->getJob(['job_type' => 'sync_basalam_bulk_update_products', 'status' => 'pending']);

$full_update_job = $job_manager->getJob(['job_type' => 'sync_basalam_update_all_products', 'status' => 'pending']);
$full_update_processing_job = $job_manager->getJob(['job_type' => 'sync_basalam_update_all_products', 'status' => 'processing']);

$single_update_count = $job_manager->getCountJobs(['job_type' => 'sync_basalam_update_single_product', 'status' => ['pending', 'processing']]);

$has_active_update_jobs = ($quick_update_processing_job || $full_update_job || $full_update_processing_job || $single_update_count > 0);
$active_update_type = '';
if ($quick_update_processing_job) {
    $active_update_type = 'quick';
} elseif ($full_update_job || $full_update_processing_job || $single_update_count > 0) {
    $active_update_type = 'full';
}

$create_products_job = $job_manager->getJob(['job_type' => 'sync_basalam_create_all_products', 'status' => 'pending']);
$create_products_processing_job = $job_manager->getJob(['job_type' => 'sync_basalam_create_all_products', 'status' => 'processing']);

$single_create_count = $job_manager->getCountJobs(['job_type' => 'sync_basalam_create_single_product', 'status' => ['pending', 'processing']]);

$has_active_create_jobs = ($create_products_job || $create_products_processing_job || $single_create_count > 0);

$auto_connect_page_job = $job_manager->getJob(['job_type' => 'sync_basalam_auto_connect_products', 'status' => 'pending']) ?: $job_manager->getJob(['job_type' => 'sync_basalam_auto_connect_products', 'status' => 'processing']);

$auto_connect_product_job_exist = $auto_connect_page_job ? 'not-allowed' : '';
?>

<div class="basalam-dashboard">
    <?php
    require_once(syncBasalamPlugin()->templatePath("products/Popups/AddProduct.php"));
    require_once(syncBasalamPlugin()->templatePath("products/Popups/EditProduct.php"));
    require_once(syncBasalamPlugin()->templatePath("products/Popups/AutoConnect.php"));
    require_once(syncBasalamPlugin()->templatePath("products/sections/Status.php"));
    ?>
    <div class="basalam-action-cards">
        <?php
        require_once(syncBasalamPlugin()->templatePath("products/sections/ProductList.php"));
        require_once(syncBasalamPlugin()->templatePath("orders/sections/OrderManagement.php"));
        require_once(syncBasalamPlugin()->templatePath("products/sections/Settings.php"));
        ?>
    </div>
</div>