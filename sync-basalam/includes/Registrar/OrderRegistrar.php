<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Admin\Order\BasalamItemMeta;
use SyncBasalam\Endpoints\EndpointRegistrar;
use SyncBasalam\Registrar\Contracts\RegistrarInterface;

defined('ABSPATH') || exit;

class OrderRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        // REST API Endpoints
        \add_action('rest_api_init', [EndpointRegistrar::class, 'registerRoutes']);
        \add_filter('woocommerce_order_item_get_formatted_meta_data', [BasalamItemMeta::class, 'hideFromFormattedMeta']);
        \add_action('woocommerce_after_order_itemmeta', [BasalamItemMeta::class, 'render'], 10, 3);
    }
}
