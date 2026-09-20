<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Product\Data\Services\VariantService;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Jobs\Exceptions\StaleVariationException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Utilities\ProductMetaKey;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class UpdateSingleProductService
{
    private $apiservice;
    private $variationsService;
    private $variantDataService;

    public function __construct(
        $apiservice = null,
        $variationsService = null,
        $variantDataService = null
    )
    {
        $this->apiservice = $apiservice ?: syncBasalamContainer()->get(ApiServiceManager::class);
        $this->variationsService = $variationsService ?: syncBasalamContainer()->get(UpdateProductVariationsService::class);
        $this->variantDataService = $variantDataService ?: new VariantService();
    }

    public function updateProductInBasalam($productData, $productId)
    {
        if (!get_post_type($productId) === 'product') throw NonRetryableException::invalidData('نوع post محصول نیست.');

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
            throw NonRetryableException::invalidData($vendorSyncPolicy->getRestrictionMessage(false));
        }

        $productData = apply_filters('sync_basalam_product_data_before_update', $productData, $productId);
        $productData = $vendorSyncPolicy->restrictUpdatePayload($productData, false);

        do_action('sync_basalam_before_update_product_api', $productId, $productData);

        $syncBasalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);
        ProductConnection::assertUnique($productId, $syncBasalamProductId);

        // The dedicated variation endpoint is only used in the "custom" update mode with the
        // variation price/stock settings ticked. Every other mode (and every not-yet-connected
        // variation) sends the complete variants section inside one product PATCH, exactly like
        // earlier major versions: no Basalam variation ids in the payload, and the response
        // mapping stores the current ids.
        if ($this->shouldUpdateVariationsSeparately($productId, $productData)) {
            $variationMappingRecovered = false;

            try {
                $this->variationsService->updateVariations($syncBasalamProductId, $productData['variants'], $productId);
            } catch (StaleVariationException $e) {
                // Core v4 returns 404 when Basalam has recreated/replaced a
                // variation but WooCommerce still holds its old id. This is not
                // an error for the user: rebuild a complete variants payload and
                // let the normal product PATCH return the current ids, so the
                // next custom-field update finds fresh variation ids again.
                $productData = $this->prepareVariationRemapPayload($productId, $productData);
                $variationMappingRecovered = true;

                Logger::warning('شناسه‌های قدیمی متغیرهای باسلام شناسایی شد؛ نگاشت متغیرها به‌صورت خودکار بازسازی می‌شود.', [
                    'product_id'                    => $productId,
                    'basalam_product_id'            => $e->getBasalamProductId(),
                    'stale_basalam_variation_id'    => $e->getBasalamVariationId(),
                ]);
            }

            // A stale mapping keeps the rebuilt variants in the product PATCH.
            // The ordinary successful path has already updated each variation,
            // so duplicate price/stock fields must still be removed.
            if (!$variationMappingRecovered) {
                unset($productData['variants'], $productData['primary_price'], $productData['stock']);
            }

            if (!$this->hasProductFieldsToUpdate($productData)) {
                return $this->finishUpdate($productId, [], 'متغیرهای محصول با موفقیت بروزرسانی شدند.');
            }
        }

        // The complete variants section must not carry Basalam variation ids: the API
        // matches variations by their properties and returns the current ids, which
        // are stored after the request. This is exactly the pre-1.10.4 behaviour.
        if (isset($productData['variants']) && is_array($productData['variants'])) {
            foreach ($productData['variants'] as &$variant) {
                if (is_array($variant)) unset($variant['id']);
            }
            unset($variant);
        }

        $url = sprintf(Endpoints::PRODUCT_UPDATE, $syncBasalamProductId);

        $maxDescriptionRetries = 3;
        $descriptionRetry = 0;
        $skuRetry = false;

        while (true) {
            try {
                $request = $this->apiservice->patch($url, $productData);
            } catch (RetryableException $e) {
                if ($this->retryWithoutDuplicateSku($productData, $e, $skuRetry)) {
                    continue;
                }

                throw $e;
            } catch (NonRetryableException $e) {
                if ($this->retryWithoutDuplicateSku($productData, $e, $skuRetry)) {
                    continue;
                }

                if ($descriptionRetry < $maxDescriptionRetries && $this->stripForbiddenDescription($e, $productData, $productId, $descriptionRetry)) {
                    $descriptionRetry++;
                    continue;
                }

                throw $e;
            } catch (\Exception $e) {
                if ($this->retryWithoutDuplicateSku($productData, $e, $skuRetry)) {
                    continue;
                }

                throw new \Exception(esc_html('خطا در ارتباط با API باسلام: ' . $e->getMessage()));
            }

            // Some API adapters return a non-2xx response instead of throwing it.
            if ($this->retryWithoutDuplicateSku($productData, $request, $skuRetry)) {
                continue;
            }

            break;
        }

        $body = $request['body'] ?? '';

        if (is_string($body)) $body = json_decode($body, true);

        if ($request['status_code'] != 200) {
            if ($request['status_code'] == 403) throw NonRetryableException::unauthorized("این محصول متعلق به غرفه فعلی نیست.");

            if (!is_array($body)) $body = [];

            if (isset($body['messages'][0]['message'])) $message = $body['messages'][0]['message'];
            elseif (isset($body[0]['message'])) $message = $body[0]['message'];
            else $message = '';

            if (isset($body['messages'][0]['fields'][0])) $field = $body['messages'][0]['fields'][0];
            elseif (isset($body[0]['fields'][0])) $field = $body[0]['fields'][0];
            else $field = '';

            $errorMessage = $message ?: 'درخواست با خطا مواجه شد.';
            if ($field) $errorMessage .= ' (فیلد: ' . $field . ')';

            throw NonRetryableException::permanent(esc_html($errorMessage));
        }

        if (is_wp_error($request)) throw NonRetryableException::permanent('خطایی در ارتباط با سرور رخ داد.');

        // Basalam may return a successful response with a stale/null product SKU
        // when another product field (most commonly a long description) is
        // present in the same patch.  Send the SKU on its own when the response
        // does not contain the value we requested, so the product-level SKU is
        // not lost for variable products.
        // When the duplicate-SKU fallback was used, deliberately keep the
        // second request SKU-free; do not issue a follow-up SKU-only patch.
        if (!$skuRetry) $this->ensureProductSkuUpdated($url, $productData, $body);

        $product = \wc_get_product($productId);
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();
            if (isset($body['variants'])) {
                $wcVariations = [];
                $attributes = $product->get_attributes();

                foreach ($variations as $variationId) {
                    $variation = \wc_get_product($variationId);
                    $attributeValues = [];

                    foreach ($attributes as $attributeName => $attribute) {
                        if ($attribute->get_variation()) {
                            $cleanAttributeName = str_replace('attribute_', '', $attributeName);
                            $value = $variation->get_attribute($cleanAttributeName);

                            $value = urldecode($value);
                            $value = trim($value);
                            $value = mb_strtolower($value, 'UTF-8');
                            $value = str_replace(['ي', 'ك'], ['ی', 'ک'], $value);
                            $value = str_replace(['-', '_', '–', '—'], ' ', $value);
                            $value = preg_replace('/\s+/', ' ', $value);

                            if (!empty($value)) $attributeValues[] = $value;
                        }
                    }

                    if (!empty($attributeValues)) {
                        $key = implode("_", $attributeValues);
                        $wcVariations[$key] = $variationId;
                    }
                }

                $syncBasalamVariations = [];
                foreach ($body['variants'] as $variant) {
                    $attributeValues = [];
                    if (!empty($variant['properties'])) {
                        foreach ($variant['properties'] as $property) {
                            $val = $property['value']['title'];

                            $val = trim($val);
                            $val = mb_strtolower($val, 'UTF-8');
                            $val = str_replace(['ي', 'ك'], ['ی', 'ک'], $val);
                            $val = str_replace(['-', '_', '–', '—'], ' ', $val);
                            $val = preg_replace('/\s+/', ' ', $val);

                            if (!empty($val)) $attributeValues[] = $val;
                        }
                    }

                    if (!empty($attributeValues)) {
                        $key = implode("_", $attributeValues);
                        $syncBasalamVariations[$key] = $variant['id'];
                    }
                }

                foreach ($wcVariations as $key => $wcVarId) {
                    if (isset($syncBasalamVariations[$key])) {
                        update_post_meta($wcVarId, 'sync_basalam_variation_id', $syncBasalamVariations[$key]);
                    }
                }

                // Some legacy products have a single empty Basalam property
                // value, so neither side produces a usable property key. A
                // one-to-one mapping is unambiguous and safe in that case.
                if (count($variations) === 1 && count($body['variants']) === 1 && !empty($body['variants'][0]['id'])) {
                    update_post_meta($variations[0], 'sync_basalam_variation_id', $body['variants'][0]['id']);
                }
            }
        }

        return $this->finishUpdate($productId, $body, 'فرایند بروزرسانی محصول با موفقیت انجام شد.');
    }

    private function finishUpdate($productId, $body, string $message): array
    {
        update_post_meta($productId, ProductMetaKey::basalamProductSyncStatus(), 'synced');

        $result = [
            'success'     => true,
            'message'     => $message,
            'status_code' => 200,
        ];

        do_action('sync_basalam_after_update_product_api', $productId, $body, $result);

        return $result;
    }

    private function shouldUpdateVariationsSeparately($productId, array $productData): bool
    {
        if (empty($productData['variants']) || !is_array($productData['variants'])) return false;

        $product = \wc_get_product($productId);
        if (!$product || !$product->is_type('variable')) return false;

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);

        // A limited inactive vendor still uses the product endpoint. Its variants
        // payload contains price/stock plus the unchanged properties needed to
        // identify each variant; it must not fall back to one request per stored
        // variation id because those ids can be recreated by Basalam.
        if ($vendorSyncPolicy->shouldRestrictUpdateFields(false)) return false;

        // Only the "custom" mode with the variation price/stock fields ticked uses the
        // dedicated variation endpoint. "All fields" and "price & stock" always send
        // the complete product payload, exactly like earlier major versions.
        $syncFields = SettingsManager::getSettings(SettingsConfig::SYNC_PRODUCT_FIELDS);
        if ($syncFields !== 'custom') return false;

        $syncVariantPrice = SettingsManager::getSettings(SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_PRICE);
        $syncVariantStock = SettingsManager::getSettings(SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_STOCK);
        if ($syncVariantPrice != 1 && $syncVariantStock != 1) return false;

        // A variation that is not connected to Basalam yet must be created through
        // the product payload, not the variation endpoint.
        return UpdateProductVariationsService::allVariantsHaveBasalamId($productData['variants']);
    }

    private function prepareVariationRemapPayload(int $productId, array $productData): array
    {
        $product = \wc_get_product($productId);
        if (!$product || !$product->is_type('variable')) {
            throw NonRetryableException::invalidData('محصول متغیر برای بازسازی نگاشت‌ها یافت نشد.');
        }

        $variants = $this->variantDataService->getVariants($product);
        if (empty($variants)) {
            throw NonRetryableException::invalidData('اطلاعات متغیرهای محصول برای بازسازی نگاشت‌ها کامل نیست.');
        }

        foreach ($variants as &$variant) {
            unset($variant['id']);
        }
        unset($variant);

        // Clear every old id only after the complete replacement payload has
        // been built. If the following API request fails, the next job retries
        // the safe full-product remapping path instead of the stale endpoint.
        foreach ($product->get_children() as $variationId) {
            delete_post_meta($variationId, 'sync_basalam_variation_id');
        }

        $productData['variants'] = $variants;

        return $productData;
    }

    private function hasProductFieldsToUpdate(array $productData): bool
    {
        $identifiers = ['id' => true, 'type' => true];

        return !empty(array_diff_key($productData, $identifiers));
    }

    private function stripForbiddenDescription(NonRetryableException $e, array &$productData, int $productId, int $attempt): bool
    {
        if (!isset($productData['description']) || !is_string($productData['description'])) return false;

        $values = DescriptionErrorSanitizer::extractDescriptionValues($e->getResponseData());
        if (empty($values)) return false;

        $cleaned = DescriptionErrorSanitizer::sanitize($productData['description'], $values);
        if ($cleaned === $productData['description']) return false;

        $productData['description'] = $cleaned;

        return true;
    }

    private function retryWithoutDuplicateSku(array &$productData, $error, bool &$retried): bool
    {
        if ($retried || !ProductSkuRetry::hasSku($productData)) return false;
        if (!ProductSkuRetry::isDuplicateSkuError($error)) return false;

        $productData = ProductSkuRetry::withoutSkus($productData);
        $retried = true;

        return true;
    }

    public function updateProductStatus($productId, $status)
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdateProductStatus()) {
            throw NonRetryableException::invalidData($vendorSyncPolicy->getRestrictionMessage(false));
        }

        $syncBasalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);

        ProductConnection::assertUnique($productId, $syncBasalamProductId);

        $url = sprintf(Endpoints::PRODUCT_UPDATE, $syncBasalamProductId);

        $data = ["status" => $status];

        $data = apply_filters('sync_basalam_product_status_data_before_update', $data, $productId, $status);

        do_action('sync_basalam_before_update_product_status', $productId, $status, $data);

        try {
            $request = $this->apiservice->patch($url, $data);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw NonRetryableException::permanent(esc_html($e->getMessage()));
        }

        if (!is_wp_error($request)) {
            update_post_meta($productId, ProductMetaKey::basalamProductSyncStatus(), 'synced');
            update_post_meta($productId, ProductMetaKey::basalamProductStatus(), $status);

            $result = [
                'success'     => true,
                'message'     => 'وضعیت محصول با موفقیت در باسلام تغییر کرد.',
                'status_code' => 200,
            ];

            do_action('sync_basalam_after_update_product_status', $productId, $status, $result);

            return $result;
        }

        throw NonRetryableException::permanent("تغییر وضعیت محصول در باسلام ناموفق بود.");
    }

    private function ensureProductSkuUpdated(string $url, array $productData, $responseBody): void
    {
        if (!array_key_exists('sku', $productData) || $productData['sku'] === null) return;

        $requestedSku = (string) $productData['sku'];
        $responseSku = null;

        if (is_array($responseBody) && array_key_exists('sku', $responseBody)) {
            $responseSku = $responseBody['sku'];
        }

        if ($responseSku !== null && (string) $responseSku === $requestedSku) return;

        $skuRequest = $this->apiservice->patch($url, ['sku' => $productData['sku']]);
        if (!is_array($skuRequest) || (int) ($skuRequest['status_code'] ?? 0) !== 200) {
            throw NonRetryableException::permanent('بروزرسانی شناسه محصول (SKU) در باسلام ناموفق بود.');
        }
    }
}
