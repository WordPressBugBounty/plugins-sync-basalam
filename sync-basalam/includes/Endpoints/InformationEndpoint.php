<?php

namespace SyncBasalam\Endpoints;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Api\RequestStatusTracker;
use SyncBasalam\Utilities\DateConverter;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class InformationEndpoint
{
    private const TOKEN_VALIDATION_URL = 'https://api.hamsalam.ir/api/v1/woo-plugin/validate-token';
    private const SENSITIVE_SETTING_KEYS = [
        SettingsConfig::TOKEN,
        SettingsConfig::REFRESH_TOKEN,
        SettingsConfig::HAMSALAM_TOKEN,
        SettingsConfig::WEBHOOK_HEADER_TOKEN,
    ];

    public static function registerRoutes(): void
    {
        register_rest_route(
            'sync-basalam',
            '/v1/plugin-status',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'getPluginStatus'],
                'permission_callback' => [__CLASS__, 'checkPermissions'],
            ]
        );
    }

    public static function checkPermissions($request)
    {
        $token = self::extractRequestToken($request);

        if (empty($token)) {
            return new \WP_Error(
                'sync_basalam_missing_status_token',
                'Token is required.',
                ['status' => 403]
            );
        }

        if (!self::validateStatusEndpointToken($token)) {
            return new \WP_Error(
                'sync_basalam_invalid_status_token',
                'توکن معتبر نیست.',
                ['status' => 403]
            );
        }

        return true;
    }

    public static function getPluginStatus($request)
    {
        global $wp_version;

        $settings = syncBasalamSettings()->getSettings();

        return rest_ensure_response([
            'domain'                => get_site_url(),
            'woosalam_version'      => syncBasalamPlugin()->getVersion(),
            'wordpress_version'     => $wp_version,
            'woocommerce_version'   => defined('WC_VERSION') ? WC_VERSION : null,
            'connection_status'     => !empty($settings[SettingsConfig::TOKEN]),
            'settings'              => self::getPublicSettings($settings),
            'request_status'        => RequestStatusTracker::getSummary(),
            'last_10_log'           => [
                'info'  => self::getLastLogsByLevel('INFO', 10),
                'error' => self::getLastLogsByLevel('ERROR', 10),
            ],
            'category_mapping_list' => CategoryMapping::getCategoryMappings(),
        ]);
    }

    private static function getPublicSettings(array $settings): array
    {
        foreach (self::SENSITIVE_SETTING_KEYS as $settingKey) {
            unset($settings[$settingKey]);
        }

        return $settings;
    }

    private static function extractRequestToken($request): ?string
    {
        $headers = $request->get_headers();

        if (!empty($headers['token'][0])) {
            return sanitize_text_field($headers['token'][0]);
        }

        if (!empty($headers['authorization'][0])) {
            $authorization = sanitize_text_field($headers['authorization'][0]);

            if (stripos($authorization, 'Bearer ') === 0) {
                return trim(substr($authorization, 7));
            }

            return $authorization;
        }

        $paramToken = $request->get_param('token');

        return !empty($paramToken) ? sanitize_text_field($paramToken) : null;
    }

    private static function validateStatusEndpointToken(string $token): bool
    {
        $url = add_query_arg(
            ['token' => $token],
            self::TOKEN_VALIDATION_URL
        );


        $apiServiceManager = syncBasalamContainer()->get(ApiServiceManager::class);

        $response = $apiServiceManager->get($url);

        if ($response['success'] == true) {
            return true;
        }

        return false;
    }


    private static function getLastLogsByLevel(string $targetLevel, int $limit = 10): array
    {
        $uploadDir = wp_upload_dir();
        $logDir = trailingslashit($uploadDir['basedir']) . 'wc-logs/';
        $logFiles = glob($logDir . 'basalam-sync-plugin*.log');

        if (empty($logFiles)) {
            return [];
        }

        usort($logFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $logs = [];

        foreach ($logFiles as $logFile) {
            if (!is_readable($logFile)) {
                continue;
            }

            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach (array_reverse($lines) as $line) {
                if (!preg_match(
                    '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}) (INFO|WARNING|ERROR|DEBUG|ALERT) (.*?)( CONTEXT: (.*))?$/',
                    $line,
                    $matches
                )) {
                    continue;
                }

                if ($matches[2] !== $targetLevel) {
                    continue;
                }

                $logs[] = [
                    'date'    => DateConverter::utcToJalaliDateTime($matches[1]),
                    'level'   => strtolower($matches[2]),
                    'message' => $matches[3],
                    'context' => isset($matches[5]) ? json_decode($matches[5], true) : null,
                ];

                if (count($logs) >= $limit) {
                    return $logs;
                }
            }
        }

        return $logs;
    }
}
