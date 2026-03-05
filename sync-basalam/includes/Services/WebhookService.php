<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;
class WebhookService
{
    private $apiService;
    private $basalamToken;
    private $webhookToken;


    public const TARGET_EVENT_IDS = [3, 5, 7];

    public function __construct()
    {
        $this->apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $this->basalamToken = Settings::getSettings(SettingsConfig::TOKEN);
        $this->webhookToken = Settings::getSettings(SettingsConfig::WEBHOOK_HEADER_TOKEN);

        if (!$this->webhookToken) {
            $newToken = Settings::generateToken();
            Settings::updateSettings([SettingsConfig::WEBHOOK_HEADER_TOKEN => $newToken]);
            $this->webhookToken = $newToken;
        }
    }

    public function setupWebhook()
    {
        if (!$this->canCreateWebhook()) return false;

        try {
            $existingWebhooks = $this->fetchWebhooks();
            if (!$existingWebhooks) return false;

            $existingWebhooks = json_decode($existingWebhooks, true);
            if (!is_array($existingWebhooks) || !isset($existingWebhooks['data']) || !is_array($existingWebhooks['data'])) {
                Logger::error('پاسخ نامعتبر از API وبهوک دریافت شد.');
                return false;
            }

            $webhookUrl = get_site_url() . "/wp-json/sync-basalam/v1/order-manager";

            $correctWebhook = null;
            $webhooksMarkedForDeletion = [];

            foreach ($existingWebhooks['data'] as $webhook) {
                if (isset($webhook['events']) && is_array($webhook['events'])) {
                    $webhookEventIds = [];
                    foreach ($webhook['events'] as $event) {
                        if (isset($event['id'])) {
                            $webhookEventIds[] = $event['id'];
                        }
                    }

                    $hasTargetEvents = !empty(array_intersect($webhookEventIds, self::TARGET_EVENT_IDS));

                    if ($hasTargetEvents) {
                        $hasAllEvents = count(array_intersect($webhookEventIds, self::TARGET_EVENT_IDS)) == count(self::TARGET_EVENT_IDS);

                        if ($hasAllEvents) $correctWebhook = $webhook['id'];
                        else $webhooksMarkedForDeletion[] = $webhook['id'];
                    }
                }
            }

            foreach (array_unique($webhooksMarkedForDeletion) as $webhookId) {
                $this->removeCurrentWebhook($webhookId);
            }

            if ($correctWebhook) return $this->updateCurrentWebhook($correctWebhook, self::TARGET_EVENT_IDS, $webhookUrl);

            return $this->createNewWebhook(self::TARGET_EVENT_IDS, $webhookUrl);
        } catch (\Exception $e) {
            Logger::error('خطا در تنظیم وبهوک: ' . $e->getMessage());
            return false;
        }
    }

    private function fetchWebhooks()
    {
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        try {
            $response = $this->apiService->get(Endpoints::WEBHOOKS, $header);
        } catch (\Exception $e) {
            Logger::error('خطا در دریافت لیست وبهوک‌ها: ' . $e->getMessage());
            return null;
        }

        if ($response && $response['status_code'] == 200) return $response['body'];

        return null;
    }

    private function createNewWebhook($eventIds, $webhookUrl)
    {
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        $data = [
            'event_ids'       => array_values($eventIds),
            'request_headers' => json_encode(['token' => $this->webhookToken]),
            'request_method'  => 'POST',
            'url'             => $webhookUrl,
            'is_active'       => true,
            'register_me'     => true,
        ];

        try {
            $response = $this->apiService->post(Endpoints::WEBHOOKS, $data, $header);
        } catch (\Exception $e) {
            Logger::error('خطا در ساخت وبهوک جدید: ' . $e->getMessage());
            return false;
        }

        if ($response && $response['status_code'] == 200) return true;
        else return false;
    }

    private function updateCurrentWebhook($webhookId, $eventIds, $webhookUrl)
    {
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        $data = [
            'event_ids'       => array_values($eventIds),
            'request_headers' => json_encode(['token' => $this->webhookToken]),
            'request_method'  => 'POST',
            'url'             => $webhookUrl,
            'is_active'       => true,
        ];

        $updateWebhookUrl = Endpoints::WEBHOOKS . '/' . $webhookId;

        try {
            $response = $this->apiService->patch($updateWebhookUrl, $data, $header);
        } catch (\Exception $e) {
            Logger::error('خطا در بروزرسانی وبهوک: ' . $e->getMessage());
            return false;
        }

        if ($response && $response['status_code'] == 200) return true;
        else return false;
    }

    private function removeCurrentWebhook($webhookId)
    {
        $deleteWebhookUrl = Endpoints::WEBHOOKS . '/' . $webhookId;
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        try {
            $response = $this->apiService->delete($deleteWebhookUrl, $header);
        } catch (\Exception $e) {
            Logger::error('خطا در حذف وبهوک: ' . $e->getMessage());
            return false;
        }

        if ($response && ($response['status_code'] == 200 || $response['status_code'] == 204)) return true;
        else return false;
    }

    private function canCreateWebhook(): bool
    {
        $siteUrl = get_site_url();

        if (str_contains($siteUrl, 'localhost')) {
            Logger::error("وبهوک برای محیط لوکال تنظیم نمی‌شود.");
            return false;
        }

        return true;
    }
}
