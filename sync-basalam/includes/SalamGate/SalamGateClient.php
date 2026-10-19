<?php

namespace SyncBasalam\SalamGate;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\OAuthTokenRefresher;

defined('ABSPATH') || exit;

/**
 * کلاینت HTTP اختصاصی سرویس سلام‌گیت (https://salam-gate.basalam.com).
 *
 * انتقال HTTP مستقل است؛ تمدید OAuth به مدیر مشترک افزونه پایه سپرده می‌شود.
 * خطای احراز هویت به‌تنهایی تنظیمات اتصال را پاک نمی‌کند. تلاش مجدد فقط یک‌بار
 * پس از 401 قطعی و دریافت توکن جدید است؛ پرداخت و لغو حتی پس از 401 خودکار تکرار
 * نمی‌شود. timeout یا پاسخ نامعلوم نیز تکرار نمی‌شود.
 *
 * قواعد قرارداد:
 * - پارامترهای آرایه‌ای (ids, external_ids, market_name) با کلید تکراری ساده
 *   ارسال می‌شوند: ids=1&ids=2 — بدون پسوند براکت (FastAPI کلید براکت‌دار را نادیده می‌گیرد).
 * - پاسخ 2xx باید JSON آرایه‌ای معتبر باشد؛ null فقط در GETهای provider،
 *   رهگیری عمومی و lookup آخرین درخواست لیبل با قرارداد nullable پذیرفته می‌شود.
 * - خطاها با kind تفکیک می‌شوند (transport/401/403/404/422/429/5xx).
 */
class SalamGateClient
{
    public const BASE_URL = 'https://salam-gate.basalam.com';

    // مسیرهای پرکاربرد (صرفاً مستنداتی؛ نقشه کامل: Contracts\Endpoints)
    public const PATH_SHOPS            = '/v1/shops';
    public const PATH_SHOP_REGISTER    = '/v1/shops/register';
    public const PATH_SHOP_ACTION      = '/v1/shops/%d/%s'; // activate|deactivate
    public const PATH_LOCATIONS        = '/v1/locations';
    public const PATH_BOX_TYPES        = '/v1/parcels/box-types';
    public const PATH_PARCELS          = '/v1/parcels';
    public const PATH_PARCEL_QUOTES    = '/v1/parcels/%d/quotes';
    public const PATH_PARCEL_PAY       = '/v1/parcels/%d/pay';
    public const PATH_PARCEL_CANCEL    = '/v1/parcels/%d/cancel';
    public const PATH_PARCEL_TRACK     = '/v1/parcels/%d/track';
    public const PATH_TRACKING_CODE    = '/v1/tracking/%s';
    public const PATH_MARKET_PARCELS   = '/v1/markets/%s/parcels';
    public const PATH_MARKET_PARCEL    = '/v1/markets/%s/parcels/%d';
    public const PATH_GENERATE_LABEL   = '/v1/parcels/generate-label';
    public const PATH_CALCULATOR       = '/v1/base/calculator';
    public const PATH_INVOICES         = '/v1/invoices';

    private const TIMEOUT = 20;

    /** Digest only: identifies the credential that produced the last response. */
    private ?string $lastRequestIdentityHash = null;

    public function lastRequestIdentityHash(): ?string
    {
        return $this->lastRequestIdentityHash;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * درخواست به اندپوینت‌های عمومی (بدون توکن) مثل locations و box-types و calculator.
     */
    public function publicGet(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query, false);
    }

    /**
     * اندپوینت‌های عمومی POST (calculator) — بدون توکن.
     */
    public function publicPost(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $body, $query, false);
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, $body, $query);
    }

    public function put(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PUT', $path, $body, $query);
    }

    public function patch(string $path, array $body = [], array $query = []): array
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    /**
     * توکن OAuth باسلام از تنظیمات پلاگین پایه. متد مجزا تا در تست‌ها override شود.
     */
    public function token(): ?string
    {
        if (class_exists(OAuthTokenRefresher::class)) {
            return OAuthTokenRefresher::accessToken();
        }

        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);

        return ($token === null || $token === '') ? null : (string) $token;
    }

    /**
     * @return array{success: bool, code: int, body: array}
     *
     * @throws SalamGateException
     */
    private function request(string $method, string $path, ?array $body, array $query, bool $withAuth = true): array
    {
        // A failed read has no uncertain mutation to reconcile. The public
        // calculator uses POST for an estimate but does not register or pay.
        $readOnly = $method === 'GET' || ($method === 'POST' && $path === self::PATH_CALCULATOR);
        if (!$readOnly) Services\SiteActivation::assertEnabled();
        $token = $withAuth ? $this->token() : null;
        $this->lastRequestIdentityHash = $withAuth && $token !== null ? substr(sha1($token), 0, 12) : null;

        if ($withAuth && $token === null) {
            throw new SalamGateException(
                'اتصال به باسلام برقرار نیست؛ از تنظیمات ووسلام دوباره متصل شوید.',
                401,
                [],
                SalamGateException::KIND_UNAUTHENTICATED,
                true // Local preflight: wp_remote_request has not been called.
            );
        }

        $url = self::BASE_URL . $path;

        $queryString = $this->buildQuery($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'Woosalam-Pro',
        ];

        if ($withAuth) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = [
            'method'    => $method,
            'timeout'   => self::TIMEOUT,
            'headers'   => $headers,
            'sslverify' => true,
            // HTTP transports may forward headers across origins on redirects.
            'redirection' => ($withAuth || preg_match('#^/v1/tracking/[0-9]{24}$#D', $path)) ? 0 : 5,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        if (!$readOnly) Services\SiteActivation::assertEnabled();
        $response = wp_remote_request($url, $args);

        // A rejected payment response is not an idempotency guarantee. Renewing
        // credentials cannot prove that a downstream debit/compensation settled.
        // Let the payment owner retain its durable guard; a later read can renew
        // OAuth and reconcile the result without dispatching another payment.
        // Cancellation includes a refund; neither debit nor refund may be replayed by OAuth.
        $paymentWrite = $method === 'POST' && preg_match('#^/v1/parcels/[1-9][0-9]*/(?:pay|cancel)$#D', $path) === 1;
        if (!is_wp_error($response) && $withAuth
            && !$paymentWrite
            && (int) wp_remote_retrieve_response_code($response) === 401
            && class_exists(OAuthTokenRefresher::class)) {
            $freshToken = OAuthTokenRefresher::refresh($token);
            if ($freshToken !== null && $freshToken !== $token) {
                // Reuse the exact prepared request; do not run token preflight again.
                // A short-lived refreshed token must not trigger a second renewal.
                $args['headers']['Authorization'] = 'Bearer ' . $freshToken;
                $token = $freshToken;
                $this->lastRequestIdentityHash = substr(sha1($token), 0, 12);
                if (!$readOnly && !Services\SiteActivation::isEnabled()) {
                    // The first request has already been dispatched. Do not
                    // label this as a definite preflight failure or clear its
                    // durable financial/creation evidence when skipping retry.
                    throw new SalamGateException('سلام‌گیت غیرفعال شد؛ نتیجه درخواست قبلی را پس از فعال‌سازی دوباره بررسی کنید.',
                        0, [], SalamGateException::KIND_TRANSPORT);
                }
                $response = wp_remote_request($url, $args);
            }
        }

        if (is_wp_error($response)) {
            $errorCode = is_object($response) && method_exists($response, 'get_error_code')
                ? (string) $response->get_error_code()
                : '';
            $isTimeout = str_contains($errorCode, 'timeout');

            throw new SalamGateException(
                $readOnly
                    ? ($isTimeout
                        ? 'دریافت اطلاعات از سلام‌گیت طول کشید؛ دوباره تلاش کنید.'
                        : 'دریافت اطلاعات از سلام‌گیت انجام نشد؛ اتصال را بررسی و دوباره تلاش کنید.')
                    : ($isTimeout
                        ? 'زمان پاسخ سلام‌گیت به پایان رسید؛ نتیجه عملیات نامعلوم است — وضعیت را بررسی کنید و تا آن زمان تکرار نکنید.'
                        : 'ارتباط با سلام‌گیت برقرار نشد؛ نتیجه عملیات نامعلوم است — وضعیت را بررسی کنید و تا آن زمان تکرار نکنید.'),
                0,
                [],
                SalamGateException::KIND_TRANSPORT
            );
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);

        if ($code >= 200 && $code < 300) {
            $noLabelRequest = $decoded === null && json_last_error() === JSON_ERROR_NONE
                && $method === 'GET' && $path === self::PATH_GENERATE_LABEL
                && !array_key_exists('request_id', $query);
            if ($noLabelRequest) {
                // The latest-label lookup explicitly returns JSON null when
                // the authenticated user has no request. Never apply this to
                // a known request ID, a label POST or an empty/malformed body.
                return ['success' => true, 'code' => $code, 'body' => [], 'no_label_request' => true];
            }
            if ($decoded === null && json_last_error() === JSON_ERROR_NONE
                && $method === 'GET'
                && preg_match('#^/v1/providers/[1-9][0-9]*(?:/(?:locations|nodes))?$#D', $path)) {
                // نبود پوشش/مرکز طبق OpenAPI با JSON null پاسخ داده می‌شود.
                $decoded = [];
            }
            if ($decoded === null && json_last_error() === JSON_ERROR_NONE && $method === 'GET'
                && preg_match('#^/v1/tracking/[0-9]{24}$#D', $path)) {
                // The public tracking contract is nullable when no events exist.
                // Empty/HTML responses and redirects remain genuine failures.
                $decoded = [];
            }
            if (!is_array($decoded)) {
                // بدنه خالی، null یا JSON خراب — هرگز موفقیت نیست.
                throw new SalamGateException(
                    $readOnly
                        ? 'اطلاعات معتبر از سلام‌گیت دریافت نشد؛ دوباره تلاش کنید.'
                        : 'پاسخ نامعتبر از سلام‌گیت دریافت شد؛ عملیات تأیید نشد — وضعیت را بررسی کنید.',
                    $code,
                    [],
                    SalamGateException::KIND_UNEXPECTED_RESPONSE
                );
            }

            $result = [
                'success' => true,
                'code'    => $code,
                'body'    => $decoded,
            ];
            if ($withAuth) {
                $result['connection_hash'] = $this->lastRequestIdentityHash;
            }
            return $result;
        }

        throw $this->buildException($code, is_array($decoded) ? $decoded : []);
    }

    /**
     * ساخت query string مطابق قرارداد FastAPI سرویس.
     *
     * پارامترهای آرایه‌ای (ids، external_ids، market_name) در OpenAPI بدون براکت
     * تعریف شده‌اند و باید با کلید تکراری ارسال شوند:
     *   ids=101&ids=102&market_name=basalam
     * فرم براکتی (ids%5B%5D=...) کلیدِ ناشناخته است و سرویس آن را بی‌صدا دور می‌اندازد.
     */
    public function buildQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            $encodedKey = rawurlencode((string) $key);

            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = $encodedKey . '=' . rawurlencode((string) $item);
                }
            } else {
                $pairs[] = $encodedKey . '=' . rawurlencode((string) $value);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * تبدیل پاسخ خطای سرویس به SalamGateException با kind مناسب.
     *
     * @throws void
     */
    private function buildException(int $code, array $body): SalamGateException
    {
        // envelope استاندارد: {"message":..,"errors":[{"location":..,"message":..}]}
        $errors = [];
        $message = '';

        if (isset($body['errors']) && is_array($body['errors'])) {
            $errors  = $body['errors'];
            $message = (string) ($body['message'] ?? '');

            if ($message === '') {
                foreach ($errors as $error) {
                    if (is_array($error) && !empty($error['message'])) {
                        $message = (string) $error['message'];
                        break;
                    }
                }
            }
        } elseif (isset($body['detail']) && is_array($body['detail']) && isset($body['detail']['message'])) {
            // خطای خام احراز هویت: {"detail":{"message":"Unauthenticated"}} → پیام فارسی اتصال
            $message = 'اعتبار اتصال باسلام تأیید نشد؛ از تنظیمات ووسلام دوباره متصل شوید.';
            $errors  = [['location' => 'auth', 'message' => $message]];
        } elseif (isset($body['detail']) && is_array($body['detail'])) {
            // قالب استاندارد FastAPI: detail=[{loc:["body",...],msg:"..."}].
            // input و ctx ممکن است داده حساس داشته باشند؛ فقط محل و پیام منتقل می‌شوند.
            foreach ($body['detail'] as $error) {
                if (!is_array($error) || !isset($error['msg']) || !is_string($error['msg'])) {
                    continue;
                }
                $location = is_array($error['loc'] ?? null)
                    ? implode('.', array_filter($error['loc'], static fn ($part): bool => is_scalar($part)))
                    : '';
                $errors[] = ['location' => $location, 'message' => $error['msg']];
            }
            $message = $errors[0]['message'] ?? '';
        } elseif (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            $message = (string) $body['message'];
            $errors  = [['location' => 'general', 'message' => $message]];
        } elseif (isset($body['detail']) && is_string($body['detail']) && $body['detail'] !== '') {
            $message = (string) $body['detail'];
            $errors  = [['location' => 'general', 'message' => $message]];
        }

        $kind = SalamGateException::kindForStatus($code);

        if ($kind === SalamGateException::KIND_UNAUTHENTICATED) {
            $message = 'اعتبار اتصال باسلام تأیید نشد؛ از تنظیمات ووسلام دوباره متصل شوید.';
            $errors = [['location' => 'auth', 'message' => $message]];
        } elseif ($kind === SalamGateException::KIND_FORBIDDEN) {
            // 403 is an authorization failure, not proof of token expiry.
            // Keep a single safe error; raw details can contain sensitive data or
            // the authentication fallback above and must not reach an AJAX UI.
            $message = 'دسترسی به این عملیات سلام‌گیت مجاز نیست. مجوزهای vendor.salamgate.read و vendor.salamgate.write را با اتصال مجدد تأیید کنید؛ اگر مجوزها در دسترس نبودند یا مشکل ادامه داشت، با پشتیبانی سلام‌گیت تماس بگیرید.';
            $errors = [['location' => 'forbidden', 'message' => $message]];
        } elseif ($kind === SalamGateException::KIND_RATE_LIMITED && $message === '') {
            $message = 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.';
        } elseif ($kind === SalamGateException::KIND_SERVER && $message === '') {
            $message = 'خطای سرور سلام‌گیت؛ کمی بعد دوباره تلاش کنید.';
        } elseif ($message === '') {
            $message = 'خطا در ارتباط با سلام‌گیت.';
        }

        return new SalamGateException($message, $code, $errors);
    }
}
