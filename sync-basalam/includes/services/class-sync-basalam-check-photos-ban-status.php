<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Check_Photos_Ban_status
{
    private $token;
    private $url;
    private $apiservice;

    function __construct()
    {
        $this->token = "UXh4RDZnubYHQwGO3Vpqg7UjkTjnFDg3hQw3wlCfGZMdFWfHANatSzmVnxlAEaR8";
        $this->url = sync_basalam_Admin_Settings::get_static_settings('url_hijab_detector');
        $this->apiservice = new sync_basalam_External_API_Service;
    }

    public function check_ban_status($photos)
    {
        $default = ['valid' => [], 'not_valid' => []];

        $data = [
            "images" => $photos
        ];

        try {
            
            $header = [
                "api-token" => $this->token
            ];

            $response = $this->apiservice->send_post_request($this->url, json_encode($data), $header, true, true);

            if (!is_array($response) || !isset($response['body']) || !is_array($response['body'])) {
                foreach ($photos as $file_id) {
                    $default['valid'][] = [
                        'file_id' => $file_id,
                        'is_forbidden' => false
                    ];
                }
                return $default;
            }

            $result = [];

            foreach ($response['body'] as $item) {
                if (isset($item['file_id']) && isset($item['is_forbidden'])) {
                    $result[] = [
                        'file_id' => $item['file_id'],
                        'is_forbidden' => $item['is_forbidden']
                    ];
                }
            }

            foreach ($result as $item) {
                if (!$item['is_forbidden']) {
                    $default['valid'][] = $item;
                } else {
                    $default['not_valid'][] = $item;
                }
            }

            return $default;
        } catch (\Throwable $th) {
            $default['valid'][] = [
                'file_id' => $file_id,
                'is_forbidden' => false
            ];
        }
        return $default;
    }
}
