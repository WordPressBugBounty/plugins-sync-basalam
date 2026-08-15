<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class UpdateProductVariationsService
{
    /** Variant fields that are sent to the single-variation endpoint. */
    private const VARIATION_FIELDS = ['primary_price', 'stock'];

    private $apiservice;

    public function __construct()
    {
        $this->apiservice = syncBasalamContainer()->get(ApiServiceManager::class);
    }

    /**
     * True when every variant already has a Basalam variation id, so each one can be
     * updated with its own request instead of being sent inside the product payload.
     */
    public static function allVariantsHaveBasalamId(array $variants): bool
    {
        if (empty($variants)) return false;

        foreach ($variants as $variant) {
            if (empty($variant['id'])) return false;
        }

        return true;
    }

    /**
     * Sends one PATCH request per variation.
     *
     * @return array{updated: int, skipped: int, failed: int}
     */
    public function updateVariations($basalamProductId, array $variants, $productId = null): array
    {
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $firstError = null;

        foreach ($variants as $variant) {
            $data = array_intersect_key($variant, array_flip(self::VARIATION_FIELDS));
            $data = array_filter($data, fn($value) => $value !== null);

            if (empty($data)) {
                $skipped++;
                continue;
            }

            $url = sprintf(Endpoints::PRODUCT_VARIATION_UPDATE, $basalamProductId, $variant['id']);

            try {
                $this->apiservice->patch($url, $data);
                $updated++;
            } catch (RetryableException $e) {
                throw $e;
            } catch (\Exception $e) {
                $failed++;
                $firstError = $firstError ?: $e->getMessage();

                Logger::error('خطا در بروزرسانی متغیر محصول در باسلام: ' . $e->getMessage(), [
                    'operation'            => 'بروزرسانی متغیر محصول',
                    'product_id'           => $productId,
                    'basalam_product_id'   => $basalamProductId,
                    'basalam_variation_id' => $variant['id'],
                ]);
            }
        }

        // Every variation failed with a permanent error: report it instead of pretending success.
        if ($updated === 0 && $failed > 0) {
            throw NonRetryableException::permanent(esc_html('بروزرسانی متغیرهای محصول ناموفق بود: ' . $firstError));
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }
}
