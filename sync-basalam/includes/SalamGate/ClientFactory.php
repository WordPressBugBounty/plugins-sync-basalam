<?php

namespace SyncBasalam\SalamGate;

defined('ABSPATH') || exit;

/**
 * فکتوری کلاینت سلام‌گیت — نقطه تزریق برای تست‌ها.
 */
class ClientFactory
{
    /**
     * برای تست‌ها: تابعی که کلاینت فیک برمی‌گرداند.
     *
     * @var \Closure|null
     */
    public static $resolver = null;

    private static ?SalamGateClient $client = null;

    public static function make(): SalamGateClient
    {
        if (self::$resolver !== null) {
            $client = (self::$resolver)();

            if ($client instanceof SalamGateClient) {
                return $client;
            }
        }

        if (self::$client === null) {
            self::$client = new SalamGateClient();
        }

        return self::$client;
    }

    /**
     * فقط برای تست‌ها: ریست کردن singleton.
     */
    public static function reset(): void
    {
        self::$client = null;
        self::$resolver = null;
    }
}
