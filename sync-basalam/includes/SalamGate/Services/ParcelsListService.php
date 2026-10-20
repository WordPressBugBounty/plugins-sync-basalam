<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

/**
 * لیست مرسولات سلام‌گیت + نرمال‌سازی برای UI.
 *
 * نکته حیاتی: مقدار پیش‌فرض market_name در سرور، بازارها به‌جز basalam است؛
 * بنابراین این سرویس همیشه فهرست بازارها را صریح ارسال می‌کند
 * (و کلاینت آنها را با کلید تکراری بدون براکت encode می‌کند).
 */
class ParcelsListService
{
    public const MARKET_LABELS = [
        'basalam'   => 'باسلام',
        'website'   => 'ووکامرس',
        'instagram' => 'اینستاگرام',
        'telegram'  => 'تلگرام',
        'eitaa'     => 'ایتا',
        'bale'      => 'بله',
        'digikala'  => 'دیجی‌کالا',
        'mixin'     => 'میکسین',
        'other'     => 'سایر',
    ];

    public const ALL_MARKETS = ['basalam', 'website', 'instagram', 'telegram', 'eitaa', 'bale', 'digikala', 'mixin', 'other'];

    /** برچسب‌ها از ماشین وضعیت؛ رنگ‌ها فقط نمایشی. */
    public const STATUS_COLORS = [
        ParcelStatuses::NEW                => '#6b7280',
        ParcelStatuses::POSTAL_BARCODE     => '#2563eb',
        ParcelStatuses::SHIPPED            => '#16a34a',
        ParcelStatuses::DELIVERED          => '#059669',
        ParcelStatuses::HANDED_TO_POSTMAN  => '#0891b2',
        ParcelStatuses::RETURNED_TO_SENDER => '#dc2626',
        ParcelStatuses::VOIDED             => '#991b1b',
    ];

    /**
     * @return array{status_id:int, title:string, color:string}[]
     */
    public static function statusBadges(): array
    {
        $badges = [];
        foreach (ParcelStatuses::TITLES as $statusId => $title) {
            if ($statusId === ParcelStatuses::NEW) { $title = 'در انتظار پرداخت'; }
            $badges[$statusId] = [$title, self::STATUS_COLORS[$statusId] ?? '#6b7280'];
        }

        return $badges;
    }

    /**
     * @return array{items: array<int, array>, next_cursor: ?string, previous_cursor: ?string}
     */
    public function list(array $filters): array
    {
        $identity = ConnectionCache::identityHash();
        $query = self::buildListQuery($filters);
        // Unfinished parcels belong in this list too: they are where payment resumes.
        // One API page per request avoids blocking navigation behind serial draft scans.
        $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, $query);
        $body = $response['body'] ?? null;
        if (!is_array($body) || !is_array($body['data'] ?? null)) {
            throw new SalamGateException('فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        $items = [];
        foreach ($body['data'] as $parcel) {
            if (!is_array($parcel) || !LabelEligibility::validListShape($parcel)) {
                throw new SalamGateException('اطلاعات یکی از مرسوله‌ها کامل نیست؛ فهرست را دوباره دریافت کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            $item = self::normalizeItem($parcel);
            if (self::matchesSearch($item, $filters['search'] ?? '')) { $items[] = $item; }
        }
        ConnectionCache::assertCurrentIdentity($identity);
        return ['items' => $items, 'connection_id' => $identity,
            'next_cursor' => is_string($body['next_cursor'] ?? null) && $body['next_cursor'] !== '' ? $body['next_cursor'] : null,
            'previous_cursor' => is_string($body['previous_cursor'] ?? null) && $body['previous_cursor'] !== '' ? $body['previous_cursor'] : null];
    }

    /**
     * پارامترهای لیست از فیلترهای UI — خالص و تست‌پذیر.
     */
    public static function buildListQuery(array $filters): array
    {
        $sort = isset($filters['sort']) && is_scalar($filters['sort']) ? (string) $filters['sort'] : 'desc';

        $query = [
            'per_page' => 30,
            'sort'     => in_array($sort, ['asc', 'desc'], true) ? $sort : 'desc',
        ];

        // بازار همیشه صریح (پیش‌فرض سرور بسالم را حذف می‌کند!)
        $markets = $filters['market_name'] ?? self::ALL_MARKETS;
        if (is_string($markets)) {
            $markets = $markets === 'all' ? self::ALL_MARKETS : [$markets];
        }
        $markets = array_values(array_intersect((array) $markets, self::ALL_MARKETS));
        $query['market_name'] = $markets !== [] ? $markets : self::ALL_MARKETS;

        // Do not synthesize a status array for "all": older deployments accept a scalar.
        // Explicit status remains a single value, including unpaid drafts (7).
        $rawStatus = $filters['status'] ?? '';
        $status = is_scalar($rawStatus) ? (int) Digits::toEnglish(trim((string) $rawStatus)) : 0;
        if ($status > 0 && ParcelStatuses::isValid($status)) {
            $query['status'] = $status;
        }

        $externalId = (int) Digits::toEnglish(trim((string) ($filters['external_id'] ?? '')));
        if ($externalId > 0) {
            $query['external_ids'] = [$externalId];
        }

        if (!empty($filters['cursor']) && is_string($filters['cursor'])) {
            $query['cursor'] = $filters['cursor'];
        }

        return $query;
    }

    /** Search only the authenticated account's returned records; no undocumented API parameters. */
    public static function matchesSearch(array $item, $search): bool
    {
        $normalize = static function ($value): string {
            $value = is_scalar($value) ? Digits::toEnglish((string) $value) : '';
            $value = strtr($value, ['ي' => 'ی', 'ك' => 'ک', '‌' => ' ', '#' => '']);
            $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? '';
            return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
        };
        $search = $normalize($search);
        if ($search === '') { return true; }
        $values = [$item['receiver_name'] ?? '', $item['id'] ?? '', $item['external_id'] ?? '',
            $item['woo_order_id'] ?? '', $item['order_number'] ?? '', $item['basalam_order_id'] ?? ''];
        foreach ($item['items'] ?? [] as $line) { $values[] = $line['title'] ?? ''; }
        foreach (explode(' ', $search) as $word) {
            $found = false;
            foreach ($values as $value) {
                if (str_contains($normalize($value), $word)) { $found = true; break; }
            }
            if (!$found) { return false; }
        }
        return true;
    }

    /**
     * پاسخ جزئیات (ParcelDetailResponse) → ساختار نمایش — adapter مستقل از لیست.
     *
     * این شکل پاسخ market/external_id/tracking_code/invoice ندارد؛ مقادیر مربوطه
     * به null صریح تبدیل می‌شوند (هیچ مقداری از لیست جعل نمی‌شود).
     */
    public static function normalizeDetail(array $detail): array
    {
        $view = ParcelViewNormalizer::fromDetail($detail);

        $view['status_color'] = self::STATUS_COLORS[$view['status_id']] ?? '#6b7280';
        $view['market_label'] = '—';
        $view['box_type'] = (string) ($detail['box_type']['title'] ?? '');
        $view['created_at_display'] = self::formatJalali($view['created_at']);
        $view['woo_order_id'] = null;
        $view['basalam_parcel_id'] = null;
        $view['post_tracking_url'] = null;
        $view['can_print_label'] = false;

        return $view;
    }

    /**
     * رکورد لیست (ParcelResponse) → ساختار نمایش — adapter لیست.
     */
    public static function normalizeItem(array $parcel): array
    {
        // A successful cancel response is stronger than a lagging list read.
        $cancel = get_option(ParcelCancellationService::key((int) ($parcel['id'] ?? 0)), null);
        if (is_array($cancel) && ($cancel['state'] ?? '') === 'confirmed'
            && ($cancel['market'] ?? null) === ($parcel['market']['value'] ?? null)) {
            $parcel['status'] = ['id' => ParcelStatuses::VOIDED, 'title' => ParcelStatuses::title(ParcelStatuses::VOIDED)];
            $parcel['invoice'] = ['status' => 63] + (is_array($parcel['invoice'] ?? null) ? $parcel['invoice'] : []);
            $parcel['tracking_code'] = null;
        }
        $view = ParcelViewNormalizer::fromListItem($parcel);

        $view['status_color'] = self::STATUS_COLORS[$view['status_id']] ?? '#6b7280';
        $view['market_label'] = self::MARKET_LABELS[$view['market']] ?? ($view['market'] !== '' ? $view['market'] : '—');
        $view['box_type'] = (string) ($parcel['box_type']['title'] ?? '');
        $view['created_at_display'] = self::formatJalali($view['created_at']);
        $linkedOrder = self::linkedOrderForParcel($view);
        $view['woo_order_id'] = $linkedOrder !== null ? (int) $linkedOrder->get_id() : null;
        $view['order_number'] = $linkedOrder !== null ? (string) $linkedOrder->get_order_number() : null;
        $view['basalam_order_id'] = $linkedOrder !== null && $view['market'] === 'basalam'
            ? BasalamOrderContext::getInvoiceId((int) $linkedOrder->get_id()) : null;
        $view['basalam_parcel_id'] = $linkedOrder instanceof BasalamParcelOrder
            ? $linkedOrder->getNativeParcelId()
            : ($linkedOrder !== null && $view['market'] === 'basalam' ? ParcelService::positiveId($view['external_id']) : null);
        $view['post_tracking_url'] = $view['tracking_code'] !== null && $view['tracking_code'] !== ''
            ? ParcelService::postTrackingUrl($view['tracking_code'])
            : null;
        $view['can_print_label'] = LabelEligibility::canPrintListItem($parcel);
        $view['can_cancel'] = \SyncBasalam\SalamGate\Contracts\CancellationEligibility::canCancel($view);
        $view['can_check_cancel'] = is_array($cancel) && (in_array($cancel['state'] ?? '', ['pending', 'review'], true)
            || (($cancel['state'] ?? '') === 'confirmed' && empty($cancel['order_synced'])));
        if ($view['can_check_cancel']) { $view['can_cancel'] = false; $view['can_print_label'] = false; }
        if (is_array($cancel) && ($cancel['state'] ?? '') === 'retryable') { $view['can_print_label'] = false; }
        $view += self::paymentAvailability($view);

        // محتویات: خلاصه عناوین اقلام واقعی از پاسخ لیست (فقط نمایش)
        $contents = [];
        foreach ((array) ($parcel['items'] ?? []) as $item) {
            $title = (string) ($item['title'] ?? '');
            if ($title !== '') {
                $contents[] = $title;
            }
        }
        $view['contents'] = implode('، ', array_slice($contents, 0, 3)) . (count($contents) > 3 ? '…' : '');

        // روش ارسال از shipping_info.method (فقط پس از انتخاب نرخ)
        $view['method_title'] = (string) ($parcel['shipping_info']['method']['title'] ?? '');
        $receiver = is_array($parcel['shipping_info']['receiver'] ?? null) ? $parcel['shipping_info']['receiver'] : [];
        $view['receiver_city'] = is_string($receiver['city']['title'] ?? null) ? $receiver['city']['title'] : '';
        $view['receiver_mobile'] = is_string($receiver['phone'] ?? null) ? $receiver['phone'] : '';
        $view['receiver_address'] = is_string($receiver['address'] ?? null) ? $receiver['address'] : '';
        $view['receiver_postal_code'] = is_string($receiver['postal_code'] ?? null) ? $receiver['postal_code'] : '';
        $view['category_title'] = is_string($parcel['category']['title'] ?? null) ? $parcel['category']['title'] : '';
        $view['invoice_id'] = ParcelService::positiveId($parcel['invoice']['id'] ?? null);
        $view['invoice_paid_at'] = is_string($parcel['invoice']['paid_at'] ?? null) ? $parcel['invoice']['paid_at'] : null;
        $view['updated_at'] = is_string($parcel['updated_at'] ?? null) ? $parcel['updated_at'] : '';
        $view['items'] = [];
        foreach ((array) ($parcel['items'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            $view['items'][] = ['title' => is_string($item['title'] ?? null) ? $item['title'] : '',
                'quantity' => ParcelService::positiveId($item['quantity'] ?? null),
                'sku' => is_string($item['sku'] ?? null) ? $item['sku'] : null];
        }

        return $view;
    }

    /** Display hint only. Checkout re-fetches account-owned identity before every operation. */
    public static function paymentAvailability(array $parcel): array
    {
        $id = ParcelService::positiveId($parcel['id'] ?? null);
        $state = 'blocked';
        $message = 'پرداخت برای این وضعیت مرسوله در دسترس نیست.';
        $note = 'وضعیت نیاز به بررسی دارد';
        $local = $id !== null ? get_option(ParcelService::paymentOutcomeKey($id), null) : null;
        $edit = $id !== null ? get_option('salam_gate_parcel_edit_' . $id, null) : null;
        $local = is_array($local) ? $local : [];
        $status = $parcel['status_id'] ?? null;
        $invoice = $parcel['invoice_status'] ?? null;
        $order = self::linkedOrderForParcel($parcel);
        require_once __DIR__ . '/WebsitePaymentGuard.php';
        // An older website draft can have a different SalamGate id while still
        // belonging to an order whose shipping was paid through WooCommerce.
        // This read-only guard never widens the mapping used for order writes.
        $websiteBlock = WebsitePaymentGuard::blockForParcel($parcel);
        if ($status === ParcelStatuses::VOIDED || $invoice === 63) {
            $message = $invoice === 63 ? 'هزینه این مرسوله عودت شده است؛ برای بررسی به جزئیات مراجعه کنید.' : 'این مرسوله ابطال شده و قابل پرداخت نیست.';
            $note = $invoice === 63 ? 'هزینه برگشت داده شده' : 'مرسوله باطل شده';
        } elseif ($websiteBlock !== null && $websiteBlock['state'] === 'paid') {
            $state = 'paid';
            $message = $websiteBlock['message'];
            $note = 'هزینه سفارش قبلاً پرداخت شده';
        } elseif (is_int($status) && ParcelStatuses::hasReceipt($status) && $invoice !== 2) {
            $state = 'unknown';
            $message = 'وضعیت ارسال ثبت شده، اما اطلاعات پرداخت کامل نیست؛ فقط وضعیت را بررسی کنید و دوباره پرداخت نکنید.';
            $note = 'در انتظار تأیید پرداخت';
        } elseif (is_int($status) && ParcelStatuses::hasReceipt($status)) {
            $state = 'paid';
            $message = 'پرداخت هزینه ارسال ثبت شده است.';
            $note = 'هزینه ارسال پرداخت شده';
        } elseif (($local['state'] ?? '') === 'paid' || $invoice === 2) {
            $state = 'paid';
            $message = 'پرداخت ثبت شده است؛ صدور کد رهگیری را با «بررسی وضعیت» پیگیری کنید.';
            $note = 'پرداخت شده؛ منتظر کد رهگیری';
        } elseif ($websiteBlock !== null) {
            $state = $websiteBlock['state'];
            $message = $websiteBlock['message'];
            $note = in_array($state, ['pending', 'unknown'], true) ? 'در انتظار تأیید پرداخت' : 'ارتباط با سفارش تأیید نشد';
        } elseif (in_array($local['state'] ?? '', ['pending', 'unknown'], true)
            || ($order !== null && $order->get_meta('_salam_gate_pay_unknown') !== '')) {
            $state = in_array($local['state'] ?? '', ['pending', 'unknown'], true) ? $local['state'] : 'unknown';
            $message = 'پرداخت در حال بررسی است؛ برای دریافت تأیید، «بررسی وضعیت» را بزنید. پرداخت را تکرار نکنید.';
            $note = 'در انتظار تأیید پرداخت';
        } elseif (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
            $message = ($edit['state'] ?? '') === 'replaced'
                ? 'این پیش‌نویس جایگزین شده است؛ پرداخت را از مرسوله جدید ادامه دهید.'
                : 'نتیجه ویرایش مرسوله هنوز مشخص نیست؛ فعلاً پرداخت نکنید.';
            $note = ($edit['state'] ?? '') === 'replaced' ? 'پرداخت از مرسوله جایگزین' : 'ویرایش مرسوله در حال بررسی';
        } elseif ($order !== null && ($order->get_meta('_salam_gate_paid_at') !== ''
            || ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id')) !== null)) {
            $state = 'paid';
            $message = 'پرداخت قبلی روی سفارش ثبت شده است؛ برای جلوگیری از پرداخت دوباره، وضعیت را بررسی کنید.';
            $note = 'هزینه سفارش قبلاً پرداخت شده';
        } elseif ($invoice === 4) {
            $state = 'pending';
            $message = 'پرداخت این مرسوله در حال بررسی است؛ پس از مشخص‌شدن نتیجه، ادامه دهید.';
            $note = 'در انتظار تأیید پرداخت';
        } elseif (($parcel['market'] ?? '') === 'website'
            && ($eligibility = WebsiteShippingEligibility::blockForOrder(WebsitePaymentGuard::linkedOrderForParcel($parcel))) !== null) {
            $message = $eligibility['payload']['message'];
            $note = 'سفارش قابل ارسال نیست';
        } elseif ($id !== null && $status === ParcelStatuses::NEW
            && in_array($parcel['market'] ?? '', self::ALL_MARKETS, true)
            && (!is_string($parcel['tracking_code'] ?? null) || trim($parcel['tracking_code']) === '')
            && in_array($invoice, [null, 3, 5], true)) {
            $state = 'unpaid';
            $message = $invoice === 3 ? 'پرداخت قبلی کامل نشد؛ روش ارسال و پرداخت را بررسی و دوباره تأیید کنید.' : '';
            $note = '';
        } elseif (is_string($parcel['tracking_code'] ?? null) && trim($parcel['tracking_code']) !== '') {
            $note = 'کد رهگیری صادر شده؛ وضعیت را بررسی کنید';
        }
        $label = $state === 'paid' ? 'پرداخت شده' : ($state === 'unpaid' ? 'در انتظار پرداخت'
            : (in_array($state, ['pending', 'unknown'], true) ? 'در حال بررسی' : 'تأیید نشده'));
        if ($note === 'مرسوله باطل شده') { $label = 'باطل شده'; }
        elseif ($note === 'هزینه برگشت داده شده') { $label = 'برگشت وجه'; }
        return ['payment_state' => $state, 'payment_message' => $message, 'payment_note' => $note, 'payment_label' => $label,
            'can_resume_payment' => $state === 'unpaid',
            'can_check_payment' => in_array($state, ['pending', 'unknown'], true)
                || ($state === 'paid' && $status === ParcelStatuses::NEW)];
    }

    /** @return \WC_Order|BasalamParcelOrder|null A remote ID alone never authorizes local writes. */
    public static function linkedOrderForParcel(array $parcel): ?object
    {
        if (!function_exists('wc_get_order')) { return null; }
        require_once dirname(__DIR__) . '/Order/OrderParcelMapping.php';
        $market = $parcel['market'] ?? '';
        $parcelId = ParcelService::positiveId($parcel['id'] ?? null);
        if ($parcelId === null || !in_array($market, ['website', 'basalam'], true)) { return null; }
        // The service clears website external_id on UPDATE and replacements
        // may have none. The unique saved SalamGate id remains authoritative.
        $rawExternal = $parcel['external_id'] ?? null;
        $externalId = ParcelService::positiveId($rawExternal);
        if (($rawExternal !== null && $externalId === null)
            || ($market === 'basalam' && $externalId === null)) { return null; }
        $orderId = \SyncBasalam\SalamGate\Order\OrderParcelMapping::orderIdForParcel($parcelId);
        $order = $orderId !== null ? wc_get_order($orderId) : null;
        if ($order instanceof \WC_Order && $market === 'basalam') {
            require_once dirname(__DIR__) . '/Order/BasalamParcelOrder.php';
            $nativeLink = $order->get_meta('_salam_gate_native_link_' . $parcelId);
            if ($nativeLink !== '' && $nativeLink !== null) {
                $nativeId = ParcelService::positiveId($nativeLink);
                $scope = $nativeId !== null ? BasalamParcelOrder::stored($order, $nativeId) : null;
                if ($scope === null || $externalId !== $nativeId
                    || ParcelService::positiveId($order->get_meta('_salam_gate_native_origin_' . $nativeId)) !== $parcelId
                    || ParcelService::positiveId($scope->get_meta('_salam_gate_parcel_id')) !== $parcelId
                    || ParcelService::positiveId($scope->get_meta('_salam_gate_external_id')) !== $nativeId
                    || $scope->get_meta('_salam_gate_market') !== $market) { return null; }
                return $scope;
            }
            // A damaged/migrated registry must not expose root financial guards to a sibling.
            if (BasalamParcelOrder::hasScopes($order)) { return null; }
        }
        $expectedExternal = $order instanceof \WC_Order ? OrderContext::externalIdFor($orderId, $market) : null;
        if (!$order instanceof \WC_Order || ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id')) !== $parcelId
            || OrderContext::marketFor($orderId) !== $market
            || $expectedExternal === null
            || ($externalId !== null && $expectedExternal !== $externalId)
            || ($order->get_meta('_salam_gate_market') !== '' && $order->get_meta('_salam_gate_market') !== $market)
            || ($order->get_meta('_salam_gate_external_id') !== ''
                && ParcelService::positiveId($order->get_meta('_salam_gate_external_id')) !== $expectedExternal)) {
            return null;
        }
        return $order;
    }

    /**
     * UTC ISO → تاریخ/ساعت تهران جلالی (از DateConverter پایه در صورت وجود).
     */
    public static function formatJalali(string $iso): string
    {
        if ($iso === '') {
            return '—';
        }

        if (class_exists(\SyncBasalam\Utilities\DateConverter::class)) {
            $jalali = \SyncBasalam\Utilities\DateConverter::utcToJalaliDateTime($iso);
            if ($jalali !== null && $jalali !== '') {
                // "YYYY/MM/DD - H:i:s" → "YYYY/MM/DD H:i"
                $parts = explode(' - ', $jalali);
                $time = isset($parts[1]) ? $parts[1] : '';
                $time = substr($time, 0, 5); // حذف ثانیه

                return trim($parts[0] . ' ' . $time);
            }

            return $iso;
        }

        $timestamp = strtotime($iso);

        return $timestamp !== false ? date_i18n('Y/m/d H:i', $timestamp) : $iso;
    }

    /**
     * سفارش وو متناظر — فقط با نگاشت معتبر:
     * - هر دو بازار: شناسه خارجی ذخیره‌شده و یکتا؛ هیچ شناسه‌ای شماره سفارش وو فرض نمی‌شود.
     * - باسلام: invoice_id شماره سفارش است و برای جست‌وجوی شناسه مرسوله استفاده نمی‌شود.
     * - سایر بازارها: بدون رابطه قطعی → null (سفارش باسلام فرض نمی‌شوند).
     */
    public static function resolveWooOrderId(string $market, ?int $externalId): ?int
    {
        if ($externalId === null || $externalId <= 0 || !function_exists('wc_get_order')
            || !in_array($market, [OrderContext::MARKET_WEBSITE, OrderContext::MARKET_BASALAM], true)) {
            return null;
        }
        require_once dirname(__DIR__) . '/Order/OrderParcelMapping.php';
        $orderId = \SyncBasalam\SalamGate\Order\OrderParcelMapping::orderIdForExternalId($externalId);
        $order = $orderId !== null ? wc_get_order($orderId) : null;
        if ($order instanceof \WC_Order && $market === OrderContext::MARKET_BASALAM) {
            $origin = $order->get_meta('_salam_gate_native_origin_' . $externalId);
            if ($origin !== '' && $origin !== null) {
                $parcelId = ParcelService::positiveId($origin);
                $scope = $parcelId !== null ? self::linkedOrderForParcel([
                    'id' => $parcelId, 'market' => $market, 'external_id' => $externalId,
                ]) : null;
                return $scope instanceof BasalamParcelOrder && (int) $scope->get_id() === $orderId ? $orderId : null;
            }
            require_once dirname(__DIR__) . '/Order/BasalamParcelOrder.php';
            if (BasalamParcelOrder::hasScopes($order)) { return null; }
        }
        if (!$order instanceof \WC_Order || OrderContext::marketFor($orderId) !== $market
            || OrderContext::externalIdFor($orderId, $market) !== $externalId
            || ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id')) === null
            || ($order->get_meta('_salam_gate_market') !== '' && $order->get_meta('_salam_gate_market') !== $market)) {
            return null;
        }
        return $orderId;
    }

    private static function formatDate(string $iso): string
    {
        if ($iso === '') {
            return '—';
        }

        $timestamp = strtotime($iso);

        return $timestamp !== false ? date_i18n('Y/m/d H:i', $timestamp) : $iso;
    }
}
