<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\CreationAttemptService;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** CreateShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class CreateShipment
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function creationStatus(): array
    {
        $attempt = $this->input['creation_id'] ?? null;
        if (!CreationAttemptService::validAttempt($attempt)) {
            return OperationResult::failure(['message' => 'شناسه ثبت مرسوله معتبر نیست.', 'code' => 'validation']);
        }
        return (new CreationAttemptService())->status($attempt, [$this, 'recoverCreatedParcel']);
    }

    public function upsert(): array
    {

        // An explicit edit identity must never degrade into an unkeyed create.
        $editing = array_key_exists('parcel_id', $this->input);
        $existingId = $editing ? (new ParcelCheckoutPolicy($this->input, $this->context))->postedPositiveId('parcel_id') : null;
        $originalMarket = $editing ? (new ParcelCheckoutPolicy($this->input, $this->context))->postedMarket('original_market') : null;
        if ($editing && ($existingId === null || $originalMarket === null)) {
            return OperationResult::failure(['message' => 'شناسه مرسوله یا مبدأ قبلی برای ویرایش معتبر نیست.', 'code' => 'validation']);
        }

        $post = fn (string $key): string => isset($this->input[$key]) && is_scalar($this->input[$key]) ? trim((string) wp_unslash($this->input[$key])) : '';

        $market = sanitize_key($post('market'));
        if (!in_array($market, DirectShipment::MARKETS, true)) {
            return OperationResult::failure(['message' => 'مبدأ سفارش را از گزینه‌های موجود انتخاب کنید.']);
        }

        $shopId = (int) Digits::toEnglish($post('provider_shop_id'));
        if ($shopId <= 0) { return OperationResult::failure(['message' => 'آدرس فرستنده را انتخاب کنید.']); }

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
            return OperationResult::failure(['message' => 'اطلاعات مشخص‌شده گیرنده را کامل یا اصلاح کنید.', 'errors' => $errors, 'code' => 'validation']);
        }

        $weight = Units::parseBoundedFormInteger($this->input['weight'] ?? null, 50, 30000);
        $boxTypeId = (int) Digits::toEnglish($post('box_type_id'));
        // Declared parcel value is independent of the descriptive item prices.
        $valueToman = Units::parseBoundedFormInteger($this->input['value_toman'] ?? null, 2000, 1000000000, true);
        $categoryId = (int) Digits::toEnglish($post('category_id'));

        if ($weight === null) { $errors['weight'] = 'وزن باید عدد صحیح بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.'; }
        if ($valueToman === null) { $errors['value_toman'] = 'ارزش مرسوله باید عدد صحیح بین ۲٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.'; }
        if ($boxTypeId <= 0) { return OperationResult::failure(['message' => 'نوع بسته‌بندی را انتخاب کنید.']); }
        if ($categoryId <= 0) { return OperationResult::failure(['message' => 'نوع مرسوله را انتخاب کنید.']); }

        $dimensions = null;
        if ($boxTypeId === ParcelPayloadBuilder::LARGE_BOX_TYPE_ID) {
            $dimensions = [];
            foreach (['length', 'width', 'height'] as $dimension) {
                $dimensions[$dimension] = Units::parseBoundedFormInteger($this->input['dimension_' . $dimension] ?? null, 1, 2147483647);
                if ($dimensions[$dimension] === null) {
                    $errors['dimension_' . $dimension] = 'بُعد بسته باید عدد صحیح و حداقل ۱ سانتی‌متر باشد.';
                }
            }
        }

        $itemsInput = [];
        if (isset($this->input['items']) && is_array($this->input['items'])) {
            foreach (wp_unslash($this->input['items']) as $index => $item) {
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
            return OperationResult::failure(['message' => 'اطلاعات مشخص‌شده را اصلاح کنید.', 'errors' => $errors, 'code' => 'validation', 'kind' => 'validation']);
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
                return OperationResult::failure(['message' => 'وزن بسته‌بندی قلم از محدوده مجاز بیشتر است.', 'errors' => ['items' => 'وزن قلم و وزن بسته‌بندی را اصلاح کنید.'], 'code' => 'validation', 'kind' => 'validation']);
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
            return (new ParcelCheckoutPolicy($this->input, $this->context))->withQuoteLease($existingId, fn (callable $renew): array =>
                (new EditShipment($this->input, $this->context))->updateOutcome($existingId, $originalMarket, $market, $body, $renew)
            );
        }

        $attempt = $this->input['creation_id'] ?? null;
        if (!CreationAttemptService::validAttempt($attempt)) {
            return OperationResult::failure(['message' => 'شناسه درخواست ثبت معتبر نیست؛ صفحه ثبت مرسوله را دوباره باز کنید.', 'code' => 'validation']);
        }
        $outcome = (new CreationAttemptService())->run($attempt, $market, $body,
            function (int $externalId) use ($market, $body): ?int {
                $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market), ['external_id' => $externalId] + $body);
                return ParcelService::positiveId($response['body']['parcel_id'] ?? null);
            }, [$this, 'recoverCreatedParcel']);
        if ($outcome['ok']) {
            $parcelId = $outcome['payload']['parcel_id'];
            $this->saveParcelContext($parcelId, $body);
            $outcome['payload']['quotes'] = $this->fetchQuotes($parcelId);
        }
        return $outcome;
    }

    public function recoverCreatedParcel(int $externalId, string $market): ?int
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

    public function fetchQuotes(int $parcelId): array
    {
        try {
            $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_QUOTES, $parcelId));
            return QuoteNormalizer::normalize(is_array($response['body'] ?? null) ? $response['body'] : []);
        } catch (SalamGateException $e) {
            return ['quotes' => [], 'errors' => [$e->firstMessage()]];
        }
    }

    public function saveParcelContext(int $parcelId, array $body): void
    {
        update_option('salam_gate_parcel_context_' . $parcelId, [
            'provider_shop_id' => $body['parcel']['provider_shop_id'], 'category_id' => $body['parcel']['category_id'],
        ], false);
    }
}
