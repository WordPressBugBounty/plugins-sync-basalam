<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class PostAutoConfirmOrder
{
    private $url;
    private $apisevice;

    public function __construct()
    {
        $this->url = Endpoints::ORDER_AUTO_CONFIRM_CONFIG;
        $this->apisevice = syncBasalamContainer()->get(ApiServiceManager::class);
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

        try {
            $response = $this->apisevice->put($this->url, $data);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در تنظیم تایید خودکار: ' . $e->getMessage(),
                'status_code' => 500,
            ];
        }

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
