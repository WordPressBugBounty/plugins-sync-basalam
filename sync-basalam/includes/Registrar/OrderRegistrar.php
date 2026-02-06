<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Registrar\Contracts\RegistrarInterface;
use SyncBasalam\OrderEndpoint;

defined('ABSPATH') || exit;

class OrderRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        // REST API Endpoints
        \add_action('rest_api_init', [OrderEndpoint::class, 'registerRoutes']);
    }
}
