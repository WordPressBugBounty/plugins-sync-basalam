<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class ApiClient
{
    private string $secret;
    private bool $debug;

    public function __construct(string $secret, bool $debug = false)
    {
        $this->secret = $secret;
        $this->debug = $debug;
    }

    public function createSession(array $payload)
    {
        return $this->request('POST', '/public-api/v1/sessions', $payload);
    }

    public function payment(int $reference)
    {
        return $this->request('GET', '/public-api/v1/payments/references/' . $reference);
    }

    public function refund(array $payload)
    {
        return $this->request('POST', '/public-api/v1/refunds', $payload);
    }

    public function refundStatus(int $reference)
    {
        // Never follow a provider-supplied status_url with merchant credentials.
        return $this->request('GET', '/public-api/v1/refunds/' . $reference . '/status');
    }

    public static function definitiveRejection(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        return in_array((int) ($data['status_code'] ?? 0), [400, 401, 403, 404, 405, 422], true);
    }

    private function request(string $method, string $path, ?array $payload = null)
    {
        if (trim($this->secret) === '') {
            return new \WP_Error('salampay_missing_secret', __('درگاه سلام‌پی تنظیم نشده است.', 'sync-basalam'));
        }
        $base = 'https://salam-pay-api.basalam.com';
        // Local simulations use real WP HTTP. Production cannot override the host.
        if (wp_get_environment_type() === 'local' && defined('SYNC_BASALAM_SALAMPAY_TEST_API_URL')) {
            $base = rtrim(SYNC_BASALAM_SALAMPAY_TEST_API_URL, '/');
        }
        $args = [
            'method' => $method, 'timeout' => 30, 'redirection' => 0,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Client-Secret' => $this->secret],
        ];
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_request($base . $path, $args);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($this->debug && function_exists('wc_get_logger')) {
            // Do not log bodies, URLs with identifiers, headers, keys or customer data.
            wc_get_logger()->debug('Salam Pay HTTP result', [
                'source' => 'woosalam-salampay', 'method' => $method,
                'operation' => preg_replace('/[0-9]+/', '{id}', $path), 'status_code' => $status,
            ]);
        }
        if (is_wp_error($response)) {
            return new \WP_Error('salampay_transport', __('ارتباط با سلام‌پی برقرار نشد؛ نتیجه نیاز به بررسی دارد.', 'sync-basalam'), ['status_code' => 0]);
        }
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('salampay_api', __('دریافت نتیجه از سلام‌پی ممکن نشد.', 'sync-basalam'), ['status_code' => $status]);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('salampay_invalid_response', __('پاسخ سلام‌پی معتبر نیست؛ نتیجه نیاز به بررسی دارد.', 'sync-basalam'), ['status_code' => $status]);
        }
        return $body;
    }
}
