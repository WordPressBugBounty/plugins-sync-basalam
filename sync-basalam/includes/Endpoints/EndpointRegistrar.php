<?php

namespace SyncBasalam\Endpoints;

defined('ABSPATH') || exit;

class EndpointRegistrar
{
    public static function registerRoutes(): void
    {
        $endpoints = [
            OrderEndpoint::class,
            InformationEndpoint::class,
            ProductEndpoint::class,
            MonitoringEndpoint::class,
        ];

        foreach ($endpoints as $endpointClass) {
            $endpointClass::registerRoutes();
        }
    }
}
