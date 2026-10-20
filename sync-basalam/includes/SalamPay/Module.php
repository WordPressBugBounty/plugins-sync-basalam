<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class Module
{
    private static bool $registered = false;
    private static bool $ownsPayments = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        // The standalone plugin keeps ownership until explicitly deactivated.
        // It shares settings, gateway ID, metadata and callback route with us.
        if (class_exists('Salampay_Plugin', false) || class_exists('WC_Gateway_Salampay', false)) {
            add_action('admin_notices', [self::class, 'standaloneNotice']);
            return;
        }
        self::$ownsPayments = true;
        add_action('init', [Recovery::class, 'register'], 20);
        add_filter('woocommerce_payment_gateways', [self::class, 'gateways']);
        add_action('rest_api_init', [Callback::class, 'register']);
        add_action('woocommerce_blocks_payment_method_type_registration', [self::class, 'blocks']);
        add_action('admin_init', [self::class, 'settingsRedirect']);
        add_action('sync_basalam_salampay_reconcile_payment', [PaymentService::class, 'reconcile'], 10, 2);
        add_action('sync_basalam_salampay_reconcile_refund', [RefundService::class, 'reconcile'], 10, 2);
        add_action('woocommerce_order_status_cancelled', [RefundService::class, 'cancelled']);
        add_action('woocommerce_create_refund', [RefundService::class, 'captureContext'], 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', [RefundService::class, 'adminDetails']);
        add_filter('woocommerce_order_actions', [self::class, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_woosalam_salampay_reconcile', [self::class, 'reconcileOrder']);
        $storefront = new Storefront();
        add_action('wp_enqueue_scripts', [$storefront, 'enqueue_styles']);
        add_action('wp_body_open', [$storefront, 'render_promotion_bar'], 5);
        add_action('woocommerce_before_shop_loop_item_title', [$storefront, 'render_loop_product_badge'], 8);
        add_action('woocommerce_single_product_summary', [$storefront, 'render_single_product_badge'], 7);
    }

    public static function ownsPayments(): bool
    {
        return self::$ownsPayments;
    }

    public static function gateways(array $gateways): array
    {
        if (!in_array(Gateway::class, $gateways, true)) {
            $gateways[] = Gateway::class;
        }
        return $gateways;
    }

    public static function blocks($registry): void
    {
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')
            && !$registry->is_registered('salampay')) {
            $registry->register(new BlocksSupport());
        }
    }

    public static function assetUrl(string $file): string
    {
        return plugins_url('assets/salam-pay/' . $file, dirname(__DIR__, 2) . '/sync-basalam.php');
    }

    public static function settingsUrl(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=salampay');
    }

    public static function settingsRedirect(): void
    {
        if (isset($_GET['page']) && is_string($_GET['page']) && $_GET['page'] === 'sync_basalam_salampay'
            && current_user_can('manage_woocommerce')) {
            wp_safe_redirect(self::settingsUrl());
            exit;
        }
    }

    public static function standaloneNotice(): void
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
        $screen = get_current_screen();
        if (current_user_can('activate_plugins') && ($page === 'wc-settings'
            || strpos($page, 'sync_basalam') === 0 || ($screen && $screen->base === 'plugins'))) {
            echo '<div class="notice notice-info"><p>' . esc_html__('درگاه سلام‌پی در ووسلام موجود است. اکنون افزونه مستقل سلام‌پی فعال است و پرداخت‌ها را مدیریت می‌کند؛ برای استفاده از نسخه داخلی، افزونه مستقل را غیرفعال کنید. تنظیمات و سفارش‌های قبلی حفظ می‌شوند.', 'sync-basalam') . '</p></div>';
        }
    }

    public static function orderActions(array $actions, $order): array
    {
        if ($order && $order->get_payment_method() === 'salampay') {
            $actions['woosalam_salampay_reconcile'] = __('بررسی پرداخت و بازگشت وجه سلام‌پی', 'sync-basalam');
        }
        return $actions;
    }

    public static function reconcileOrder($order): void
    {
        if ($order && $order->get_payment_method() === 'salampay') {
            $result = PaymentService::verify($order->get_id());
            if (is_wp_error($result)) {
                $order->add_order_note($result->get_error_message());
            }
            RefundService::reconcile($order->get_id());
        }
    }

    public static function deactivate(): void
    {
        wp_unschedule_hook('sync_basalam_salampay_reconcile_payment');
        wp_unschedule_hook('sync_basalam_salampay_reconcile_refund');
        wp_unschedule_hook(Recovery::HOOK);
    }
}
