<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Order\BasalamCheckoutPreflight;
use SyncBasalam\SalamGate\Order\OrderWeightConfirmation;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\CategoriesService;
use SyncBasalam\SalamGate\Services\CreationAttemptService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\PaymentRejection;
use SyncBasalam\SalamGate\Services\WebsiteShippingEligibility;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;
use SyncBasalam\SalamGate\Utils\OptionLock;

if (! defined('ABSPATH')) exit;

class SendActions
{
    public const MARKETS = ['instagram', 'eitaa', 'bale', 'telegram', 'website', 'digikala', 'mixin', 'other'];

    public static function bootstrap(): void
    {
        self::guard();
        $errors = [];
        $stale = [];
        try {
            $shops = (new ShopsService())->getShops();
            $activeShops = array_values(array_filter($shops, static fn (array $s): bool =>
                (int) ($s['status_id'] ?? 0) === ShopStatuses::ACTIVE
            ));
            if ($activeShops === []) {
                wp_send_json_error(['message' => 'آدرس فرستنده فعالی پیدا نشد؛ وضعیت حساب سلام‌گیت را در بخش ارسال مرسوله بررسی کنید.',
                    'code' => 'account_unavailable'], 409);
                return;
            }
        } catch (SalamGateException $e) {
            // An expired active snapshot can discover a new denial here. Do
            // not fan out to categories/boxes/locations after the account fails.
            wp_send_json_error(['message' => $e->firstMessage(), 'code' => 'account_unavailable'], 409);
            return;
        }
        try {
            $categories = (new CategoriesService())->all();
            if ($categories === []) $errors['categories'] = 'نوع‌های مرسوله دریافت نشد؛ دوباره تلاش کنید.';
        } catch (\Throwable $e) {
            $categories = [];
            $errors['categories'] = 'نوع‌های مرسوله دریافت نشد؛ دوباره تلاش کنید.';
        }
        $references = new ReferenceDataService();
        try {
            $result = $references->getBoxTypesWithState();
            $boxTypes = $result['data'];
            $stale['box_types'] = $result['stale'];
        } catch (\Throwable $e) {
            $boxTypes = [];
            $errors['box_types'] = 'نوع‌های بسته‌بندی دریافت نشد؛ دوباره تلاش کنید.';
        }
        try {
            $result = $references->getProvinceTreeWithState();
            $provinces = $result['data'];
            $stale['provinces'] = $result['stale'];
        } catch (\Throwable $e) {
            $provinces = [];
            $errors['provinces'] = 'فهرست استان‌ها و شهرها دریافت نشد؛ دوباره تلاش کنید.';
        }

        // Keep existing Mixin parcels readable, but do not offer it for new orders.
        $selectableMarkets = array_values(array_diff(self::MARKETS, ['mixin']));
        wp_send_json_success([
            'shops' => $activeShops,
            'categories' => $categories,
            'box_types' => $boxTypes,
            'provinces' => $provinces,
            // Basalam is a real-order picker, never an arbitrary direct-market create.
            'markets' => current_user_can('manage_woocommerce') ? array_merge(['basalam'], $selectableMarkets) : $selectableMarkets,
            'ready' => $errors === [],
            'errors' => $errors,
            'stale' => $stale,
        ]);
    }

    /** Check an uncertain create without resubmitting or retaining receiver fields. */
    public static function creationStatus(): void
    {
        self::guard();
        $attempt = $_POST['creation_id'] ?? null;
        if (!CreationAttemptService::validAttempt($attempt)) {
            wp_send_json_error(['message' => 'شناسه ثبت مرسوله معتبر نیست.', 'code' => 'validation']);
        }
        self::sendOutcome((new CreationAttemptService())->status($attempt, [self::class, 'recoverCreatedParcel']));
    }

    public static function upsert(): void
    {
        self::guard();

        // An explicit edit identity must never degrade into an unkeyed create.
        $editing = array_key_exists('parcel_id', $_POST);
        $existingId = $editing ? self::postedPositiveId('parcel_id') : null;
        $originalMarket = $editing ? self::postedMarket('original_market') : null;
        if ($editing && ($existingId === null || $originalMarket === null)) {
            wp_send_json_error(['message' => 'شناسه مرسوله یا مبدأ قبلی برای ویرایش معتبر نیست.', 'code' => 'validation']);
        }

        $post = static fn (string $key): string => isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';

        $market = sanitize_key($post('market'));
        if (!in_array($market, self::MARKETS, true)) {
            wp_send_json_error(['message' => 'مبدأ سفارش را از گزینه‌های موجود انتخاب کنید.']);
        }

        $shopId = (int) Digits::toEnglish($post('provider_shop_id'));
        if ($shopId <= 0) { wp_send_json_error(['message' => 'آدرس فرستنده را انتخاب کنید.']); }

        $name = $post('name');
        $mobile = Digits::normalizeMobile(Digits::toEnglish($post('mobile')));
        $address = $post('address');
        $postalCode = Digits::normalizePostalCode(Digits::toEnglish($post('postal_code')));
        $cityId = (int) Digits::toEnglish($post('city_id'));

        $errors = [];
        if (mb_strlen($name) < 3) { $errors['name'] = 'نام گیرنده باید حداقل ۳ حرف باشد.'; }
        if (!preg_match('/^09\d{9}$/', $mobile)) { $errors['mobile'] = 'شماره همراه گیرنده را با ۰۹ و در ۱۱ رقم وارد کنید.'; }
        if (mb_strlen($address) < 10) { $errors['address'] = 'نشانی کامل گیرنده را با حداقل ۱۰ حرف وارد کنید.'; }
        if ($postalCode === null) { $errors['postal_code'] = 'کد پستی ۱۰ رقمی گیرنده را وارد کنید.'; }
        if ($cityId <= 0) { $errors['city_id'] = 'شهر گیرنده را انتخاب کنید.'; }
        if ($errors !== []) {
            wp_send_json_error(['message' => 'اطلاعات مشخص‌شده گیرنده را کامل یا اصلاح کنید.', 'errors' => $errors, 'code' => 'validation']);
        }

        $weight = Units::parseBoundedFormInteger($_POST['weight'] ?? null, 50, 30000);
        $boxTypeId = (int) Digits::toEnglish($post('box_type_id'));
        // Declared parcel value is independent of the descriptive item prices.
        $valueToman = Units::parseBoundedFormInteger($_POST['value_toman'] ?? null, 2000, 1000000000, true);
        $categoryId = (int) Digits::toEnglish($post('category_id'));

        if ($weight === null) { $errors['weight'] = 'وزن باید عدد صحیح بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.'; }
        if ($valueToman === null) { $errors['value_toman'] = 'ارزش مرسوله باید عدد صحیح بین ۲٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.'; }
        if ($boxTypeId <= 0) { wp_send_json_error(['message' => 'نوع بسته‌بندی را انتخاب کنید.']); }
        if ($categoryId <= 0) { wp_send_json_error(['message' => 'نوع مرسوله را انتخاب کنید.']); }

        $dimensions = null;
        if ($boxTypeId === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            $dimensions = [];
            foreach (['length', 'width', 'height'] as $dimension) {
                $dimensions[$dimension] = Units::parseBoundedFormInteger($_POST['dimension_' . $dimension] ?? null, 1, 2147483647);
                if ($dimensions[$dimension] === null) {
                    $errors['dimension_' . $dimension] = 'بُعد بسته باید عدد صحیح و حداقل ۱ سانتی‌متر باشد.';
                }
            }
        }

        $itemsInput = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach (wp_unslash($_POST['items']) as $index => $item) {
                if (!is_array($item)) { $errors['items'] = 'اطلاعات اقلام معتبر نیست.'; continue; }
                $title = isset($item['title']) && is_scalar($item['title']) ? trim((string) $item['title']) : '';
                $qty = Units::parseBoundedFormInteger($item['quantity'] ?? null, 1, 2147483647);
                $netW = Units::parseBoundedFormInteger($item['net_weight'] ?? null, 1, 2147483647);
                $priceInput = $item['price_toman'] ?? '';
                $priceToman = is_string($priceInput) && trim($priceInput) === '' ? 0
                    : Units::parseBoundedFormInteger($priceInput, 1000, intdiv(PHP_INT_MAX, 10), true);
                if ($title === '') { $errors['items.' . $index . '.title'] = 'عنوان قلم را وارد کنید.'; }
                if ($qty === null) { $errors['items.' . $index . '.quantity'] = 'تعداد باید عدد صحیح و حداقل ۱ باشد.'; }
                if ($netW === null) { $errors['items.' . $index . '.net_weight'] = 'وزن خالص باید عدد صحیح و حداقل ۱ گرم باشد.'; }
                if ($priceToman === null) { $errors['items.' . $index . '.price_toman'] = 'قیمت قلم باید عدد صحیح و حداقل ۱٬۰۰۰ تومان باشد.'; }
                if ($title === '' || $qty === null || $netW === null || $priceToman === null) continue;
                $itemsInput[] = ['title' => $title, 'quantity' => $qty, 'net_weight' => $netW, 'price_toman' => $priceToman];
            }
        }

        if ($itemsInput === []) {
            $errors['items'] = 'حداقل یک قلم معتبر وارد کنید.';
        }
        if ($errors !== []) {
            wp_send_json_error(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $errors, 'code' => 'validation', 'kind' => 'validation']);
        }

        $parcelValueRial = Units::formTomanToRial($valueToman);

        $parcel = [
            'value' => $parcelValueRial,
            'weight' => $weight,
            'is_liquid' => $post('is_liquid') === '1',
            'is_fragile' => $post('is_fragile') === '1',
            'provider_shop_id' => $shopId,
            'category_id' => $categoryId,
        ];

        $packaging = ['box_type_id' => $boxTypeId, 'dimensions' => null];
        if ($boxTypeId === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            $packaging['dimensions'] = $dimensions;
        }

        $receiver = [
            'name' => $name, 'mobile' => $mobile, 'address' => $address,
            'city_id' => $cityId, 'postal_code' => $postalCode,
            'latitude' => null, 'longitude' => null,
        ];

        $items = [];
        $paddingGrams = max(0, (int) SalamGateSettings::instance()->get(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS));
        foreach ($itemsInput as $item) {
            $priceRial = $item['price_toman'] > 0 ? Units::formTomanToRial($item['price_toman']) : 10000;
            if ($paddingGrams > 2147483647 - $item['net_weight']) {
                wp_send_json_error(['message' => 'وزن بسته‌بندی قلم از محدوده مجاز بیشتر است.', 'errors' => ['items' => 'وزن قلم و وزن بسته‌بندی را اصلاح کنید.'], 'code' => 'validation', 'kind' => 'validation']);
            }
            $items[] = [
                'title' => $item['title'], 'price' => $priceRial,
                'quantity' => $item['quantity'],
                'net_weight' => max(1, $item['net_weight']),
                'packaged_weight' => max(1, $item['net_weight']) + $paddingGrams,
            ];
        }

        $body = ['parcel' => $parcel, 'packaging' => $packaging, 'receiver' => $receiver, 'items' => $items];

        if ($editing) {
            self::sendOutcome(self::withQuoteLease($existingId, static fn (callable $renew): array =>
                self::updateOutcome($existingId, $originalMarket, $market, $body, $renew)
            ));
        }

        $attempt = $_POST['creation_id'] ?? null;
        if (!CreationAttemptService::validAttempt($attempt)) {
            wp_send_json_error(['message' => 'شناسه درخواست ثبت معتبر نیست؛ صفحه ثبت مرسوله را دوباره باز کنید.', 'code' => 'validation']);
        }
        $outcome = (new CreationAttemptService())->run($attempt, $market, $body,
            static function (int $externalId) use ($market, $body): ?int {
                $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), ['external_id' => $externalId] + $body);
                return ParcelService::positiveId($response['body']['parcel_id'] ?? null);
            }, [self::class, 'recoverCreatedParcel']);
        if ($outcome['ok']) {
            $parcelId = $outcome['payload']['parcel_id'];
            self::saveParcelContext($parcelId, $body);
            $outcome['payload']['quotes'] = self::fetchQuotes($parcelId);
        }
        self::sendOutcome($outcome);
    }

    /** Query filters alone do not establish identity; require one exact owner-filtered match. */
    public static function recoverCreatedParcel(int $externalId, string $market): ?int
    {
        $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS,
            ['external_ids' => [$externalId], 'market_name' => [$market], 'per_page' => 30]);
        $data = $response['body']['data'] ?? null;
        if (!is_array($data)) throw new SalamGateException('پاسخ بررسی ثبت مرسوله معتبر نیست.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        $matched = null;
        foreach ($data as $item) {
            if (!is_array($item) || ($item['market']['value'] ?? null) !== $market
                || ParcelService::positiveId($item['external_id'] ?? null) !== $externalId) continue;
            $id = ParcelService::positiveId($item['id'] ?? null);
            if ($id === null || $matched !== null) throw new SalamGateException('شناسه مرسوله ثبت‌شده یکتا نیست؛ از فهرست بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            $matched = $id;
        }
        return $matched;
    }

    /** Reuse the same unpaid-draft lifecycle from the guarded WooCommerce order endpoint. */
    public static function updateWebsiteOrderDraft(int $parcelId, array $body, callable $renew): array
    {
        return self::updateOutcome($parcelId, 'website', 'website', $body, $renew);
    }

    /** Confirm the replacement's explicit selection using the same server snapshot and guards. */
    public static function selectWebsiteOrderQuote(int $parcelId, string $hash, callable $renew): array
    {
        return self::selectQuoteOutcome($parcelId, 'website', $hash, $renew);
    }

    /** The API updates an internal parcel ID through the market upsert POST, never PATCH. */
    private static function updateOutcome(int $parcelId, string $originalMarket, string $market, array $body, callable $renew): array
    {
        $previousEdit = get_option(self::editOutcomeKey($parcelId), null);
        if (is_array($previousEdit) && ($previousEdit['state'] ?? '') === 'replaced') {
            return self::restoreReplacement($parcelId, $market, $body, $previousEdit);
        }
        if (is_array($previousEdit) && ($previousEdit['state'] ?? '') !== 'selection_required') {
            return self::unknownEditOutcome($parcelId, $previousEdit);
        }
        $attempted = false;
        $confirmed = false;
        try {
            $item = self::assertParcelMarket($parcelId, $originalMarket);
            self::assertPayableStatus($item);
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
            $replaceDraft = !self::sameEditableItems($body['items'], $detail['items']);
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
                return self::leaseUnavailable();
            }
            if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null) {
                return ['ok' => false, 'payload' => [
                    'message' => 'پرداخت این مرسوله در حال بررسی است؛ اطلاعات آن را تغییر ندهید.',
                    'code' => 'pay_unknown', 'parcel_id' => $parcelId, 'market' => $originalMarket,
                ]];
            }

            WebsiteShippingEligibility::assertForParcel($item);
            $marker = ['state' => 'unknown', 'original_market' => $originalMarket, 'market' => $market,
                'request_hash' => self::editRequestHash($body), 'replacement' => $replaceDraft,
                'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id']];
            // Website UPDATE drops external_id; keep the verified financial identity first.
            \SyncBasalam\SalamGate\Services\WebsitePaymentGuard::rememberIdentity($item);
            update_option(self::editOutcomeKey($parcelId), $marker, false);
            if (get_option(self::editOutcomeKey($parcelId), null) !== $marker) {
                return ['ok' => false, 'payload' => ['message' => 'وضعیت ویرایش ذخیره نشد؛ درخواست ارسال نشد.', 'code' => 'preflight_unavailable']];
            }
            if (!$replaceDraft) { $body['id'] = $parcelId; }
            $attempted = true;
            $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), $body);
            $savedId = ParcelService::positiveId($response['body']['parcel_id'] ?? null);
            if ($savedId === null || (!$replaceDraft && $savedId !== $parcelId) || ($replaceDraft && $savedId === $parcelId)) {
                return self::unknownEditOutcome($parcelId, $marker);
            }
            $confirmed = true;
            if ($replaceDraft && $originalMarket === 'website' && $market === 'website') {
                \SyncBasalam\SalamGate\Services\WebsitePaymentGuard::rememberIdentity($item, $savedId);
            }
            self::saveParcelContext($savedId, $body);
            $marker['state'] = $replaceDraft ? 'replaced' : 'selection_required';
            if ($replaceDraft) { $marker['replacement_parcel_id'] = $savedId; }
            update_option(self::editOutcomeKey($parcelId), $marker, false);
            if (get_option(self::editOutcomeKey($parcelId), null) !== $marker) {
                return self::unknownEditOutcome($parcelId, $marker);
            }
            $quotes = ['parcel_id' => $savedId, 'quotes' => [], 'errors' => []];
            $refresh = $renew();
            $fetch = static function (callable $quoteRenew) use ($savedId, $replaceDraft, $body, $market, $refresh): array {
                if ($replaceDraft) {
                    // Preparing the new selection guard and reading its quotes share the NEW lease.
                    // A reused response ID must never overwrite a pre-existing financial/edit guard.
                    if (get_option(ParcelService::paymentOutcomeKey($savedId), null) !== null
                        || get_option(self::editOutcomeKey($savedId), null) !== null) {
                        return ['ok' => false, 'payload' => ['message' => 'وضعیت پیش‌نویس جدید باید دوباره بررسی شود؛ نرخ‌ها تغییر نکردند.']];
                    }
                    $nextMarker = ['state' => 'selection_required', 'market' => $market,
                        'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id']];
                    update_option(self::editOutcomeKey($savedId), $nextMarker, false);
                    if (get_option(self::editOutcomeKey($savedId), null) !== $nextMarker) {
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
                    WebsiteShippingEligibility::assertForParcel(self::assertParcelMarket($savedId, $market));
                    $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $savedId));
                    return ['ok' => true, 'payload' => self::checkedQuotes($response['body'] ?? null, $savedId)];
                } catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => ['message' => $exception->firstMessage()]];
                }
            };
            $quoteOutcome = $replaceDraft ? self::withQuoteLease($savedId, $fetch) : $fetch($renew);
            if ($quoteOutcome['ok']) { $quotes = $quoteOutcome['payload']; }
            else { $quotes['errors'][] = $quoteOutcome['payload']['message']; }
            return ['ok' => true, 'payload' => [
                'parcel_id' => $savedId, 'market' => $market, 'updated' => true,
                'replaces_parcel_id' => $replaceDraft ? $parcelId : null,
                'requires_quote_selection' => true, 'quotes' => $quotes,
            ]];
        } catch (SalamGateException $exception) {
            if ($attempted && !$confirmed && ParcelService::isResultUnknown($exception)) {
                return self::unknownEditOutcome($parcelId, $marker);
            }
            if ($attempted && !$confirmed) {
                if ($previousEdit === null) {
                    delete_option(self::editOutcomeKey($parcelId));
                } else {
                    update_option(self::editOutcomeKey($parcelId), $previousEdit, false);
                }
            }
            return ['ok' => false, 'payload' => [
                'message' => $exception->firstMessage(), 'errors' => $exception->getErrors(),
                'kind' => $exception->getKind(), 'code' => 'update_failed',
                'parcel_id' => $parcelId, 'market' => $originalMarket,
            ]];
        }
    }

    private static function editRequestHash(array $body): string
    {
        // Packaging padding may change outside the wizard; only compare the user's item fields.
        foreach ($body['items'] as &$item) { unset($item['packaged_weight']); }
        unset($item, $body['id']);
        return hash('sha256', json_encode($body));
    }

    /** A repeated confirmed replacement request must never create a second draft. */
    private static function restoreReplacement(int $oldId, string $market, array $body, array $marker): array
    {
        $newId = ParcelService::positiveId($marker['replacement_parcel_id'] ?? null);
        if ($newId === null || ($marker['market'] ?? '') !== $market
            || !hash_equals((string) ($marker['request_hash'] ?? ''), self::editRequestHash($body))) {
            return self::unknownEditOutcome($oldId, $marker);
        }
        return ['ok' => true, 'payload' => [
            'parcel_id' => $newId, 'market' => $market, 'updated' => true, 'replaces_parcel_id' => $oldId,
            'requires_quote_selection' => true,
            // Recovery is read-only: another tab could already be selecting or paying the new draft.
            'quotes' => ['parcel_id' => $newId, 'quotes' => [], 'errors' => []],
        ]];
    }

    /** Restore the authoritative replacement after the browser lost a confirmed update response. */
    private static function recoverReplacement(int $oldId, string $oldMarket, array $marker): array
    {
        if (($marker['original_market'] ?? '') !== $oldMarket) { return self::unknownEditOutcome($oldId, $marker); }
        $seen = [$oldId => true];
        $current = $marker;
        for ($hop = 0; $hop < 20; ++$hop) {
            $newId = ParcelService::positiveId($current['replacement_parcel_id'] ?? null);
            $market = $current['market'] ?? null;
            if ($newId === null || isset($seen[$newId]) || !in_array($market, self::MARKETS, true)) {
                return self::unknownEditOutcome($oldId, $marker);
            }
            $seen[$newId] = true;
            $next = get_option(self::editOutcomeKey($newId), null);
            if (is_array($next) && ($next['state'] ?? '') === 'replaced') { $current = $next; continue; }
            // All reads and any quote refresh for the new identity use its own shared workflow lease.
            return self::withQuoteLease($newId, static function (callable $renew) use ($oldId, $newId, $market, $current, $next): array {
                $outcome = self::quotesOutcome($newId, $market, true, $renew);
                if (!$outcome['ok']) { return $outcome; }
                $outcome['payload']['replaces_parcel_id'] = $oldId;
                if (($outcome['payload']['payment_state'] ?? '') !== '') { return $outcome; }
                $outcome['payload']['replacement_payload'] = $outcome['payload']['parcel_payload'];
                return $outcome;
            });
        }
        return self::unknownEditOutcome($oldId, $marker);
    }

    private static function saveParcelContext(int $parcelId, array $body): void
    {
        update_option('salam_gate_parcel_context_' . $parcelId, [
            'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id'],
        ], false);
    }

    /** Every restored editable form comes from the server, never from a browser dirty flag. */
    private static function canonicalParcelPayload(int $parcelId, array $listItem, ?array $edit): array
    {
        $detail = (new ParcelService())->getDetail($parcelId);
        if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId) { throw self::invalidQuotes(); }
        $receiver = $detail['shipping_info']['receiver'] ?? null;
        $cityId = is_array($receiver) ? ParcelService::positiveId($receiver['city']['id'] ?? $receiver['city_id'] ?? null) : null;
        if ($cityId === null || !is_array($detail['items'] ?? null) || $detail['items'] === []) { throw self::invalidQuotes(); }
        foreach (['full_name', 'phone', 'address', 'postal_code'] as $key) {
            if (!is_string($receiver[$key] ?? null)) { throw self::invalidQuotes(); }
        }
        $items = [];
        foreach ($detail['items'] as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null)) { throw self::invalidQuotes(); }
            foreach (['quantity', 'price', 'net_weight'] as $key) {
                if (ParcelService::positiveId($item[$key] ?? null) === null) { throw self::invalidQuotes(); }
            }
            if ((int) $item['price'] % 10 !== 0) { throw self::fractionalFormMoney(); }
            $items[] = ['title' => $item['title'], 'quantity' => (int) $item['quantity'],
                'net_weight' => (int) $item['net_weight'], 'price_toman' => Units::toToman((int) $item['price']),
                'sku' => is_string($item['sku'] ?? null) ? $item['sku'] : null]
                + (array_key_exists('external_id', $item) ? ['external_id' => $item['external_id']] : []);
        }
        foreach (['weight', 'value'] as $key) {
            if (ParcelService::positiveId($detail[$key] ?? null) === null) { throw self::invalidQuotes(); }
        }
        if ((int) $detail['value'] % 10 !== 0) { throw self::fractionalFormMoney(); }
        if (ParcelService::positiveId($detail['box_type']['id'] ?? null) === null) { throw self::invalidQuotes(); }
        $context = get_option('salam_gate_parcel_context_' . $parcelId, []);
        $ids = (is_array($context) ? $context : []) + (is_array($edit) ? $edit : []);
        $providerId = ParcelService::positiveId($ids['provider_shop_id'] ?? null);
        if ($providerId === null) { $providerId = self::providerFromSavedSender($detail); }
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

    private static function fractionalFormMoney(): SalamGateException
    {
        return new SalamGateException('مبالغ اقلام و ارزش این پیش‌نویس دارای اعشار تومان هستند؛ پیش از ادامه آن‌ها را به تومان کامل بررسی و اصلاح کنید. مبلغ ارسال گرد نشده است.', 422,
            [['location' => 'parcel.value', 'message' => 'مبالغ اقلام و ارزش این پیش‌نویس دارای اعشار تومان هستند؛ قبل از ادامه آن‌ها را بررسی و اصلاح کنید.']], SalamGateException::KIND_VALIDATION);
    }

    /** Legacy drafts lack saved provider IDs: only an unambiguous address match may recover one. */
    private static function providerFromSavedSender(array $detail): ?int
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

    private static function sameEditableItems(array $requested, array $existing): bool
    {
        $normalize = static function (array $items): ?array {
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
            usort($result, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            return $result;
        };
        $left = $normalize($requested);
        $right = $normalize($existing);
        return $left !== null && $right !== null && $left !== [] && $left === $right;
    }

    private static function editOutcomeKey(int $parcelId): string
    {
        return 'salam_gate_parcel_edit_' . $parcelId;
    }

    private static function unknownEditOutcome(int $parcelId, array $marker): array
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

    private static function fetchQuotes(int $parcelId): array
    {
        try {
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            return QuoteNormalizer::normalize(is_array($response['body'] ?? null) ? $response['body'] : []);
        } catch (SalamGateException $e) {
            return ['quotes' => [], 'errors' => [$e->firstMessage()]];
        }
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'برای این کار به دسترسی مدیریت فروشگاه نیاز دارید.'], 403);
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'مهلت این صفحه تمام شده است؛ صفحه را تازه کنید و دوباره ادامه دهید.'], 403);
        }
    }

    /** Resume an already-created parcel; never creates a second parcel or pays it. */
    public static function quotes(bool $listCheckout = false): void
    {
        self::guard();
        $parcelId = self::postedPositiveId('parcel_id');
        $market = self::postedMarket('market', $listCheckout);
        if ($parcelId === null || $market === null) {
            wp_send_json_error(['message' => 'شناسه مرسوله یا مبدأ سفارش نامعتبر است.', 'code' => 'validation']);
        }
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        // Existing financial outcomes only reconcile with a GET; they never refresh a rate.
        if (is_array($previous) && in_array($previous['state'] ?? '', ['paid', 'pending', 'unknown'], true)) {
            self::sendOutcome(self::quotesOutcome($parcelId, $market, false, static fn (): bool => false, $listCheckout));
        }
        self::sendOutcome(self::withQuoteLease($parcelId, static fn (callable $renew): array => self::quotesOutcome($parcelId, $market, true, $renew, $listCheckout)));
    }

    private static function quotesOutcome(int $parcelId, string $market, bool $allowQuotes, callable $renew, bool $listCheckout = false): array
    {
        try {
            $edit = get_option(self::editOutcomeKey($parcelId), null);
            if (!$listCheckout && is_array($edit) && ($edit['state'] ?? '') === 'replaced') {
                return self::recoverReplacement($parcelId, $market, $edit);
            }
            // The attempted update may have migrated the market; do not hide its identity behind a stale filter.
            if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
                return self::unknownEditOutcome($parcelId, $edit);
            }
            $item = self::assertParcelMarket($parcelId, $market);
            // Recovery is local and lease-fenced; it never submits another payment.
            if (!$allowQuotes && self::canReconcileFailure($item)) {
                return self::withQuoteLease($parcelId, static fn (callable $refresh): array => self::quotesOutcome($parcelId, $market, true, $refresh, $listCheckout));
            }
            if ($allowQuotes && $renew()) self::reconcileFailedCheckoutPayment($item);
            if ($listCheckout) {
                $checkout = self::checkoutPayload($item);
                if (!$checkout['can_pay']) {
                    // A read-only recheck never rotates a quote or resubmits an uncertain payment.
                    $result = $checkout['payment_state'] === 'paid' ? self::applyPayResult([], $item, $market, $parcelId) : null;
                    if ($result !== null) {
                        $result = self::reconcileCheckoutOrder($item, $result, $allowQuotes, $renew);
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
            if (self::confirmedReceipt($item)) {
                if (!ParcelService::recordReceiptOutcome($item)) { throw self::paymentIdentityError(); }
                $paymentState = 'paid';
            }
            if ($paymentState !== '') {
                $invoiceId = is_array($previous) ? ParcelService::positiveId($previous['invoice_id'] ?? null) : null;
                return ['ok' => true, 'payload' => [
                    'parcel_id' => $parcelId, 'market' => $market, 'payment_state' => $paymentState,
                    'quotes' => ['parcel_id' => $parcelId, 'quotes' => [], 'errors' => []],
                    'result' => self::applyPayResult($invoiceId !== null ? ['invoice' => ['id' => $invoiceId]] : [], $item, $market, $parcelId),
                ]];
            }
            if (!$allowQuotes || !$renew()) {
                return self::leaseUnavailable();
            }
            self::assertPayableStatus($item);
            $weightReview = self::basalamCheckoutReview($item);
            if (!$renew()) { return self::leaseUnavailable(); }
            WebsiteShippingEligibility::assertForParcel($item);
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            $quotes = self::checkedQuotes($response['body'] ?? null, $parcelId);
            if ($listCheckout) {
                return ['ok' => true, 'payload' => self::checkoutPayload($item) + ['quotes' => $quotes,
                    'requires_quote_selection' => is_array($edit)] + ($weightReview !== null ? ['weight_confirmation' => $weightReview] : [])];
            }
            return ['ok' => true, 'payload' => [
                'parcel_id' => $parcelId, 'market' => $market, 'payment_state' => '', 'quotes' => $quotes,
                'parcel_payload' => self::canonicalParcelPayload($parcelId, $item, is_array($edit) ? $edit : null),
                'payment_failure_confirmed' => ParcelService::paymentFailureConfirmed($item),
                'requires_quote_selection' => is_array($edit),
            ]];
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => self::preflightError($exception)];
        }
    }

    /**
     * انتخاب نرخ ارسال (PUT /v1/parcels/{id}/quotes) — پارت ۷.
     * hash_id کهنه ممکن است رد یا هزینه مجدد محاسبه شود.
     */
    public static function selectQuote(bool $listCheckout = false): void
    {
        self::guard();

        $post = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';

        $parcelId = self::postedPositiveId('parcel_id');
        $hash = $post('hash');
        $market = self::postedMarket('market', $listCheckout);

        if ($parcelId === null || $hash === '' || strlen($hash) > 256) {
            wp_send_json_error(['message' => 'پارامترهای انتخاب نرخ نامعتبر است.']);
        }

        if ($market === null) {
            wp_send_json_error(['message' => 'مبدأ سفارش نامعتبر است.']);
        }

        self::sendOutcome(self::withQuoteLease($parcelId, static fn (callable $renew): array => self::selectQuoteOutcome($parcelId, $market, $hash, $renew, $listCheckout)));
    }

    private static function selectQuoteOutcome(int $parcelId, string $market, string $hash, callable $renew, bool $listCheckout = false): array
    {
        $quoteAttempted = false;
        try {
            $edit = get_option(self::editOutcomeKey($parcelId), null);
            if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
                return self::unknownEditOutcome($parcelId, $edit);
            }
            $item = self::assertParcelMarket($parcelId, $market);
            self::assertPayableStatus($item);
            $weightReview = self::basalamCheckoutReview($item);
            if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null) {
                return ['ok' => false, 'payload' => ['message' => 'پرداخت این مرسوله ثبت شده یا در حال بررسی است؛ نرخ را تغییر ندهید.', 'code' => 'pay_unknown']];
            }
            if (!$renew()) {
                return self::leaseUnavailable();
            }
            WebsiteShippingEligibility::assertForParcel($item);
            $quoteAttempted = true;
            $response = ClientFactory::make()->put(
                sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId),
                ['hash' => $hash]
            );
            $normalized = self::checkedQuotes($response['body'] ?? null, $parcelId);
            $selected = self::selectedQuote($normalized);
            if ($selected === null || $selected['hash_id'] !== $hash) {
                return ['ok' => false, 'payload' => ['message' => 'نرخ انتخابی تأیید نشد؛ دوباره نرخ بگیرید.', 'code' => 'quote_changed']];
            }

            // Stage3 confirms the same server information that was actually priced, including other-tab edits.
            $canonical = $listCheckout ? null : self::canonicalParcelPayload($parcelId, $item, is_array($edit) ? $edit : null);
            if (is_array($edit)) {
                if (!$renew()) { return self::leaseUnavailable(); }
                delete_option(self::editOutcomeKey($parcelId));
            }

            if ($listCheckout) {
                return ['ok' => true, 'payload' => self::checkoutPayload($item) + ['quotes' => $normalized, 'requires_quote_selection' => false]
                    + ($weightReview !== null ? ['weight_confirmation' => $weightReview] : [])];
            }
            return ['ok' => true, 'payload' => ['parcel_id' => $parcelId, 'market' => $market, 'quotes' => $normalized,
                'parcel_payload' => $canonical, 'requires_quote_selection' => false]];
        } catch (SalamGateException $e) {
            if (!$quoteAttempted) { return ['ok' => false, 'payload' => self::preflightError($e)]; }
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

    /**
     * پرداخت مرسوله ارسال مستقل (POST /v1/parcels/{id}/pay) — پارت ۷.
     * method: بسالم=19 (فقط بازار basalam)؛ سایر=20/21.
     * نتیجه مبهم → pay_unknown (تکرار کور ممنوع؛ وضعیت با GET shops/parcels بررسی می‌شود).
     */
    public static function pay(bool $listCheckout = false): void
    {
        self::guard();

        $post = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';

        $parcelId = self::postedPositiveId('parcel_id');
        $market = self::postedMarket('market', $listCheckout);
        $method = self::postedPositiveId('method');

        if ($parcelId === null) {
            wp_send_json_error(['message' => 'شناسه مرسوله نامعتبر است.']);
        }

        if ($market === null) {
            wp_send_json_error(['message' => 'مبدأ سفارش نامعتبر است.']);
        }

        // method: بازار غیر باسلام فقط 20/21
        if ($method === null || !PayMethods::isValidParcelMethodForMarket($method, $market)) {
            wp_send_json_error(['message' => 'روش پرداخت انتخاب‌شده برای این مرسوله در دسترس نیست.']);
        }

        $outcomeKey = ParcelService::paymentOutcomeKey($parcelId);
        $previous = get_option($outcomeKey, null);
        if (is_array($previous)) {
            self::sendOutcome(self::storedPaymentOutcome($parcelId, $market, $previous));
        }

        $expectedHash = $post('expected_hash');
        $expectedTotal = self::postedInteger('expected_total_rial');
        if ($expectedHash === '' || strlen($expectedHash) > 256 || $expectedTotal === null) {
            wp_send_json_error(['message' => 'ابتدا نرخ ارسال و مبلغ نهایی را بررسی و تأیید کنید.', 'code' => 'quote_changed']);
        }

        self::sendOutcome(self::withQuoteLease($parcelId, static fn (callable $renew): array => self::newPaymentOutcome($parcelId, $market, $method, $expectedHash, $expectedTotal, $renew, $listCheckout)));
    }

    private static function newPaymentOutcome(int $parcelId, string $market, int $method, string $expectedHash, int $expectedTotal, callable $renew, bool $listCheckout = false): array
    {
        // A previous request may have settled while we waited to acquire this lease.
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        if (is_array($previous)) {
            return self::storedPaymentOutcome($parcelId, $market, $previous, true, $renew);
        }
        $edit = get_option(self::editOutcomeKey($parcelId), null);
        if (is_array($edit)) {
            if (($edit['state'] ?? '') !== 'selection_required') {
                return self::unknownEditOutcome($parcelId, $edit);
            }
            return ['ok' => false, 'payload' => [
                'message' => 'اطلاعات مرسوله ویرایش شده است؛ روش ارسال را از نرخ‌های تازه انتخاب و دوباره تأیید کنید.',
                'code' => 'quote_changed', 'requires_quote_selection' => true,
            ]];
        }
        $paymentAttempted = false;
        try {
            $item = self::assertParcelMarket($parcelId, $market);
            self::assertPayableStatus($item);
            // The bulk wrapper forces this internal context AFTER validating
            // selection membership. Existing individual continuation stays
            // unchanged, while every group payment pins fresh packaged weight
            // under the same quote/payment lease, not a stale browser snapshot.
            if ($listCheckout && ($_POST['_salam_gate_bulk_weight_required'] ?? null) === '1') {
                try { OrderWeightConfirmation::assertConfirmed(wp_unslash($_POST), $item['weight'] ?? null); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => [
                        'message' => $exception->firstMessage(), 'errors' => $exception->getErrors(),
                        'kind' => $exception->getKind(), 'code' => 'weight_confirmation_required',
                        'current_weight_grams' => Units::parseBoundedFormInteger($item['weight'] ?? null, 50, 30000),
                        'request_dispatched' => false,
                    ]];
                }
            }
            self::basalamCheckoutReview($item, true);
            // Quote GET can also persist a replacement rate, so fence it as well as pay POST.
            if (!$renew()) {
                return self::leaseUnavailable();
            }
            WebsiteShippingEligibility::assertForParcel($item);
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            $quotes = self::checkedQuotes($response['body'] ?? null, $parcelId);
            $selected = self::selectedQuote($quotes);
            if ($selected === null || $selected['hash_id'] !== $expectedHash || $selected['total_rial'] !== $expectedTotal) {
                return ['ok' => false, 'payload' => ['message' => 'نرخ یا مبلغ ارسال تغییر کرده است؛ دوباره انتخاب و تأیید کنید. پرداخت انجام نشد.', 'code' => 'quote_changed', 'quotes' => $quotes]];
            }
            if (!$renew()) {
                return self::leaseUnavailable();
            }
            WebsiteShippingEligibility::assertForParcel($item);
            $service = new \SyncBasalam\SalamGate\Services\ParcelService();
            $paymentAttempted = true;
            $payBody = $service->pay($parcelId, $method, $item);

            // نتیجه نهایی با GET /v1/parcels با ids و market_name صریح — نه data[0]
            try {
                $parcelItem = $service->fetchAfterPay($parcelId, $market,
                    $market === 'basalam' ? ParcelService::positiveId($item['external_id'] ?? null) : null);
            } catch (\SyncBasalam\SalamGate\SalamGateException $fetchEx) {
                $parcelItem = null; // fetch ناموفق ≠ شکست قطعی
            }

            $result = self::applyPayResult($payBody, $parcelItem, $market, $parcelId);
            if ($listCheckout) {
                // The preflight identity also covers an unavailable post-payment read.
                $result = self::syncCheckoutOrder($payBody, $parcelItem, $item, $market, $result);
            }
            return ['ok' => true, 'payload' => $result];
        } catch (\SyncBasalam\SalamGate\SalamGateException $e) {
            $payload = ['message' => $e->firstMessage(), 'errors' => $e->getErrors(), 'kind' => $e->getKind()];

            if (!$paymentAttempted) {
                $payload = self::preflightError($e);
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
            } elseif (str_contains($e->firstMessage() . ' ' . json_encode($e->getErrors()), 'already-paid')) {
                $payload['code'] = 'already_paid';
                $payload['message'] = 'این مرسوله قبلاً پرداخت شده است.';
            }

            return ['ok' => false, 'payload' => $payload];
        }
    }

    /** This local workflow lease is separate from the permanent financial outcome marker. */
    private static function withQuoteLease(int $parcelId, callable $work): array
    {
        $key = 'salam_gate_quote_workflow_' . $parcelId;
        $lease = OptionLock::acquire($key);
        if ($lease === null) {
            return self::leaseUnavailable();
        }
        try {
            $renew = static function () use ($key, &$lease): bool {
                $next = OptionLock::renew($key, $lease);
                if ($next === null) {
                    return false;
                }
                $lease = $next;
                return true;
            };
            return $work($renew);
        } finally {
            // wp_send_json exits PHP, bypassing finally: all responses are sent AFTER this returns.
            OptionLock::release($key, $lease);
        }
    }

    private static function leaseUnavailable(): array
    {
        return ['ok' => false, 'payload' => [
            'message' => 'عملیات دیگری روی این مرسوله در حال اجراست؛ کمی بعد دوباره بررسی کنید. درخواست پرداخت ارسال نشد.',
            'code' => 'preflight_locked',
        ]];
    }

    private static function sendOutcome(array $outcome): void
    {
        if ($outcome['ok']) {
            wp_send_json_success($outcome['payload']);
        }
        wp_send_json_error($outcome['payload']);
    }

    private static function storedPaymentOutcome(int $parcelId, string $market, array $previous, bool $leaseHeld = false, ?callable $renew = null): array
    {
        if (($previous['state'] ?? '') === 'paid') {
            return ['ok' => false, 'payload' => ['message' => 'پرداخت هزینه ارسال این مرسوله قبلاً ثبت شده است.', 'code' => 'already_paid']];
        }
        try {
            $item = self::assertParcelMarket($parcelId, $market);
        } catch (SalamGateException $exception) {
            $item = null;
        }
        if ($item !== null && self::confirmedReceipt($item)) {
            if (!ParcelService::recordReceiptOutcome($item)) {
                return ['ok' => false, 'payload' => ['message' => self::paymentIdentityError()->firstMessage(), 'code' => 'pay_unknown']];
            }
            return ['ok' => true, 'payload' => self::applyPayResult([], $item, $market, $parcelId)];
        }
        if ($item !== null && self::canReconcileFailure($item)) {
            $work = static function (callable $renew) use ($parcelId, $market): array {
                try { $fresh = self::assertParcelMarket($parcelId, $market); }
                catch (SalamGateException $exception) {
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی در حال بررسی است؛ «بررسی وضعیت» را دوباره بزنید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
                }
                if ($renew() && self::reconcileFailedCheckoutPayment($fresh)) {
                    return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی ناموفق بود. روش ارسال و پرداخت را دوباره بررسی و تأیید کنید.', 'code' => 'payment_failed', 'payment_state' => 'unpaid']];
                }
                return ['ok' => false, 'payload' => ['message' => 'پرداخت قبلی در حال بررسی است؛ برای دریافت تأیید، «بررسی وضعیت» را بزنید.', 'code' => 'pay_unknown']];
            };
            return $leaseHeld && $renew !== null ? $work($renew) : self::withQuoteLease($parcelId, $work);
        }
        return ['ok' => false, 'payload' => ['message' => 'پرداخت در حال بررسی است؛ تأیید آن را از فهرست مرسوله‌ها با «بررسی وضعیت» بگیرید. پرداخت را تکرار نکنید.', 'code' => 'pay_unknown']];
    }

    private static function canReconcileFailure(array $item): bool
    {
        return ($item['invoice_status'] ?? null) === 3 && ($item['status_id'] ?? null) === 7
            && is_array(get_option(ParcelService::paymentOutcomeKey($item['id']), null));
    }

    private static function reconcileFailedCheckoutPayment(array $item): bool
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

    private static function postedInteger(string $key): ?int
    {
        $raw = $_POST[$key] ?? null;
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

    private static function postedPositiveId(string $key): ?int
    {
        $value = self::postedInteger($key);
        return $value !== null && $value > 0 ? $value : null;
    }

    private static function postedMarket(string $key = 'market', bool $listCheckout = false): ?string
    {
        $raw = $_POST[$key] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $market = sanitize_key(wp_unslash($raw));
        return in_array($market, $listCheckout ? ParcelsListService::ALL_MARKETS : self::MARKETS, true) ? $market : null;
    }

    /** The list endpoint is owner-filtered by the API; returned ID and market must match. */
    private static function assertParcelMarket(int $parcelId, string $market): array
    {
        $previous = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
        $guarded = is_array($previous) && in_array($previous['state'] ?? '', ['paid', 'pending', 'unknown'], true);
        $native = $guarded && $market === 'basalam'
            ? ParcelService::positiveId($previous['baseline_external_id'] ?? null) : null;
        $bulkIdentity = self::bulkNativeIdentity($parcelId, $market);
        if ($bulkIdentity !== null) {
            if ($guarded && $native !== $bulkIdentity['parcel_id']) { throw self::paymentIdentityError(); }
            // Reapply the exact reviewed child inside the shipping lease. The
            // wrapper's earlier read cannot authorize an unlinked draft that
            // was reassigned to another native order before this fresh fetch.
            $native = $bulkIdentity['parcel_id'];
        }
        if ($guarded && !ParcelService::matchesPaymentIdentity(['market' => $market, 'external_id' => $native], $previous)) {
            throw self::paymentIdentityError();
        }
        try { $item = (new ParcelService())->fetchAfterPay($parcelId, $market, $native); }
        catch (SalamGateException $exception) {
            if ($guarded) { throw self::paymentIdentityError(); }
            throw $exception;
        }
        if ($guarded && $item !== null && !ParcelService::matchesPaymentIdentity($item, $previous)) {
            throw self::paymentIdentityError();
        }
        if ($item === null) {
            if ($guarded) { throw self::paymentIdentityError(); }
            throw new SalamGateException('مرسوله برای حساب متصل و مبدأ انتخابی یافت نشد.', 404, [
                ['location' => 'parcel_id', 'message' => 'هویت مرسوله تأیید نشد.'],
            ], SalamGateException::KIND_NOT_FOUND);
        }
        return $item;
    }

    /** Optional restrictions can only narrow checkout; browser data never grants access. */
    private static function bulkNativeIdentity(int $parcelId, string $market): ?array
    {
        $pin = $_POST['_salam_gate_bulk_native_identity'] ?? null;
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

    private static function paymentIdentityError(): SalamGateException
    {
        return new SalamGateException('پرداخت قبلی در حال بررسی است؛ تأیید آن برای این مرسوله هنوز دریافت نشده است. وضعیت ارسال را بررسی کنید و پرداخت را تکرار نکنید.',
            422, [['location' => 'payment.identity', 'message' => 'payment-identity-unconfirmed']], SalamGateException::KIND_VALIDATION);
    }

    private static function confirmedReceipt(array $item): bool
    {
        return \SyncBasalam\SalamGate\Contracts\ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            && (($item['market'] ?? '') !== 'basalam' || (($item['invoice_status'] ?? null) === 2
                && is_string($item['tracking_code'] ?? null) && trim($item['tracking_code']) !== ''));
    }

    private static function assertPayableStatus(array $item): void
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

    private static function syncCheckoutOrder(array $payBody, ?array $parcelItem, array $identity, string $market, array $result): array
    {
        try {
            $order = ParcelsListService::linkedOrderForParcel($identity);
            if ($order !== null) {
                ParcelActions::applyPaySuccess($order, $payBody, $parcelItem, $market);
                $result['woo_order_id'] = (int) $order->get_id();
            }
        } catch (\Throwable $exception) {
            // Payment is already settled: a local metadata problem never authorizes another charge.
            $result['order_sync_failed'] = true;
        }
        return $result;
    }

    /** Reconcile a timed-out payment only from a paid invoice + receipt, without financial requests. */
    private static function reconcileCheckoutOrder(array $item, array $result, bool $leaseHeld, callable $renew): array
    {
        if (($item['invoice_status'] ?? null) !== 2
            || !\SyncBasalam\SalamGate\Contracts\ParcelStatuses::hasReceipt((int) ($item['status_id'] ?? 0))
            || !is_string($item['tracking_code'] ?? null) || trim($item['tracking_code']) === '') {
            return $result;
        }
        $work = static function (callable $refreshLease) use ($item, $result): array {
            if (!$refreshLease()) { return self::leaseUnavailable(); }
            if (!ParcelService::recordReceiptOutcome($item)) {
                return ['ok' => false, 'payload' => ['message' => self::paymentIdentityError()->firstMessage(), 'code' => 'pay_unknown']];
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
                        $result = self::syncCheckoutOrder([], $item, $item, $item['market'], $result);
                    } else {
                        $result['woo_order_id'] = (int) $order->get_id();
                    }
                }
            } catch (\Throwable $exception) { $result['order_sync_failed'] = true; }
            return ['ok' => true, 'payload' => $result];
        };
        // Known outcomes bypass the rate lease on initial read. Claim it only for local
        // reconciliation, so an overlapping checkout cannot duplicate order notes.
        $outcome = $leaseHeld ? $work($renew) : self::withQuoteLease($item['id'], $work);
        return $outcome['ok'] ? $outcome['payload'] : $result + ['order_sync_pending' => true];
    }

    /** No form reconstruction or sender lookup is needed to pay an existing account-owned parcel. */
    private static function checkoutPayload(array $item): array
    {
        $availability = ParcelsListService::paymentAvailability($item);
        $item['market_label'] = ParcelsListService::MARKET_LABELS[$item['market']] ?? 'سایر';
        return ['parcel_id' => $item['id'], 'market' => $item['market'], 'parcel' => $item,
            'payment_state' => $availability['payment_state'], 'can_pay' => $availability['can_resume_payment'],
            'payment_failure_confirmed' => $availability['can_resume_payment'] && ParcelService::paymentFailureConfirmed($item),
            'allowed_methods' => array_keys(PayMethods::parcelMethodsForMarket($item['market'])),
            'message' => $availability['payment_message']];
    }

    /** The list must enforce the same original-order guard as the order modal. */
    private static function basalamCheckoutReview(array $item, bool $requireWeightConsent = false): ?array
    {
        if (($item['market'] ?? '') !== 'basalam') { return null; }
        require_once __DIR__ . '/../../Order/BasalamCheckoutPreflight.php';
        $review = BasalamCheckoutPreflight::check($item, $requireWeightConsent, wp_unslash($_POST));
        $pin = self::bulkNativeIdentity((int) $item['id'], 'basalam');
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

    /** Do not turn malformed money/hash/selection data into a confirmed quote by casting. */
    private static function checkedQuotes($body, int $parcelId): array
    {
        if (!is_array($body) || ParcelService::positiveId($body['parcel_id'] ?? null) !== $parcelId
            || !is_array($body['quotes'] ?? null)
        ) {
            throw self::invalidQuotes();
        }
        foreach ($body['quotes'] as $quote) {
            if (!is_array($quote) || !is_bool($quote['selected'] ?? null)) {
                throw self::invalidQuotes();
            }
            if (isset($quote['error']) && !is_string($quote['error'])) {
                throw self::invalidQuotes();
            }
            if (($quote['error'] ?? null) !== null && ($quote['error'] ?? '') !== '') {
                if ($quote['selected']) {
                    throw self::invalidQuotes();
                }
                continue;
            }
            if (!is_int($quote['service_type']['id'] ?? null) || $quote['service_type']['id'] < 1
                || !is_string($quote['hash_id'] ?? null) || $quote['hash_id'] === '' || strlen($quote['hash_id']) > 256
                || !is_int($quote['costs']['total'] ?? null) || $quote['costs']['total'] < 0
            ) {
                throw self::invalidQuotes();
            }
        }
        $quotes = QuoteNormalizer::normalize($body);
        if (count(array_filter($quotes['quotes'], static fn (array $q): bool => $q['selected'])) > 1) {
            throw self::invalidQuotes();
        }
        return $quotes;
    }

    private static function selectedQuote(array $quotes): ?array
    {
        foreach ($quotes['quotes'] as $quote) {
            if ($quote['selected'] && !$quote['error']) {
                return $quote;
            }
        }
        return null;
    }

    private static function invalidQuotes(): SalamGateException
    {
        return new SalamGateException('پاسخ نرخ ارسال معتبر نیست؛ انتخاب و مبلغ تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }

    private static function preflightError(SalamGateException $exception): array
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

    /**
     * نتیجه پرداخت موفق — بدون fallback ساختگی (پارت ۷ قاعده اصلی).
     *
     * @param array|null $parcelItem رکورد لیست نرمال‌شده یا null = وضعیت نامعلوم
     */
    private static function applyPayResult(array $payBody, ?array $parcelItem, string $market, int $parcelId = 0): array
    {
        $statusId = (int) ($parcelItem['status_id'] ?? 0);
        $confirmed = $parcelItem !== null && self::confirmedReceipt($parcelItem);
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
}
