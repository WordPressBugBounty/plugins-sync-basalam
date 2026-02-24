<?php

namespace SyncBasalam\Admin\Announcement;

use SyncBasalam\Admin\Components\AnnouncementComponents;
use SyncBasalam\Services\FetchAnnouncements;

defined('ABSPATH') || exit;

class AnnouncementCenter
{
    public const MARK_SEEN_ACTION = 'sync_basalam_mark_announcements_seen';
    public const FETCH_PAGE_ACTION = 'sync_basalam_fetch_announcements_page';
    private const SEEN_META_KEY = 'sync_basalam_seen_announcements';
    private const PER_PAGE = 5;

    public static function shouldLoadOnCurrentPage(): bool
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page === '') {
            return false;
        }

        if (str_starts_with($page, 'sync_basalam')) {
            return true;
        }

        return in_array($page, ['basalam-onboarding', 'basalam-save-token', 'basalam-show-products'], true);
    }

    public static function renderPanel(): void
    {
        if (!self::shouldLoadOnCurrentPage()) {
            return;
        }

        AnnouncementComponents::renderPanel();
    }

    public static function getConfig(): array
    {
        $result = self::getAnnouncements();
        $seenIds = self::getSeenIds($result['data']);

        return [
            'nonce'          => wp_create_nonce(self::MARK_SEEN_ACTION),
            'fetchPageNonce' => wp_create_nonce(self::FETCH_PAGE_ACTION),
            'markSeenAction' => self::MARK_SEEN_ACTION,
            'fetchPageAction' => self::FETCH_PAGE_ACTION,
            'perPage'        => self::PER_PAGE,
            'totalPage'      => $result['total_page'],
            'seenIds'        => $seenIds,
            'items'          => $result['data'],
        ];
    }

    public static function markAllSeen(): void
    {
        check_ajax_referer(self::MARK_SEEN_ACTION, 'nonce', true);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $result = self::getAnnouncements();
        $allIds = array_values(array_map(static fn($item) => (string) ($item['id'] ?? ''), $result['data']));
        $allIds = array_values(array_filter($allIds));

        update_user_meta(get_current_user_id(), self::SEEN_META_KEY, $allIds);

        wp_send_json_success(['seenIds' => $allIds]);
    }

    public static function fetchPage(): void
    {
        check_ajax_referer(self::FETCH_PAGE_ACTION, 'nonce', true);

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        $page = max(1, $page);

        $result = self::getAnnouncements($page);

        wp_send_json_success([
            'items'      => $result['data'],
            'page'       => $result['page'],
            'totalPage'  => $result['total_page'],
        ]);
    }

    private static function getSeenIds(array $announcements): array
    {
        $seenIds = get_user_meta(get_current_user_id(), self::SEEN_META_KEY, true);

        if (!is_array($seenIds)) {
            return [];
        }

        $validAnnouncementIds = array_values(array_map(static fn($item) => (string) ($item['id'] ?? ''), $announcements));

        return array_values(array_intersect(array_map('strval', $seenIds), $validAnnouncementIds));
    }

    private static function getAnnouncements(int $page = 1): array
    {
        $fetcher = new FetchAnnouncements();
        return $fetcher->fetch($page, self::PER_PAGE);
    }
}
