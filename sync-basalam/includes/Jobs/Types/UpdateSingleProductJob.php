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
use SyncBasalam\Services\Products\ProductPriceStockRetry;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Admin\Product\Data\ProductDataBuilder;

defined('ABSPATH') || exit;

class UpdateSingleProductJob extends AbstractJobType
{
    private $productOperations;
    private $ownership;
    private $priceStockUpdateService;

    public function __construct($jobManager, $productOperations, $ownership = null, $priceStockUpdateService = null)
    {
        parent::__construct($jobManager);
        $this->productOperations = $productOperations;
        $this->ownership = $ownership ?: new ProductOwnership();
        $this->priceStockUpdateService = $priceStockUpdateService;
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
            if (!empty($payload['price_stock_only']) && $product->get_status() !== 'publish') {
                throw NonRetryableException::invalidData('محصول منتشرنشده برای بروزرسانی قیمت و موجودی قابل ارسال نیست.');
            }
            if (!$this->ownership->prepareQueuedUpdate((int) $productId)) {
                return $this->success(['product_id' => $productId, 'skipped' => true, 'reason' => 'product_not_connected']);
            }
            $result = !empty($payload['price_stock_only'])
                ? $this->updatePriceStock((int) $productId)
                : $this->productOperations->updateExistProduct($productId, null);
            if (empty($result['success'])) {
                $status = (int) ($result['status_code'] ?? 0);
                $message = (string) ($result['message'] ?? 'بروزرسانی محصول ناموفق بود.');
                if ($status >= 500 || in_array($status, [408, 425, 429], true)) throw new RetryableException($message, $status);
                throw new NonRetryableException($message, $status);
            }
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

    /** Retry a bulk price/stock item using current Woo values, independent of custom field settings. */
    private function updatePriceStock(int $productId): array
    {
        $factory = new ProductDataFactory();
        $data = (new ProductDataBuilder(null, $factory))
            ->setStrategy($factory->createStrategy('quick_update'))
            ->fromWooProduct($productId)
            ->build();
        $service = $this->priceStockUpdateService ?: syncBasalamContainer()->get(UpdateSingleProductService::class);
        return $service->updateProductInBasalam(ProductPriceStockRetry::onlyPriceStock($data), $productId);
    }
}
