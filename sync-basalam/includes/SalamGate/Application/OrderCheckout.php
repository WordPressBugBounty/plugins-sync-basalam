<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\PaymentRejection;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** OrderCheckout: explicit inputs, returned outcomes, no HTTP response termination. */
final class OrderCheckout
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function getQuotes(): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            return OperationResult::failure(['message' => 'برای این سفارش هنوز مرسوله سلام‌گیت ساخته نشده است.']);
        }

        return (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $renew): array => $this->quotesOutcome($order, $parcelId, $renew));
    }

    public function selectQuote(): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        $hash = isset($this->input['hash']) ? sanitize_text_field(wp_unslash($this->input['hash'])) : '';

        if ($parcelId <= 0 || $hash === '') {
            return OperationResult::failure(['message' => 'پارامترهای انتخاب نرخ نامعتبر است.']);
        }

        return (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, function (callable $renew) use ($order, $parcelId, $hash): array {
            $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->quoteMutationBlock($order, $parcelId);
            if ($blocked !== null) return $blocked;
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->basalamQuoteMutationBlock($order, $parcelId);
            if ($blocked !== null) return $blocked;
            if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();
            try {
                WebsiteShippingEligibility::assertForOrder($order);
                if (OrderContext::marketFor((int) $order->get_id()) === OrderContext::MARKET_WEBSITE
                    && get_option('salam_gate_parcel_edit_' . $parcelId, null) !== null) {
                    $outcome = (new DirectShipment($this->input, $this->context))->selectWebsiteOrderQuote($parcelId, $hash, $renew);
                    if (!$outcome['ok']) return $outcome;
                    $normalized = $outcome['payload']['quotes'];
                    $canonical = $outcome['payload']['parcel_payload'] ?? null;
                    $storedDraft = $order->get_meta('_salam_gate_order_draft_payload');
                    if (is_array($canonical) && is_array($storedDraft)) {
                        // Saved snapshots already carry the verified local display identity.
                        $localItems = $storedDraft['items'] ?? [];
                        foreach ($localItems as &$localItem) {
                            $localItem['_order_item_id'] = (new PrepareShipment($this->input, $this->context))->storedOrderItemId($localItem);
                        }
                        unset($localItem);
                        $canonical['items'] = (new PrepareShipment($this->input, $this->context))->associateRemoteItems($canonical['items'], $localItems);
                    }
                } else {
                    $response = ClientFactory::make()->put(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId), ['hash' => $hash]);
                    $normalized = (new OrderCheckoutPolicy($this->input, $this->context))->checkedPaymentQuotes($response['body'] ?? null, $parcelId);
                }

                // A PUT response must confirm the exact requested selection before
                // storing a local service name or announcing successful selection.
                $selected = array_values(array_filter($normalized['quotes'],
                    fn (array $quote): bool => $quote['selected'] === true && !$quote['error']));
                if (count($selected) !== 1 || $selected[0]['hash_id'] !== $hash) {
                    return ['ok' => false, 'payload' => ['message' => 'نرخ انتخابی تأیید نشد؛ دوباره نرخ‌ها را دریافت و انتخاب کنید.',
                        'code' => 'quote_changed', 'request_dispatched' => true]];
                }

                // سرویس انتخابی را ذخیره کن
                foreach ($normalized['quotes'] as $quote) {
                    if ($quote['selected'] && $quote['hash_id'] === $hash) {
                        $order->update_meta_data('_salam_gate_selected_service', $quote['service_name'] !== '' ? $quote['service_name'] : $quote['service_type_label']);
                        $order->save();
                        break;
                    }
                }

                return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'quotes' => $normalized]
                    + (isset($canonical) ? ['parcel_payload' => $canonical, 'requires_quote_selection' => false] : [])];
            } catch (SalamGateException $exception) {
                return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)];
            }
        });
    }

    public function payParcel(): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        $orderId = (int) $order->get_id();

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            return OperationResult::failure(['message' => 'ابتدا مرسوله سلام‌گیت این سفارش را بسازید.']);
        }

        $market = OrderContext::marketFor($orderId);
        $method = $this->resolvePayMethod($market, $this->input['method'] ?? $this->input['pay_method'] ?? null);

        $expectedExternal = OrderContext::externalIdForOrder($order, $market);
        $storedExternal = $order->get_meta('_salam_gate_external_id');
        $storedMarket = $order->get_meta('_salam_gate_market');
        if ($expectedExternal === null || ($storedMarket !== '' && $storedMarket !== $market)
            || ($storedExternal !== '' && ParcelService::positiveId($storedExternal) !== $expectedExternal)) {
            return OperationResult::failure(['message' => 'ارتباط مرسوله با این سفارش تأیید نشد؛ ابتدا اطلاعات مرسوله را بررسی کنید.', 'code' => 'identity_mismatch']);
        }

        if (!PayMethods::isValidParcelMethodForMarket($method, $market)) {
            return OperationResult::failure(['message' => 'روش پرداخت برای این بازار مجاز نیست.']);
        }

        $known = $this->knownPaymentOutcome($order, $parcelId, $market);
        if ($known !== null) return $known;
        $selectionBlock = (new OrderCheckoutPolicy($this->input, $this->context))->quoteSelectionBlock($parcelId);
        if ($selectionBlock !== null) return $selectionBlock;

        // Pin the service and amount the user reviewed; refreshing quotes can change selection.
        $expectedHash = isset($this->input['expected_hash']) && is_string($this->input['expected_hash'])
            ? trim(wp_unslash($this->input['expected_hash'])) : '';
        $rawTotal = $this->input['expected_total_rial'] ?? null;
        $expectedTotal = (is_int($rawTotal) || (is_string($rawTotal) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawTotal)))
            ? filter_var($rawTotal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($expectedHash === '' || strlen($expectedHash) > 256 || $expectedTotal === false) {
            return OperationResult::failure(['message' => 'ابتدا نرخ ارسال و مبلغ نهایی را بررسی و تأیید کنید.', 'code' => 'quote_changed', 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => []]);
        }

        return (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, function (callable $renew) use ($order, $parcelId, $market, $method, $expectedHash, $expectedTotal, $expectedExternal): array {
            // The previous operation may have settled between our first check and acquiring the lease.
            $known = $this->knownPaymentOutcome($order, $parcelId, $market, true, $renew);
            if ($known !== null) return $known;
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            // An edit may have completed after the initial check while this payment waited for its lease.
            $selectionBlock = (new OrderCheckoutPolicy($this->input, $this->context))->quoteSelectionBlock($parcelId);
            if ($selectionBlock !== null) return $selectionBlock;
            if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();

            // Read current original-order eligibility under the same shipping lease.
            // Known paid/unknown outcomes above always take precedence over a new attempt.
            if ($market === OrderContext::MARKET_BASALAM) {
                try {
                    $preflight = BasalamShippingPreflight::check($order);
                    if ($preflight['identity']['parcel_id'] !== $expectedExternal) {
                        throw new SalamGateException('ارتباط مرسوله با سفارش اصلی باسلام تغییر کرده است؛ پرداخت انجام نشد.', 422);
                    }
                } catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)];
                }
                if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();
            }

            // A failed preflight is safe to retry and must never create an unknown-payment marker.
            try {
                $baseline = (new ParcelService())->fetchAfterPay($parcelId, $market,
                    $market === OrderContext::MARKET_BASALAM ? $expectedExternal : null);
                if ($baseline === null || ($baseline['status_id'] ?? null) !== ParcelStatuses::NEW
                    || !in_array($baseline['invoice_status'] ?? null, [null, 3, 5], true)
                    || !empty($baseline['tracking_code'])
                    || ($market === OrderContext::MARKET_BASALAM && ($baseline['external_id'] ?? null) !== $expectedExternal)
                    || ($market === OrderContext::MARKET_WEBSITE && ($baseline['external_id'] ?? null) !== null && $baseline['external_id'] !== $expectedExternal)) {
                    throw new SalamGateException('وضعیت مرسوله برای پرداخت تأیید نشد؛ ابتدا وضعیت را بررسی کنید.', 422);
                }
                try { OrderWeightConfirmation::assertConfirmed(wp_unslash($this->input), $baseline['weight'] ?? null); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + [
                        'code' => 'weight_confirmation_required',
                        'current_weight_grams' => Units::parseBoundedFormInteger($baseline['weight'] ?? null, 50, 30000),
                    ]];
                }
                if ($market === OrderContext::MARKET_BASALAM) {
                    try {
                        $detail = (new ParcelService())->getDetail($parcelId);
                        if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId) {
                            throw new SalamGateException('مشخصات مرسوله موجود برای پرداخت تأیید نشد.', 422);
                        }
                        BasalamShippingPreflight::assertOriginalFinancials($preflight, $detail);
                        $receiverErrors = BasalamShippingPreflight::receiverInputErrors((new PrepareShipment($this->input, $this->context))->receiverFromDetail($detail));
                        if ($receiverErrors !== []) {
                            return ['ok' => false, 'payload' => ['message' => 'مشخصات گیرنده مرسوله را پیش از پرداخت کامل کنید.',
                                'errors' => $receiverErrors, 'code' => 'validation', 'kind' => 'validation']];
                        }
                    } catch (SalamGateException $exception) {
                        return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'basalam_parcel_mismatch']];
                    }
                    if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();
                }
                WebsiteShippingEligibility::assertForOrder($order);
            } catch (SalamGateException $exception) {
                if ($exception->errorsAt('order.status') !== []) {
                    return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)];
                }
                return ['ok' => false, 'payload' => [
                    'message' => 'تأیید نرخ ارسال انجام نشد؛ دوباره نرخ‌ها را بررسی کنید. پرداخت انجام نشد.',
                    'code' => 'quote_check_failed', 'kind' => SalamGateException::KIND_VALIDATION,
                    'errors' => $exception->getErrors(),
                ]];
            }
            return (new PaymentWorkflow())->execute(new PaymentCommand($parcelId, $market, $method, $baseline, $expectedHash, $expectedTotal), $renew,
                fn (): ?array => WebsiteShippingEligibility::blockForOrder($order),
                [new OrderCheckoutPolicy($this->input, $this->context), 'quoteLeaseUnavailable'],
                function (array $payBody, ?array $parcelItem) use ($order, $market): array {
                return ['ok' => true, 'payload' => $this->applyPaySuccess($order, $payBody, $parcelItem, $market)];
                },
                function (SalamGateException $exception, string $stage) use ($parcelId, $method, $baseline, $order): array {
                    if ($stage === 'preflight') {
                        if ($exception->errorsAt('order.status') !== []) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)); }
                        return OperationResult::failure(['message' => 'تأیید نرخ ارسال انجام نشد؛ دوباره نرخ‌ها را بررسی کنید. پرداخت انجام نشد.',
                            'code' => 'quote_check_failed', 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => $exception->getErrors()]);
                    }

                $notDispatched = PaymentRejection::notDispatchedPayload($exception, $parcelId);
                if ($notDispatched !== null) { return ['ok' => false, 'payload' => $notDispatched]; }
                $rejection = PaymentRejection::insufficientFundsPayload($exception, $parcelId, $method, $baseline);
                if ($rejection !== null) {
                    return ['ok' => false, 'payload' => $rejection + ['order_id' => (int) $order->get_id()]];
                }
                if (ParcelService::isResultUnknown($exception)) {
                    // درخواست pay قطع شد — ممکن است انجام شده باشد؛ کورکورانه تکرار ممنوع.
                    $order->update_meta_data('_salam_gate_pay_unknown', current_time('mysql'));
                    $order->save();
                    return ['ok' => false, 'payload' => [
                        'message' => 'پرداخت در حال بررسی است؛ تأیید آن هنوز دریافت نشده است. «بررسی وضعیت» را بزنید و پرداخت را تکرار نکنید.',
                        'code'    => 'pay_unknown',
                    ]];
                }

                return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)];
                });
        });
    }

    public function resolvePayMethod(string $market, $raw): int
    {
        if ($market === OrderContext::MARKET_BASALAM) {
            return PayMethods::PARCEL_ORDER_BALANCE;
        }

        $method = (int) Digits::toEnglish((string) $raw);

        return PayMethods::isValidParcelMethodForMarket($method, $market)
            ? $method
            : (int) SalamGateSettings::instance()->get(SalamGateSettings::KEY_PAY_METHOD_DEFAULT);
    }

    public function applyPaySuccess(object $order, array $payBody, ?array $parcelItem, string $market = OrderContext::MARKET_BASALAM): array
    {
        return (new ShipmentReceipt())->apply(OrderContext::shipment($order), $payBody, $parcelItem, $market);
    }

    public function knownPaymentOutcome($order, int $parcelId, string $market, bool $leaseHeld = false, ?callable $renew = null): ?array
    {
        $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->quoteMutationBlock($order, $parcelId);
        if ($blocked === null || $blocked['payload']['code'] !== 'pay_unknown') return $blocked;
        $expectedExternal = null;
        try {
            if ($market === OrderContext::MARKET_BASALAM) {
                $expectedExternal = OrderContext::externalIdForOrder($order, $market);
                $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
                if ($expectedExternal === null || (is_array($previous)
                    && !ParcelService::matchesPaymentIdentity(['market' => $market, 'external_id' => $expectedExternal], $previous))) {
                    return $blocked;
                }
            }
            $item = (new ParcelService())->fetchAfterPay($parcelId, $market, $expectedExternal);
        } catch (SalamGateException $exception) {
            $item = null;
        }
        if ($item !== null && ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            && ($market !== OrderContext::MARKET_BASALAM || (($item['invoice_status'] ?? null) === 2
                && is_string($item['tracking_code'] ?? null) && trim($item['tracking_code']) !== ''))) {
            return ['ok' => true, 'payload' => $this->applyPaySuccess($order, [], $item, $market)];
        }
        if ($item !== null && ($item['invoice_status'] ?? null) === 3) {
            $work = function (callable $refresh) use ($order, $parcelId, $market, $blocked, $expectedExternal): array {
                try { $fresh = (new ParcelService())->fetchAfterPay($parcelId, $market, $expectedExternal); }
                catch (SalamGateException $exception) { return $blocked; }
                if ($fresh !== null && $refresh() && ParcelService::reconcileFailedPayment($fresh)) {
                    if (!$order->get_meta('_salam_gate_paid_at') && !ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id'))) {
                        $order->update_meta_data('_salam_gate_pay_unknown', '');
                        $order->save();
                    }
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی ناموفق بود؛ نرخ ارسال و روش پرداخت را دوباره بررسی و تأیید کنید.', 'code' => 'payment_failed']];
                }
                return $blocked;
            };
            return $leaseHeld && $renew !== null ? $work($renew) : (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, $work);
        }
        return $blocked;
    }

    public function quotesOutcome($order, int $parcelId, callable $renew): array
    {
        $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->quoteMutationBlock($order, $parcelId);
        if ($blocked !== null) return $blocked;
        $eligibility = WebsiteShippingEligibility::blockForOrder($order);
        if ($eligibility !== null) { return $eligibility; }
        $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->basalamQuoteMutationBlock($order, $parcelId);
        if ($blocked !== null) return $blocked;
        if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();
        try {
            WebsiteShippingEligibility::assertForOrder($order);
            return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'quotes' => (new OrderCheckoutPolicy($this->input, $this->context))->fetchNormalizedQuotes($parcelId)]];
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)];
        }
    }
}
