<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class BasalamAppStoreReview
{
    private string $BasalamAppReviewUrl;
    private ApiServiceManager $apiService;

    public function __construct()
    {
        $this->BasalamAppReviewUrl = "https://apps-api.basalam.com/v1/apps/13/reviews";
        $this->apiService = new ApiServiceManager();
    }

    public function createReview($comment = null)
    {
        if (!$this->verify()) {
            return;
        }

        $body = [
            "comment" => $comment ?? "ممنونم از تیم شما.",
            "rating"  => 5,
        ];

        $response = $this->apiService->sendPostRequest($this->BasalamAppReviewUrl, $body);

        if ($response['status_code'] == 200) {
            update_option('sync_basalam_like', true);
        }
    }

    private function verify(): bool
    {
        return isset($_POST['sync_basalam_support'])
            && $_POST['sync_basalam_support'] == 1
            && isset($_POST['sync_basalam_support_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sync_basalam_support_nonce'])), 'sync_basalam_support_action');
    }
}
