<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\FeatureAvailability;

defined('ABSPATH') || exit;

/** Compatibility endpoint for old open tabs. Reading Woo orders must never probe SalamGate. */
final class OrderFeatureGate
{
    public static function availability(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
        }
        $nonce = $_POST['_wpnonce'] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است.'], 403);
        }
        // Even an older browser script cannot trigger a remote account read.
        wp_send_json_success(['available' => FeatureAvailability::isVisible()]);
    }
}
