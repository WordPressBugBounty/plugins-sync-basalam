<?php
class Sync_Basalam_Discount_Manager
{
    private $api_service;
    private $url;
    private $token;

    public function __construct()
    {
        $this->api_service = new Sync_basalam_External_API_Service();
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $url_template = sync_basalam_Admin_Settings::get_static_settings("discount_price_url");
        $vendor_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);
        $this->url = str_replace('{vendor_id}', $vendor_id, $url_template);
    }

    public function apply($discount_percent, $product_ids, $variation_ids, $active_days = null)
    {
        if (!$active_days) {
            $active_days = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DISCOUNT_DURATION) ?? 7;
        }

        $data = json_encode([
            'product_filter' => [
                'product_ids'      => $product_ids,
                'variation_ids'    => $variation_ids,
            ],

            'discount_percent' => $discount_percent,
            'active_days'      => $active_days,
        ]);

        $header = [
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $res =  $this->api_service->send_post_request($this->url, $data, $header);
        return $res;
    }

    public function remove($product_ids, $variation_ids)
    {
        $data = json_encode([
            'product_filter' => [
                'product_ids'      => $product_ids,
                'variation_ids'    => $variation_ids,
            ],
        ]);

        $header = [
            'Authorization' => 'Bearer ' . $this->token,
        ];

        $res =  $this->api_service->send_delete_request($this->url, $header, $data);
        return $res;
    }

    public static function calculate_discount_percent($primary_price, $discounted_price)
    {
        if ($primary_price <= 0) {
            return 0;
        }

        $discount_percent = (($primary_price - $discounted_price) / $primary_price) * 100;
        return round($discount_percent);
    }
}
