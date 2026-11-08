<?php
namespace SyncBasalam\SalamGate\Domain;

use SyncBasalam\SalamGate\Contracts\ShopStatuses;

defined('ABSPATH') || exit;

/** Pure account-state precedence; no hooks, templates or network. */
class AccountStateResolver
{
    public const STATE_NOT_CONNECTED  = 'not_connected';
    public const STATE_LOADING        = 'loading';
    public const STATE_ERROR_NETWORK  = 'error_network';
    public const STATE_ERROR_AUTH     = 'error_auth';
    public const STATE_ERROR_FORBIDDEN = 'error_forbidden';
    public const STATE_NOT_REGISTERED = 'not_registered';
    public const STATE_PENDING        = 'pending';
    public const STATE_REJECTED       = 'rejected';
    public const STATE_INACTIVE       = 'inactive';
    public const STATE_ACTIVE         = 'active';
    public const STATE_DISABLED       = 'disabled';

    public const ALL_STATES = [
        self::STATE_NOT_CONNECTED,
        self::STATE_LOADING,
        self::STATE_ERROR_NETWORK,
        self::STATE_ERROR_AUTH,
        self::STATE_ERROR_FORBIDDEN,
        self::STATE_NOT_REGISTERED,
        self::STATE_PENDING,
        self::STATE_REJECTED,
        self::STATE_INACTIVE,
        self::STATE_ACTIVE,
        self::STATE_DISABLED,
    ];


    public static function stateForShops(array $shops): string
    {
        if ($shops === []) {
            return self::STATE_NOT_REGISTERED;
        }

        $statuses = [];
        foreach ($shops as $shop) {
            $statuses[] = (int) ($shop['status_id'] ?? 0);
        }

        if (in_array(ShopStatuses::ACTIVE, $statuses, true)) {
            return self::STATE_ACTIVE;
        }
        if (in_array(ShopStatuses::PENDING_APPROVAL, $statuses, true)) {
            return self::STATE_PENDING;
        }
        if (in_array(ShopStatuses::INACTIVE, $statuses, true)) {
            return self::STATE_INACTIVE;
        }
        if (in_array(ShopStatuses::REJECTED, $statuses, true)) {
            return self::STATE_REJECTED;
        }

        return self::STATE_ERROR_NETWORK; // وضعیت ناشناخته — محافظه‌کارانه، نه تازه‌وارد
    }
}
