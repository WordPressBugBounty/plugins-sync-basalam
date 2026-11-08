<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Order\BasalamCheckoutPreflight;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** ParcelCheckoutPolicy: explicit inputs, returned outcomes, no HTTP response termination. */
final class ParcelCheckoutPolicy
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function assertParcelMarket(int $parcelId, string $market): array
    {
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        $guarded = is_array($previous) && in_array($previous['state'] ?? '', ['paid', 'pending', 'unknown'], true);
        $native = $guarded && $market === 'basalam'
            ? ParcelService::positiveId($previous['baseline_external_id'] ?? null) : null;
        $bulkIdentity = $this->bulkNativeIdentity($parcelId, $market);
        if ($bulkIdentity !== null) {
            if ($guarded && $native !== $bulkIdentity['parcel_id']) { throw $this->paymentIdentityError(); }
            // Reapply the exact reviewed child inside the shipping lease. The
            // wrapper's earlier read cannot authorize an unlinked draft that
            // was reassigned to another native order before this fresh fetch.
            $native = $bulkIdentity['parcel_id'];
        }
        if ($guarded && !ParcelService::matchesPaymentIdentity(['market' => $market, 'external_id' => $native], $previous)) {
            throw $this->paymentIdentityError();
        }
        try { $item = (new ParcelService())->fetchAfterPay($parcelId, $market, $native); }
        catch (SalamGateException $exception) {
            if ($guarded) { throw $this->paymentIdentityError(); }
            throw $exception;
        }
        if ($guarded && $item !== null && !ParcelService::matchesPaymentIdentity($item, $previous)) {
            throw $this->paymentIdentityError();
        }
        if ($item === null) {
            if ($guarded) { throw $this->paymentIdentityError(); }
            throw new SalamGateException('مرسوله برای حساب متصل و مبدأ انتخابی یافت نشد.', 404, [
                ['location' => 'parcel_id', 'message' => 'هویت مرسوله تأیید نشد.'],
            ], SalamGateException::KIND_NOT_FOUND);
        }
        return $item;
    }

    public function bulkNativeIdentity(int $parcelId, string $market): ?array
    {
        $pin = $this->context->nativeIdentity();
        if ($pin === null) { return null; }
        if ($market !== 'basalam' || !is_array($pin) || ($pin['salam_gate_parcel_id'] ?? null) !== $parcelId
            || !is_int($pin['parcel_id'] ?? null) || $pin['parcel_id'] < 1
            || !is_int($pin['invoice_id'] ?? null) || $pin['invoice_id'] < 1
            || !is_int($pin['vendor_id'] ?? null) || $pin['vendor_id'] < 1
            || !is_string($pin['allocation_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $pin['allocation_hash'])) {
            throw new SalamGateException('ارتباط بسته انتخاب‌شده تغییر کرده است؛ ارسال گروهی را دوباره باز کنید. پرداختی انجام نشد.',
                422, [], SalamGateException::KIND_VALIDATION);
        }
        return $pin;
    }

    public function paymentIdentityError(): SalamGateException
    {
        return new SalamGateException('پرداخت قبلی در حال بررسی است؛ تأیید آن برای این مرسوله هنوز دریافت نشده است. وضعیت ارسال را بررسی کنید و پرداخت را تکرار نکنید.',
            422, [['location' => 'payment.identity', 'message' => 'payment-identity-unconfirmed']], SalamGateException::KIND_VALIDATION);
    }

    public function confirmedReceipt(array $item): bool
    {
        return \SyncBasalam\SalamGate\Contracts\ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            && (($item['market'] ?? '') !== 'basalam' || (($item['invoice_status'] ?? null) === 2
                && is_string($item['tracking_code'] ?? null) && trim($item['tracking_code']) !== ''));
    }

    public function assertPayableStatus(array $item): void
    {
        WebsiteShippingEligibility::assertForParcel($item);
        if (!\SyncBasalam\SalamGate\Contracts\ParcelStatuses::requiresPayment((int) $item['status_id'])) {
            throw new SalamGateException('فقط مرسوله جدید قابل انتخاب نرخ و پرداخت است؛ وضعیت این مرسوله را از فهرست بررسی کنید.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        // A new parcel may already have a paid/pending invoice while receipt issuance is delayed.
        if (!in_array($item['invoice_status'] ?? null, [null, 3, 5], true)) {
            throw new SalamGateException('پرداخت قبلی این مرسوله نیاز به بررسی دارد؛ دوباره پرداخت نکنید و وضعیت را بررسی کنید.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        if (is_string($item['tracking_code'] ?? null) && trim($item['tracking_code']) !== '') {
            throw new SalamGateException('برای این مرسوله کد رهگیری ثبت شده است؛ قبل از هر پرداخت، وضعیت ارسال را بررسی کنید.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        // Legacy WooCommerce outcomes may predate the shared durable payment marker.
        if (class_exists(ParcelsListService::class)) {
            $availability = ParcelsListService::paymentAvailability($item);
            if (!$availability['can_resume_payment']) {
                throw new SalamGateException($availability['payment_message'], 422, [], SalamGateException::KIND_VALIDATION);
            }
        }
    }

    public function basalamCheckoutReview(array $item, bool $requireWeightConsent = false): ?array
    {
        if (($item['market'] ?? '') !== 'basalam') { return null; }
        $review = BasalamCheckoutPreflight::check($item, $requireWeightConsent, wp_unslash($this->input));
        $pin = $this->bulkNativeIdentity((int) $item['id'], 'basalam');
        if ($pin !== null) {
            $identity = $review['preflight']['identity'];
            $allocation = [];
            foreach ($review['preflight']['order_data']['items'] as $line) {
                $allocation[$line['external_id']] = $line['quantity'];
            }
            ksort($allocation);
            if ($identity['parcel_id'] !== $pin['parcel_id'] || $identity['invoice_id'] !== $pin['invoice_id']
                || $identity['vendor_id'] !== $pin['vendor_id'] || hash('sha256', serialize($allocation)) !== $pin['allocation_hash']) {
                throw new SalamGateException('سفارش یا اقلام این بسته از زمان انتخاب تغییر کرده است؛ ارسال گروهی را دوباره باز کنید. پرداختی انجام نشد.',
                    422, [], SalamGateException::KIND_VALIDATION);
            }
        }
        return $review['weight_confirmation'];
    }

    public function checkedQuotes($body, int $parcelId): array
    {
        return PaymentQuotes::checked($body, $parcelId);
    }

    public function selectedQuote(array $quotes): ?array
    {
        foreach ($quotes['quotes'] as $quote) {
            if ($quote['selected'] && !$quote['error']) {
                return $quote;
            }
        }
        return null;
    }

    public function invalidQuotes(): SalamGateException
    {
        return new SalamGateException('پاسخ نرخ ارسال معتبر نیست؛ انتخاب و مبلغ تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }

    public function preflightError(SalamGateException $exception): array
    {
        if ($exception->errorsAt('order.status') !== []) {
            return ['message' => $exception->firstMessage(), 'code' => 'order_not_actionable',
                'errors' => $exception->getErrors(), 'kind' => $exception->getKind(), 'request_dispatched' => false];
        }
        if ($exception->errorsAt('payment.identity') !== []) {
            return ['message' => $exception->firstMessage(), 'code' => 'pay_unknown'];
        }
        return [
            'message' => ParcelService::isResultUnknown($exception)
                ? 'اطلاعات مرسوله و نرخ ارسال دریافت نشد؛ درخواست پرداخت ارسال نشده است. دوباره بررسی کنید.'
                : $exception->firstMessage(),
            'errors' => $exception->getErrors(),
            'kind' => $exception->getKind(),
            'code' => 'preflight_unavailable',
        ];
    }

    public function applyPayResult(array $payBody, ?array $parcelItem, string $market, int $parcelId = 0): array
    {
        $statusId = (int) ($parcelItem['status_id'] ?? 0);
        $confirmed = $parcelItem !== null && $this->confirmedReceipt($parcelItem);
        $trackingCode = (string) ($parcelItem['tracking_code'] ?? '');

        $invoiceId = isset($payBody['invoice']['id']) ? (int) $payBody['invoice']['id'] : 0;
        $amount = $payBody['invoice']['amount'] ?? null;
        $invoiceStatus = $payBody['invoice']['status'] ?? null;
        $finalRial = is_int($amount) && $amount >= 0 && ($invoiceStatus === null || $invoiceStatus === 2) ? $amount : null;
        if ($finalRial === null && ($parcelItem['invoice_status'] ?? null) === 2
            && is_int($parcelItem['shipping_cost_rial'] ?? null) && $parcelItem['shipping_cost_rial'] >= 0) {
            $finalRial = $parcelItem['shipping_cost_rial'];
        }
        $deliveryNode = (string) ($payBody['delivery_node']['title'] ?? '');
        if ($deliveryNode === '') {
            $deliveryNode = (string) ($parcelItem['delivery_node'] ?? '');
        }

        return [
            'parcel_id'     => $parcelId > 0 ? $parcelId : (int) ($parcelItem['id'] ?? 0),
            'tracking_code' => $confirmed ? $trackingCode : '',
            'status_id'     => $confirmed ? $statusId : 0,
            'status_title'  => $confirmed ? (string) ($parcelItem['status_title'] ?? '') : 'در حال بررسی',
            'confirmed'     => $confirmed,
            'invoice_id'    => $invoiceId,
            'final_total_rial' => $finalRial,
            'final_total_toman' => $finalRial !== null ? Units::toToman($finalRial) : null,
            'delivery_node' => $deliveryNode,
            'post_tracking_url' => $confirmed && $trackingCode !== ''
                ? \SyncBasalam\SalamGate\Services\ParcelService::postTrackingUrl($trackingCode)
                : null,
        ];
    }

    public function withQuoteLease(int $parcelId, callable $work): array
    {
        return QuoteWorkflowLease::run($parcelId, $work, [$this, 'leaseUnavailable']);
    }

    public function leaseUnavailable(): array
    {
        return ['ok' => false, 'payload' => [
            'message' => 'عملیات دیگری روی این مرسوله در حال اجراست؛ کمی بعد دوباره بررسی کنید. درخواست پرداخت ارسال نشد.',
            'code' => 'preflight_locked',
        ]];
    }


    public function postedInteger(string $key): ?int
    {
        $raw = $this->input[$key] ?? null;
        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }
        $raw = Digits::toEnglish(trim((string) wp_unslash($raw)));
        if (!preg_match('/^(0|[1-9]\d*)$/D', $raw)) {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? null : $value;
    }

    public function postedPositiveId(string $key): ?int
    {
        $value = $this->postedInteger($key);
        return $value !== null && $value > 0 ? $value : null;
    }

    public function postedMarket(string $key = 'market', bool $listCheckout = false): ?string
    {
        $raw = $this->input[$key] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $market = sanitize_key(wp_unslash($raw));
        return in_array($market, $listCheckout ? ParcelsListService::ALL_MARKETS : DirectShipment::MARKETS, true) ? $market : null;
    }
}
