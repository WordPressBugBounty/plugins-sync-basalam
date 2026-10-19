<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class Callback
{
    public static function register(): void
    {
        // Retain the standalone route so in-flight sessions survive the handover.
        register_rest_route('salampay/v1', '/callback', [
            'methods' => ['GET', 'POST'], 'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission($request)
    {
        $order = self::order($request);
        return is_wp_error($order) ? $order : true;
    }

    public static function handle($request)
    {
        $order = self::order($request);
        if (is_wp_error($order)) {
            return $order;
        }
        // The supplied callback status, amount and payment ID are untrusted.
        $result = PaymentService::verify($order->get_id());
        if (is_wp_error($result)) {
            return $result;
        }
        $order = wc_get_order($order->get_id());
        $url = $result === 'paid' ? $order->get_checkout_order_received_url() : $order->get_checkout_payment_url();
        if ($request->get_method() === 'POST') {
            return new \WP_REST_Response(['status' => $result, 'redirect' => $url], 200, ['Cache-Control' => 'no-store']);
        }
        return new \WP_REST_Response(null, 302, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    private static function order($request)
    {
        $id = $request->get_param('order_id') ?? $request->get_param('reference_id') ?? $request->get_param('referenceId');
        $key = $request->get_param('key');
        if (!PaymentService::positiveInteger($id) || !is_string($key) || $key === '') {
            return new \WP_Error('salampay_invalid_callback', __('اطلاعات بازگشت پرداخت معتبر نیست.', 'sync-basalam'), ['status' => 403]);
        }
        $order = wc_get_order((int) $id);
        if (!$order || $order->get_payment_method() !== 'salampay'
            || !hash_equals((string) $order->get_order_key(), $key)) {
            return new \WP_Error('salampay_invalid_callback', __('اطلاعات بازگشت پرداخت معتبر نیست.', 'sync-basalam'), ['status' => 403]);
        }
        return $order;
    }
}
