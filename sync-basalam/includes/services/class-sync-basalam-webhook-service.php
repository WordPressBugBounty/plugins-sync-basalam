<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Webhook_Service
{
    private ?string $token;
    private sync_basalam_External_API_Service $apiService;


    const TARGET_EVENT_IDS = [3, 5, 7];

    public function __construct()
    {
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->apiService = new sync_basalam_External_API_Service();
    }

    public function setupWebhooks()
    {
        if (!$this->token) {
            return false;
        }

        $existingWebhooks = $this->getWebhooks();

        if (!$existingWebhooks || !isset($existingWebhooks['data'])) {
            return false;
        }

        $webhookUrl = sync_basalam_Admin_Settings::get_static_settings("site_url_webhook");

        $webhookWithAllEvents = null;
        $webhooksToDelete = [];

        foreach ($existingWebhooks['data'] as $webhook) {
            if (isset($webhook['events']) && is_array($webhook['events'])) {
                $webhookEventIds = [];
                foreach ($webhook['events'] as $event) {
                    if (isset($event['id'])) {
                        $webhookEventIds[] = $event['id'];
                    }
                }

                // Check if this webhook has any of our target event IDs
                $hasTargetEvents = !empty(array_intersect($webhookEventIds, self::TARGET_EVENT_IDS));

                if ($hasTargetEvents) {
                    // Check if it has all three event IDs
                    $hasAllEvents = count(array_intersect($webhookEventIds, self::TARGET_EVENT_IDS)) == count(self::TARGET_EVENT_IDS);

                    if ($hasAllEvents) {
                        // This webhook has all three events, keep it for update
                        $webhookWithAllEvents = $webhook['id'];
                    } else {
                        // This webhook has some but not all events, mark for deletion
                        $webhooksToDelete[] = $webhook['id'];
                    }
                }
            }
        }

        // Delete webhooks that have some but not all target events
        foreach (array_unique($webhooksToDelete) as $webhookId) {
            $this->deleteWebhook($webhookId);
        }

        if ($webhookWithAllEvents) {
            // Update the existing webhook that has all three events
            $this->updateWebhook($webhookWithAllEvents, self::TARGET_EVENT_IDS, $webhookUrl);
        } else {
            // Create new webhook with all three event IDs
            $this->createWebhook(self::TARGET_EVENT_IDS, $webhookUrl);
        }

        return true;
    }

    private function getWebhooks()
    {

        error_log($this->token);

        $headers = [
            'authorization' => $this->token
        ];

        $response = $this->apiService->send_get_request('https://webhook.basalam.com/v1/webhooks', $headers);

        if ($response && $response['status_code'] == 200) {
            return $response['data'];
        }

        return null;
    }

    private function createWebhook($eventIds, $webhookUrl)
    {
        $webhook_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN);
        $headers = [
            'authorization' => $this->token
        ];

        $data = json_encode([
            'event_ids' => array_values($eventIds),
            'request_headers' => json_encode(['token' => $webhook_token]),
            'request_method' => 'POST',
            'url' => $webhookUrl,
            'is_active' => true,
            'register_me' => true
        ]);

        $response = $this->apiService->send_post_request('https://webhook.basalam.com/v1/webhooks', $data, $headers);

        if ($response && $response['status_code'] == 200) {
            return true;
        } else {
            return false;
        }
    }

    private function updateWebhook($webhookId, $eventIds, $webhookUrl)
    {
        $webhook_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN);

        $headers = [
            'authorization' => $this->token
        ];

        $data = json_encode([
            'event_ids' => array_values($eventIds),
            'request_headers' => json_encode(['token' => $webhook_token]),
            'request_method' => 'POST',
            'url' => $webhookUrl,
            'is_active' => true
        ]);

        $updateUrl = 'https://webhook.basalam.com/v1/webhooks/' . $webhookId;

        $response = $this->apiService->send_patch_request($updateUrl, $data, $headers);

        if ($response && $response['status_code'] == 200) {
            return true;
        } else {
            return false;
        }
    }

    private function deleteWebhook($webhookId)
    {
        $headers = [
            'authorization' => $this->token
        ];

        $deleteUrl = 'https://webhook.basalam.com/v1/webhooks/' . $webhookId;

        $response = $this->apiService->send_delete_request($deleteUrl, $headers);

        if ($response && ($response['status_code'] == 200 || $response['status_code'] == 204)) {
            return true;
        } else {
            return false;
        }
    }
}
