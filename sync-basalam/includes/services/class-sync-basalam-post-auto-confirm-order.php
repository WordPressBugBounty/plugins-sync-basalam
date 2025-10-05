<?php
if (! defined('ABSPATH')) exit;
class SyncBasalamPostAutoConfirmOrder
{
    private $url;
    private $apisevice;
    private $token;
    public function __construct()
    {
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->url = sync_basalam_Admin_Settings::get_static_settings("auto_confirm_order_url");
        $this->apisevice = new Sync_basalam_External_API_Service();
    }

    public function post_auto_confirm_order($is_active = true, $key = 6392)
    {
        $data = json_encode([
            [
                "title" => "تایید خودکار همه سفارش ها",
                "key" => $key,
                "is_active" => $is_active,
                "rules" => null
            ]
        ], JSON_UNESCAPED_UNICODE);

        $header = [
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $response = $this->apisevice->send_put_request($this->url, $data, $header);
        var_dump($response);
        if ($response['status_code'] == 200) {
            return [
                'success' => true,
                'message' =>  'تایید خودکار سفارشات با موفقیت فعال شد.',
                'status_code' => $response['status_code'] ?? 200
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'خطایی در فعال سازی تایید خودکار سفارشات رخ داده است.',
            'status_code' => $response['status_code'] ?? 500
        ];
    }
}
