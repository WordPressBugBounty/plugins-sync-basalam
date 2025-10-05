<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Product_Service
{
    private $apiservice;

    public function __construct()
    {
        $this->apiservice = new sync_basalam_External_API_Service;
    }

    public function update_product_in_basalam($product_data, $product_id)
    {
        $operation = "بروزرسانی محصول باسلام";

        if (!(sync_basalam_Admin_Asset::is_product($product_id))) {
            throw new \Exception('نوع post محصول نیست.');
            return false;
        }

        $json_data = json_encode($product_data, JSON_UNESCAPED_UNICODE);
        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
        $url = 'https://core.basalam.com/v4/products/' . $sync_basalam_product_id;

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $request = $this->apiservice->send_patch_request($url, $json_data, $headers);

        $status_code = $request['status_code'];

        if ($status_code != 200) {
            if ($status_code == 401) {
                $data = [
                    sync_basalam_Admin_Settings::TOKEN => '',
                    sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
                ];
                sync_basalam_Admin_Settings::update_settings($data);
            }

            $body = $request['body'] ?? [];

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

            throw new \Exception("بروزرسانی محصول ناموفق بود :" . esc_html($message) . ' ' . esc_html($field));
        }

        if (is_wp_error($request)) {
            throw new \Exception("بروزرسانی محصول ناموفق بود :" . esc_html($request['body'][0]['message']));
        }

        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();

            if (isset($request['body']['variants'])) {
                $wc_variations = [];
                $attributes = $product->get_attributes();

                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    $attribute_values = [];

                    foreach ($attributes as $attribute_name => $attribute) {
                        if ($attribute->get_variation()) {
                            $clean_attribute_name = str_replace('attribute_', '', $attribute_name);
                            $value = $variation->get_attribute($clean_attribute_name);

                            $value = urldecode($value);
                            $value = trim(mb_strtolower($value, 'UTF-8'));
                            $value = str_replace(['ي', 'ك'], ['ی', 'ک'], $value);

                            if (!empty($value)) {
                                $attribute_values[] = $value;
                            }
                        }
                    }

                    if (!empty($attribute_values)) {
                        $key = implode("_", $attribute_values);
                        $wc_variations[$key] = $variation_id;
                    }
                }

                $sync_basalam_variations = [];
                foreach ($request['body']['variants'] as $variant) {
                    $attribute_values = [];
                    if (!empty($variant['properties'])) {
                        foreach ($variant['properties'] as $property) {
                            $val = trim(mb_strtolower($property['value']['value'], 'UTF-8'));
                            $val = str_replace(['ي', 'ك'], ['ی', 'ک'], $val);
                            if (!empty($val)) {
                                $attribute_values[] = $val;
                            }
                        }
                    }

                    if (!empty($attribute_values)) {
                        $key = implode("_", $attribute_values);
                        $sync_basalam_variations[$key] = $variant['id'];
                    }
                }

                foreach ($wc_variations as $key => $wc_var_id) {
                    if (isset($sync_basalam_variations[$key])) {
                        update_post_meta($wc_var_id, 'sync_basalam_variation_id', $sync_basalam_variations[$key]);
                    }
                }
            }
        }

        update_post_meta($product_id, 'sync_basalam_product_sync_status', 'ok');
        sync_basalam_Logger::info("محصول با موفقیت بروزرسانی شد.", ['product_id' => $product_id, 'عملیات' => $operation]);

        return [
            'success' => true,
            'message' => 'فرایند به روزرسانی محصول با موفقیت انجام شد.',
            'status_code' => 200
        ];
    }

    public function update_product_status($product_id, $status)
    {
        $operation = "بروزرسانی وضعیت محصول";
        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
        $url = 'https://core.basalam.com/v4/products/' . $sync_basalam_product_id;

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $data = ["status" => $status];
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);

        $request = $this->apiservice->send_patch_request($url, $json_data, $headers);

        if (isset($request['status']) && $request['status'] == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
        }

        if (!is_wp_error($request)) {
            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'ok');
            update_post_meta($product_id, 'sync_basalam_product_status', $status);
            sync_basalam_Logger::info("وضعیت محصول با موفقیت در باسلام تغییر کرد.", ['product_id' => $product_id, 'عملیات' => $operation]);
            return [
                'success' => true,
                'message' => 'وضعیت محصول با موفقیت در باسلام تغییر کرد.',
                'status_code' => 200
            ];
        }
        throw new \Exception("تغییر وضعیت محصول در باسلام ناموفق بود.");
    }
}
