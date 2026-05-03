<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Endpoints\EndpointRegistrar;
use SyncBasalam\Registrar\Contracts\RegistrarInterface;

defined('ABSPATH') || exit;

class OrderRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        // REST API Endpoints
        \add_action('rest_api_init', [EndpointRegistrar::class, 'registerRoutes']);
    }
}
