<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\VendorSyncPolicy;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Services\Products\ProductOwnership;

defined('ABSPATH') || exit;

class UpdateSingleProductJob extends AbstractJobType
{
    private $productOperations;
    private $ownership;

    public function __construct($jobManager, $productOperations, $ownership = null)
    {
        parent::__construct($jobManager);
        $this->productOperations = $productOperations;
        $this->ownership = $ownership ?: new ProductOwnership();
    }

    public function getType(): string
    {
        return 'sync_basalam_update_single_product';
    }

    public function getPriority(): int
    {
        return 2;
    }

    public function execute(array $payload): JobResult
    {
        if (!empty($payload['automatic']) && !SettingsManager::isProductUpdateSyncEnabled()) {
            return $this->success(['skipped' => true, 'reason' => 'همگام‌سازی خودکار بروزرسانی محصول غیرفعال است.']);
        }

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
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
            if (!$this->ownership->prepareQueuedUpdate((int) $productId)) {
                return $this->success(['product_id' => $productId, 'skipped' => true, 'reason' => 'product_not_connected']);
            }
            $result = $this->productOperations->updateExistProduct($productId, null);
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
