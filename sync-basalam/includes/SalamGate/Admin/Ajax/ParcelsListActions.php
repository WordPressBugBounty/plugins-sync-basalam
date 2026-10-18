<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\Digits;

if (! defined('ABSPATH')) exit;

/**
 * اکشن‌های AJAX صفحه مرسولات سلام‌گیت (پارت ۵).
 */
class ParcelsListActions
{
    /** These entry points share the same financial lease and replay guards as the send wizard. */
    public static function checkout(): void { SendActions::quotes(true); }
    public static function checkoutSelect(): void { SendActions::selectQuote(true); }
    public static function checkoutPay(): void { SendActions::pay(true); }

    /** Explicit cancellation or read-only recovery; no browser-supplied financial identity. */
    public static function cancelParcel(): void
    {
        self::guard();
        $id = ParcelService::positiveId($_POST['parcel_id'] ?? null);
        $confirm = $_POST['confirm'] ?? null;
        if ($id === null || !in_array($confirm, ['0', '1'], true)) {
            wp_send_json_error(['message' => 'درخواست لغو مرسوله معتبر نیست.', 'request_dispatched' => false], 422);
            return;
        }
        try {
            $result = (new \SyncBasalam\SalamGate\Services\ParcelCancellationService())->run($id, $confirm === '1');
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => $exception->firstMessage(), 'request_dispatched' => false], 422);
            return;
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => 'بررسی لغو کامل نشد؛ وضعیت مرسوله را دوباره بررسی کنید.', 'code' => 'cancel_review'], 422);
            return;
        }
        // The service releases its lease before wp_send_json terminates PHP.
        wp_send_json_success($result);
    }

    public static function parcelsList(): void
    {
        self::guard();

        $filters = [
            'status'      => $_POST['status'] ?? '',
            'market_name' => $_POST['market_name'] ?? 'all',
            'external_id' => $_POST['external_id'] ?? '',
            'sort'        => $_POST['sort'] ?? 'desc',
            'search'      => isset($_POST['search']) && is_string($_POST['search'])
                ? mb_substr(sanitize_text_field(wp_unslash($_POST['search'])), 0, 120) : '',
            'cursor'      => isset($_POST['cursor']) ? sanitize_text_field(wp_unslash($_POST['cursor'])) : '',
        ];

        try {
            wp_send_json_success((new ParcelsListService())->list($filters));
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => $exception->firstMessage()]);
        }
    }

    /**
     * جزئیات مرسوله؛ رهگیری پستی مستقیماً در سایت پست انجام می‌شود.
     */
    public static function parcelDetail(): void
    {
        self::guard();

        $parcelId = isset($_POST['parcel_id']) ? (int) $_POST['parcel_id'] : 0;
        if ($parcelId <= 0) {
            wp_send_json_error(['message' => 'شناسه مرسوله نامعتبر است.']);
            return;
        }

        try {
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCELS . '/%d', $parcelId));
            $detail = is_array($response['body'] ?? null) ? $response['body'] : [];

            wp_send_json_success([
                // خروجی detail فیلدهای tracking/invoice/market ندارد — adapter مستقل، بدون جعل مقدار.
                'parcel' => ParcelsListService::normalizeDetail($detail),
                'detail' => $detail,
                'tracking_provider' => 'post',
            ]);
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => $exception->firstMessage()]);
        }
    }

    /**
     * ساخت لیبل (تکی/گروهی) — با async خودکار.
     */
    public static function bulkLabel(): void
    {
        self::guard();

        $parcelIds = [];
        if (isset($_POST['parcel_ids']) && is_array($_POST['parcel_ids'])) {
            foreach (wp_unslash($_POST['parcel_ids']) as $parcelId) {
                if ((!is_int($parcelId) && !is_string($parcelId))
                    || !preg_match('/^[1-9]\d*$/D', (string) $parcelId)
                    || filter_var($parcelId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    wp_send_json_error(['message' => 'شناسه مرسوله نامعتبر است.', 'request_dispatched' => false]);
                }
                $parcelIds[] = (int) $parcelId;
            }
        }
        $parcelIds = array_values(array_unique($parcelIds));

        if (count($parcelIds) === 0) {
            wp_send_json_error(['message' => 'هیچ مرسوله‌ای انتخاب نشده است.', 'request_dispatched' => false]);
        }
        if (count($parcelIds) > 100) {
            wp_send_json_error(['message' => 'در هر درخواست حداکثر ۱۰۰ مرسوله را انتخاب کنید.', 'request_dispatched' => false]);
        }

        $template = isset($_POST['template']) ? sanitize_text_field(wp_unslash($_POST['template'])) : '';
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) {
            $template = 'invoice';
        }

        $requestDispatched = false;
        try {
            // Recheck authoritative list data; hiding a button is not a server guard.
            $eligible = [];
            $matched = [];
            $unfinished = [];
            // The list endpoint permits at most 30 rows. Each read uses the
            // authenticated client's account scope, never a caller-supplied user.
            foreach (array_chunk($parcelIds, 30) as $ids) {
                $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, [
                    'ids' => $ids, 'market_name' => ParcelsListService::ALL_MARKETS, 'per_page' => 30,
                ]);
                $body = $response['body'] ?? null;
                if (!is_array($body) || !is_array($body['data'] ?? null)) {
                    throw new SalamGateException('اطلاعات مرسوله برای چاپ تأیید نشد؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                }
                foreach ($body['data'] as $raw) {
                    if (!is_array($raw) || !LabelEligibility::validListShape($raw)) {
                        throw new SalamGateException('اطلاعات مرسوله برای چاپ تأیید نشد؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                    }
                    $id = (int) $raw['id'];
                    if (!in_array($id, $ids, true)) { continue; }
                    if (in_array($id, $matched, true)) {
                        throw new SalamGateException('اطلاعات مرسوله برای چاپ تأیید نشد؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                    }
                    $matched[] = $id;
                    if ((int) $raw['status']['id'] === 7 || in_array((int) ($raw['invoice']['status'] ?? 0), [1, 3, 4, 5], true)) { $unfinished[] = $id; }
                    if (LabelEligibility::canPrintListItem($raw)) { $eligible[] = $id; }
                }
            }
            if (array_diff($parcelIds, $eligible) !== []) {
                $message = array_diff($parcelIds, $matched) !== []
                    ? 'مرسوله انتخاب‌شده در حساب متصل پیدا نشد یا هنوز برای چاپ در دسترس نیست؛ فهرست را دوباره بررسی کنید.'
                    : ($unfinished !== []
                        ? 'فرایند پرداخت و تکمیل ارسال مرسوله انجام نشده است؛ پس از پرداخت موفق و صدور کد رهگیری، برچسب را چاپ کنید.'
                        : 'ثبت ارسال و صدور کد رهگیری این مرسوله تأیید نشد یا رسید آن ابطال شده است؛ وضعیت را دوباره بررسی کنید.');
                wp_send_json_error(['message' => $message, 'code' => 'label_not_ready', 'request_dispatched' => false]);
            }
            $requestDispatched = true;
            wp_send_json_success((new ParcelService())->getLabelUrl($parcelIds, $template));
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => self::mapLabelError($exception), 'request_dispatched' => $requestDispatched]);
        }
    }

    /**
     * پیگیری درخواست لیبل (بدون request_id = آخرین درخواست).
     */
    public static function labelPoll(): void
    {
        self::guard();

        $rawRequestId = $_POST['request_id'] ?? 0;
        $requestId = in_array($rawRequestId, [0, '0', ''], true) ? 0 : ParcelService::positiveId($rawRequestId);
        if ($requestId === null) {
            wp_send_json_error(['message' => 'شناسه درخواست برچسب معتبر نیست.']);
        }

        try {
            wp_send_json_success((new ParcelService())->getLabelStatus($requestId));
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => self::mapLabelError($exception)]);
        }
    }

    public static function labelPreview(): void
    {
        // Native PDF tabs use GET; the existing read-only POST remains supported.
        // Both require the same administrator capability and nonce.
        $request = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'GET' ? $_GET : $_POST;
        self::guard($request);
        $requestId = ParcelService::positiveId($request['request_id'] ?? null);
        if ($requestId === null) { wp_send_json_error(['message'=>'شناسه فایل برچسب معتبر نیست.'], 422); }
        try {
            $bytes = \SyncBasalam\SalamGate\Services\LabelPreviewService::read($requestId);
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="salam-gate-label.pdf"');
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message'=>$exception->firstMessage()], 422);
        }
    }

    /**
     * چاپ گروهی با بازه تاریخ (پارت ۸) — body {start_date,end_date,market_names}.
     *
     * تاریخ جلالی UI → بازه تهران با offset صریح (+03:30). بازه بر parcels.created_at
     * اعمال می‌شود؛ «تا» تا انتهای روز انتخابی inclusive است (T23:59:59).
     */
    public static function bulkLabelByDate(): void
    {
        self::guard();

        $post = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';

        $fromJ = self::parseJalaliDate(Digits::toEnglish($post('from_jalali')));
        $toJ = self::parseJalaliDate(Digits::toEnglish($post('to_jalali')));

        if ($fromJ === null || $toJ === null) {
            wp_send_json_error(['message' => 'بازه تاریخ را کامل انتخاب کنید (از تاریخ / تا تاریخ).', 'request_dispatched' => false]);
        }

        // جلالی → میلادی ISO با تهران +03:30
        $fromIso = self::jalaliToTehranIso($fromJ['y'], $fromJ['m'], $fromJ['d'], '00:00:00');
        $toIso = self::jalaliToTehranIso($toJ['y'], $toJ['m'], $toJ['d'], '23:59:59');

        if ($fromIso === null || $toIso === null || $fromIso > $toIso) {
            wp_send_json_error(['message' => 'بازه تاریخ نامعتبر است (از باید قبل از تا باشد).', 'request_dispatched' => false]);
        }

        // بازارهای scope صفحه — فقط بازارهای انتخابی در فیلتر (مرسوله باسلام ناخواسته وارد نمی‌شود)
        $markets = [];
        if (isset($_POST['market_names']) && is_array($_POST['market_names'])) {
            foreach (wp_unslash($_POST['market_names']) as $m) {
                $m = sanitize_key((string) $m);
                if (in_array($m, ParcelsListService::ALL_MARKETS, true)) {
                    $markets[] = $m;
                }
            }
        }
        if ($markets === []) {
            $markets = array_values(array_diff(ParcelsListService::ALL_MARKETS, ['basalam']));
        }

        $template = $post('template');
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) {
            $template = 'invoice';
        }

        $requestDispatched = false;
        try {
            // Resolve the date range to verified, completed shipments before generating any label.
            // API date ranges use updated_at (the registration/payment update), not draft creation.
            $ids = self::registeredIdsByDate($fromIso, $toIso, $markets);
            if ($ids === []) {
                wp_send_json_error(['message' => 'در این بازه مرسوله‌ای با پرداخت و ثبت ارسال کامل پیدا نشد.', 'code' => 'label_not_ready', 'request_dispatched' => false]);
            }
            $requestDispatched = true;
            wp_send_json_success((new ParcelService())->getLabelUrl($ids, $template));
        } catch (SalamGateException $exception) {
            wp_send_json_error(['message' => self::mapLabelError($exception), 'request_dispatched' => $requestDispatched]);
        }
    }

    /** Paginate within the authenticated account; no browser-provided account identifiers. */
    private static function registeredIdsByDate(string $fromIso, string $toIso, array $markets): array
    {
        $query = ParcelsListService::buildListQuery(['market_name' => $markets, 'sort' => 'desc']);
        $from = strtotime($fromIso);
        $to = strtotime($toIso);
        $ids = [];
        $seen = [];
        for ($page = 0; $page < 50; ++$page) {
            $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, $query);
            $body = $response['body'] ?? null;
            if (!is_array($body) || !is_array($body['data'] ?? null)) {
                throw new SalamGateException('فهرست مرسولات برای چاپ تأیید نشد؛ دوباره تلاش کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            foreach ($body['data'] as $parcel) {
                if (!is_array($parcel) || !LabelEligibility::validListShape($parcel)) {
                    throw new SalamGateException('فهرست مرسولات برای چاپ تأیید نشد؛ دوباره تلاش کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                }
                if (!LabelEligibility::canPrintListItem($parcel)) { continue; }
                $updated = $parcel['updated_at'] ?? null;
                $stamp = is_string($updated) ? strtotime($updated) : false;
                if ($stamp === false) {
                    throw new SalamGateException('تاریخ مرسوله برای چاپ تأیید نشد؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                }
                if ( $stamp >= $from && $stamp <= $to) {
                    $ids[] = (int) $parcel['id'];
                    if (count(array_unique($ids)) > 100) {
                        throw new SalamGateException('این بازه بیش از ۱۰۰ مرسوله دارد؛ بازه تاریخ را کوتاه‌تر کنید.', 422);
                    }
                }
            }
            $next = $body['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') { return array_values(array_unique($ids)); }
            if (isset($seen[$next])) { break; }
            $seen[$next] = true;
            $query['cursor'] = $next;
        }
        // Never send a silently truncated label batch if the source pagination is incomplete.
        throw new SalamGateException('بازه چاپ به طور کامل بررسی نشد؛ بازه تاریخ را کوتاه‌تر کنید و دوباره تلاش کنید.', 422);
    }

    /**
     * "1405/07/11" یا "1405-7-11" → ['y','m','d'] (ارقام فارسی قبلاً نرمال شده).
     */
    public static function parseJalaliDate(string $value): ?array
    {
        if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', trim($value), $m)) {
            return null;
        }

        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];

        if (!\SyncBasalam\SalamGate\Utils\JalaliDate::isValidJalali($jy, $jm, $jd)) {
            return null;
        }

        return ['y' => $jy, 'm' => $jm, 'd' => $jd];
    }

    /**
     * جلالی → "YYYY-MM-DDTHH:MM:SS+03:30" (تهران؛ ایران از ۱۴۰۱ DST ندارد).
     */
    public static function jalaliToTehranIso(int $jy, int $jm, int $jd, string $time = '00:00:00'): ?string
    {
        $g = \SyncBasalam\SalamGate\Utils\JalaliDate::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02dT%s+03:30', $g['gy'], $g['gm'], $g['gd'], $time);
    }

    /**
     * پیام فارسی خطاهای لیبل — مخصوصاً درخواست موازی (label-request-in-progress).
     */
    public static function mapLabelError(SalamGateException $exception): string
    {
        foreach ($exception->getErrors() as $error) {
            $haystack = $error['message'] . ' ' . $error['location'];

            if (str_contains($haystack, 'label-request-in-progress')) {
                return 'یک درخواست لیبل در حال پردازش است؛ وضعیت آن را دنبال کنید یا چند لحظه بعد دوباره تلاش کنید.';
            }
        }

        $message = $exception->firstMessage();
        $all = $message . ' ' . json_encode($exception->getErrors(), JSON_UNESCAPED_UNICODE);
        if (str_contains($all, 'invoices:validation:not_paid') || str_contains($all, 'invoice-not-paid')
            || str_contains($all, 'صورتحساب پرداخت نشده') || str_contains($all, 'فاکتور پرداخت نشده')) {
            return 'فرایند پرداخت و تکمیل ارسال مرسوله انجام نشده است؛ پس از پرداخت موفق، برچسب را چاپ کنید.';
        }
        if ($exception->isKind(SalamGateException::KIND_SERVER) && preg_match('/internal server error|^server error$/i', $message)) {
            return 'سلام‌گیت نتوانست برچسب را آماده کند؛ کمی بعد دوباره تلاش کنید.';
        }
        return $exception->firstMessage();
    }

    private static function guard(?array $request = null): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }

        $request ??= $_POST;
        $nonce = is_string($request['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($request['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }
    }
}
