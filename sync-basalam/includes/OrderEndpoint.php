<?php

namespace SyncBasalam;

use SyncBasalam\Services\WebhookService;
use SyncBasalam\Services\Orders\OrderManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Admin\Settings\SettingsConfig;


defined('ABSPATH') || exit;
class OrderEndpoint
{
    public static function registerRoutes()
    {
        register_rest_route(
            'sync-basalam',
            '/v1/order-manager',
            [
                'methods'             => 'POST',
                'callback'            => [OrderManager::class, 'orderManger'],
                'permission_callback' => [__CLASS__, 'checkPermissions'],
            ]
        );
    }

    public static function checkPermissions($request)
    {
        $webhook_token = syncBasalamSettings()->getSettings(SettingsConfig::WEBHOOK_HEADER_TOKEN);

        $headers = $request->get_headers();

        if (!isset($headers['token'][0])) {
            $webhookService = new WebhookService();
            $webhookService->setupWebhook();
            Logger::error("سفارش جدیدی در باسلام ثبت شد ، اما توکن ارسال نشد");

            return false;
        }

        $receive_token = sanitize_text_field($headers['token'][0]);

        if ($receive_token === $webhook_token) return true; else {
            $webhookService = new WebhookService();
            $webhookService->setupWebhook();
            Logger::error("سفارش جدیدی در باسلام ایجاد شد اما توکن ارسالی معتبر نیست.");

            return false;
        }
    }
}
