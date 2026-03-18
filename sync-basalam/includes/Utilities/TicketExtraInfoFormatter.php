<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

class TicketExtraInfoFormatter
{
    public static function appendFromRequest(string $content, array $request): string
    {
        $dashboardLines = self::collectDashboardLines($request);
        $hostPanelLines = self::collectHostPanelLines($request);

        if (empty($dashboardLines) && empty($hostPanelLines)) {
            return trim($content);
        }

        $sections = [trim($content)];

        if (!empty($dashboardLines)) {
            $sections[] = "اطلاعات پیشخوان:\n" . implode("\n", $dashboardLines);
        }

        if (!empty($hostPanelLines)) {
            $sections[] = "کنترل پنل هاست:\n" . implode("\n", $hostPanelLines);
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    private static function collectDashboardLines(array $request): array
    {
        $lines = [];

        $dashboardLoginUrl = self::sanitize($request['dashboard_login_url'] ?? '');
        $dashboardUsername = self::sanitize($request['dashboard_username'] ?? '');
        $dashboardPassword = self::sanitize($request['dashboard_password'] ?? '');

        if ($dashboardLoginUrl !== '') $lines[] = 'آدرس لاگین پیشخوان: ' . $dashboardLoginUrl;
        if ($dashboardUsername !== '') $lines[] = 'نام کاربری پیشخوان: ' . $dashboardUsername;
        if ($dashboardPassword !== '') $lines[] = 'رمز عبور پیشخوان: ' . $dashboardPassword;

        return $lines;
    }

    private static function collectHostPanelLines(array $request): array
    {
        $lines = [];

        $hostPanelLoginUrl = self::sanitize($request['host_panel_login_url'] ?? '');
        $hostPanelUsername = self::sanitize($request['host_panel_username'] ?? '');
        $hostPanelPassword = self::sanitize($request['host_panel_password'] ?? '');

        if ($hostPanelLoginUrl !== '') $lines[] = 'آدرس لاگین کنترل پنل هاست: ' . $hostPanelLoginUrl;
        if ($hostPanelUsername !== '') $lines[] = 'نام کاربری کنترل پنل هاست: ' . $hostPanelUsername;
        if ($hostPanelPassword !== '') $lines[] = 'رمز عبور کنترل پنل هاست: ' . $hostPanelPassword;

        return $lines;
    }

    private static function sanitize($value): string
    {
        return sanitize_text_field(wp_unslash((string) $value));
    }
}
