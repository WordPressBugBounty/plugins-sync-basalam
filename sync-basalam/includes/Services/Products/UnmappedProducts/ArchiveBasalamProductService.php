<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Products\ProductOwnership;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

/**
 * آرشیو محصول باسلام با شناسهٔ خود محصول.
 *
 * مسیر موجود (UpdateSingleProductService::updateProductStatus) به محصول ووکامرسی و
 * متای اتصال وابسته است؛ محصولات این گزارش اصلاً در ووکامرس وجود ندارند.
 */
class ArchiveBasalamProductService
{
    public const STATUS_ARCHIVED = 3790;

    private $api;
    private $ownership;
    private $vendorSyncPolicy;

    public function __construct($api = null, $ownership = null, $vendorSyncPolicy = null)
    {
        $this->api = $api;
        $this->ownership = $ownership;
        $this->vendorSyncPolicy = $vendorSyncPolicy;
    }

    /** @throws \SyncBasalam\Jobs\Exceptions\JobException */
    public function archive(int $basalamProductId): void
    {
        if ($basalamProductId <= 0) {
            throw NonRetryableException::invalidData('شناسهٔ محصول باسلام نامعتبر است.');
        }

        $policy = $this->vendorSyncPolicy ?: syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$policy->canUpdateProductStatus()) {
            throw NonRetryableException::invalidData($policy->getRestrictionMessage(false));
        }

        $api = $this->api ?: syncBasalamContainer()->get(ApiServiceManager::class);

        // محصول متعلق به غرفهٔ دیگر هرگز نباید از اینجا آرشیو شود.
        $ownership = $this->ownership ?: new ProductOwnership($api);
        $ownership->assertOwned($basalamProductId);

        try {
            $response = $api->patch(
                sprintf(Endpoints::PRODUCT_UPDATE, $basalamProductId),
                ['status' => self::STATUS_ARCHIVED]
            );
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw NonRetryableException::permanent(esc_html($e->getMessage()));
        }

        if (is_wp_error($response)) {
            throw NonRetryableException::permanent('آرشیو محصول در باسلام ناموفق بود.');
        }
    }
}
