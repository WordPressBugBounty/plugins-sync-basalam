<?php
namespace SyncBasalam\SalamGate\Admin\Assets;

defined('ABSPATH') || exit;

final class AssetManifest
{
    public static function scripts(): array
    {
        return [
            'woosalam-salam-gate-help' => ['path' => 'js/salam-gate-help.js', 'deps' => ['jquery', 'basalam-admin-help-script']],
            'woosalam-salam-gate-amount' => ['path' => 'js/salam-gate-amount.js', 'deps' => []],
            'woosalam-send-draft' => ['path' => 'js/salam-gate-send-draft.js', 'deps' => []],
            'woosalam-feedback' => ['path' => 'js/salam-gate-feedback.js', 'deps' => ['basalam-admin-toast-script']],
            'woosalam-salam-gate-admin' => ['path' => 'js/salam-gate-admin.js', 'deps' => ['jquery', 'woosalam-feedback']],
            'woosalam-salam-gate-script' => ['path' => 'js/index.js', 'deps' => ['woosalam-salam-gate-amount', 'jquery', 'woosalam-send-draft', 'woosalam-feedback', 'woosalam-salam-gate-admin']],
            'woosalam-salam-gate-money' => ['path' => 'js/salam-gate-money.js', 'deps' => []],
            'woosalam-salam-gate-navigation' => ['path' => 'js/salam-gate-navigation.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-tracking' => ['path' => 'js/salam-gate-tracking.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-basalam-orders' => ['path' => 'js/salam-gate-basalam-orders.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-order-parcels' => ['path' => 'js/salam-gate-order-parcels.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-order-source-status' => ['path' => 'js/salam-gate-order-source-status.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-product-search' => ['path' => 'js/salam-gate-product-search.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-cancel-dialog' => ['path' => 'js/salam-gate-cancel-dialog.js', 'deps' => ['woosalam-salam-gate-script']],
            'woosalam-salam-gate-payment' => ['path' => 'js/salam-gate-payment.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money']],
            'woosalam-salam-gate-pickers' => ['path' => 'js/salam-gate-pickers.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-payment']],
            'woosalam-salam-gate-validation' => ['path' => 'js/salam-gate-validation.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money']],
            'woosalam-salam-gate-bulk-shipping' => ['path' => 'js/salam-gate-bulk-shipping.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-validation']],
            'woosalam-salam-gate-parcels-checkout' => ['path' => 'js/salam-gate-parcels-checkout.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-payment']],
            'woosalam-sg-order-list' => ['path' => 'js/salam-gate-order-list.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-bulk-shipping']],
            'woosalam-sg-page-status' => ['path' => 'js/pages/status.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-account' => ['path' => 'js/pages/account.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-activation' => ['path' => 'js/pages/activation.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-dashboard' => ['path' => 'js/pages/dashboard.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-settings' => ['path' => 'js/pages/settings.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-post-offices' => ['path' => 'js/pages/post-offices.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-calculator' => ['path' => 'js/pages/calculator.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment']],
            'woosalam-sg-page-send' => ['path' => 'js/pages/send.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-basalam-orders']],
            'woosalam-sg-page-order-modal' => ['path' => 'js/pages/order-modal.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-order-parcels', 'woosalam-salam-gate-order-source-status', 'woosalam-salam-gate-product-search']],
            'woosalam-sg-page-parcels' => ['path' => 'js/pages/parcels.js', 'deps' => ['woosalam-salam-gate-script', 'woosalam-salam-gate-money', 'woosalam-salam-gate-validation', 'woosalam-salam-gate-pickers', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-parcels-checkout', 'woosalam-salam-gate-cancel-dialog']],
        ];
    }

    public static function styles(): array
    {
        return [
            'woosalam-salam-gate-help' => ['path' => 'styles/salam-gate-help.css', 'deps' => ['basalam-admin-style', 'basalam-admin-font-style']],
            'woosalam-salam-gate-motion' => ['path' => 'styles/salam-gate-motion.css', 'deps' => []],
            'woosalam-salam-gate-mobile' => ['path' => 'styles/salam-gate-mobile.css', 'deps' => []],
            'woosalam-salam-gate-icons' => ['path' => 'styles/salam-gate-icons.css', 'deps' => []],
            'woosalam-sg-tokens' => ['path' => 'styles/tokens.css', 'deps' => []],
            'woosalam-sg-buttons' => ['path' => 'styles/components/buttons.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-sg-cards' => ['path' => 'styles/components/cards.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-style' => ['path' => 'styles/style.css', 'deps' => ['woosalam-sg-tokens']],
            'woosalam-connection-gate' => ['path' => 'styles/salam-gate-connection.css', 'deps' => ['woosalam-salam-gate-style']],
            'woosalam-salam-gate-admin' => ['path' => 'styles/salam-gate-admin.css', 'deps' => ['woosalam-salam-gate-style', 'basalam-admin-font-style']],
            'woosalam-salam-gate-layout' => ['path' => 'styles/salam-gate-layout.css', 'deps' => ['woosalam-sg-buttons', 'woosalam-sg-cards']],
            'woosalam-salam-gate-order' => ['path' => 'styles/salam-gate-order.css', 'deps' => ['woosalam-sg-buttons', 'woosalam-sg-cards']],
            'woosalam-salam-gate-feedback' => ['path' => 'styles/salam-gate-feedback.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-payment' => ['path' => 'styles/salam-gate-payment.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-order-parcels' => ['path' => 'styles/salam-gate-order-parcels.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-basalam-orders' => ['path' => 'styles/salam-gate-basalam-orders.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-pickers' => ['path' => 'styles/salam-gate-pickers.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-validation' => ['path' => 'styles/salam-gate-validation.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-order-source-status' => ['path' => 'styles/salam-gate-order-source-status.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-product-search' => ['path' => 'styles/salam-gate-product-search.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-settings' => ['path' => 'styles/salam-gate-settings.css', 'deps' => ['woosalam-salam-gate-layout']],
            'woosalam-salam-gate-activation' => ['path' => 'styles/salam-gate-activation.css', 'deps' => ['woosalam-salam-gate-layout']],
            'woosalam-salam-gate-tracking' => ['path' => 'styles/salam-gate-tracking.css', 'deps' => ['woosalam-salam-gate-layout']],
            'woosalam-salam-gate-parcels' => ['path' => 'styles/salam-gate-parcels.css', 'deps' => ['woosalam-salam-gate-layout']],
            'woosalam-salam-gate-cancel-dialog' => ['path' => 'styles/salam-gate-cancel-dialog.css', 'deps' => ['woosalam-salam-gate-parcels']],
            'woosalam-salam-gate-bulk-shipping' => ['path' => 'styles/salam-gate-bulk-shipping.css', 'deps' => ['woosalam-salam-gate-admin', 'woosalam-salam-gate-payment']],
            'woosalam-sg-order-status' => ['path' => 'styles/salam-gate-order-status.css', 'deps' => ['woosalam-salam-gate-admin']],
            'woosalam-salam-gate-order-list-preview' => ['path' => 'styles/salam-gate-basalam-orders.css', 'deps' => ['woosalam-salam-gate-admin']],
        ];
    }
}
