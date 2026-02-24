<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class CreateSingleProductService
{
    private $apiservice;

    public function __construct()
    {
        $this->apiservice = new ApiServiceManager();
    }

    public function createProductInBasalam($productData, $productId)
    {
        if (!get_post_type($productId) === 'product') {
            throw new \Exception('نوع post محصول نیست.');
        }

        $productData = apply_filters('sync_basalam_product_data_before_create', $productData, $productId);

        do_action('sync_basalam_before_create_product_api', $productId, $productData);

        $vendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);

        $url = "https://openapi.basalam.com/v1/vendors/$vendorId/products";

        try {
            $request = $this->apiservice->sendPostRequest($url, $productData);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        if ($request['status_code'] != 201 && isset($request['status_code'])) {

            $body = $request['body'] ?? '';

            if (is_string($body))
                $responseData = json_decode($body, true);
            else $responseData = $body;

            if (!is_array($responseData)) $responseData = [];

            if ($responseData && isset($responseData['messages'][0])) {
                $message = $responseData['messages'][0]['message'] ?? 'خطای نامشخص';
                $field = $responseData['messages'][0]['fields'][0] ?? '';
            } else {
                $message = 'درخواست با تایم اوت مواجه شد.';
                $field = '';
            }

            $errorMessage = 'فرایند اضافه کردن محصول ناموفق بود: ' . esc_html($message);
            if ($field) $errorMessage .= ' (فیلد: ' . esc_html($field) . ')';

            throw new \Exception($errorMessage);
        }

        if (is_wp_error($request)) {
            $body = $request['body'] ?? '';

            if (is_string($body))
                $responseData = json_decode($body, true);
            else $responseData = $body;

            $message = $responseData[0]['message'] ?? 'خطای نامشخص';
            throw new \Exception('درخواست موفقیت آمیز نبود: ' . esc_html($message));
        }

        $responseData = json_decode($request['body'], true);
        if (isset($responseData['id'])) {
            if (isset($responseData['variants'])) {
                $product = wc_get_product($productId);

                if ($product && $product->is_type('variable')) {
                    $wcVariations = [];
                    $attributes = $product->get_attributes();

                    foreach ($product->get_children() as $variationId) {
                        $variation = wc_get_product($variationId);
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

                                if (!empty($value)) {
                                    $attributeValues[] = $value;
                                }
                            }
                        }

                        if (!empty($attributeValues)) {
                            $key = implode("_", $attributeValues);
                            $wcVariations[$key] = $variationId;
                        }
                    }

                    $syncBasalamVariations = [];
                    foreach ($responseData['variants'] as $variant) {
                        $attributeValues = [];
                        if (!empty($variant['properties'])) {
                            foreach ($variant['properties'] as $property) {
                                $val = trim($property['value']['title']);
                                $val = mb_strtolower($val, 'UTF-8');
                                $val = str_replace(['ي', 'ك'], ['ی', 'ک'], $val);
                                $val = str_replace(['-', '_', '–', '—'], ' ', $val);
                                $val = preg_replace('/\s+/', ' ', $val);
                                if (!empty($val)) {
                                    $attributeValues[] = $val;
                                }
                            }
                        }

                        if (!empty($attributeValues)) {
                            $key = implode("_", $attributeValues);
                            $syncBasalamVariations[$key] = $variant['id'];
                        }
                    }

                    $variationMapping = [];
                    foreach ($wcVariations as $key => $wcVarId) {
                        if (isset($syncBasalamVariations[$key])) {
                            $variationMapping[$wcVarId] = $syncBasalamVariations[$key];
                            update_post_meta($wcVarId, 'sync_basalam_variation_id', $syncBasalamVariations[$key]);
                        }
                    }
                }
            }

            update_post_meta($productId, 'sync_basalam_product_id', $responseData['id']);
            update_post_meta($productId, 'sync_basalam_product_status', 2976);
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');

            $result = [
                'success'     => true,
                'message'     => 'محصول با موفقیت به باسلام اضافه شد.',
                'status_code' => 200,
                'basalam_id'  => $responseData['id'],
            ];

            do_action('sync_basalam_after_create_product_api', $productId, $responseData, $result);

            return $result;
        }

        throw new \Exception("فرایند اضافه کردن محصول ناموفق بود");
    }
}
