<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class FetchProductsData
{
    private $url;
    public function __construct()
    {
        $vendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
        $this->url = "https://openapi.basalam.com/v1/vendors/$vendorId/products";
    }

    public function getProductData($title = null, $page = 1, $perPage = 100)
    {
        if ($title) {
            $this->url .= '?title=' . $title;
        } else {
            $this->url .= '?page=' . $page;
            $this->url .= '&per_page=' . $perPage;
        }

        $apiservice = new ApiServiceManager();
        $response = $apiservice->sendGetRequest($this->url);

        $products = [];

        if (!empty($response['body'])) $bodyData = json_decode($response['body'], true);

        if (isset($bodyData['data'])) {
            foreach ($bodyData['data'] as $product) {
                $products[] = [
                    'id'    => $product['id'],
                    'title' => $product['title'],
                    'photo' => $product['photo']['md'],
                    'price' => $product['price'],
                ];
            }
        }

        return [
            'total_page' => $bodyData['total_page'] ?? 1,
            'products'   => $products,
        ];
    }
}
