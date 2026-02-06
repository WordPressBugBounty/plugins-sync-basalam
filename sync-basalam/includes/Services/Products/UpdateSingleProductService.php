<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;

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
        if (!get_post_type($productId) === 'product') throw new \Exception('نوع post محصول نیست.');

        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

        $url = 'https://openapi.basalam.com/v1/products/' . $syncBasalamProductId;

        $request = $this->apiservice->sendPatchRequest($url, $productData);

        $body = $request['body'] ?? '';

        if (is_string($body)) $body = json_decode($body, true);

        if ($request['status_code'] != 200) {
            if ($request['status_code'] == 403) throw new \Exception("این محصول متعلق به غرفه فعلی نیست.");

            if (!is_array($body)) $body = [];

            if (isset($body['messages'][0]['message'])) {
                $message = $body['messages'][0]['message'];
            } elseif (isset($body[0]['message'])) {
                $message = $body[0]['message'];
            } else {
                $message = '';
            }

            if (isset($body['messages'][0]['fields'][0])) {
                $field = $body['messages'][0]['fields'][0];
            } elseif (isset($body[0]['fields'][0])) {
                $field = $body[0]['fields'][0];
            } else {
                $field = '';
            }

            $errorMessage = $message ? esc_html($message) : 'خطایی در بروزرسانی محصول رخ داد.';
            if ($field) $errorMessage .= ' (فیلد: ' . esc_html($field) . ')';

            throw new \Exception($errorMessage);
        }

        if (is_wp_error($request)) {
            $errorMessage = isset($request['body'][0]['message']) ? $request['body'][0]['message'] : 'خطایی در ارتباط با سرور رخ داد.';
            throw new \Exception(esc_html($errorMessage));
        }

        $product = wc_get_product($productId);
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();
            if (isset($body['variants'])) {
                $wcVariations = [];
                $attributes = $product->get_attributes();

                foreach ($variations as $variationId) {
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

        return [
            'success'     => true,
            'message'     => 'فرایند بروزرسانی محصول با موفقیت انجام شد.',
            'status_code' => 200,
        ];
    }

    public function updateProductStatus($productId, $status)
    {
        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
        $url = 'https://openapi.basalam.com/v1/products/' . $syncBasalamProductId;

        $data = ["status" => $status];

        $request = $this->apiservice->sendPatchRequest($url, $data);

        if (!is_wp_error($request)) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');
            update_post_meta($productId, 'sync_basalam_product_status', $status);

            return [
                'success'     => true,
                'message'     => 'وضعیت محصول با موفقیت در باسلام تغییر کرد.',
                'status_code' => 200,
            ];
        }

        throw new \Exception("تغییر وضعیت محصول در باسلام ناموفق بود.");
    }
}
