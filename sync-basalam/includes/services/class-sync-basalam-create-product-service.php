<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Create_Product_Service
{
    private $apiservice;
    public function __construct()
    {
        $this->apiservice = new sync_basalam_External_API_Service;
    }
    public function create_product_in_basalam($product_data, $product_id)
    {
        if (!(sync_basalam_Admin_Asset::is_product($product_id))) {
            throw new \Exception('نوع post محصول نیست.');
            return false;
        }
        $json_data = json_encode($product_data, JSON_UNESCAPED_UNICODE);
        $vendor_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);
        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $url = "https://core.basalam.com/v4/vendors/$vendor_id/products";

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $request = $this->apiservice->send_post_request($url, $json_data, $headers);
        if ($request['status_code'] != 201 && isset($request['status_code'])) {
            if ($request['status_code'] == 401) {
                $data = [
                    sync_basalam_Admin_Settings::TOKEN => '',
                    sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
                ];
                sync_basalam_Admin_Settings::update_settings($data);
            }
            $message = !empty($request['body']['messages'][0]['message']) ? $request['body']['messages'][0]['message'] : $request['body'][0]['message'];
            $field = !empty($request['body']['messages'][0]['fields'][0]) ? $request['body']['messages'][0]['fields'][0] : $request['body'][0]['fields'][0];
            throw new \Exception('فرایند اضافه کردن محصول ناموفق بود: ' . esc_html($message) . ' :   ' . esc_html($field));
            return false;
        }

        if (is_wp_error($request)) {
            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'no');
            throw new \Exception('درخواست موفقیت آمیز نبود: ' . esc_html($request['body'][0]['message']));
            return false;
        }
        if (isset($request['body']['id'])) {
            if (isset($request['body']['variants'])) {
                $product = wc_get_product($product_id);

                if ($product && $product->is_type('variable')) {
                    $wc_variations = [];
                    $attributes = $product->get_attributes();

                    foreach ($product->get_children() as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        $attribute_values = [];

                        foreach ($attributes as $attribute_name => $attribute) {
                            if ($attribute->get_variation()) {
                                $clean_attribute_name = str_replace('attribute_', '', $attribute_name);
                                $value = $variation->get_attribute($clean_attribute_name);
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
                                $attribute_values[] = $property['value']['value'];
                            }
                        }

                        if (!empty($attribute_values)) {
                            $key = implode("_", $attribute_values);
                            $sync_basalam_variations[$key] = $variant['id'];
                        }
                    }

                    $variation_mapping = [];
                    foreach ($wc_variations as $key => $wc_var_id) {
                        if (isset($sync_basalam_variations[$key])) {
                            $variation_mapping[$wc_var_id] = $sync_basalam_variations[$key];
                            update_post_meta($wc_var_id, 'sync_basalam_variation_id', $sync_basalam_variations[$key]);
                        }
                    }
                }
            }

            update_post_meta($product_id, 'sync_basalam_product_id', $request['body']['id']);
            update_post_meta($product_id, 'sync_basalam_product_status', 2976);
            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'ok');
            sync_basalam_Logger::info("محصول با موفقیت در باسلام ایجاد شد.", ['product_id' => $product_id, 'عملیات' => "افزودن محصول جدید در باسلام"]);
            return [
                'success' => true,
                'message' => 'محصول با موفقیت به باسلام اضافه شد.',
                'status_code' => 200
            ];
        }

        throw new \Exception("فرایند اضافه کردن محصول ناموفق بود");
        return false;
    }
}
