<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamParcelSet;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;

defined('ABSPATH') || exit;

/** ShipmentReader: explicit inputs, returned outcomes, no HTTP response termination. */
final class ShipmentReader
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function getTrack(): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);

        $parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($parcelId <= 0) {
            return OperationResult::failure(['message' => 'مرسوله‌ای برای پیگیری وجود ندارد.']);
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        }

        try {
            $code = $order->get_meta('_salam_gate_tracking_code');
            return OperationResult::success((new \SyncBasalam\SalamGate\Services\TrackingService())->lookup(is_string($code) ? $code : ''));
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception));
        }
    }

    public function getLabel(): array
    {

        // Printing an existing receipt is not a new shipping workflow. In
        // particular, a delivered/cancelled native order must not reopen the
        // source-order registration preflight or migrate another child receipt.
        $order = $this->labelOrderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);

        $parcelId = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $market = OrderContext::marketFor((int) $order->get_id());
        $externalId = OrderContext::externalIdForOrder($order, $market);
        $savedMarket = $order->get_meta('_salam_gate_market');
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        if ($parcelId === null || $externalId === null
            || ($savedMarket !== '' && $savedMarket !== $market)
            || ($savedExternal !== '' && ParcelService::positiveId($savedExternal) !== $externalId)) {
            return OperationResult::failure(['message' => 'ارتباط مرسوله این سفارش برای چاپ تأیید نشد؛ وضعیت ارسال را بررسی کنید.',
                'code' => 'label_not_ready', 'request_dispatched' => false]);
        }

        $template = is_string($this->input['template'] ?? null) ? sanitize_text_field(wp_unslash($this->input['template'])) : '';
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) {
            $template = (string) SalamGateSettings::instance()->get(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT);
        }
        if (!in_array($template, ParcelService::LABEL_TEMPLATES, true)) { $template = 'invoice'; }

        $requestDispatched = false;
        try {
            $service = new ParcelService();
            // The local badge may predate a refund/void. Verify the exact
            // authenticated parcel and market. Legacy website receipts may
            // omit external_id; a present conflicting ID is still rejected.
            // Basalam must always match its exact original child identity.
            $parcel = $service->fetchAfterPay(
                $parcelId,
                $market,
                $externalId,
                $market === OrderContext::MARKET_WEBSITE
            );
            if ($parcel === null || !LabelEligibility::canPrint($parcel)) {
                return OperationResult::failure(['message' => 'برچسب پستی پس از پرداخت موفق و صدور کد رهگیری در دسترس است؛ رسید ابطال‌شده یا هزینه عودت‌شده قابل چاپ نیست.',
                    'code' => 'label_not_ready', 'request_dispatched' => false]);
            }
            $requestDispatched = true;
            $result = $service->getLabelUrl([$parcelId], $template);

            if ($result['status'] === 'completed') {
                $order->update_meta_data('_salam_gate_label', [
                    'url'       => $result['url'],
                    'expire_at' => $result['expire_at'] ?? null,
                ]);
                $order->save();
            }

            return OperationResult::success($result);
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['request_dispatched' => $requestDispatched]);
        }
    }

    public function labelStatus(): array
    {

        $rawRequestId = $this->input['request_id'] ?? 0;
        $requestId = in_array($rawRequestId, [0, '0', ''], true) ? 0 : ParcelService::positiveId($rawRequestId);
        if ($requestId === null) {
            return OperationResult::failure(['message' => 'شناسه درخواست برچسب معتبر نیست.']);
        }

        try {
            return OperationResult::success((new ParcelService())->getLabelStatus($requestId));
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception));
        }
    }

    public function nativeParcels(): array
    {
        $orderId = ParcelService::positiveId($this->input['order_id'] ?? null);
        $root = $orderId !== null ? wc_get_order($orderId) : null;
        if (!$root instanceof \WC_Order || !BasalamOrderContext::isBasalamOrder($orderId)) {
            return OperationResult::failure(['message' => 'این سفارش، سفارش معتبر باسلام نیست.']);
        }
        $cursor = $this->input['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) { return OperationResult::failure(['message' => 'صفحه بعد مرسوله‌ها معتبر نیست.']); }
        try {
            $result = BasalamParcelSet::discover($root, $cursor);
            $legacy = $root->get_meta('_salam_gate_basalam_mapping');
            $legacyId = is_array($legacy) ? ParcelService::positiveId($legacy['parcel_id'] ?? null) : null;
            $legacyMatches = $legacyId !== null && $legacy === ['order_id' => $orderId,
                'invoice_id' => BasalamOrderContext::getInvoiceId($orderId),
                'vendor_id' => (int) syncBasalamSettings()->getSettings('vendor_id'), 'parcel_id' => $legacyId]
                && in_array($root->get_meta('_salam_gate_market'), ['', 'basalam'], true)
                && in_array($root->get_meta('_salam_gate_external_id'), ['', $legacyId, (string) $legacyId], true);
            foreach ($result['parcels'] as &$row) {
                $scope = BasalamParcelOrder::stored($root, $row['basalam_parcel_id']);
                if ($scope === null && $legacyMatches && $legacyId === $row['basalam_parcel_id']) { $scope = $root; }
                $row['has_local'] = $scope !== null && (int) $scope->get_meta('_salam_gate_parcel_id') > 0;
                $row['local_status_title'] = $scope !== null ? ParcelStatuses::title((int) $scope->get_meta('_salam_gate_status')) : '';
                $row['tracking_code'] = $scope !== null ? (string) $scope->get_meta('_salam_gate_tracking_code') : '';
            }
            unset($row);
            return OperationResult::success($result);
        } catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)); }
    }

    public function labelOrderOrError(): ?object
    {
        $orderId = ParcelService::positiveId($this->input['order_id'] ?? null);
        $root = $orderId !== null ? wc_get_order($orderId) : null;
        if (!$root instanceof \WC_Order) {
            throw new WorkflowFailure(['message' => 'سفارش برای چاپ برچسب یافت نشد.', 'request_dispatched' => false]);
        }
        $isNative = BasalamOrderContext::isBasalamOrder($orderId);
        $rawNative = $this->input['basalam_parcel_id'] ?? null;
        if (!in_array($rawNative, [null, '', 0, '0'], true)) {
            $nativeId = ParcelService::positiveId($rawNative);
            if (!$isNative || $nativeId === null) {
                throw new WorkflowFailure(['message' => 'مرسوله انتخاب‌شده برای چاپ معتبر نیست.', 'request_dispatched' => false]);
            }
            $scope = BasalamParcelOrder::stored($root, $nativeId);
            if ($scope !== null) { return $scope; }
            // Preserve explicitly mapped pre-migration single parcels only.
            if (BasalamParcelOrder::hasScopes($root) || BasalamOrderContext::getParcelId($orderId) !== $nativeId) {
                throw new WorkflowFailure(['message' => 'ارتباط ذخیره‌شده این مرسوله با سفارش تأیید نشد.', 'request_dispatched' => false]);
            }
        } elseif ($isNative && BasalamParcelOrder::hasScopes($root)) {
            throw new WorkflowFailure(['message' => 'مرسوله موردنظر را برای چاپ برچسب انتخاب کنید.',
                'code' => 'parcel_selection_required', 'request_dispatched' => false]);
        }
        return $root;
    }
}
