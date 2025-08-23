<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Get_Product_data
{

    public function get_sync_basalam_product_data($url, $token, $title = null, $page = 1, $per_page = 100)
    {
        $url = $url;
        if ($title) {
            $url .= '?title=' . $title;
        } else {
            $url .= '?page=' . $page;
            $url .= '&per_page=' . $per_page;
        }

        $apiservice = new sync_basalam_External_API_Service();
        $response = $apiservice->send_get_request($url, [
            'Authorization' => 'Bearer ' . $token
        ]);

        if (isset($response['timeout_error']) && $response['timeout_error'] === true) {
            return [
                'timeout_error' => true,
                'total_page' => 1,
                'products' => []
            ];
        }

        $products = [];

        if (isset($response['data']['data'])) {
            foreach ($response['data']['data'] as $product) {
                $products[] = [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'photo' => $product['photo']['md'],
                    'price' => $product['price']
                ];
            }
        }

        return [
            'total_page' => $response['data']['total_page'] ?? 1,
            'products' => $products
        ];
    }
}
