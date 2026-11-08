<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;

defined('ABSPATH') || exit;

/** OrderCheckoutPolicy: explicit inputs, returned outcomes, no HTTP response termination. */
final class OrderCheckoutPolicy
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function withQuoteLease(int $parcelId, callable $work): array
    {
        return QuoteWorkflowLease::run($parcelId, $work, [$this, 'quoteLeaseUnavailable']);
    }

    public function quoteLeaseUnavailable(): array
    {
        return ['ok' => false, 'payload' => [
            'message' => 'عملیات دیگری روی این مرسوله در حال اجراست؛ کمی بعد دوباره تلاش کنید. درخواست پرداخت ارسال نشد.',
            'code' => 'quote_busy', 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
        ]];
    }


    public function quoteSelectionBlock(int $parcelId): ?array
    {
        $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
        if (!is_array($edit) || ($edit['state'] ?? '') !== 'selection_required') return null;
        return ['ok' => false, 'payload' => [
            'message' => 'اطلاعات مرسوله تغییر کرده است؛ روش ارسال را دوباره انتخاب کنید.',
            'code' => 'quote_selection_required', 'requires_quote_selection' => true,
            'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
        ]];
    }

    public function quoteMutationBlock($order, int $parcelId): ?array
    {
        // Standalone edits may have changed the parcel's market before a timeout.
        // Preserve its durable uncertainty across the order and standalone entry points.
        $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
        if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
            return ['ok' => false, 'payload' => [
                'message' => 'نتیجه ویرایش مرسوله تأیید نشد؛ همان مرسوله را در فهرست بررسی کنید و مرسوله تازه ثبت نکنید.',
                'code' => 'update_unknown', 'parcel_id' => $parcelId,
                'market' => $edit['original_market'] ?? $edit['market'] ?? '',
                'attempted_market' => $edit['market'] ?? '', 'requires_quote_selection' => true,
            ]];
        }
        $stored = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        if ($order->get_meta('_salam_gate_pay_unknown') || (is_array($stored) && ($stored['state'] ?? '') !== 'paid')) {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت در حال بررسی است؛ برای دریافت تأیید، وضعیت را از فهرست مرسوله‌ها بررسی کنید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
        }
        if (is_array($stored) && ($stored['state'] ?? '') === 'paid'
            || $order->get_meta('_salam_gate_paid_at') || (int) $order->get_meta('_salam_gate_invoice_id') > 0) {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت هزینه ارسال این مرسوله قبلاً ثبت شده است؛ وضعیت را از فهرست مرسولات بررسی کنید.', 'code' => 'already_paid']];
        }
        return null;
    }

    public function basalamQuoteMutationBlock(object $order, int $parcelId): ?array
    {
        OrderContext::assertSupportedOrder($order);
        if (OrderContext::marketFor((int) $order->get_id()) !== OrderContext::MARKET_BASALAM) { return null; }
        try {
            // Rate GET/selection changes the remote draft; source cancellation must
            // stop these calls too, not just the final upsert/payment POST.
            BasalamShippingPreflight::check($order);
            $externalId = OrderContext::externalIdForOrder($order, OrderContext::MARKET_BASALAM);
            $remote = (new ParcelService())->fetchAfterPay($parcelId, OrderContext::MARKET_BASALAM, $externalId);
            if ($externalId === null || $remote === null || ($remote['external_id'] ?? null) !== $externalId) {
                throw new SalamGateException('ارتباط مرسوله موجود با سفارش باسلام تأیید نشد؛ وضعیت ارسال را دوباره بررسی کنید.', 422);
            }
            if (ParcelStatuses::hasReceipt($remote['status_id']) || ($remote['invoice_status'] ?? null) === 2 || !empty($remote['tracking_code'])) {
                return ['ok' => false, 'payload' => ['message' => 'این مرسوله قبلاً پرداخت یا ارسال شده است؛ وضعیت ارسال را بررسی کنید.', 'code' => 'already_paid']];
            }
            if ($remote['status_id'] !== ParcelStatuses::NEW || !in_array($remote['invoice_status'] ?? null, [null, 3, 5], true)) {
                return ['ok' => false, 'payload' => ['message' => 'وضعیت پرداخت این مرسوله در سلام‌گیت هنوز قابل تغییر نیست؛ کمی بعد وضعیت ارسال را دوباره بررسی کنید.',
                    'code' => 'payment_check_required', 'kind' => 'validation', 'errors' => []]];
            }
            return null;
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => $this->errorPayload($exception) + ['code' => 'payment_check_required']];
        }
    }

    public function fetchNormalizedQuotes(int $parcelId): array
    {
        $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));

        return QuoteNormalizer::normalize(is_array($response['body'] ?? null) ? $response['body'] : []);
    }

    public function checkedPaymentQuotes($body, int $parcelId): array
    {
        return PaymentQuotes::checked($body, $parcelId);
    }

    public function orderOrError(): ?object
    {
        $orderId = isset($this->input['order_id']) ? (int) $this->input['order_id'] : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order) {
            throw new WorkflowFailure(['message' => 'سفارش یافت نشد.']);
        }

        if (array_key_exists('basalam_parcel_id', $this->input) && $this->input['basalam_parcel_id'] !== ''
            && $this->input['basalam_parcel_id'] !== null && $this->input['basalam_parcel_id'] !== 0 && $this->input['basalam_parcel_id'] !== '0') {
            $nativeId = ParcelService::positiveId($this->input['basalam_parcel_id']);
            if ($nativeId === null || !BasalamOrderContext::isBasalamOrder($orderId)) {
                throw new WorkflowFailure(['message' => 'مرسوله انتخاب‌شده برای این سفارش معتبر نیست.']);
            }
            try {
                return BasalamParcelOrder::open($order, $nativeId);
            } catch (SalamGateException $exception) { throw new WorkflowFailure($this->errorPayload($exception)); }
        }

        // Once a native scope exists, never silently fall back to the old single
        // parcel fields. An explicit native identity is required on every action.
        if (BasalamOrderContext::isBasalamOrder($orderId)) {
            if (BasalamParcelOrder::hasScopes($order)) {
                throw new WorkflowFailure(['message' => 'ابتدا مرسوله موردنظر این سفارش را انتخاب کنید.', 'code' => 'parcel_selection_required']);
            }
        }

        return $order;
    }

    public function errorPayload(SalamGateException $exception): array
    {
        $payload = [
            'message' => $exception->firstMessage(),
            'errors'  => $exception->getErrors(),
            'kind'    => $exception->getKind(),
        ];

        if ($exception->errorsAt('order.status') !== []) {
            $payload['code'] = 'order_not_actionable';
            $payload['request_dispatched'] = false;
        } elseif ($this->isAlreadyPaid($exception)) {
            $payload['code'] = 'already_paid';
            $payload['message'] = 'این مرسوله قبلاً پرداخت شده است.';
        } elseif ($exception->errorsAt('parcel.rate') !== []) {
            $payload['code'] = 'quote_changed';
            $payload['message'] = 'نرخ‌های ارسال تغییر کرده‌اند؛ دوباره نرخ بگیرید و روش را انتخاب کنید.';
        } elseif ($exception->errorsAt('vendor_balance') !== []) {
            $payload['message'] = 'موجودی تراز غرفه‌دار کافی نیست؛ از پنل باسلام شارژ کنید.';
        } elseif ($exception->errorsAt('user_wallet') !== []) {
            $payload['message'] = 'موجودی کیف پول کافی نیست؛ روش پرداخت دیگری را انتخاب کنید.';
        } elseif ($exception->isKind(SalamGateException::KIND_RATE_LIMITED)) {
            $payload['message'] = 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.';
        }

        return $payload;
    }

    public function isAlreadyPaid(SalamGateException $exception): bool
    {
        return \SyncBasalam\SalamGate\Contracts\UpstreamFailure::code($exception) === 'already_paid';
    }
}
