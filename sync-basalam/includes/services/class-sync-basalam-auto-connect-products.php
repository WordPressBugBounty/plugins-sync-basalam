<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Auto_Connect_Products
{
    private $token;
    private $url;

    function __construct()
    {
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->url = sync_basalam_Admin_Settings::get_static_settings("url_get_all_sync_basalam_products");
    }

    private function normalize_title($title)
    {
        $title = sync_basalam_Convert_Fa_Num::convert_numbers_to_english($title);
        return mb_strtolower($title);
    }

    public function check_same_product($title = null, $page = 1)
    {
        try {
            $get_sync_basalam_product_data = new sync_basalam_Get_Product_data();
            if ($title) {
                $title = mb_substr($title, 0, 120);
                $sync_basalam_products = $get_sync_basalam_product_data->get_sync_basalam_product_data($this->url, $this->token, $title);
            } else {
                $sync_basalam_products = $get_sync_basalam_product_data->get_sync_basalam_product_data($this->url, $this->token, null, $page);
            }

            if ($title) {
                return $sync_basalam_products['products'];
            }

            global $wpdb;
            $matched_products = [];

            foreach ($sync_basalam_products['products'] as $sync_basalam_product) {
                $normalized_title = $this->normalize_title($sync_basalam_product['title']);

                if (mb_strlen($normalized_title) >= 120) {
                    $like_title = $normalized_title . '%';
                } else {
                    $like_title =  $normalized_title;
                }

                $product_id = $wpdb->get_var(
                    $wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sync_basalam_product_id'
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND pm.post_id IS NULL
                    AND LOWER(p.post_title) LIKE LOWER(%s)
                    LIMIT 1
                ", $like_title)
                );

                if ($product_id) {
                    $connect_product_service = new Sync_basalam_connect_product_service;
                    $result = $connect_product_service->connect_product_by_id($product_id, $sync_basalam_product['id']);
                    if ($result) {
                        sync_basalam_Logger::info($sync_basalam_product['title'] . ' به محصول مشابه خود در باسلام متصل شد', [
                            'product_id' => $product_id,
                            'عملیات' => "اتصال اتوماتیک محصولات ووکامرس و باسلام"
                        ]);
                    }
                    $matched_products[] = $sync_basalam_product;
                }
            }

            if (!empty($sync_basalam_products['total_page']) && is_numeric($sync_basalam_products['total_page'])) {
                update_option('sync_basalam_auto_connect_all_pages', $sync_basalam_products['total_page']);
            } else {
                $current = get_option('sync_basalam_auto_connect_last_page_checked', 1);
                update_option('sync_basalam_auto_connect_all_pages', $current + 10);
            }

            $total_page = get_option('sync_basalam_auto_connect_all_pages');

            if ($page < $total_page) {
                return [
                    'success' => true,
                    'message' => 'محصولات با موفقیت به صف اتصال افزوده شدند.',
                    'status_code' => 200
                ];
            } else {
                if (!empty($matched_products)) {
                    return [
                        'success' => true,
                        'message' => 'اتصال محصولات کامل شد.',
                        'status_code' => 200
                    ];
                } else {
                    return [
                        'error' => true,
                        'message' => 'محصول مشابهی یافت نشد.',
                        'status_code' => 404
                    ];
                }
            }
        } catch (Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'status_code' => 400
            ];
        }
    }
}
