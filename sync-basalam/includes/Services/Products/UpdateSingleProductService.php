<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class UpdateSingleProductService
{
    private $apiservice;

    public function __construct()
    {
        $this->apiservice = new ApiServiceManager();
    }

    public function updateProductInBasalam($productData, $productId)
    {
        if (!get_post_type($productId) === 'product') throw NonRetryableException::invalidData('نوع post محصول نیست.');

        $productData = apply_filters('sync_basalam_product_data_before_update', $productData, $productId);

        do_action('sync_basalam_before_update_product_api', $productId, $productData);

        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

        $url = 'https://openapi.basalam.com/v1/products/' . $syncBasalamProductId;

        try {
            $request = $this->apiservice->sendPatchRequest($url, $productData);
        } catch (\Exception $e) {
            throw new \Exception('خطا در ارتباط با API باسلام: ' . $e->getMessage());
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

            $errorMessage = $message ? esc_html($message) : 'درخواست با خطا مواجه شد.';
            if ($field) $errorMessage .= ' (فیلد: ' . esc_html($field) . ')';

            throw NonRetryableException::permanent($errorMessage);
        }

        if (is_wp_error($request)) throw NonRetryableException::permanent('خطایی در ارتباط با سرور رخ داد.');

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
            }
        }

        update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');

        $result = [
            'success'     => true,
            'message'     => 'فرایند بروزرسانی محصول با موفقیت انجام شد.',
            'status_code' => 200,
        ];

        do_action('sync_basalam_after_update_product_api', $productId, $body, $result);

        return $result;
    }

    public function updateProductStatus($productId, $status)
    {
        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
        $url = 'https://openapi.basalam.com/v1/products/' . $syncBasalamProductId;

        $data = ["status" => $status];

        $data = apply_filters('sync_basalam_product_status_data_before_update', $data, $productId, $status);

        do_action('sync_basalam_before_update_product_status', $productId, $status, $data);

        try {
            $request = $this->apiservice->sendPatchRequest($url, $data);
        } catch (\Exception $e) {
            throw NonRetryableException::permanent($e->getMessage());
        }

        if (!is_wp_error($request)) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');
            update_post_meta($productId, 'sync_basalam_product_status', $status);

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
}
