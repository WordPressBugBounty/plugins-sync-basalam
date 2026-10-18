<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;

if (! defined('ABSPATH')) exit;

/**
 * دسته‌بندی کالای مرسوله (GET /v1/providers/{id}/categories — احرازشده).
 *
 * is_default از میانگین مرسوله‌های «همان کاربر» مشتق می‌شود؛ بنابراین این داده
 * وابسته به حساب است و کش آن per-account است (ConnectionCache) تا میان حساب‌ها
 * به اشتراک گذاشته نشود.
 *
 * «لیست خالی» حالت معتبر است ([]) و با «خطای دریافت» (exception) فرق دارد.
 */
class CategoriesService
{
    public const CACHE_NAME = 'categories';
    public const CACHE_TTL  = 3600;
    public const POST_PROVIDER_ID = 1;

    /**
     * @return array<int, array{id:int, title:string, is_default:bool}>
     * @throws \SyncBasalam\SalamGate\SalamGateException
     */
    public function all(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = ConnectionCache::get(self::CACHE_NAME);
            if ($cached !== null && isset($cached['data']['categories'])) {
                return $cached['data']['categories'];
            }
        }

        $identity = ConnectionCache::identityHash();
        $response = ClientFactory::make()->get(sprintf('/v1/providers/%d/categories', self::POST_PROVIDER_ID));
        $identity = ConnectionCache::responseIdentity($response, $identity);
        ConnectionCache::assertCurrentIdentity($identity);
        $raw = is_array($response['body'] ?? null) ? $response['body'] : [];

        $categories = [];
        foreach ($raw as $category) {
            if (!is_array($category) || !isset($category['id'])) {
                continue;
            }

            $categories[] = [
                'id'         => (int) $category['id'],
                'title'      => (string) ($category['title'] ?? ''),
                'is_default' => !empty($category['is_default']),
            ];
        }

        ConnectionCache::setForIdentity(self::CACHE_NAME, ['categories' => $categories], self::CACHE_TTL, $identity);

        return $categories;
    }

    /**
     * دسته پیش‌فرض کاربر (از is_default پاسخ) یا null.
     */
    public function defaultCategoryId(bool $fresh = false): ?int
    {
        foreach ($this->all($fresh) as $category) {
            if ($category['is_default']) {
                return $category['id'];
            }
        }

        return null;
    }
}
