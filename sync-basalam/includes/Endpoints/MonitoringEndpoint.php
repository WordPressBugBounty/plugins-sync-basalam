<?php

namespace SyncBasalam\Endpoints;

use SyncBasalam\Services\MonitoringHealth;
use SyncBasalam\Services\MonitoringProducts;
use SyncBasalam\Services\MonitoringRepair;
use SyncBasalam\Services\MonitoringDiagnostics;
use SyncBasalam\Services\MonitoringCatalog;

defined('ABSPATH') || exit;

class MonitoringEndpoint
{
    public static function registerRoutes(): void
    {
        add_filter('rest_pre_serve_request', [MonitoringDiagnostics::class, 'serveRepairResponse'], 10, 4);
        register_rest_route('sync-basalam', '/v1/monitoring-diagnostics', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'diagnostics'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
            'args' => ['cursor' => ['type' => 'string', 'maxLength' => 40, 'default' => '',
                'pattern' => '^([a-f0-9]{16}:[0-9]{1,16})?$']],
        ]);
        register_rest_route('sync-basalam', '/v1/monitoring-repair', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'repair'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
            'args' => [
                'woo_product_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'basalam_product_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true],
                'command_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'required' => true],
                'grant' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 100, 'required' => true],
                'request_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
                'scan_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]);
        register_rest_route('sync-basalam', '/v1/monitoring-health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'health'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
        ]);
        register_rest_route('sync-basalam', '/v1/monitoring-products', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'products'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
            'args' => [
                'after_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'product_ids' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 420,
                    'pattern' => '^[1-9][0-9]*(,[1-9][0-9]*)*$'],
            ],
        ]);
        register_rest_route('sync-basalam', '/v1/monitoring-catalog-browse', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'browseCatalog'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
        ]);
        register_rest_route('sync-basalam', '/v1/monitoring-catalog', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'catalog'],
            'permission_callback' => [InformationEndpoint::class, 'checkSitePermissions'],
            'args' => [
                'after_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                'product_ids' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2100,
                    'pattern' => '^[1-9][0-9]*(,[1-9][0-9]*)*$'],
            ],
        ]);
    }

    public static function health($request)
    {
        try {
            $response = rest_ensure_response((new MonitoringHealth())->snapshot());
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (\Throwable $error) {
            return new \WP_Error('monitoring_unavailable', 'اطلاعات پایش در دسترس نیست.', ['status' => 503]);
        }
    }

    public static function products($request)
    {
        try {
            $ids = MonitoringCatalog::parseIds($request->get_param('product_ids'), MonitoringProducts::PAGE_SIZE);
            $data = (new MonitoringProducts())->page((int) $request->get_param('after_id'), (int) $request->get_param('product_id'), $ids);
            $response = rest_ensure_response($data);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('monitoring_invalid_products', 'فهرست محصولات معتبر نیست.', ['status' => 422]);
        } catch (\Throwable $error) {
            return new \WP_Error('monitoring_products_unavailable', 'اطلاعات محصولات در دسترس نیست.', ['status' => 503]);
        }
    }

    public static function catalog($request)
    {
        try {
            $ids = MonitoringCatalog::parseIds($request->get_param('product_ids'), MonitoringCatalog::PAGE_SIZE);
            $data = (new MonitoringCatalog())->page((int) $request->get_param('after_id'), (int) ($request->get_param('limit') ?? 100), $ids);
            $response = rest_ensure_response($data);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('monitoring_invalid_products', 'فهرست محصولات معتبر نیست.', ['status' => 422]);
        } catch (\Throwable $error) {
            return new \WP_Error('monitoring_catalog_unavailable', 'فهرست محصولات در دسترس نیست.', ['status' => 503]);
        }
    }

    public static function browseCatalog($request)
    {
        try {
            if (strlen($request->get_body()) > 550000) throw new \InvalidArgumentException('Request too large');
            $input = $request->get_json_params();
            if (!is_array($input)) throw new \InvalidArgumentException('Invalid input');
            $response = rest_ensure_response((new MonitoringCatalog())->browse($input));
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('monitoring_invalid_products', 'درخواست محصولات معتبر نیست.', ['status' => 422]);
        } catch (\Throwable $error) {
            return new \WP_Error('monitoring_catalog_unavailable', 'فهرست محصولات در دسترس نیست.', ['status' => 503]);
        }
    }

    public static function repair($request)
    {
        $result = (new MonitoringRepair())->execute($request->get_params());
        if (is_wp_error($result)) return $result;
        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function diagnostics($request)
    {
        try {
            $data = MonitoringDiagnostics::page((string) $request->get_param('cursor'));
            $response = rest_ensure_response($data);
            $response->header('Cache-Control', 'no-store, private');
            MonitoringDiagnostics::logRecovered($data['events']);
            return $response;
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('monitoring_invalid_cursor', 'نشانگر گزارش معتبر نیست.', ['status' => 422]);
        } catch (\Throwable $error) {
            return new \WP_Error('monitoring_diagnostics_unavailable', 'گزارش تشخیصی در دسترس نیست.', ['status' => 503]);
        }
    }
}
