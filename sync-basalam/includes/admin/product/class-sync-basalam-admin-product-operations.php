<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Product_Operations
{
    private $update_product_service;
    private $create_product_service;

    public function __construct()
    {
        $this->update_product_service = new sync_basalam_Update_Product_Service;
        $this->create_product_service = new sync_basalam_Create_Product_Service;
    }

    public function update_exist_product($product_id, $category_ids = null)
    {
        try {
            $product_data = $this->get_product_data($product_id, 'update', $category_ids);
            return $this->update_product_service->update_product_in_basalam($product_data, $product_id);
        } catch (\Throwable $th) {
            sync_basalam_Logger::error("خطا در بروزرسانی محصول: " . $th->getMessage(), [
                'product_id' => $product_id,
                'عملیات' => 'بروزرسانی محصول باسلام',
            ]);

            return [
                'success' => false,
                'message' => 'فرایند به روزرسانی محصول ناموفق بود.',
                'error' => $th->getMessage(),
                'status_code' => 400
            ];
        }
    }


    public function create_new_product($product_id)
    {
        try {
            $product_data = $this->get_product_data($product_id);
            return $this->create_product_service->create_product_in_basalam($product_data, $product_id);
        } catch (\Throwable $th) {
            sync_basalam_Logger::error("خطا در اضافه کردن محصول: " . $th->getMessage(), [
                'product_id' => $product_id,
                'عملیات' => 'اضافه کردن محصول به باسلام',
            ]);

            return [
                'success' => false,
                'message' => 'فرایند اضافه کردن محصول ناموفق بود.',
                'status_code' => 400
            ];
        }
    }

    public function restore_exist_product($product_id)
    {
        try {
            $result =  $this->update_product_service->update_product_status($product_id, 2976);
            if ($result) {
                return $result;
            }
            throw new \Exception();
        } catch (\Throwable $th) {
            return [
                'success' => false,
                'message' => 'تغییر وضعیت محصول در باسلام ناموفق بود.',
                'status_code' => 400
            ];
        }
    }

    public function archive_exist_product($product_id)
    {
        try {
            $result = $this->update_product_service->update_product_status($product_id, 3790);
            if ($result) {
                return $result;
            }
            throw new \Exception();
        } catch (\Throwable $th) {
            return [
                'success' => false,
                'message' => 'تغییر وضعیت محصول در باسلام ناموفق بود.',
                'status_code' => 400
            ];
        }
    }

    private function get_product_data($product_id, $is_update = false, $category_ids = null)
    {
        $data_handler = new sync_basalam_Admin_Get_Product_Data_Json;
        $product_data = $data_handler->build_product_data($product_id, $is_update, $category_ids);
        return $product_data;
    }

    public static function disconnect_product($product_id)
    {
        $meta_keys_to_remove = [
            'sync_basalam_product_id',
            'sync_basalam_product_sync_status',
            'sync_basalam_product_status',
        ];

        foreach ($meta_keys_to_remove as $meta_key) {
            delete_post_meta($product_id, $meta_key);
        }
        return [
            'success' => true,
            'message' => 'اتصال محصولات با موفقیت حذف شد.',
            'status_code' => 200
        ];
    }
}
