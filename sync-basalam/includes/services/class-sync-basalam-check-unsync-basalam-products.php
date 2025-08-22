<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Check_Unsync_Basalam_Products
{
    private $get_sync_basalam_products_service;
    private $url;
    private $token;

    public function __construct()
    {
        $this->get_sync_basalam_products_service = new sync_basalam_Get_Product_data();
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->url = sync_basalam_Admin_Settings::get_static_settings("url_get_all_sync_basalam_products");
    }

    public function get_unsync_basalam_products($page)
    {
        $product_data = $this->get_sync_basalam_products_service->get_sync_basalam_product_data($this->url, $this->token, null, $page);

        if (empty($product_data['products'])) {
            return [];
        }

        $products = [];
        foreach ($product_data['products'] as $product) {
            if (!get_posts([
                'post_type'   => 'product',
                'meta_key'    => 'sync_basalam_product_id',
                'meta_value'  => $product['id'],
                'fields'      => 'ids'
            ])) {
                $products[] = $product;
            }
        }

        if (empty($products)) {
            return $this->get_unsync_basalam_products($page + 1);
        }

        return $products;
    }
}
