<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** EditShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class EditShipment
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function updateWebsiteOrderDraft(int $parcelId, array $body, callable $renew): array
    {
        return $this->updateOutcome($parcelId, 'website', 'website', $body, $renew);
    }

    public function selectWebsiteOrderQuote(int $parcelId, string $hash, callable $renew): array
    {
        return (new ParcelCheckout($this->input, $this->context))->selectQuoteOutcome($parcelId, 'website', $hash, $renew);
    }

    public function updateOutcome(int $parcelId, string $originalMarket, string $market, array $body, callable $renew): array
    {
        $previousEdit = get_option($this->editOutcomeKey($parcelId), null);
        if (is_array($previousEdit) && ($previousEdit['state'] ?? '') === 'replaced') {
            return $this->restoreReplacement($parcelId, $market, $body, $previousEdit);
        }
        if (is_array($previousEdit) && ($previousEdit['state'] ?? '') !== 'selection_required') {
            return $this->unknownEditOutcome($parcelId, $previousEdit);
        }
        $attempted = false;
        $confirmed = false;
        try {
            $item = (new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($parcelId, $originalMarket);
            (new ParcelCheckoutPolicy($this->input, $this->context))->assertPayableStatus($item);
            if ($originalMarket === 'website' && $market !== 'website'
                && \SyncBasalam\SalamGate\Services\WebsitePaymentGuard::hasLinkedOrder($item)) {
                throw new SalamGateException('مبدأ مرسوله متصل به سفارش ووکامرس قابل تغییر نیست؛ ارسال را از همان سفارش ادامه دهید.', 422);
            }
            if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null
                || ($item['invoice_status'] !== null && !in_array($item['invoice_status'], [3, 5], true))
            ) {
                return ['ok' => false, 'payload' => [
                    'message' => 'پرداخت این مرسوله ثبت شده یا در حال بررسی است؛ اطلاعات آن را تغییر ندهید.',
                    'code' => 'pay_unknown', 'parcel_id' => $parcelId, 'market' => $originalMarket,
                ]];
            }

            // The public upsert ignores items on UPDATE. Changed items require a new unpaid draft.
            // The old draft remains permanently unpayable through this integration once replaced.
            $detail = (new ParcelService())->getDetail($parcelId);
            if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId || !is_array($detail['items'] ?? null)) {
                throw new SalamGateException('اقلام مرسوله برای ویرایش تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            $replaceDraft = !$this->sameEditableItems($body['items'], $detail['items']);
            // Retain the actual saved padding when the item rows are unchanged.
            foreach ($body['items'] as &$requestedItem) {
                foreach ($detail['items'] as $existingItem) {
                    if ($requestedItem['title'] === $existingItem['title']
                        && $requestedItem['quantity'] === ParcelService::positiveId($existingItem['quantity'])
                        && $requestedItem['price'] === ParcelService::positiveId($existingItem['price'])
                        && $requestedItem['net_weight'] === ParcelService::positiveId($existingItem['net_weight'])
                    ) {
                        $packaged = ParcelService::positiveId($existingItem['packaged_weight'] ?? null);
                        if ($packaged === null || $packaged < $requestedItem['net_weight']) {
                            throw new SalamGateException('وزن اقلام مرسوله تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
                        }
                        $requestedItem['packaged_weight'] = $packaged;
                        break;
                    }
                }
            }
            unset($requestedItem);
            if (!$renew()) {
                return (new ParcelCheckoutPolicy($this->input, $this->context))->leaseUnavailable();
            }
            if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null) {
                return ['ok' => false, 'payload' => [
                    'message' => 'پرداخت این مرسوله در حال بررسی است؛ اطلاعات آن را تغییر ندهید.',
                    'code' => 'pay_unknown', 'parcel_id' => $parcelId, 'market' => $originalMarket,
                ]];
            }

            WebsiteShippingEligibility::assertForParcel($item);
            $marker = ['state' => 'unknown', 'original_market' => $originalMarket, 'market' => $market,
                'request_hash' => $this->editRequestHash($body), 'replacement' => $replaceDraft,
                'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id']];
            // Website UPDATE drops external_id; keep the verified financial identity first.
            \SyncBasalam\SalamGate\Services\WebsitePaymentGuard::rememberIdentity($item);
            update_option($this->editOutcomeKey($parcelId), $marker, false);
            if (get_option($this->editOutcomeKey($parcelId), null) !== $marker) {
                return ['ok' => false, 'payload' => ['message' => 'وضعیت ویرایش ذخیره نشد؛ درخواست ارسال نشد.', 'code' => 'preflight_unavailable']];
            }
            if (!$replaceDraft) { $body['id'] = $parcelId; }
            $attempted = true;
            $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), $body);
            $savedId = ParcelService::positiveId($response['body']['parcel_id'] ?? null);
            if ($savedId === null || (!$replaceDraft && $savedId !== $parcelId) || ($replaceDraft && $savedId === $parcelId)) {
                return $this->unknownEditOutcome($parcelId, $marker);
            }
            $confirmed = true;
            if ($replaceDraft && $originalMarket === 'website' && $market === 'website') {
                \SyncBasalam\SalamGate\Services\WebsitePaymentGuard::rememberIdentity($item, $savedId);
            }
            (new CreateShipment($this->input, $this->context))->saveParcelContext($savedId, $body);
            $marker['state'] = $replaceDraft ? 'replaced' : 'selection_required';
            if ($replaceDraft) { $marker['replacement_parcel_id'] = $savedId; }
            update_option($this->editOutcomeKey($parcelId), $marker, false);
            if (get_option($this->editOutcomeKey($parcelId), null) !== $marker) {
                return $this->unknownEditOutcome($parcelId, $marker);
            }
            $quotes = ['parcel_id' => $savedId, 'quotes' => [], 'errors' => []];
            $refresh = $renew();
            $fetch = function (callable $quoteRenew) use ($savedId, $replaceDraft, $body, $market, $refresh): array {
                if ($replaceDraft) {
                    // Preparing the new selection guard and reading its quotes share the NEW lease.
                    // A reused response ID must never overwrite a pre-existing financial/edit guard.
                    if (get_option(ParcelService::paymentOutcomeKey($savedId), null) !== null
                        || get_option($this->editOutcomeKey($savedId), null) !== null) {
                        return ['ok' => false, 'payload' => ['message' => 'وضعیت پیش‌نویس جدید باید دوباره بررسی شود؛ نرخ‌ها تغییر نکردند.']];
                    }
                    $nextMarker = ['state' => 'selection_required', 'market' => $market,
                        'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id']];
                    update_option($this->editOutcomeKey($savedId), $nextMarker, false);
                    if (get_option($this->editOutcomeKey($savedId), null) !== $nextMarker) {
                        return ['ok' => false, 'payload' => ['message' => 'وضعیت پیش‌نویس جدید تأیید نشد؛ دوباره بررسی کنید.']];
                    }
                }
                if (!$refresh || !$quoteRenew()) {
                    return ['ok' => false, 'payload' => ['message' => 'اطلاعات ذخیره شد؛ برای ادامه دوباره نرخ ارسال بگیرید.']];
                }
                if (get_option(ParcelService::paymentOutcomeKey($savedId), null) !== null) {
                    return ['ok' => false, 'payload' => ['message' => 'وضعیت پرداخت در حال بررسی است؛ نرخ‌ها تغییر نکردند.']];
                }
                try {
                    WebsiteShippingEligibility::assertForParcel((new ParcelCheckoutPolicy($this->input, $this->context))->assertParcelMarket($savedId, $market));
                    $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $savedId));
                    return ['ok' => true, 'payload' => (new ParcelCheckoutPolicy($this->input, $this->context))->checkedQuotes($response['body'] ?? null, $savedId)];
                } catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => ['message' => $exception->firstMessage()]];
                }
            };
            $quoteOutcome = $replaceDraft ? (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($savedId, $fetch) : $fetch($renew);
            if ($quoteOutcome['ok']) { $quotes = $quoteOutcome['payload']; }
            else { $quotes['errors'][] = $quoteOutcome['payload']['message']; }
            return ['ok' => true, 'payload' => [
                'parcel_id' => $savedId, 'market' => $market, 'updated' => true,
                'replaces_parcel_id' => $replaceDraft ? $parcelId : null,
                'requires_quote_selection' => true, 'quotes' => $quotes,
            ]];
        } catch (SalamGateException $exception) {
            if ($attempted && !$confirmed && ParcelService::isResultUnknown($exception)) {
                return $this->unknownEditOutcome($parcelId, $marker);
            }
            if ($attempted && !$confirmed) {
                if ($previousEdit === null) {
                    delete_option($this->editOutcomeKey($parcelId));
                } else {
                    update_option($this->editOutcomeKey($parcelId), $previousEdit, false);
                }
            }
            return ['ok' => false, 'payload' => [
                'message' => $exception->firstMessage(), 'errors' => $exception->getErrors(),
                'kind' => $exception->getKind(), 'code' => 'update_failed',
                'parcel_id' => $parcelId, 'market' => $originalMarket,
            ]];
        }
    }

    public function editRequestHash(array $body): string
    {
        // Packaging padding may change outside the wizard; only compare the user's item fields.
        foreach ($body['items'] as &$item) { unset($item['packaged_weight']); }
        unset($item, $body['id']);
        return hash('sha256', json_encode($body));
    }

    public function restoreReplacement(int $oldId, string $market, array $body, array $marker): array
    {
        $newId = ParcelService::positiveId($marker['replacement_parcel_id'] ?? null);
        if ($newId === null || ($marker['market'] ?? '') !== $market
            || !hash_equals((string) ($marker['request_hash'] ?? ''), $this->editRequestHash($body))) {
            return $this->unknownEditOutcome($oldId, $marker);
        }
        return ['ok' => true, 'payload' => [
            'parcel_id' => $newId, 'market' => $market, 'updated' => true, 'replaces_parcel_id' => $oldId,
            'requires_quote_selection' => true,
            // Recovery is read-only: another tab could already be selecting or paying the new draft.
            'quotes' => ['parcel_id' => $newId, 'quotes' => [], 'errors' => []],
        ]];
    }

    public function recoverReplacement(int $oldId, string $oldMarket, array $marker): array
    {
        if (($marker['original_market'] ?? '') !== $oldMarket) { return $this->unknownEditOutcome($oldId, $marker); }
        $seen = [$oldId => true];
        $current = $marker;
        for ($hop = 0; $hop < 20; ++$hop) {
            $newId = ParcelService::positiveId($current['replacement_parcel_id'] ?? null);
            $market = $current['market'] ?? null;
            if ($newId === null || isset($seen[$newId]) || !in_array($market, DirectShipment::MARKETS, true)) {
                return $this->unknownEditOutcome($oldId, $marker);
            }
            $seen[$newId] = true;
            $next = get_option($this->editOutcomeKey($newId), null);
            if (is_array($next) && ($next['state'] ?? '') === 'replaced') { $current = $next; continue; }
            // All reads and any quote refresh for the new identity use its own shared workflow lease.
            return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($newId, function (callable $renew) use ($oldId, $newId, $market, $current, $next): array {
                $outcome = (new ParcelCheckout($this->input, $this->context))->quotesOutcome($newId, $market, true, $renew);
                if (!$outcome['ok']) { return $outcome; }
                $outcome['payload']['replaces_parcel_id'] = $oldId;
                if (($outcome['payload']['payment_state'] ?? '') !== '') { return $outcome; }
                $outcome['payload']['replacement_payload'] = $outcome['payload']['parcel_payload'];
                return $outcome;
            });
        }
        return $this->unknownEditOutcome($oldId, $marker);
    }

    public function canonicalParcelPayload(int $parcelId, array $listItem, ?array $edit): array
    {
        $detail = (new ParcelService())->getDetail($parcelId);
        if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
        $receiver = $detail['shipping_info']['receiver'] ?? null;
        $cityId = is_array($receiver) ? ParcelService::positiveId($receiver['city']['id'] ?? $receiver['city_id'] ?? null) : null;
        if ($cityId === null || !is_array($detail['items'] ?? null) || $detail['items'] === []) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
        foreach (['full_name', 'phone', 'address', 'postal_code'] as $key) {
            if (!is_string($receiver[$key] ?? null)) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
        }
        $items = [];
        foreach ($detail['items'] as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null)) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
            foreach (['quantity', 'price', 'net_weight'] as $key) {
                if (ParcelService::positiveId($item[$key] ?? null) === null) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
            }
            if ((int) $item['price'] % 10 !== 0) { throw $this->fractionalFormMoney(); }
            $items[] = ['title' => $item['title'], 'quantity' => (int) $item['quantity'],
                'net_weight' => (int) $item['net_weight'], 'price_toman' => Units::toToman((int) $item['price']),
                'sku' => is_string($item['sku'] ?? null) ? $item['sku'] : null]
                + (array_key_exists('external_id', $item) ? ['external_id' => $item['external_id']] : []);
        }
        foreach (['weight', 'value'] as $key) {
            if (ParcelService::positiveId($detail[$key] ?? null) === null) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
        }
        if ((int) $detail['value'] % 10 !== 0) { throw $this->fractionalFormMoney(); }
        if (ParcelService::positiveId($detail['box_type']['id'] ?? null) === null) { throw (new ParcelCheckoutPolicy($this->input, $this->context))->invalidQuotes(); }
        $context = get_option('salam_gate_parcel_context_' . $parcelId, []);
        $ids = (is_array($context) ? $context : []) + (is_array($edit) ? $edit : []);
        $providerId = ParcelService::positiveId($ids['provider_shop_id'] ?? null);
        if ($providerId === null) { $providerId = $this->providerFromSavedSender($detail); }
        $categoryId = ParcelService::positiveId($listItem['category_id'] ?? null)
            ?? ParcelService::positiveId($ids['category_id'] ?? null);
        return [
            'provider_shop_id' => $providerId, 'category_id' => $categoryId,
            'weight' => (int) $detail['weight'], 'value_toman' => Units::toToman((int) $detail['value']),
            'box_type_id' => (int) $detail['box_type']['id'],
            'dimension_length' => $detail['dimensions']['length'] ?? '',
            'dimension_width' => $detail['dimensions']['width'] ?? '',
            'dimension_height' => $detail['dimensions']['height'] ?? '',
            'is_liquid' => !empty($detail['is_liquid']), 'is_fragile' => !empty($detail['is_fragile']),
            'receiver' => ['name' => $receiver['full_name'], 'mobile' => $receiver['phone'],
                'address' => $receiver['address'], 'postal_code' => $receiver['postal_code'], 'city_id' => $cityId,
                'city_title' => $receiver['city']['title'] ?? '', 'province_id' => $receiver['city']['parent']['id'] ?? null],
            'items' => $items,
        ];
    }

    public function fractionalFormMoney(): SalamGateException
    {
        return new SalamGateException('مبالغ اقلام و ارزش این پیش‌نویس دارای اعشار تومان هستند؛ پیش از ادامه آن‌ها را به تومان کامل بررسی و اصلاح کنید. مبلغ ارسال گرد نشده است.', 422,
            [['location' => 'parcel.value', 'message' => 'مبالغ اقلام و ارزش این پیش‌نویس دارای اعشار تومان هستند؛ قبل از ادامه آن‌ها را بررسی و اصلاح کنید.']], SalamGateException::KIND_VALIDATION);
    }

    public function providerFromSavedSender(array $detail): ?int
    {
        $sender = $detail['shipping_info']['sender'] ?? null;
        if (!is_array($sender)) { return null; }
        foreach (['postal_code', 'phone', 'address'] as $key) {
            if (!is_string($sender[$key] ?? null) || trim($sender[$key]) === '') { return null; }
        }
        $cityId = ParcelService::positiveId($sender['city']['id'] ?? null);
        if ($cityId === null) { return null; }
        $matches = [];
        foreach ((new ShopsService())->getShops() as $shop) {
            $address = $shop['address'] ?? null;
            if (!is_array($address) || ParcelService::positiveId($address['city']['id'] ?? null) !== $cityId) { continue; }
            foreach (['postal_code', 'phone', 'address'] as $key) {
                if (!is_string($address[$key] ?? null) || trim($address[$key]) !== trim($sender[$key])) { continue 2; }
            }
            $id = ParcelService::positiveId($shop['id'] ?? null);
            if ($id !== null) { $matches[] = $id; }
        }
        return count(array_unique($matches)) === 1 ? $matches[0] : null;
    }

    public function sameEditableItems(array $requested, array $existing): bool
    {
        $normalize = function (array $items): ?array {
            $result = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['title'] ?? null)) { return null; }
                $row = ['title' => $item['title']];
                foreach (['quantity', 'price', 'net_weight'] as $key) {
                    $value = ParcelService::positiveId($item[$key] ?? null);
                    if ($value === null) { return null; }
                    $row[$key] = $value;
                }
                $result[] = $row;
            }
            // API item order is not guaranteed; compare the same multiset including duplicates.
            usort($result, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            return $result;
        };
        $left = $normalize($requested);
        $right = $normalize($existing);
        return $left !== null && $right !== null && $left !== [] && $left === $right;
    }

    public function editOutcomeKey(int $parcelId): string
    {
        return 'salam_gate_parcel_edit_' . $parcelId;
    }

    public function unknownEditOutcome(int $parcelId, array $marker): array
    {
        if (($marker['state'] ?? '') === 'replaced') {
            return ['ok' => false, 'payload' => [
                'message' => 'پیش‌نویس این مرسوله با اقلام جدید جایگزین شده است؛ از پیش‌نویس جدید ادامه دهید.',
                'code' => 'parcel_replaced', 'parcel_id' => $parcelId,
                'replacement_parcel_id' => $marker['replacement_parcel_id'] ?? null,
                'market' => $marker['market'] ?? '', 'requires_quote_selection' => true,
            ]];
        }
        return ['ok' => false, 'payload' => [
            'message' => 'نتیجه ویرایش مرسوله تأیید نشد؛ دوباره ثبت یا پرداخت نکنید و وضعیت درخواست را بررسی کنید.',
            'code' => 'update_unknown', 'parcel_id' => $parcelId,
            'market' => $marker['original_market'] ?? $marker['market'] ?? '',
            'attempted_market' => $marker['market'] ?? '', 'requires_quote_selection' => true,
        ]];
    }
}
