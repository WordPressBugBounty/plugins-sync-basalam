<?php

namespace SyncBasalam\Services;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class BasalamAppStoreReview
{
    private string $BasalamAppReviewUrl;
    private $apiService;

    public function __construct()
    {
        $this->BasalamAppReviewUrl = Endpoints::APP_REVIEW;
        $this->apiService = syncBasalamContainer()->get(ApiServiceManager::class);
    }

    public function createReview($comment, $rating = 5)
    {
        $rating = intval($rating);
        if ($rating < 1) $rating = 1;
        if ($rating > 5) $rating = 5;

        $body = [
            "comment" => $comment,
            "rating"  => $rating,
        ];

        try {
            return $this->apiService->post($this->BasalamAppReviewUrl, $body);
        } catch (\Exception $e) {
            Logger::error('خطا در ثبت نظر افزونه: ' . $e->getMessage());
            return [
                'status_code' => 500,
                'body' => null,
                'error' => 'خطا در ثبت نظر: ' . $e->getMessage(),
            ];
        }
    }
}
