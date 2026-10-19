<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

/** Restore interrupted jobs, including intents left by the standalone plugin. */
final class Recovery
{
    public const HOOK = 'sync_basalam_salampay_resume';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'resume']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function resume(int $page = 1): void
    {
        if (!Module::ownsPayments()) {
            return;
        }
        $orders = wc_get_orders(['type' => 'shop_order', 'payment_method' => 'salampay',
            'limit' => 50, 'page' => max(1, $page), 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($orders as $order) {
            PaymentService::resume($order);
            RefundService::resume($order);
        }
        if (count($orders) === 50 && !wp_next_scheduled(self::HOOK, [$page + 1])) {
            wp_schedule_single_event(time() + 60, self::HOOK, [$page + 1]);
        }
    }
}
