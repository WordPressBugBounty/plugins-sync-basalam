<?php

namespace SyncBasalam\Utilities;

class TicketUserResolver
{
    private const SYSTEM_USERS = [
        6 => 'پیام سیستمی',
        7 => 'ربات هوش مصنوعی',
    ];

    public static function getLabel(array $user): string
    {
        if (isset(self::SYSTEM_USERS[$user['id']])) return self::SYSTEM_USERS[$user['id']];

        if (!empty($user['is_admin'])) return 'ادمین';    

        return 'شما';
    }
}