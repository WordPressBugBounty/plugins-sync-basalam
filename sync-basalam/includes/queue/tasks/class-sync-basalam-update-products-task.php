<?php

if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Products_wp_bg_proccess_Task extends WP_Async_Background_Process
{
    protected $action = 'sync_basalam_update_products_taks';
    protected $batch_size = 1;
    protected function task($args)
    {
        $product_get_data_service = new SyncBasalamAdminGetProductDataV2();

        $apiservice = new Sync_basalam_External_API_Service();
        $url = sync_basalam_Admin_Settings::get_static_settings("update_bulk_products_url");
        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);

        // Calculate optimal batch size based on server resources (20-200)
        $monitor = Sync_Basalam_System_Resource_Monitor::get_instance();
        $posts_per_page = $monitor->calculate_optimal_batch_size(20, 200);

        $offset = get_option('last_offset_update_products') ?? 0;
        update_option('last_offset_update_products', $offset + $posts_per_page);


        $batch_data = [
            'posts_per_page' => $posts_per_page,
            'offset'         => $offset,
        ];

        $product_ids = sync_basalam_Product_Queue_Manager::get_products_for_update($batch_data);
        if (!$product_ids) {
            delete_option('last_offset_update_products');
            sync_basalam_Logger::info('بروزرسانی دسته‌ای: همه محصولات بروزرسانی شدند.');
            return false;
        }

        $products_data = [];
        foreach ($product_ids as $product_id) {
            $product_data = $product_get_data_service->build_product_data($product_id);
            if (!empty($product_data) && $product_data) {
                $products_data[] = $product_data;
            }
        }

        $data = json_encode([
            'data' => $products_data
        ]);

        $header = [
            'Authorization' => 'Bearer ' .  $token,
        ];

        $res = $apiservice->send_patch_request($url, $data, $header);
        if ($res['status_code'] == 202) {
            sync_basalam_Logger::info('بروزرسانی دسته جمعی محصولات با موفقیت انجام شد.');
        } else {
            sync_basalam_Logger::error('خطا در بروزرسانی دسته جمعی محصولات: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return false;
    }

    protected function complete()
    {
        parent::complete();

        $offset = get_option('last_offset_update_products');

        if ($offset) {
            $job_manager = new SyncBasalamJobManager();
            $job_manager->create_job(
                'sync_basalam_update_all_products',
                'pending',
                $offset,
            );
        }
    }
}
