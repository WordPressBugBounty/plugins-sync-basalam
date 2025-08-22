<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_REST_Controller
{
    /**
     * Register routes
     */
    public static function register_routes()
    {
        register_rest_route(
            'basalam',
            '/v1/new-order',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array('sync_basalam_Create_Order_Service', 'create_order_in_woo'),
                'permission_callback' => array(__CLASS__, 'check_permissions'),
            )
        );
    }

    public static function check_permissions($request)
    {
        sync_basalam_Logger::debug("وب هوک باسلام اطلاعات سفارش جدیدی را ارسال کرد.");
        $webhook_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN);

        $headers = $request->get_headers();

        if (!isset($headers['token'][0])) {
            sync_basalam_Logger::error("سفارش جدیدی در باسلام ثبت شد ، اما توکن ارسال نشد");
            return false;
        }

        $receive_token = sanitize_text_field($headers['token'][0]);
        if ($receive_token === $webhook_token) {
            return true;
        } else {
            $apiservice = new Sync_basalam_External_API_Service();
            $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);

            if (!$token) return false;

            $headers = [
                'Authorization' => 'Bearer ' . $token,
            ];

            $url = 'https://webhook.basalam.com/v1/webhooks?event_ids=5';
            $basalam_webhooks_detail = $apiservice->send_get_request($url, $headers);

            if ($basalam_webhooks_detail['status_code'] == 200 && !empty($basalam_webhooks_detail['data']['data'])) {
                $basalam_webhook_id = $basalam_webhooks_detail['data']['data'][0]['id'];

                $data = [
                    'request_headers' => json_encode(['token' => $webhook_token])
                ];

                $url = "https://webhook.basalam.com/v1/webhooks/$basalam_webhook_id";
                $apiservice->send_patch_request($url, json_encode($data), $headers);
            }

            sync_basalam_Logger::error("سفارش جدیدی در باسلام ایجاد شد اما توکن ارسالی معتبر نیست.");
        }
    }
}
