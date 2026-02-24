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

    public function createReview($comment, $rating = 5)
    {
        $rating = intval($rating);
        if ($rating < 1) $rating = 1;
        if ($rating > 5) $rating = 5;

        $body = [
            "comment" => $comment,
            "rating"  => $rating,
        ];

        return $this->apiService->sendPostRequest($this->BasalamAppReviewUrl, $body);
    }
}
