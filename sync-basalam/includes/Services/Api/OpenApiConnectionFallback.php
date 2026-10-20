<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Config\Endpoints;

defined('ABSPATH') || exit;

/** Retry an OpenAPI connection failure through its documented service route. */
class OpenApiConnectionFallback
{
    private OpenApiFallbackPolicy $policy;

    public function __construct(?OpenApiFallbackPolicy $policy = null)
    {
        $this->policy = $policy ?? new OpenApiFallbackPolicy();
    }

    /**
     * Core versions differ from the public OpenAPI versions. Only map routes
     * with a corresponding Core operation using the same method and payload.
     * Source: https://core.basalam.com/openapi.json
     */
    private const CORE_ROUTES = [
        '~^/v1/products/([0-9]+)$~' => '/v4/products/$1',
        '~^/v1/vendors/([0-9]+)/products$~' => '/v4/vendors/$1/products',
        '~^/v1/vendors/([0-9]+)/products/batch-updates$~' => '/v4/vendors/$1/products',
        '~^/v1/categories$~' => '/v3/categories',
        '~^/v1/categories/([0-9]+)/attributes$~' => '/v3/categories/$1/attributes',
        '~^/v1/vendors/([0-9]+)$~' => '/v3/vendors/$1',
        '~^/v1/vendors/([0-9]+)/discounts$~' => '/v3/vendors/$1/discounts',
    ];

    /** @return array|\WP_Error The unchanged WordPress HTTP response shape. */
    public function request(string $url, callable $send)
    {
        $fallbackUrl = $this->fallbackUrl($url);
        if ($fallbackUrl !== null && $this->policy->isActive()) {
            return $this->sendFallback($fallbackUrl, $send);
        }

        $redirected = false;
        $response = $this->send($url, $send, $redirected);

        if ($redirected || $fallbackUrl === null || !$this->failedBeforeSending($response)) {
            return $response;
        }

        $this->policy->recordConnectionFailure();
        return $this->sendFallback($fallbackUrl, $send);
    }

    private function sendFallback(string $fallbackUrl, callable $send)
    {
        $redirected = false;
        // The destination uses normal WordPress DNS, transport and proxy configuration.
        // Keep certificate and hostname verification enabled on the retry.
        $verifyArgs = static function (array $args, string $requestUrl) use ($fallbackUrl): array {
            if ($requestUrl === $fallbackUrl) $args['sslverify'] = true;
            return $args;
        };
        $verifyTls = static function ($verify, string $requestUrl) use ($fallbackUrl) {
            return $requestUrl === $fallbackUrl && !is_string($verify) ? true : $verify;
        };
        add_filter('http_request_args', $verifyArgs, PHP_INT_MAX, 2);
        add_filter('https_ssl_verify', $verifyTls, PHP_INT_MAX, 2);
        try {
            // Exactly one fallback attempt; return its real HTTP/network result.
            return $this->send($fallbackUrl, $send, $redirected);
        } finally {
            remove_filter('http_request_args', $verifyArgs, PHP_INT_MAX);
            remove_filter('https_ssl_verify', $verifyTls, PHP_INT_MAX);
        }
    }

    private function send(string $url, callable $send, bool &$redirected)
    {
        $redirected = false;
        $observeRedirect = static function () use (&$redirected): void {
            $redirected = true;
        };
        // A failure on a redirect target may follow an already applied write.
        add_action('requests-requests.before_redirect', $observeRedirect);
        try {
            return $send($url);
        } finally {
            remove_action('requests-requests.before_redirect', $observeRedirect);
        }
    }

    private function fallbackUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'openapi.basalam.com'
            || (int) ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $fallback = null;
        // Order Processing's v3 list has the same cursor, filters and response
        // shape as the OpenAPI list. Source: /openapi.json on ORDER_BASE.
        if ($path === '/v1/vendor-parcels') {
            $fallback = Endpoints::ORDER_BASE . '/v3/vendor-parcels';
        }
        // The webhook service documents the same collection and numeric-id
        // CRUD routes, payloads and Bearer auth. Source: WEBHOOK_BASE/openapi.json.
        if (preg_match('~^/v1/webhooks(?:/[0-9]+)?$~', $path)) {
            $fallback = Endpoints::WEBHOOK_BASE . $path;
        }
        foreach (self::CORE_ROUTES as $pattern => $replacement) {
            if (!preg_match($pattern, $path)) continue;
            $fallback = Endpoints::CORE_BASE . preg_replace($pattern, $replacement, $path);
            break;
        }
        if ($fallback === null) return null;
        if (isset($parts['query'])) $fallback .= '?' . $parts['query'];
        if (isset($parts['fragment'])) $fallback .= '#' . $parts['fragment'];
        return $fallback;
    }

    private function failedBeforeSending($response): bool
    {
        // Timeouts/disconnections can happen after applying a POST/PATCH.
        return $response instanceof \WP_Error
            && $response->get_error_code() === 'http_request_failed'
            && preg_match('/\bcURL error (?:6|7)\b/i', $response->get_error_message()) === 1;
    }
}
