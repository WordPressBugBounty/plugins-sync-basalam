<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;

defined('ABSPATH') || exit;

/** Shared rate review, fencing and single dispatch. Source policies run under the caller's lease. */
final class PaymentWorkflow
{
    private SalamGateClient $client;
    private ParcelService $parcels;

    public function __construct(?SalamGateClient $client = null, ?ParcelService $parcels = null)
    {
        $this->client = $client ?? ClientFactory::make();
        $this->parcels = $parcels ?? new ParcelService();
    }

    public function execute(PaymentCommand $command, callable $renew, callable $eligible,
        callable $unavailable, callable $complete, callable $failed): array
    {
        $parcelId = $command->parcelId;
        $market = $command->market;
        $method = $command->method;
        $baseline = $command->baseline;
        $expectedHash = $command->expectedHash;
        $expectedTotal = $command->expectedTotal;
        $stage = 'preflight';
        try {
            if (!$renew()) { return $unavailable(); }
            $blocked = $eligible();
            if ($blocked !== null) { return $blocked; }
            // A rate GET has side effects and is fenced just like the debit.
            $response = $this->client->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            $quotes = PaymentQuotes::checked($response['body'] ?? null, $parcelId);
            $selected = array_values(array_filter($quotes['quotes'], static fn (array $quote): bool => $quote['selected'] && !$quote['error']));
            if (count($selected) !== 1 || $selected[0]['hash_id'] !== $expectedHash || $selected[0]['total_rial'] !== $expectedTotal) {
                return OperationResult::failure([
                    'message' => 'نرخ یا مبلغ ارسال تغییر کرده است؛ دوباره انتخاب و تأیید کنید. پرداخت انجام نشد.',
                    'code' => 'quote_changed', 'quotes' => $quotes, 'kind' => SalamGateException::KIND_VALIDATION, 'errors' => [],
                ]);
            }
            if (!$renew()) { return $unavailable(); }
            $blocked = $eligible();
            if ($blocked !== null) { return $blocked; }
            $stage = 'payment';
            $service = $this->parcels;
            $body = $service->pay($parcelId, $method, $baseline);
            try {
                $item = $service->fetchAfterPay($parcelId, $market,
                    $market === 'basalam' ? ParcelService::positiveId($baseline['external_id'] ?? null) : null);
            } catch (SalamGateException $exception) {
                // A missing receipt does not undo a debit or permit replay.
                $item = null;
            }
            return $complete($body, $item);
        } catch (SalamGateException $exception) {
            return $failed($exception, $stage);
        }
    }
}
