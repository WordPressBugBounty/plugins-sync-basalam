<?php

namespace SyncBasalam\SalamPay;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class BlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'salampay';
    private $gateway;

    public function initialize()
    {
        $this->gateway = WC()->payment_gateways()->payment_gateways()['salampay'] ?? null;
    }

    public function is_active()
    {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script('sync-basalam-salampay-blocks', Module::assetUrl('blocks.js'),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], \SyncBasalam\Plugin::VERSION, true);
        return ['sync-basalam-salampay-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->gateway ? $this->gateway->get_title() : __('پرداخت با سلام‌پی', 'sync-basalam'),
            'description' => $this->gateway ? wp_strip_all_tags($this->gateway->get_description()) : '',
            'icon' => Module::assetUrl('logo.svg'), 'supports' => ['products'],
        ];
    }
}
