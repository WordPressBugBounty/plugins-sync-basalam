<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;
use SyncBasalam\Registrar\AdminRegistrar;

defined('ABSPATH') || exit;

class VendorSyncPolicy
{
    public const MODE_ACTIVE = 'active';
    public const MODE_INACTIVE_LIMITED = 'inactive_limited';
    public const MODE_INACTIVE_SUSPENDED = 'inactive_suspended';

    public const CRON_HOOK = 'sync_basalam_daily_vendor_status_check';
    public const OPTION_NAME = 'sync_basalam_vendor_sync_state';

    private const CHECK_INTERVAL_SECONDS = 24 * HOUR_IN_SECONDS;
    private const SUSPEND_AFTER_SECONDS = 21 * DAY_IN_SECONDS;
    private const REFRESH_LOCK = 'sync_basalam_vendor_status_refresh_lock';
    private const REFRESH_LOCK_SECONDS = 60;
    private const UNAVAILABLE_STATUS_CODES = [2988, 3199];

    private $vendorInfoService;
    private $clock;

    public function __construct($vendorInfoService = null, $clock = null)
    {
        $this->vendorInfoService = $vendorInfoService ?: new VendorInfoService();
        $this->clock = is_callable($clock) ? $clock : 'time';
    }

    public function registerHooks(): void
    {
        add_action(self::CRON_HOOK, [$this, 'refresh']);
        add_action('init', [$this, 'ensureScheduled']);
        add_action('admin_notices', [$this, 'renderAdminNotice']);
    }

    public function ensureScheduled(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) return;

        wp_schedule_event($this->now() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function getState(bool $refreshIfDue = true): array
    {
        if ($refreshIfDue) return $this->refreshIfDue();

        return $this->getStoredState();
    }

    public function getMode(bool $refreshIfDue = true): string
    {
        return self::resolveMode($this->getState($refreshIfDue), $this->now());
    }

    public function canCreate(bool $refreshIfDue = true): bool
    {
        return $this->getMode($refreshIfDue) === self::MODE_ACTIVE;
    }

    public function canUpdate(bool $refreshIfDue = true): bool
    {
        return $this->getMode($refreshIfDue) !== self::MODE_INACTIVE_SUSPENDED;
    }

    public function canUpdateProductStatus(bool $refreshIfDue = true): bool
    {
        return $this->getMode($refreshIfDue) === self::MODE_ACTIVE;
    }

    public function shouldRestrictUpdateFields(bool $refreshIfDue = true): bool
    {
        return $this->getMode($refreshIfDue) === self::MODE_INACTIVE_LIMITED;
    }

    public function restrictUpdatePayload(array $productData, bool $refreshIfDue = true): array
    {
        return self::restrictUpdatePayloadForMode($productData, $this->getMode($refreshIfDue));
    }

    public static function restrictUpdatePayloadForMode(array $productData, string $mode): array
    {
        if ($mode === self::MODE_ACTIVE) return $productData;
        if ($mode === self::MODE_INACTIVE_SUSPENDED) return [];

        $allowed = [
            'id' => true,
            'type' => true,
            'primary_price' => true,
            'stock' => true,
            'variants' => true,
        ];

        $restricted = array_intersect_key($productData, $allowed);

        if (isset($restricted['variants']) && is_array($restricted['variants'])) {
            $variantFields = [
                'id' => true,
                'primary_price' => true,
                'stock' => true,
            ];

            $restricted['variants'] = array_map(function ($variant) use ($variantFields) {
                return is_array($variant) ? array_intersect_key($variant, $variantFields) : [];
            }, $restricted['variants']);

            $restricted['variants'] = array_values(array_filter(
                $restricted['variants'],
                function ($variant) {
                    return !empty($variant['id']);
                }
            ));

            if (empty($restricted['variants'])) unset($restricted['variants']);
        }

        return $restricted;
    }

    public function getRestrictionMessage(bool $refreshIfDue = true): string
    {
        $mode = $this->getMode($refreshIfDue);

        if ($mode === self::MODE_INACTIVE_LIMITED) {
            return 'غرفه باسلام شما غیرفعال است؛ ایجاد محصول جدید متوقف شده و فقط قیمت و موجودی محصولات بروزرسانی می‌شود.';
        }

        if ($mode === self::MODE_INACTIVE_SUSPENDED) {
            return 'غرفه باسلام شما بیش از ۳ هفته غیرفعال بوده است؛ ایجاد و بروزرسانی محصولات تا فعال‌شدن مجدد غرفه متوقف است.';
        }

        return '';
    }

    public function getFrontendState(bool $refreshIfDue = true): array
    {
        $state = $this->getState($refreshIfDue);
        $mode = self::resolveMode($state, $this->now());
        $inactiveSince = isset($state['inactive_since']) ? (int) $state['inactive_since'] : 0;
        $inactiveSeconds = $inactiveSince > 0 ? max(0, $this->now() - $inactiveSince) : 0;

        return array_merge($state, [
            'mode' => $mode,
            'can_create' => $mode === self::MODE_ACTIVE,
            'can_update' => $mode !== self::MODE_INACTIVE_SUSPENDED,
            'update_fields' => $mode === self::MODE_INACTIVE_LIMITED
                ? ['price', 'stock']
                : ($mode === self::MODE_ACTIVE ? ['all'] : []),
            'inactive_days' => (int) floor($inactiveSeconds / DAY_IN_SECONDS),
            'message' => $this->getRestrictionMessage(false),
        ]);
    }

    public function refreshIfDue(): array
    {
        $vendorId = (int) Settings::getSettings(SettingsConfig::VENDOR_ID);
        $token = Settings::getSettings(SettingsConfig::TOKEN);
        $state = $this->getStoredState();
        $now = $this->now();

        if (!$vendorId || !$token) {
            $state = $this->defaultState($vendorId);
            update_option(self::OPTION_NAME, $state, false);

            return $state;
        }

        if (!self::isRefreshDue($state, $vendorId, $now)) return $state;
        if (get_transient(self::REFRESH_LOCK)) {
            return (int) ($state['vendor_id'] ?? 0) === $vendorId
                ? $state
                : $this->defaultState($vendorId);
        }

        set_transient(self::REFRESH_LOCK, 1, self::REFRESH_LOCK_SECONDS);

        try {
            return $this->refresh();
        } finally {
            delete_transient(self::REFRESH_LOCK);
        }
    }

    public function refresh(): array
    {
        $vendorId = (int) Settings::getSettings(SettingsConfig::VENDOR_ID);
        $token = Settings::getSettings(SettingsConfig::TOKEN);
        $now = $this->now();
        $state = $this->getStoredState();

        if (!$vendorId || !$token) {
            $state = $this->defaultState($vendorId);
            update_option(self::OPTION_NAME, $state, false);

            return $state;
        }

        if ((int) ($state['vendor_id'] ?? 0) !== $vendorId) {
            $state = $this->defaultState($vendorId);
        }

        $vendorInfo = $this->vendorInfoService->FetchVendorInfo();

        if (!is_array($vendorInfo)) {
            $state['checked_at'] = $now;
            $state['last_error_at'] = $now;
            update_option(self::OPTION_NAME, $state, false);

            return $state;
        }

        $state = self::stateFromVendorInfo($state, $vendorInfo, $vendorId, $now);
        update_option(self::OPTION_NAME, $state, false);

        return $state;
    }

    public function renderAdminNotice(): void
    {
        if (!$this->isRelevantAdminScreen()) return;

        $vendorSyncState = $this->getFrontendState();
        if ($vendorSyncState['mode'] === self::MODE_ACTIVE) return;

        $template = syncBasalamPlugin()->templatePath('notifications/VendorInactiveAlert.php');
        if (is_readable($template)) require $template;
    }

    public static function isRefreshDue(array $state, int $vendorId, int $now): bool
    {
        if ((int) ($state['vendor_id'] ?? 0) !== $vendorId) return true;

        $checkedAt = (int) ($state['checked_at'] ?? 0);

        return $checkedAt <= 0
            || $checkedAt > $now
            || ($now - $checkedAt) >= self::CHECK_INTERVAL_SECONDS;
    }

    public static function resolveMode(array $state, int $now): string
    {
        if (array_key_exists('is_active', $state) && self::normalizeBoolean($state['is_active'])) {
            return self::MODE_ACTIVE;
        }

        $inactiveSince = (int) ($state['inactive_since'] ?? 0);
        if ($inactiveSince > 0 && ($now - $inactiveSince) >= self::SUSPEND_AFTER_SECONDS) {
            return self::MODE_INACTIVE_SUSPENDED;
        }

        return self::MODE_INACTIVE_LIMITED;
    }

    public static function stateFromVendorInfo(
        array $previousState,
        array $vendorInfo,
        int $vendorId,
        int $now
    ): array {
        $statusCode = self::extractStatusCode($vendorInfo);
        $statusName = self::extractStatusName($vendorInfo);
        $isActive = self::extractIsActive($vendorInfo, $statusCode, $statusName);
        $wasSameInactiveVendor = (int) ($previousState['vendor_id'] ?? 0) === $vendorId
            && empty($previousState['is_active'])
            && !empty($previousState['inactive_since']);

        return [
            'vendor_id' => $vendorId,
            'is_active' => $isActive,
            'status_code' => $statusCode,
            'status_name' => $statusName,
            'checked_at' => $now,
            'inactive_since' => $isActive
                ? null
                : ($wasSameInactiveVendor ? (int) $previousState['inactive_since'] : $now),
            'last_error_at' => null,
        ];
    }

    private static function extractStatusCode(array $vendorInfo): ?int
    {
        $status = $vendorInfo['status'] ?? null;

        if (is_array($status)) {
            $status = $status['value'] ?? $status['id'] ?? null;
        }

        return is_numeric($status) ? (int) $status : null;
    }

    private static function extractStatusName(array $vendorInfo): string
    {
        $status = $vendorInfo['status'] ?? null;

        if (is_array($status)) {
            return trim((string) ($status['name'] ?? $status['title'] ?? ''));
        }

        return '';
    }

    private static function extractIsActive(array $vendorInfo, ?int $statusCode, string $statusName): bool
    {
        if ($statusCode !== null && in_array($statusCode, self::UNAVAILABLE_STATUS_CODES, true)) {
            return false;
        }

        if (array_key_exists('is_active', $vendorInfo)) {
            return self::normalizeBoolean($vendorInfo['is_active']);
        }

        $normalizedName = function_exists('mb_strtolower')
            ? mb_strtolower($statusName, 'UTF-8')
            : strtolower($statusName);

        foreach (['غیرفعال', 'بسته', 'disabled', 'inactive', 'closed'] as $inactiveLabel) {
            if ($normalizedName !== '' && strpos($normalizedName, $inactiveLabel) !== false) return false;
        }

        return true;
    }

    private static function normalizeBoolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function getStoredState(): array
    {
        $vendorId = (int) Settings::getSettings(SettingsConfig::VENDOR_ID);
        $stored = get_option(self::OPTION_NAME, []);

        return array_merge($this->defaultState($vendorId), is_array($stored) ? $stored : []);
    }

    private function defaultState(int $vendorId): array
    {
        return [
            'vendor_id' => $vendorId,
            'is_active' => true,
            'status_code' => null,
            'status_name' => '',
            'checked_at' => 0,
            'inactive_since' => null,
            'last_error_at' => null,
        ];
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function isRelevantAdminScreen(): bool
    {
        if (method_exists(AdminRegistrar::class, 'isWoosalamRelatedAdminScreen')) {
            return AdminRegistrar::isWoosalamRelatedAdminScreen();
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== '' && strpos($page, 'sync_basalam') === 0) return true;
        if (in_array($page, ['basalam-onboarding', 'basalam-show-products'], true)) return true;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $postType = $screen && isset($screen->post_type) ? (string) $screen->post_type : '';

        return in_array($postType, ['product', 'shop_order'], true);
    }
}
