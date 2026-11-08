<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\UpsertIdentity;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\CreationAttemptService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** CreateOrderShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class CreateOrderShipment
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function upsertParcel(): array
    {

        $order = (new OrderCheckoutPolicy($this->input, $this->context))->orderOrError();
        if (!$order) return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        $orderId = (int) $order->get_id();

        $eligibility = WebsiteShippingEligibility::blockForOrder($order);
        if ($eligibility !== null) { return $eligibility; }

        $market = OrderContext::marketFor($orderId);

        // Shipping an existing order cannot add, remove or rewrite its items.
        // Enforce this server-side too, before a forged/stale browser can touch
        // the remote draft. The independent send form has its own endpoint.
        if (array_key_exists('items', $this->input) || !empty($this->input['item_net_weights'])) {
            return OperationResult::failure(['message' => 'اقلام از سفارش اصلی خوانده می‌شوند و در فرم ارسال قابل تغییر نیستند.',
                'code' => 'items_locked', 'kind' => 'validation']);
        }

        $inputErrors = $this->numericInputErrors(wp_unslash($this->input), $market);
        if ($inputErrors !== []) {
            return OperationResult::failure(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $inputErrors, 'code' => 'validation', 'kind' => 'validation']);
        }

        $shopId = FeatureAvailability::activeShopId();
        if ($shopId === null) {
            return OperationResult::failure(['message' => 'غرفه سلام‌گیت فعالی یافت نشد.']);
        }

        $preflight = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $preflight = BasalamShippingPreflight::check($order); }
            catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception)); }
        }
        $externalId = OrderContext::externalIdForOrder($order, $market);
        if ($externalId === null) {
            return OperationResult::failure(['message' => 'شناسه معتبر مرسوله این سفارش تأیید نشد؛ دوباره بررسی کنید.']);
        }

        $this->recoverConfirmedOrderReplacement($order);
        $recovered = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try { $recovered = (new PrepareShipment($this->input, $this->context))->recoverBasalamParcel($order, $externalId); }
            catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'basalam_recovery_failed']); }
            if ($recovered !== null && (ParcelStatuses::hasReceipt($recovered['parcel']['status_id'])
                || ($recovered['parcel']['invoice_status'] ?? null) === 2 || !empty($recovered['parcel']['tracking_code']))) {
                return OperationResult::failure(['message' => 'این مرسوله قبلاً پرداخت یا ارسال شده است؛ ثبت مجدد انجام ندهید.', 'code' => 'already_paid']);
            }
        }
        $overrides = $this->overridesFromRequest($market);
        $existingParcelId = (int) $order->get_meta('_salam_gate_parcel_id');
        if ($preflight !== null && $recovered !== null) {
            try { BasalamShippingPreflight::assertOriginalFinancials($preflight, $recovered['detail']); }
            catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'basalam_parcel_mismatch']); }
        }
        $builder = new ParcelPayloadBuilder();
        if ($preflight !== null && $recovered !== null) {
            $preflight['order_data']['receiver'] = (new PrepareShipment($this->input, $this->context))->receiverFromDetail($recovered['detail'], $preflight['order_data']['receiver']);
            // When omitted, preserve an existing independent declared value instead of resetting it to item totals.
            if (!array_key_exists('value_toman', $this->input)) { $overrides['value'] = $recovered['detail']['value'] ?? 0; }
            foreach (['is_liquid', 'is_fragile'] as $flag) {
                if (!array_key_exists($flag, $this->input)) { $overrides[$flag] = $recovered['detail'][$flag]; }
            }
            if (!array_key_exists('category_id', $this->input) && ($recovered['parcel']['category_id'] ?? null) !== null) {
                $overrides['category_id'] = $recovered['parcel']['category_id'];
            }
            if (!array_key_exists('box_type_id', $this->input)) {
                $overrides['box_type_id'] = $recovered['detail']['box_type']['id'];
                if ($overrides['box_type_id'] === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) { $overrides['dimensions'] = $recovered['detail']['dimensions']; }
            }
        }
        if ($market === OrderContext::MARKET_BASALAM && empty($overrides['city_id'])) {
            $overrides['city_id'] = $preflight['order_data']['receiver']['city_id'];
        }
        $payload = $preflight !== null
            ? $builder->assemble($preflight['order_data'], $shopId, $overrides)
            : $builder->build($order, $shopId, $overrides, $market);
        if ($market === OrderContext::MARKET_WEBSITE && $existingParcelId > 0) {
            $saved = $order->get_meta('_salam_gate_order_draft_payload');
            if ((int) $order->get_meta('_salam_gate_order_draft_parcel_id') === $existingParcelId
                && is_array($saved['items'] ?? null) && $saved['items'] !== []) {
                // Previously confirmed remote draft contents stay authoritative
                // when only receiver, packaging or parcel details are edited.
                $payload['items'] = $saved['items'];
            }
        }
        if ($preflight !== null) {
            $submittedReceiver = $payload['receiver'];
            // Explicit empty/malformed form fields must not be hidden by builder fallback defaults.
            foreach (['name', 'mobile', 'address', 'postal_code', 'city_id'] as $field) {
                if (array_key_exists($field, $this->input)) { $submittedReceiver[$field] = wp_unslash($this->input[$field]); }
            }
            $errors = BasalamShippingPreflight::receiverInputErrors($submittedReceiver);
            if (!BasalamShippingPreflight::isValidDeclaredValue($payload['parcel']['value'] ?? null)) {
                $errors['value_toman'] = 'ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان باشد.';
            }
            if ($errors !== []) {
                return OperationResult::failure(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $errors, 'code' => 'validation', 'kind' => 'validation']);
            }
        }
        // Automatic valuation must obey the same declared-value floor as an explicit input.
        if (($payload['parcel']['value'] ?? 0) < 20000) {
            return OperationResult::failure(['message' => 'حداقل ارزش مرسوله ۲٬۰۰۰ تومان است.',
                'errors' => ['value_toman' => 'حداقل ارزش مرسوله ۲٬۰۰۰ تومان است.'], 'code' => 'validation', 'kind' => 'validation']);
        }
        try { OrderWeightConfirmation::assertConfirmed(wp_unslash($this->input), $payload['parcel']['weight'] ?? null); }
        catch (SalamGateException $exception) { return OperationResult::failure((new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception) + ['code' => 'weight_confirmation_required']); }

        // شهر گیرنده برای هر دو بازار الزامی است
        if (empty($payload['receiver']['city_id'])) {
            return OperationResult::failure(['message' => 'استان و شهر گیرنده را انتخاب کنید.']);
        }

        $warnings = $payload['_warnings'];
        unset($payload['_warnings'], $payload['_display'], $payload['_contents']);

        // هویت: بسالم همیشه external_id (ساخت و ویرایش)؛ ویرایش بازارهای دیگر با id سلام‌گیت.
        $identity = $existingParcelId > 0
            ? UpsertIdentity::forUpdate($market, $existingParcelId, $externalId)
            : UpsertIdentity::forCreate($market, $externalId);

        $work = function (callable $renew) use ($order, $shopId, $existingParcelId, $market, $identity, $payload, $externalId, $warnings): array {
            $eligibility = WebsiteShippingEligibility::blockForOrder($order);
            if ($eligibility !== null) { return $eligibility; }
            if ($existingParcelId > 0) {
                $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->quoteMutationBlock($order, $existingParcelId);
                if ($blocked !== null) return $blocked;
                $blocked = (new OrderCheckoutPolicy($this->input, $this->context))->basalamQuoteMutationBlock($order, $existingParcelId);
                if ($blocked !== null) return $blocked;
                if (!$renew()) return (new OrderCheckoutPolicy($this->input, $this->context))->quoteLeaseUnavailable();
            }
            try {
                $reusedCreation = false;
                if ($existingParcelId === 0) {
                    $creation = (new CreationAttemptService())->runForOrder($shopId, $market, $externalId, $payload,
                        function (int $id) use ($order, $market, $payload): ?int {
                            WebsiteShippingEligibility::assertForOrder($order);
                            $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market),
                                UpsertIdentity::forCreate($market, $id) + $payload);
                            return ParcelService::positiveId($response['body']['parcel_id'] ?? null);
                        }, [CreationAttemptService::class, 'recoverOrderParcel']);
                    if (!$creation['ok']) {
                        $recoveredId = ParcelService::positiveId($creation['payload']['parcel_id'] ?? null);
                        if ($recoveredId !== null && ($creation['payload']['creation_state'] ?? '') === 'created') {
                            $order->update_meta_data('_salam_gate_parcel_id', $recoveredId);
                            $order->update_meta_data('_salam_gate_external_id', $externalId);
                            $order->update_meta_data('_salam_gate_market', $market);
                            $order->save();
                            // No quote/POST here. Reopening prepareParcel loads actual remote fields.
                            $creation['payload']['code'] = 'create_recovered';
                            $creation['payload']['message'] = 'مرسوله قبلی پیدا شد و اطلاعات تازه ثبت نشد؛ این پنجره را ببندید و ارسال با سلام‌گیت را دوباره باز کنید تا اطلاعات ذخیره‌شده را بررسی کنید.';
                        }
                        return $creation;
                    }
                    $parcelId = $creation['payload']['parcel_id'];
                    $reusedCreation = !empty($creation['payload']['reused']);
                } else {
                    WebsiteShippingEligibility::assertForOrder($order);
                    $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), $identity + $payload);
                    $parcelId = ParcelService::positiveId($response['body']['parcel_id'] ?? null);
                }
                if ($parcelId === null || ($existingParcelId > 0 && $parcelId !== $existingParcelId)) {
                    return ['ok' => false, 'payload' => ['message' => 'نتیجه ثبت مرسوله نامعلوم است؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید.', 'code' => 'create_unknown']];
                }

                // ذخیره متاها (HPOS-safe)
                $order->update_meta_data('_salam_gate_parcel_id', $parcelId);
                $order->update_meta_data('_salam_gate_external_id', $externalId);
                $order->update_meta_data('_salam_gate_market', $market);
                if (empty((int) $order->get_meta('_salam_gate_created_at'))) {
                    $order->update_meta_data('_salam_gate_created_at', current_time('mysql'));
                }
                // Recovery is read-only: do not pretend the incoming form was saved remotely.
                if ($market === OrderContext::MARKET_WEBSITE && !$reusedCreation) { $this->saveOrderDraft($order, $parcelId, $payload); }
                else { $order->save(); }

                if ($reusedCreation) {
                    // Even an identical request hash does not prove the remote recipient
                    // stayed unchanged in the native panel. Require a fresh prepare/review.
                    return ['ok' => false, 'payload' => ['code' => 'create_recovered', 'parcel_id' => $parcelId,
                        'message' => 'مرسوله قبلی پیدا شد؛ این پنجره را ببندید و ارسال با سلام‌گیت را دوباره باز کنید تا اطلاعات واقعی آن را بررسی و تأیید کنید. ثبت یا پرداخت دوباره انجام نشد.']];
                }

                // بلافاصله نرخ‌ها (توجه: این GET اثر انتخاب نرخ در سرویس دارد)
                $quoteOutcome = $existingParcelId > 0
                    ? (new OrderCheckout($this->input, $this->context))->quotesOutcome($order, $parcelId, $renew)
                    : (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($parcelId, fn (callable $quoteRenew): array => (new OrderCheckout($this->input, $this->context))->quotesOutcome($order, $parcelId, $quoteRenew));
                $quotes = $quoteOutcome['ok'] ? $quoteOutcome['payload']['quotes']
                    : ['parcel_id' => $parcelId, 'quotes' => [], 'errors' => [$quoteOutcome['payload']['message']]];

                return ['ok' => true, 'payload' => [
                    'parcel_id' => $parcelId,
                    'quotes'    => $quotes,
                    'warnings'  => $warnings,
                ] + ($market === OrderContext::MARKET_WEBSITE && !$reusedCreation
                    ? ['parcel_payload' => $this->orderFormSnapshot($payload)] : [])];
            } catch (SalamGateException $exception) {
                $error = (new OrderCheckoutPolicy($this->input, $this->context))->errorPayload($exception);
                if (ParcelService::isResultUnknown($exception)) {
                    $error['code'] = 'create_unknown';
                    $error['message'] = 'نتیجه ثبت مرسوله نامعلوم است؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید.';
                }
                return ['ok' => false, 'payload' => $error];
            }
        };
        return $existingParcelId > 0 ? (new OrderCheckoutPolicy($this->input, $this->context))->withQuoteLease($existingParcelId, $work) : $work(fn (): bool => true);
    }

    public function numericInputErrors(array $input, ?string $market = null): array
    {
        $errors = [];
        // Missing fields may use the order's defaults; explicitly invalid fields may not.
        if (array_key_exists('weight', $input) && Units::parseBoundedFormInteger($input['weight'], 50, 30000) === null) {
            $errors['weight'] = 'وزن باید عدد صحیح بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.';
        }
        $nonStandard = isset($input['is_non_standard']) && is_scalar($input['is_non_standard']) && trim((string) $input['is_non_standard']) !== '';
        $basalam = $market === OrderContext::MARKET_BASALAM;
        if ($basalam && (array_key_exists('is_non_standard', $input) || array_key_exists('proposed_price', $input))) {
            $errors['value_toman'] = 'برای سفارش باسلام، ارزش مرسوله را در فیلد ارزش وارد کنید؛ قیمت پیشنهادی کاربرد ندارد.';
        }
        $valueKey = $nonStandard ? 'proposed_price' : 'value_toman';
        if (array_key_exists($valueKey, $input) || $nonStandard) {
            $value = $input[$valueKey] ?? null;
            $parsedValue = $basalam
                ? Units::parseFormTomanToRial($value, BasalamShippingPreflight::MIN_DECLARED_VALUE_RIAL,
                    BasalamShippingPreflight::MAX_DECLARED_VALUE_RIAL)
                : Units::parseBoundedFormInteger($value, 2000, 1000000000, true);
            if ($parsedValue === null) {
                $errors[$valueKey] = $basalam ? 'ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان، با حداکثر یک رقم اعشار باشد.'
                    : 'ارزش مرسوله باید عدد صحیح بین ۲٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.';
            }
        }
        if (Units::parseBoundedFormInteger($input['box_type_id'] ?? null, 1, 2147483647) === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            foreach (['length', 'width', 'height'] as $dimension) {
                $key = 'dimension_' . $dimension;
                if (Units::parseBoundedFormInteger($input[$key] ?? null, $basalam ? 10 : 1, $basalam ? 100 : 2147483647) === null) {
                    $errors[$key] = $basalam ? 'بُعد بسته باید عدد صحیح بین ۱۰ تا ۱۰۰ سانتی‌متر باشد.'
                        : 'بُعد بسته باید عدد صحیح و حداقل ۱ سانتی‌متر باشد.';
                }
            }
        }
        if (array_key_exists('item_net_weights', $input)) {
            if (!is_array($input['item_net_weights'])) {
                $errors['item_net_weights'] = 'وزن اقلام معتبر نیست.';
            } else {
                foreach ($input['item_net_weights'] as $id => $weight) {
                    if (Units::parseBoundedFormInteger($weight, 1, 2147483647) === null) {
                        $errors['item_net_weights.' . $id] = 'وزن خالص باید عدد صحیح و حداقل ۱ گرم باشد.';
                    }
                }
            }
        }
        if (array_key_exists('items', $input)) {
            if (!is_array($input['items']) || $input['items'] === []) {
                $errors['items'] = 'حداقل یک قلم معتبر وارد کنید.';
            } else {
                $padding = max(0, (int) SalamGateSettings::instance()->get(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS));
                foreach ($input['items'] as $index => $item) {
                    $prefix = 'items.' . $index . '.';
                    if (!is_array($item)) { $errors['items'] = 'اطلاعات اقلام معتبر نیست.'; continue; }
                    if (!isset($item['title']) || !is_scalar($item['title']) || trim(sanitize_text_field((string) $item['title'])) === '') {
                        $errors[$prefix . 'title'] = 'عنوان قلم را وارد کنید.';
                    }
                    $quantity = Units::parseBoundedFormInteger($item['quantity'] ?? null, 1, 2147483647);
                    $netWeight = Units::parseBoundedFormInteger($item['net_weight'] ?? null, 1, max(1, 2147483647 - $padding));
                    $priceInput = $item['price_toman'] ?? '';
                    $price = is_string($priceInput) && trim($priceInput) === '' ? 1000
                        : Units::parseBoundedFormInteger($priceInput, 1000, 1000000000, true);
                    if ($quantity === null) { $errors[$prefix . 'quantity'] = 'تعداد باید عدد صحیح و حداقل ۱ باشد.'; }
                    if ($netWeight === null) { $errors[$prefix . 'net_weight'] = 'وزن خالص قلم معتبر نیست.'; }
                    if ($price === null) { $errors[$prefix . 'price_toman'] = 'قیمت قلم باید حداقل ۱٬۰۰۰ تومان باشد.'; }
                    $localId = $item['_order_item_id'] ?? 0;
                    if ($localId !== 0 && $localId !== '0' && (new PrepareShipment($this->input, $this->context))->canonicalItemId($localId) === null) {
                        $errors[$prefix . '_order_item_id'] = 'شناسه قلم سفارش معتبر نیست.';
                    }
                }
            }
            if (Units::parseBoundedFormInteger($input['category_id'] ?? null, 1, 2147483647) === null) {
                $errors['category_id'] = 'نوع مرسوله را انتخاب کنید.';
            }
        }
        return $errors;
    }

    public function overridesFromRequest(?string $market = null): array
    {
        $post = fn (string $key) => isset($this->input[$key]) && is_scalar($this->input[$key]) ? trim((string) wp_unslash($this->input[$key])) : '';

        $nonStandard = $post('is_non_standard') !== '';
        $valueToman = Units::parseBoundedFormInteger($nonStandard ? $post('proposed_price') : $post('value_toman'), 2000, 1000000000, true) ?? 0;
        $valueRial = $market === OrderContext::MARKET_BASALAM
            ? (Units::parseFormTomanToRial($post('value_toman'), BasalamShippingPreflight::MIN_DECLARED_VALUE_RIAL,
                BasalamShippingPreflight::MAX_DECLARED_VALUE_RIAL) ?? 0)
            : ($valueToman > 0 ? Units::formTomanToRial($valueToman) : 0);
        $boxTypeId = (int) Digits::toEnglish($post('box_type_id'));

        $overrides = [
            'weight'         => (int) Digits::toEnglish($post('weight')),
            'value'          => $valueRial,
            'contents'       => $post('contents'),
            'category_id'    => (int) Digits::toEnglish($post('category_id')),
            'box_type_id'    => $boxTypeId,
            'is_liquid'      => $post('is_liquid') !== '',
            'is_fragile'     => $post('is_fragile') !== '',
            'name'           => $post('name'),
            'mobile'         => $post('mobile'),
            'address'        => $post('address'),
            'postal_code'    => $post('postal_code'),
            'city_id'        => (int) Digits::toEnglish($post('city_id')),
            'item_net_weights' => [],
        ];

        if ($boxTypeId === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            $overrides['dimensions'] = [
                'length' => (int) Digits::toEnglish($post('dimension_length')),
                'width'  => (int) Digits::toEnglish($post('dimension_width')),
                'height' => (int) Digits::toEnglish($post('dimension_height')),
            ];
        }

        if (isset($this->input['item_net_weights']) && is_array($this->input['item_net_weights'])) {
            foreach (wp_unslash($this->input['item_net_weights']) as $itemId => $netWeight) {
                $overrides['item_net_weights'][(int) $itemId] = (int) Digits::toEnglish((string) $netWeight);
            }
        }

        if (array_key_exists('items', $this->input)) {
            $overrides['items'] = [];
            foreach (wp_unslash($this->input['items']) as $item) {
                $price = $item['price_toman'] ?? '';
                $overrides['items'][] = [
                    'title' => sanitize_text_field(trim((string) $item['title'])),
                    'quantity' => Units::parseBoundedFormInteger($item['quantity'], 1, 2147483647),
                    'net_weight' => Units::parseBoundedFormInteger($item['net_weight'], 1, 2147483647),
                    'price_toman' => is_string($price) && trim($price) === '' ? 1000
                        : Units::parseBoundedFormInteger($price, 1000, 1000000000, true),
                    '_order_item_id' => (new PrepareShipment($this->input, $this->context))->canonicalItemId($item['_order_item_id'] ?? null) ?? 0,
                ];
            }
        }
        return $overrides;
    }

    public function editableRemoteItems($items): bool
    {
        if (!is_array($items) || $items === []) return false;
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null)) return false;
            foreach (['quantity', 'price', 'net_weight'] as $field) {
                if (ParcelService::positiveId($item[$field] ?? null) === null) return false;
            }
            if ((int) $item['price'] % 10 !== 0) return false;
        }
        return true;
    }

    public function orderFormSnapshot(array $payload): array
    {
        $parcel = $payload['parcel'];
        $packaging = $payload['packaging'];
        $items = [];
        foreach ($payload['items'] as $item) {
            $items[] = ['title' => $item['title'], 'quantity' => $item['quantity'], 'net_weight' => $item['net_weight'],
                'price_toman' => isset($item['price']) ? Units::toToman((int) $item['price']) : null,
                '_order_item_id' => (new PrepareShipment($this->input, $this->context))->storedOrderItemId($item)];
        }
        return [
            'provider_shop_id' => $parcel['provider_shop_id'], 'category_id' => $parcel['category_id'] ?? null,
            'weight' => $parcel['weight'], 'value_toman' => Units::toToman((int) $parcel['value']),
            'box_type_id' => $packaging['box_type_id'],
            'dimension_length' => $packaging['dimensions']['length'] ?? '',
            'dimension_width' => $packaging['dimensions']['width'] ?? '',
            'dimension_height' => $packaging['dimensions']['height'] ?? '',
            'is_liquid' => !empty($parcel['is_liquid']), 'is_fragile' => !empty($parcel['is_fragile']),
            'receiver' => $payload['receiver'], 'items' => $items,
        ];
    }

    public function saveOrderDraft($order, int $parcelId, array $payload): void
    {
        $snapshot = [];
        foreach (['parcel', 'packaging', 'receiver', 'items'] as $key) {
            if (is_array($payload[$key] ?? null)) { $snapshot[$key] = $payload[$key]; }
        }
        $providerId = ParcelService::positiveId($snapshot['parcel']['provider_shop_id'] ?? null);
        $categoryId = ParcelService::positiveId($snapshot['parcel']['category_id'] ?? null);
        if ($providerId !== null && $categoryId !== null) {
            update_option('salam_gate_parcel_context_' . $parcelId, ['provider_shop_id' => $providerId, 'category_id' => $categoryId], false);
        }
        $order->update_meta_data('_salam_gate_order_draft_parcel_id', $parcelId);
        $order->update_meta_data('_salam_gate_order_draft_payload', $snapshot);
        $order->save();
    }

    public function recoverConfirmedOrderReplacement($order): void
    {
        if (OrderContext::marketFor((int) $order->get_id()) !== OrderContext::MARKET_WEBSITE) return;
        $originalId = (int) $order->get_meta('_salam_gate_parcel_id');
        $currentId = $originalId;
        $seen = [];
        $resolved = false;
        for ($hop = 0; $hop < 20 && $currentId > 0; ++$hop) {
            if (isset($seen[$currentId])) return;
            $seen[$currentId] = true;
            $marker = get_option('salam_gate_parcel_edit_' . $currentId, null);
            if (!is_array($marker) || ($marker['state'] ?? '') !== 'replaced') { $resolved = true; break; }
            if (($marker['market'] ?? '') !== OrderContext::MARKET_WEBSITE
                || ($marker['original_market'] ?? '') !== OrderContext::MARKET_WEBSITE) return;
            $nextId = ParcelService::positiveId($marker['replacement_parcel_id'] ?? null);
            if ($nextId === null) return;
            $currentId = $nextId;
        }
        // An unfinished chain must not link the order to an already-retired intermediate draft.
        if (!$resolved) return;
        if ($currentId > 0 && $currentId !== $originalId) {
            $order->update_meta_data('_salam_gate_parcel_id', $currentId);
            $order->update_meta_data('_salam_gate_selected_service', '');
            $order->save();
        }
    }
}
