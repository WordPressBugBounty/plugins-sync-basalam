<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * نرمال‌سازی پاسخ فروشگاه سلام‌گیت (ShopResponse / RegisterShopResponse).
 *
 * تفاوت‌های مهم شکل پاسخ:
 * - GET /v1/shops → status شامل color/icon هم هست.
 * - POST /v1/shops/register (async) → identifier=null، status فقط {id,title}،
 *   و «id» در واقع شناسه صف است نه غرفه تأییدشده — نباید provider_shop_id تلقی شود.
 */
final class ShopNormalizer
{
    /**
     * @return array{
     *   id: int, identifier: ?string, status_id: int, status_title: string,
     *   status_color: ?string, created_at: ?string, mobile: ?string,
     *   address: ?array, provider: ?array, provider_node: ?array,
     *   queued: bool
     * }
     */
    public static function normalize(array $shop): array
    {
        $statusId = (int) ($shop['status']['id'] ?? 0);
        $identifier = isset($shop['identifier']) && $shop['identifier'] !== null && $shop['identifier'] !== ''
            ? (string) $shop['identifier']
            : null;

        return [
            'id'             => (int) ($shop['id'] ?? 0),
            'owner_user_id'   => isset($shop['user']['id']) ? (int) $shop['user']['id'] : null,
            'identifier'     => $identifier,
            'status_id'      => $statusId,
            'status_title'   => (string) ($shop['status']['title'] ?? ShopStatuses::title($statusId)),
            'status_color'   => isset($shop['status']['color']) ? (string) $shop['status']['color'] : null,
            'created_at'     => isset($shop['created_at']) ? (string) $shop['created_at'] : null,
            'mobile'         => isset($shop['user']['mobile']) ? (string) $shop['user']['mobile'] : null,
            'address'        => is_array($shop['address'] ?? null) ? $shop['address'] : null,
            'provider'       => is_array($shop['provider'] ?? null) ? $shop['provider'] : null,
            'provider_node'  => is_array($shop['provider_node'] ?? null) ? $shop['provider_node'] : null,
            // پاسخ صفِ ثبت async: بدون identifier و در انتظار تأیید — id آن شناسه غرفه نیست.
            'queued'         => $identifier === null && $statusId === ShopStatuses::PENDING_APPROVAL,
        ];
    }

    /**
     * @param array<int, array> $shops
     * @return array<int, array>
     */
    public static function normalizeList(array $shops): array
    {
        $normalized = [];
        foreach ($shops as $shop) {
            if (is_array($shop)) {
                $normalized[] = self::normalize($shop);
            }
        }

        return $normalized;
    }
}
