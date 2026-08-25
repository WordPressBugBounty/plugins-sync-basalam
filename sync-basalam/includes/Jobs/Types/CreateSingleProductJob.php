<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class CreateSingleProductJob extends AbstractJobType
{
    private $productOperations;

    public function __construct($jobManager,$productOperations)
    {
        parent::__construct($jobManager);
        $this->productOperations = $productOperations;
    }

    public function getType(): string
    {
        return 'sync_basalam_create_single_product';
    }

    public function getPriority(): int
    {
        return 4;
    }

    public function execute(array $payload): JobResult
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canCreate()) {
            return $this->success([
                'skipped' => true,
                'reason' => $vendorSyncPolicy->getRestrictionMessage(false),
            ]);
        }

        $productId = $payload['product_id'] ?? $payload;

        if (!$productId) {
            throw NonRetryableException::invalidData('شناسه محصول الزامی است');
        }

        $product = \wc_get_product($productId);
        if (!$product) {
            throw NonRetryableException::productNotFound(esc_html($productId));
        }

        try {
            $result = $this->productOperations->createNewProduct($productId, null);
            return $this->success(['product_id' => $productId, 'result' => $result]);
        } catch (RetryableException $e) {
            Logger::error("خطا در اضافه کردن محصول به باسلام: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'اضافه کردن محصول به باسلام',
            ]);
            throw $e;
        } catch (NonRetryableException $e) {
            Logger::error("خطا در اضافه کردن محصول به باسلام: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'اضافه کردن محصول به باسلام',
            ]);
            throw $e;
        } catch (\Exception $e) {
            Logger::error("خطا در اضافه کردن محصول به باسلام: " . $e->getMessage(), [
                'product_id' => $productId,
                'operation' => 'اضافه کردن محصول به باسلام',
            ]);
            throw $e;
        }
    }
}
