<?php

namespace SyncBasalam\Admin\Product;

use SyncBasalam\Admin\Product\Operations\UpdateProduct;
use SyncBasalam\Admin\Product\Operations\CreateProduct;
use SyncBasalam\Admin\Product\Operations\ArchiveProduct;
use SyncBasalam\Admin\Product\Operations\RestoreProduct;

defined('ABSPATH') || exit;

class ProductOperations
{
    private UpdateProduct $updateOperation;
    private CreateProduct $createOperation;
    private ArchiveProduct $archiveOperation;
    private RestoreProduct $restoreOperation;

    public function __construct()
    {
        $this->updateOperation = new UpdateProduct();
        $this->createOperation = new CreateProduct();
        $this->archiveOperation = new ArchiveProduct();
        $this->restoreOperation = new RestoreProduct();
    }

    public function updateExistProduct($product_id, $category_ids = null)
    {
        try {
            return $this->updateOperation->execute($product_id, ['category_ids' => $category_ids]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function createNewProduct($product_id, $category_ids)
    {
        try {
            return $this->createOperation->execute($product_id, ['category_ids' => $category_ids]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function restoreExistProduct($product_id)
    {
        try {
            return $this->restoreOperation->execute($product_id);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function archiveExistProduct($product_id)
    {
        try {
            return $this->archiveOperation->execute($product_id);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    public static function disconnectProduct($product_id)
    {
        do_action('sync_basalam_before_disconnect_product', $product_id);

        $metaKeysToRemove = ['sync_basalam_product_id', 'sync_basalam_product_sync_status', 'sync_basalam_product_status'];

        foreach ($metaKeysToRemove as $metaKey) {
            delete_post_meta($product_id, $metaKey);
        }

        $product = wc_get_product($product_id);

        if ($product && $product->is_type('variable')) {
            $variationIds = $product->get_children();
            foreach ($variationIds as $variationId) {
                delete_post_meta($variationId, 'sync_basalam_variation_id');
            }
        }

        $result = [
            'success'     => true,
            'message'     => 'اتصال محصولات با موفقیت حذف شد.',
            'status_code' => 200,
        ];

        $result = apply_filters('sync_basalam_disconnect_product_result', $result, $product_id);

        do_action('sync_basalam_after_disconnect_product', $result, $product_id);

        return $result;
    }
}
