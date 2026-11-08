<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\PaymentRejection;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** ParcelCheckout: explicit inputs, returned outcomes, no HTTP response termination. */
final class ParcelCheckout
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function quotes(bool $listCheckout = false): array
    {
        $parcelId = (new ParcelCheckoutPolicy($this->input, $this->context))->postedPositiveId('parcel_id');
        $market = (new ParcelCheckoutPolicy($this->input, $this->context))->postedMarket('market', $listCheckout);
        if ($parcelId === null || $market === null) {
            return OperationResult::failure(['message' => 'شناسه مرسوله یا مبدأ سفارش نامعتبر است.', 'code' => 'validation']);
        }
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        // Existing financial outcomes only reconcile with a GET; they never refresh a rate.
        if (is_array($previous) && in_array($previous['state'] ?? '', ['paid', 'pending', 'unknown'], true)) {
            return $this->quotesOutcome($parcelId, $market, false, fn (): bool => false, $listCheckout);
        }
        return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $renew): array => $this->quotesOutcome($parcelId, $market, true, $renew, $listCheckout));
    }

    public function quotesOutcome(int $parcelId, string $market, bool $allowQuotes, callable $renew, bool $listCheckout = false): array
    {
        try {
            $edit = get_option((new EditShipment($this->input, $this->context))->editOutcomeKey($parcelId), null);
            if (!$listCheckout && is_array($edit) && ($edit['state'] ?? '') === 'replaced') {
                return (new EditShipment($this->input, $this->context))->recoverReplacement($parcelId, $market, $edit);
            }
            // The attempted update may have migrated the market; do not hide its identity behind a stale filter.
            if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
                return (new EditShipment($this->input, $this->context))->unknownEditOutcome($parcelId, $edit);
            }
            $item = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $market);
            // Recovery is local and lease-fenced; it never submits another payment.
            if (!$allowQuotes && $this->canReconcileFailure($item)) {
                return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $refresh): array => $this->quotesOutcome($parcelId, $market, true, $refresh, $listCheckout));
            }
            if ($allowQuotes && $renew()) $this->reconcileFailedCheckoutPayment($item);
            if ($listCheckout) {
                $checkout = $this->checkoutPayload($item);
                if (!$checkout['can_pay']) {
                    // A read-only recheck never rotates a quote or resubmits an uncertain payment.
                    $result = $checkout['payment_state'] === 'paid' ? (new ParcelCheckoutPolicy($this->input, $this->context))->applyPayResult([], $item, $market, $parcelId) : null;
                    if ($result !== null) {
                        $result = $this->reconcileCheckoutOrder($item, $result, $allowQuotes, $renew);
                    }
                    return ['ok' => true, 'payload' => $checkout + [
                        'quotes' => ['parcel_id' => $parcelId, 'quotes' => [], 'errors' => []],
                        'result' => $result,
                    ]];
                }
            }
            $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
            $paymentState = is_array($previous) && in_array($previous['state'] ?? '', ['paid', 'pending', 'unknown'], true)
                ? $previous['state'] : '';
            if ((new ParcelCheckoutPolicy($this->input, $this->context))->confirmedReceipt($item)) {
                if (!ParcelService::recordReceiptOutcome($item)) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->paymentIdentityError(); }
                $paymentState = 'paid';
            }
            if ($paymentState !== '') {
                $invoiceId = is_array($previous) ? ParcelService::positiveId($previous['invoice_id'] ?? null) : null;
                return ['ok' => true, 'payload' => [
                    'parcel_id' => $parcelId, 'market' => $market, 'payment_state' => $paymentState,
                    'quotes' => ['parcel_id' => $parcelId, 'quotes' => [], 'errors' => []],
                    'result' => (new ParcelCheckoutPolicy($this->input, $this->context))->applyPayResult($invoiceId !== null ? ['invoice' => ['id' => $invoiceId]] : [], $item, $market, $parcelId),
                ]];
            }
            if (!$allowQuotes || !$renew()) {
                return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable();
            }
            (new ParcelCheckoutPolicy($this->input, $this->context))->assertPayableStatus($item);
            $weightReview = (new ParcelCheckoutPolicy($this->input, $this->context))->basalamCheckoutReview($item);
            if (!$renew()) { return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable(); }
            WebsiteShippingEligibility::assertForParcel($item);
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            $quotes = (new ParcelCheckoutPolicy($this->input, $this->context))->checkedQuotes($response['body'] ?? null, $parcelId);
            if ($listCheckout) {
                return ['ok' => true, 'payload' => $this->checkoutPayload($item) + ['quotes' => $quotes,
                    'requires_quote_selection' => is_array($edit)] + ($weightReview !== null ? ['weight_confirmation' => $weightReview] : [])];
            }
            return ['ok' => true, 'payload' => [
                'parcel_id' => $parcelId, 'market' => $market, 'payment_state' => '', 'quotes' => $quotes,
                'parcel_payload' => (new EditShipment($this->input, $this->context))->canonicalParcelPayload($parcelId, $item, is_array($edit) ? $edit : null),
                'payment_failure_confirmed' => ParcelService::paymentFailureConfirmed($item),
                'requires_quote_selection' => is_array($edit),
            ]];
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => (new ParcelCheckoutPolicy($this->input, $this->context))->preflightError($exception)];
        }
    }

    public function selectQuote(bool $listCheckout = false): array
    {

        $post = fn (string $k): string => isset($this->input[$k]) && is_scalar($this->input[$k]) ? trim((string) wp_unslash($this->input[$k])) : '';

        $parcelId = (new ParcelCheckoutPolicy($this->input, $this->context))->postedPositiveId('parcel_id');
        $hash = $post('hash');
        $market = (new ParcelCheckoutPolicy($this->input, $this->context))->postedMarket('market', $listCheckout);

        if ($parcelId === null || $hash === '' || strlen($hash) > 256) {
            return OperationResult::failure(['message' => 'پارامترهای انتخاب نرخ نامعتبر است.']);
        }

        if ($market === null) {
            return OperationResult::failure(['message' => 'مبدأ سفارش نامعتبر است.']);
        }

        return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $renew): array => $this->selectQuoteOutcome($parcelId, $market, $hash, $renew, $listCheckout));
    }

    public function selectQuoteOutcome(int $parcelId, string $market, string $hash, callable $renew, bool $listCheckout = false): array
    {
        $quoteAttempted = false;
        try {
            $edit = get_option((new EditShipment($this->input, $this->context))->editOutcomeKey($parcelId), null);
            if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
                return (new EditShipment($this->input, $this->context))->unknownEditOutcome($parcelId, $edit);
            }
            $item = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $market);
            (new ParcelCheckoutPolicy($this->input, $this->context))->assertPayableStatus($item);
            $weightReview = (new ParcelCheckoutPolicy($this->input, $this->context))->basalamCheckoutReview($item);
            if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null) {
                return ['ok' => false, 'payload' => ['message' => 'پرداخت این مرسوله ثبت شده یا در حال بررسی است؛ نرخ را تغییر ندهید.', 'code' => 'pay_unknown']];
            }
            if (!$renew()) {
                return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable();
            }
            WebsiteShippingEligibility::assertForParcel($item);
            $quoteAttempted = true;
            $response = ClientFactory::make()->put(
                sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId),
                ['hash' => $hash]
            );
            $normalized = (new ParcelCheckoutPolicy($this->input, $this->context))->checkedQuotes($response['body'] ?? null, $parcelId);
            $selected = (new ParcelCheckoutPolicy($this->input, $this->context))->selectedQuote($normalized);
            if ($selected === null || $selected['hash_id'] !== $hash) {
                return ['ok' => false, 'payload' => ['message' => 'نرخ انتخابی تأیید نشد؛ دوباره نرخ بگیرید.', 'code' => 'quote_changed']];
            }

            // Stage3 confirms the same server information that was actually priced, including other-tab edits.
            $canonical = $listCheckout ? null : (new EditShipment($this->input, $this->context))->canonicalParcelPayload($parcelId, $item, is_array($edit) ? $edit : null);
            if (is_array($edit)) {
                if (!$renew()) { return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable(); }
                delete_option((new EditShipment($this->input, $this->context))->editOutcomeKey($parcelId));
            }

            if ($listCheckout) {
                return ['ok' => true, 'payload' => $this->checkoutPayload($item) + ['quotes' => $normalized, 'requires_quote_selection' => false]
                    + ($weightReview !== null ? ['weight_confirmation' => $weightReview] : [])];
            }
            return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'market' => $market, 'quotes' => $normalized,
                'parcel_payload' => $canonical, 'requires_quote_selection' => false]];
        } catch (SalamGateException $e) {
            if (!$quoteAttempted) { return ['ok' => false, 'payload' => (new ParcelCheckoutPolicy($this->input, $this->context))->preflightError($e)]; }
            $payload = ['message' => $e->firstMessage(), 'errors' => $e->getErrors(), 'kind' => $e->getKind()];

            if (ParcelService::isResultUnknown($e)) {
                $payload['code'] = 'quote_unknown';
                $payload['message'] = 'نتیجه انتخاب نرخ تأیید نشد؛ پیش از پرداخت دوباره نرخ‌ها را بررسی کنید.';
            } elseif ($e->errorsAt('parcel.rate') !== [] || $e->errorsAt('body.hash') !== []) {
                $payload['code'] = 'quote_changed';
                $payload['message'] = 'نرخ‌های ارسال تغییر کرده‌اند؛ دوباره نرخ بگیرید و روش را انتخاب کنید.';
            }

            return ['ok' => false, 'payload' => $payload];
        }
    }

    public function pay(bool $listCheckout = false): array
    {

        $post = fn (string $k): string => isset($this->input[$k]) && is_scalar($this->input[$k]) ? trim((string) wp_unslash($this->input[$k])) : '';

        $parcelId = (new ParcelCheckoutPolicy($this->input, $this->context))->postedPositiveId('parcel_id');
        $market = (new ParcelCheckoutPolicy($this->input, $this->context))->postedMarket('market', $listCheckout);
        $method = (new ParcelCheckoutPolicy($this->input, $this->context))->postedPositiveId('method');

        if ($parcelId === null) {
            return OperationResult::failure(['message' => 'شناسه مرسوله نامعتبر است.']);
        }

        if ($market === null) {
            return OperationResult::failure(['message' => 'مبدأ سفارش نامعتبر است.']);
        }

        // method: بازار غیر باسلام فقط 20/21
        if ($method === null || !PayMethods::isValidParcelMethodForMarket($method, $market)) {
            return OperationResult::failure(['message' => 'روش پرداخت انتخاب‌شده برای این مرسوله در دسترس نیست.']);
        }

        $outcomeKey = ParcelService::paymentOutcomeKey($parcelId);
        $previous = get_option($outcomeKey, null);
        if (is_array($previous)) {
            return $this->storedPaymentOutcome($parcelId, $market, $previous);
        }

        $expectedHash = $post('expected_hash');
        $expectedTotal = (new ParcelCheckoutPolicy($this->input, $this->context))->postedInteger('expected_total_rial');
        if ($expectedHash === '' || strlen($expectedHash) > 256 || $expectedTotal === null) {
            return OperationResult::failure(['message' => 'ابتدا نرخ ارسال و مبلغ نهایی را بررسی و تأیید کنید.', 'code' => 'quote_changed']);
        }

        return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $renew): array => $this->newPaymentOutcome($parcelId, $market, $method, $expectedHash, $expectedTotal, $renew, $listCheckout));
    }

    public function newPaymentOutcome(int $parcelId, string $market, int $method, string $expectedHash, int $expectedTotal, callable $renew, bool $listCheckout = false): array
    {
        // A previous request may have settled while we waited to acquire this lease.
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        if (is_array($previous)) {
            return $this->storedPaymentOutcome($parcelId, $market, $previous, true, $renew);
        }
        $edit = get_option((new EditShipment($this->input, $this->context))->editOutcomeKey($parcelId), null);
        if (is_array($edit)) {
            if (($edit['state'] ?? '') !== 'selection_required') {
                return (new EditShipment($this->input, $this->context))->unknownEditOutcome($parcelId, $edit);
            }
            return ['ok' => false, 'payload' => [
                'message' => 'اطلاعات مرسوله ویرایش شده است؛ روش ارسال را از نرخ‌های تازه انتخاب و دوباره تأیید کنید.',
                'code' => 'quote_changed', 'requires_quote_selection' => true,
            ]];
        }
        $paymentAttempted = false;
        try {
            $item = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $market);
            (new ParcelCheckoutPolicy($this->input, $this->context))->assertPayableStatus($item);
            // The bulk wrapper forces this internal context AFTER validating
            // selection membership. Existing individual continuation stays
            // unchanged, while every group payment pins fresh packaged weight
            // under the same quote/payment lease, not a stale browser snapshot.
            if ($listCheckout && $this->context->requiresWeightReview()) {
                try { OrderWeightConfirmation::assertConfirmed(wp_unslash($this->input), $item['weight'] ?? null); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => [
                        'message' => $exception->firstMessage(), 'errors' => $exception->getErrors(),
                        'kind' => $exception->getKind(), 'code' => 'weight_confirmation_required',
                        'current_weight_grams' => Units::parseBoundedFormInteger($item['weight'] ?? null, 50, 30000),
                        'request_dispatched' => false,
                    ]];
                }
            }
            (new ParcelCheckoutPolicy($this->input, $this->context))->basalamCheckoutReview($item, true);
            return (new PaymentWorkflow())->execute(new PaymentCommand($parcelId, $market, $method, $item, $expectedHash, $expectedTotal), $renew,
                function () use ($item): ?array { WebsiteShippingEligibility::assertForParcel($item); return null; },
                [new ParcelCheckoutPolicy($this->input, $this->context), 'leaseUnavailable'],
                function (array $payBody, ?array $parcelItem) use ($parcelId, $market, $item, $listCheckout): array {
            $result = (new ParcelCheckoutPolicy($this->input, $this->context))->applyPayResult($payBody, $parcelItem, $market, $parcelId);
            if ($listCheckout) {
                // The preflight identity also covers an unavailable post-payment read.
                $result = $this->syncCheckoutOrder($payBody, $parcelItem, $item, $market, $result);
            }
            return ['ok' => true, 'payload' => $result];
                },
                function (SalamGateException $exception, string $stage) use (&$paymentAttempted): array {
                    $paymentAttempted = $stage === 'payment';
                    throw $exception;
                });
        } catch (\SyncBasalam\SalamGate\SalamGateException $e) {
            $payload = ['message' => $e->firstMessage(), 'errors' => $e->getErrors(), 'kind' => $e->getKind()];

            if (!$paymentAttempted) {
                $payload = (new ParcelCheckoutPolicy($this->input, $this->context))->preflightError($e);
            } elseif (($notDispatched = PaymentRejection::notDispatchedPayload($e, $parcelId)) !== null) {
                $payload = $notDispatched;
            } elseif (($rejection = PaymentRejection::insufficientFundsPayload($e, $parcelId, $method, $item)) !== null) {
                $payload = $rejection;
            } elseif (ParcelService::isResultUnknown($e)) {
                $payload['code'] = 'pay_unknown';
                $payload['message'] = 'پرداخت در حال بررسی است؛ برای دریافت تأیید، «بررسی وضعیت» را بزنید. پرداخت را تکرار نکنید.';
            } elseif ($e->errorsAt('parcel.rate') !== []) {
                $payload['code'] = 'quote_changed';
                $payload['message'] = 'نرخ تغییر کرده؛ دوباره نرخ بگیرید.';
            } elseif (\SyncBasalam\SalamGate\Contracts\UpstreamFailure::code($e) === 'already_paid') {
                $payload['code'] = 'already_paid';
                $payload['message'] = 'این مرسوله قبلاً پرداخت شده است.';
            }

            return ['ok' => false, 'payload' => $payload];
        }
    }

    public function storedPaymentOutcome(int $parcelId, string $market, array $previous, bool $leaseHeld = false, ?callable $renew = null): array
    {
        if (($previous['state'] ?? '') === 'paid') {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت هزینه ارسال این مرسوله قبلاً ثبت شده است.', 'code' => 'already_paid']];
        }
        try {
            $item = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $market);
        } catch (SalamGateException $exception) {
            $item = null;
        }
        if ($item !== null && (new ParcelCheckoutPolicy($this->input, $this->context))->confirmedReceipt($item)) {
            if (!ParcelService::recordReceiptOutcome($item)) {
                return ['ok' => false, 'payload' => ['message' => (new ParcelCheckoutPolicy($this->input, $this->context))->paymentIdentityError()->firstMessage(), 'code' => 'pay_unknown']];
            }
            return ['ok' => true, 'payload' => (new ParcelCheckoutPolicy($this->input, $this->context))->applyPayResult([], $item, $market, $parcelId)];
        }
        if ($item !== null && $this->canReconcileFailure($item)) {
            $work = function (callable $renew) use ($parcelId, $market): array {
                try { $fresh = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $market); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی در حال بررسی است؛ «بررسی وضعیت» را دوباره بزنید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
                }
                if ($renew() && $this->reconcileFailedCheckoutPayment($fresh)) {
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی ناموفق بود. روش ارسال و پرداخت را دوباره بررسی و تأیید کنید.', 'code' => 'payment_failed', 'payment_state' => 'unpaid']];
                }
                return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی در حال بررسی است؛ برای دریافت تأیید، «بررسی وضعیت» را بزنید.', 'code' => 'pay_unknown']];
            };
            return $leaseHeld && $renew !== null ? $work($renew) : (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, $work);
        }
        return ['ok' => false, 'payload' => ['message' => 'پرداخت در حال بررسی است؛ تأیید آن را از فهرست مرسوله‌ها با «بررسی وضعیت» بگیرید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
    }

    public function canReconcileFailure(array $item): bool
    {
        return ($item['invoice_status'] ?? null) === 3 && ($item['status_id'] ?? null) === 7
            && is_array(get_option(ParcelService::paymentOutcomeKey($item['id']), null));
    }

    public function reconcileFailedCheckoutPayment(array $item): bool
    {
        if (!ParcelService::reconcileFailedPayment($item)) return false;
        try {
            $order = ParcelsListService::linkedOrderForParcel($item);
            if ($order !== null && $order->get_meta('_salam_gate_paid_at') === ''
                && ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id')) === null) {
                $order->update_meta_data('_salam_gate_pay_unknown', '');
                $order->save();
            }
        } catch (\Throwable $exception) {
            // Legacy order markers remain conservative if the local save cannot be completed.
        }
        return true;
    }

    public function syncCheckoutOrder(array $payBody, ?array $parcelItem, array $identity, string $market, array $result): array
    {
        try {
            $order = ParcelsListService::linkedOrderForParcel($identity);
            if ($order !== null) {
                (new OrderShipment([]))->applyPaySuccess($order, $payBody, $parcelItem, $market);
                $result['woo_order_id'] = (int) $order->get_id();
            }
        } catch (\Throwable $exception) {
            // Payment is already settled: a local metadata problem never authorizes another charge.
            $result['order_sync_failed'] = true;
        }
        return $result;
    }

    public function reconcileCheckoutOrder(array $item, array $result, bool $leaseHeld, callable $renew): array
    {
        if (($item['invoice_status'] ?? null) !== 2
            || !\SyncBasalam\SalamGate\Contracts\ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            || !is_string($item['tracking_code'] ?? null) || trim($item['tracking_code']) === '') {
            return $result;
        }
        $work = function (callable $refreshLease) use ($item, $result): array {
            if (!$refreshLease()) { return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable(); }
            if (!ParcelService::recordReceiptOutcome($item)) {
                return ['ok' => false, 'payload' => ['message' => (new ParcelCheckoutPolicy($this->input, $this->context))->paymentIdentityError()->firstMessage(), 'code' => 'pay_unknown']];
            }
            try {
                $order = ParcelsListService::linkedOrderForParcel($item);
                if ($order !== null) {
                    $current = $order->get_meta('_salam_gate_pay_unknown') === ''
                        && $order->get_meta('_salam_gate_paid_at') !== ''
                        && (int) $order->get_meta('_salam_gate_status') === $item['status_id']
                        && (string) $order->get_meta('_salam_gate_tracking_code') === $item['tracking_code']
                        && (string) $order->get_meta('_salam_gate_delivery_node') === (string) ($item['delivery_node'] ?? '');
                    if (is_int($item['shipping_cost_rial'] ?? null)) {
                        $current = $current && (string) $order->get_meta('_salam_gate_shipping_cost_rial') === (string) $item['shipping_cost_rial'];
                    }
                    if (!$current) {
                        // Reuse the verified existing mapping, metadata conventions and note text.
                        $result = $this->syncCheckoutOrder([], $item, $item, $item['market'], $result);
                    } else {
                        $result['woo_order_id'] = (int) $order->get_id();
                    }
                }
            } catch (\Throwable $exception) { $result['order_sync_failed'] = true; }
            return ['ok' => true, 'payload' => $result];
        };
        // Known outcomes bypass the rate lease on initial read. Claim it only for local
        // reconciliation, so an overlapping checkout cannot duplicate order notes.
        $outcome = $leaseHeld ? $work($renew) : (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($item['id'], $work);
        return $outcome['ok'] ? $outcome['payload'] : $result + ['order_sync_pending' => true];
    }

    public function checkoutPayload(array $item): array
    {
        $availability = ParcelsListService::paymentAvailability($item);
        $item['market_label'] = ParcelsListService::MARKET_LABELS[$item['market']] ?? 'سایر';
        return ['parcel_id' => $item['id'], 'market' => $item['market'], 'parcel' => $item,
            'payment_state' => $availability['payment_state'], 'can_pay' => $availability['can_resume_payment'],
            'payment_failure_confirmed' => $availability['can_resume_payment'] && ParcelService::paymentFailureConfirmed($item),
            'allowed_methods' => array_keys(PayMethods::parcelMethodsForMarket($item['market'])),
            'message' => $availability['payment_message']];
    }
}
