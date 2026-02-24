<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class FetchProductsData
{
    private $baseUrl;
    private $vendorId;

    public function __construct()
    {
        $this->vendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
        $this->baseUrl = 'https://core.basalam.com/v4/products';
    }

    public function getProductData($title = null, $cursor = null)
    {
        $query = ['per_page' => 30];

        if (!empty($this->vendorId)) $query['vendor_ids'] = $this->vendorId;
        if (!empty($title)) $query['product_title'] = $title;

        if ($cursor !== null) $query['cursor'] = $cursor;

        $url = $this->baseUrl . '?' . http_build_query($query);

        $apiservice = new ApiServiceManager();

        try {
            $response = $apiservice->sendGetRequest($url);
        } catch (\Exception $e) {
            Logger::error('خطا در دریافت اطلاعات محصولات از باسلام: ' . $e->getMessage());
            return [
                'data'        => [],
                'has_more'    => false,
                'next_cursor' => null,
            ];
        }

        $bodyData = [];

        if (!empty($response['body'])) $bodyData = json_decode($response['body'], true);

        $data = isset($bodyData['data']) && is_array($bodyData['data']) ? $bodyData['data'] : [];

        $nextCursor = $bodyData['next_cursor'];

        return [
            'data'        => $data,
            'has_more'    => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ];
    }
}
