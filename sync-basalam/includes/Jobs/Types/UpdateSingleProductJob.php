<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class UpdateSingleProductJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_update_single_product';
    }

    public function getPriority(): int
    {
        return 3;
    }

    public function execute(array $payload): JobResult
    {
        $productId = $payload['product_id'] ?? $payload;

        if (!$productId) {
            throw NonRetryableException::invalidData('شناسه محصول الزامی است');
        }

        $product = \wc_get_product($productId);
        if (!$product) {
            throw NonRetryableException::productNotFound($productId);
        }

        try {
            $productOperations = new ProductOperations();
            $result = $productOperations->updateExistProduct($productId, null);
            return $this->success(['product_id' => $productId, 'result' => $result]);
        } catch (RetryableException $e) {
            Logger::error("خطا در بروزرسانی محصول: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'بروزرسانی محصول',
            ]);
            throw $e;
        } catch (NonRetryableException $e) {
            Logger::error("خطا در بروزرسانی محصول: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'بروزرسانی محصول',
            ]);
            throw $e;
        } catch (\Exception $e) {
            Logger::error("خطا در بروزرسانی محصول:: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'بروزرسانی محصول',
            ]);
            throw $e;
        }
    }
}
