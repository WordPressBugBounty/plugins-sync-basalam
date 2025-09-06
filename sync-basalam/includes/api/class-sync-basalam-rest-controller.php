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
            'sync-basalam',
            '/v1/order-manager',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array('SyncBasalamOrderManger', 'orderManger'),
                'permission_callback' => array(__CLASS__, 'check_permissions'),
            )
        );
    }

    public static function check_permissions($request)
    {
        $webhook_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN);

        $headers = $request->get_headers();

        if (!isset($headers['token'][0])) {
            $webhookService = new Sync_Basalam_Webhook_Service();
            $webhookService->setupWebhooks();
            sync_basalam_Logger::error("سفارش جدیدی در باسلام ثبت شد ، اما توکن ارسال نشد");
            return false;
        }

        $receive_token = sanitize_text_field($headers['token'][0]);

        if ($receive_token === $webhook_token) {
            return true;
        } else {
            $webhookService = new Sync_Basalam_Webhook_Service();
            $webhookService->setupWebhooks();
            sync_basalam_Logger::error("سفارش جدیدی در باسلام ایجاد شد اما توکن ارسالی معتبر نیست.");
            return false;
        }
    }
}
