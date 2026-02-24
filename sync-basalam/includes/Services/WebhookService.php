<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;
class WebhookService
{
    private ApiServiceManager $apiService;
    private $basalamToken;
    private $webhookToken;


    public const TARGET_EVENT_IDS = [3, 5, 7];

    public function __construct()
    {
        $this->apiService = new ApiServiceManager();
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
        
        $existingWebhooks = $this->fetchWebhooks();
        $existingWebhooks = json_decode($existingWebhooks, true);

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

        if ($correctWebhook) $this->updateCurrentWebhook($correctWebhook, self::TARGET_EVENT_IDS, $webhookUrl);

        else $this->createNewWebhook(self::TARGET_EVENT_IDS, $webhookUrl);

        return true;
    }

    private function fetchWebhooks()
    {
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        $response = $this->apiService->sendGetRequest('https://openapi.basalam.com/v1/webhooks', $header);

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

        $response = $this->apiService->sendPostRequest('https://openapi.basalam.com/v1/webhooks', $data, $header);

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

        $updateWebhookUrl = 'https://openapi.basalam.com/v1/webhooks/' . $webhookId;

        $response = $this->apiService->sendPatchRequest($updateWebhookUrl, $data, $header);

        if ($response && $response['status_code'] == 200) return true;
        else return false;
    }

    private function removeCurrentWebhook($webhookId)
    {
        $deleteWebhookUrl = 'https://openapi.basalam.com/v1/webhooks/' . $webhookId;
        $header = ['Authorization' => 'Bearer ' . $this->basalamToken];

        $response = $this->apiService->sendDeleteRequest($deleteWebhookUrl, $header);

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
