<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class PostAutoConfirmOrder
{
    private $url;
    private $apisevice;

    public function __construct()
    {
        $this->url = "https://order-processing.basalam.com/v1/vendor/automation-config";
        $this->apisevice = new ApiServiceManager();
    }

    public function postAutoConfirmOrder($isActive = true, $key = 6392)
    {
        $data = [
            [
                "title"     => "تایید خودکار همه سفارش ها",
                "key"       => $key,
                "is_active" => $isActive,
                "rules"     => null,
            ],
        ];

        $response = $this->apisevice->sendPutRequest($this->url, $data);

        if ($response['status_code'] == 200) {
            return [
                'success'     => true,
                'message'     => 'تایید خودکار سفارشات با موفقیت فعال شد.',
                'status_code' => $response['status_code'] ?? 200,
            ];
        }

        return [
            'success'     => false,
            'message'     => $response['message'] ?? 'خطایی در فعال سازی تایید خودکار سفارشات رخ داده است.',
            'status_code' => $response['status_code'] ?? 500,
        ];
    }
}
