<?php

namespace SyncBasalam\Admin\Product;

use SyncBasalam\Admin\Product\Operations\UpdateProduct;
use SyncBasalam\Admin\Product\Operations\CreateProduct;
use SyncBasalam\Admin\Product\Operations\ArchiveProduct;
use SyncBasalam\Admin\Product\Operations\RestoreProduct;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class ProductOperations
{
    private $updateOperation;
    private $createOperation;
    private $archiveOperation;
    private $restoreOperation;

    public function __construct(
        $updateOperation = null,
        $createOperation = null,
        $archiveOperation = null,
        $restoreOperation = null
    ) {
        $this->updateOperation = $updateOperation ?: syncBasalamContainer()->get(UpdateProduct::class);
        $this->createOperation = $createOperation ?: syncBasalamContainer()->get(CreateProduct::class);
        $this->archiveOperation = $archiveOperation ?: syncBasalamContainer()->get(ArchiveProduct::class);
        $this->restoreOperation = $restoreOperation ?: syncBasalamContainer()->get(RestoreProduct::class);
    }

    public function updateExistProduct($product_id, $category_ids = null)
    {
        try {
            return $this->updateOperation->execute($product_id, ['category_ids' => $category_ids]);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function createNewProduct($product_id, $category_ids)
    {
        try {
            return $this->createOperation->execute($product_id, ['category_ids' => $category_ids]);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function restoreExistProduct($product_id)
    {
        try {
            return $this->restoreOperation->execute($product_id);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function archiveExistProduct($product_id)
    {
        try {
            return $this->archiveOperation->execute($product_id);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    public static function disconnectProduct($product_id)
    {
        do_action('sync_basalam_before_disconnect_product', $product_id);

        $metaKeysToRemove = ProductMetaKey::basalamProductMetaKeys();

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
