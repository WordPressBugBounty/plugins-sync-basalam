<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Contracts\ShopNormalizer;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\AccountService;
use SyncBasalam\SalamGate\Services\ActivationIdentityService;
use SyncBasalam\SalamGate\Services\PaymentBalanceService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;
use SyncBasalam\SalamGate\Utils\JalaliDate;

if (! defined('ABSPATH')) exit;

/**
 * ثبت اولیه سلام‌گیت (پارت ۴) — ویزارد «هویت → آدرس → منطقه و مرکز → مرور».
 *
 * قواعد قرارداد:
 * - ثبت (POST /v1/shops/register) فقط با اقدام صریح کاربر بعد از مرور انجام می‌شود.
 * - pay_method اینجا 8/2 است (نه enum مرسوله 19/20/21).
 * - موجودی نمایش فقط راهنماست؛ مبلغ دقیق و موجودی روش انتخابی پیش از ثبت دوباره خوانده می‌شوند.
 * - پاسخ async (status=15 + identifier=null + id صف) هرگز provider_shop_id ذخیره نمی‌شود.
 * - نتیجه مبهم (timeout) = register_unknown؛ تکرار کور ممنوع — وضعیت با GET shops بررسی می‌شود.
 * - پیش‌پر فقط از داده واقعی profile؛ برای فیلد غایب (مثل birth_date) مقدار جعلی ساخته نمی‌شود.
 * - مختصات بدون ورود معتبر null است — مختصات صفر/فرضی ممنوع.
 */
class OnboardingActions
{
    public const POST_PROVIDER_ID = 1;

    public static function form(): void
    {
        self::guard();
        $bufferLevel = ob_get_level();
        try {
            $data = \SyncBasalam\SalamGate\Admin\AccountStateRouter::resolveCurrent(true);
            ob_start();
            include dirname(__DIR__, 4) . '/templates/SalamGate/states/view-activation.php';
            $html = ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
            wp_send_json_error(['message' => 'فرم فعال‌سازی دریافت نشد؛ دوباره تلاش کنید.']);
            return;
        }
        wp_send_json_success(['html' => $html, 'state' => $data['state']]);
    }

    /**
     * داده راه‌اندازی ویزارد: profile (404=ثبت‌نشده)، shops همه وضعیت‌ها،
     * provider (activation_cost واقعی — نه هاردکد)، درخت استان/شهر عمومی.
     */
    public static function bootstrap(): void
    {
        self::guard();

        try {
            $activation = isset($_POST['purpose']) && is_string($_POST['purpose'])
                && wp_unslash($_POST['purpose']) === 'activation';
            wp_send_json_success(self::bootstrapData($activation));
        } catch (SalamGateException $exception) {
            // 401/403 ≠ «هنوز ثبت‌نام نکرده» — خطای مجوز جداگانه برمی‌گردد.
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * @internal فقط برای تست — همان bootstrap بدون wp_send_json.
     */
    public static function bootstrapViaTest(bool $activation = false): array
    {
        return self::bootstrapData($activation);
    }

    /**
     * Public province/city references for the read-only delivery-center browser.
     * The WordPress admin permission/nonce is still required, but this lookup must
     * not depend on authenticated SalamGate profile, shops or activation data.
     */
    public static function postOfficesReferences(): void
    {
        self::guard();

        $fresh = isset($_POST['fresh']) && is_string($_POST['fresh'])
            && wp_unslash($_POST['fresh']) === '1';

        try {
            $references = (new ReferenceDataService())->getProvinceTreeWithState($fresh);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
            return;
        } catch (\Throwable $exception) {
            wp_send_json_error([
                'message' => 'دریافت فهرست استان‌ها و شهرها ناموفق بود؛ دوباره تلاش کنید.',
                'kind' => SalamGateException::KIND_TRANSPORT,
            ]);
            return;
        }

        wp_send_json_success([
            'provinces' => self::compactTree($references['data']),
            'stale' => $references['stale'],
        ]);
    }

    private static function bootstrapData(bool $activation = false): array
    {
        // The same bootstrap serves calculator/send. Only activation needs
        // fresh identity/shop recovery; ordinary navigation retains its cache.
        $account = new AccountService();
        $profile = $account->profile($activation);
        $shopsService = new ShopsService();

        try {
            $shops = $shopsService->getShops($activation);
        } catch (SalamGateException $exception) {
            // A newly discovered denial must stop this bootstrap before it
            // reads providers, locations, balances or activation identity.
            if (in_array($exception->getStatus(), [401, 403], true)) throw $exception;
            $shops = null;
        }
        $account->assertSnapshot($profile);

        try {
            $providerResponse = ClientFactory::make()->get(sprintf('/v1/providers/%d', self::POST_PROVIDER_ID));
            $provider = self::providerData($providerResponse['body'] ?? null);
        } catch (SalamGateException $exception) {
            $provider = null;
        }
        $account->assertSnapshot($profile);

        try {
            $provinces = (new ReferenceDataService())->getProvinceTree();
        } catch (\Throwable $exception) {
            $provinces = null;
        }

        $boxTypes = null;
        if (!$activation) {
            try {
                $boxTypes = (new ReferenceDataService())->getBoxTypes();
            } catch (\Throwable $exception) {
                $boxTypes = null; // خطای دریافت ≠ فهرست خالی؛ شناسه بسته‌بندی حدس زده نمی‌شود.
            }
        }

        $registrationState = $activation ? $shopsService->registrationState($profile, $shops) : null;
        $account->assertSnapshot($profile);
        $balances = [];
        if ($activation) {
            foreach ([PayMethods::SHOP_ACTIVATION_VENDOR_BALANCE, PayMethods::SHOP_ACTIVATION_WALLET] as $method) {
                try {
                    $balance = self::activationBalance($method, true);
                    $balances[$method] = $balance + ['state' => 'ready'];
                } catch (\Throwable $exception) {
                    // A failed financial read is not a zero balance and must
                    // not prevent identity/address completion or recovery.
                    $balances[$method] = ['method' => $method, 'state' => 'unavailable',
                        'balance_rial' => null, 'display_balance_rial' => null, 'fetched_at' => null];
                }
                $account->assertSnapshot($profile);
            }
        }

        $identity = $activation ? (new ActivationIdentityService())->get($profile) : null;
        $account->assertSnapshot($profile);

        return [
            'identity' => $identity,
            'profile'   => $profile, // {user: {id,mobile,national_code,national_card_serial}|null, registered: bool}
            'shops'     => $shops,  // null = خطای دریافت (با «بدون ثبت» فرق دارد)
            'provider'  => $provider, // A missing/malformed price is never displayed as free activation.
            'provinces' => $provinces === null ? null : self::compactTree($provinces),
            'box_types' => $boxTypes,
            'balances' => $balances,
            'registration_state' => $registrationState,
            'registration_code' => $registrationState === null ? null
                : ($registrationState === 'pending' ? 'registration_pending' : 'register_unknown'),
            'pay_methods' => [
                ['id' => PayMethods::SHOP_ACTIVATION_VENDOR_BALANCE, 'title' => PayMethods::shopActivationTitle(PayMethods::SHOP_ACTIVATION_VENDOR_BALANCE)],
                ['id' => PayMethods::SHOP_ACTIVATION_WALLET, 'title' => PayMethods::shopActivationTitle(PayMethods::SHOP_ACTIVATION_WALLET)],
            ],
        ];
    }

    /**
     * مناطق پستی provider برای یک استان عمومی — [] یعنی پوشش ندارد (≠ خطا).
     */
    public static function providerLocations(): void
    {
        self::guard();

        $provinceId = self::formInteger($_POST['province_id'] ?? null, 1, 2147483647);

        if ($provinceId === null) {
            wp_send_json_error(['message' => 'استان نامعتبر است.']);
            return;
        }

        try {
            $locations = (new ReferenceDataService())->getProviderLocations($provinceId);
            wp_send_json_success(['locations' => $locations, 'has_coverage' => $locations !== []]);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * مراکز تحویل یک منطقه پستی (پارامتر city_id مسیر سرویس = provider_location.id).
     */
    public static function providerNodes(): void
    {
        self::guard();

        $locationId = self::formInteger($_POST['provider_location_id'] ?? null, 1, 2147483647);

        if ($locationId === null) {
            wp_send_json_error(['message' => 'منطقه پستی نامعتبر است.']);
            return;
        }

        try {
            $nodes = (new ReferenceDataService())->getProviderNodes($locationId);
            wp_send_json_success(['nodes' => $nodes]);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    /**
     * ثبت فروشگاه — فقط پس از اقدام صریح (دکمه مرحله مرور).
     * اعتبارسنجی کامل سمت سرور (نقشه UI) + ساخت دقیق RegisterShopRequest.
     */
    public static function register(): void
    {
        self::guard();

        $outcome = self::registerOutcome(self::collectInput());

        if ($outcome['ok']) {
            wp_send_json_success($outcome['payload']);
        }

        wp_send_json_error($outcome['payload']);
    }

    /**
     * @internal فقط برای تست — همان register بدون wp_send_json (ورودی مستقیم نرمال‌شده).
     */
    public static function registerViaTest(array $input): array
    {
        $outcome = self::registerOutcome($input);

        return $outcome['ok'] ? $outcome['payload'] : $outcome['payload'];
    }

    /**
     * @return array{ok: bool, payload: array}
     */
    private static function registerOutcome(array $input): array
    {
        $errors = self::validate($input);

        if ($errors !== []) {
            // داده فرم حفظ می‌شود (کلاینت مقدار را نگه می‌دارد) — خطاها با نام فیلد برمی‌گردند.
            return [
                'ok' => false,
                'payload' => [
                    'message' => 'اطلاعات وارد‌شده کامل نیست؛ موارد مشخص‌شده را اصلاح کنید.',
                    'errors'  => self::fieldErrors($errors),
                    'code'    => 'validation',
                ],
            ];
        }

        // Everything before register is read-only. A failed preflight must not
        // be described as an unknown registration or authorize a financial POST.
        try {
            $account = new AccountService();
            $profile = $account->profile(true);
            $identity = (new ActivationIdentityService())->get($profile);
            if ($identity['national_code'] === null || $input['national_code'] !== $identity['national_code']) {
                return ['ok' => false, 'payload' => ['code' => 'validation', 'registration_submitted' => false,
                    'message' => 'کد ملی باید با اطلاعات حساب متصل در باسلام یکسان باشد.',
                    'errors' => [['location' => 'national_code', 'message' => 'کد ملی را از حساب باسلام دوباره دریافت کنید.']]]];
            }
            $price = self::currentActivationPrice();
            $expected = self::formInteger($input['expected_activation_cost_rial'] ?? null, 0, 9007199254740991);
            if ($expected === null || $expected !== $price) {
                return ['ok' => false, 'payload' => [
                    'code' => 'activation_cost_changed',
                    'message' => 'هزینه فعال‌سازی را دوباره بررسی و تأیید کنید؛ هنوز ثبت یا پرداختی انجام نشده است.',
                    'activation_cost_rial' => $price,
                ]];
            }
            self::validateRegistrationReferences($input);
            $account->assertSnapshot($profile);
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => self::errorPayload($exception) + [
                'code' => $exception->isKind(SalamGateException::KIND_VALIDATION) ? 'validation' : 'registration_unavailable',
                'registration_submitted' => false,
            ]];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'payload' => [
                'code' => 'registration_unavailable',
                'message' => 'اطلاعات لازم برای فعال‌سازی دریافت نشد؛ دوباره تلاش کنید. ثبت یا پرداختی انجام نشده است.',
                'registration_submitted' => false,
            ]];
        }

        $body = self::buildRequestBody($input);

        $shopsService = new ShopsService();
        try {
            $shop = $shopsService->register($body, static function () use ($input, $price, $account, $profile): void {
                $account->assertSnapshot($profile);
                // A valid zero activation fee does not require available funds.
                if ($price === 0) return;
                try {
                    $balance = self::activationBalance($input['pay_method'], true);
                } catch (\Throwable $exception) {
                    throw new SalamGateException('موجودی روش پرداخت تأیید نشد؛ موجودی را تازه‌سازی کنید. ثبت یا پرداختی انجام نشده است.',
                        0, [['location' => 'activation_balance', 'message' => 'balance_unavailable']],
                        SalamGateException::KIND_UNEXPECTED_RESPONSE);
                }
                $account->assertSnapshot($profile);
                if ($balance['balance_rial'] < $price) {
                    $message = $input['pay_method'] === PayMethods::SHOP_ACTIVATION_VENDOR_BALANCE
                        ? 'موجودی تراز غرفه برای فعال‌سازی کافی نیست؛ کیف پول باسلام را انتخاب و در صورت نیاز شارژ کنید.'
                        : 'موجودی کیف پول برای فعال‌سازی کافی نیست؛ ابتدا کیف پول باسلام را شارژ کنید.';
                    throw new SalamGateException($message, 422,
                        [['location' => 'activation_balance', 'message' => 'insufficient_balance']], SalamGateException::KIND_VALIDATION);
                }
            });

            return [
                'ok' => true,
                'payload' => [
                    'shop'    => $shop, // نرمال‌شده؛ queued=true یعنی id آن شناسه صف است نه غرفه
                    'queued'  => !empty($shop['queued']),
                    'state'   => $shop['status_id'] === ShopStatuses::ACTIVE
                        ? 'active'
                        : ($shop['queued'] ? 'pending' : ShopStatuses::title($shop['status_id'])),
                ],
            ];
        } catch (SalamGateException $exception) {
            $payload = self::errorPayload($exception);
            $registrationErrors = $exception->errorsAt('registration');

            if (!$shopsService->registrationSubmitted() && $exception->errorsAt('activation_balance') !== []) {
                $payload['code'] = $exception->errorsAt('activation_balance')[0]['message'];
                $payload['message'] = $exception->getMessage();
                $payload['errors'] = [];
                $payload['registration_submitted'] = false;
            } elseif ($registrationErrors !== []) {
                $payload['code'] = $registrationErrors[0]['message'];
                $payload['message'] = $exception->getMessage();
                $payload['errors'] = [];
            } elseif (!$shopsService->registrationSubmitted()
                && in_array($exception->getKind(), [SalamGateException::KIND_TRANSPORT, SalamGateException::KIND_SERVER, SalamGateException::KIND_UNEXPECTED_RESPONSE], true)) {
                $payload['code'] = 'registration_unavailable';
                $payload['message'] = 'اطلاعات لازم برای فعال‌سازی دریافت نشد؛ دوباره تلاش کنید. ثبت یا پرداختی انجام نشده است.';
                $payload['registration_submitted'] = false;
            } elseif ($exception->isKind(SalamGateException::KIND_TRANSPORT)
                || $exception->isKind(SalamGateException::KIND_SERVER)
                || $exception->isKind(SalamGateException::KIND_UNEXPECTED_RESPONSE)
            ) {
                // ثبت ممکن است انجام شده باشد — نتیجه نامعلوم؛ تکرار کور ممنوع.
                $payload['code'] = 'register_unknown';
                $payload['message'] = 'نتیجه ثبت نامعلوم است؛ دوباره ثبت نکنید. با «بررسی وضعیت» نتیجه واقعی را بگیرید.';
            } elseif (self::isDuplicateRegistration($exception)) {
                $payload['code'] = 'duplicate_registration';
                $payload['message'] = 'برای این کاربر/سرویس‌دهنده با این کدپستی قبلاً ثبت انجام شده است.';
            } elseif (self::mentionsBalance($exception)) {
                $payload['code'] = 'insufficient_balance';
                $payload['message'] = 'موجودی روش پرداخت انتخاب‌شده برای هزینه فعال‌سازی کافی نیست.';
            }

            return ['ok' => false, 'payload' => $payload];
        } catch (\Throwable $exception) {
            // A local exception after the write started cannot prove rejection.
            return ['ok' => false, 'payload' => [
                'code' => 'register_unknown',
                'message' => 'نتیجه ثبت کامل دریافت نشد؛ دوباره ثبت نکنید و وضعیت فعال‌سازی را بررسی کنید.',
            ]];
        }
    }

    /**
     * ماشین‌حساب هزینه ارسال (پارت ۵) — POST عمومی /v1/base/calculator.
     * ورودی ارزش تومان است؛ ×۱۰ به ریال تبدیل می‌شود (Units::formTomanToRial).
     */
    public static function calculate(): void
    {
        self::guard();

        $post = static function (string $key): string {
            return isset($_POST[$key]) && (is_string($_POST[$key]) || is_int($_POST[$key])) ? trim((string) wp_unslash($_POST[$key])) : '';
        };

        $senderCityId = (int) Digits::toEnglish($post('sender_city_id'));
        $receiverCityId = (int) Digits::toEnglish($post('receiver_city_id'));
        $weightGrams = Units::parseBoundedFormInteger($_POST['weight'] ?? null, 50, 30000);
        $valueToman = Units::parseBoundedFormInteger($_POST['value_toman'] ?? null, 2000, 214748364, true);
        $boxTypeId = (int) Digits::toEnglish($post('box_type_id'));
        $serviceTypeRaw = (int) Digits::toEnglish($post('service_type'));

        $errors = [];
        if ($senderCityId <= 0) { $errors['sender_city_id'] = 'شهر فرستنده را انتخاب کنید.'; }
        if ($receiverCityId <= 0) { $errors['receiver_city_id'] = 'شهر گیرنده را انتخاب کنید.'; }
        if ($weightGrams === null) { $errors['weight'] = 'وزن باید عدد صحیح بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.'; }
        if ($valueToman === null) { $errors['value_toman'] = 'ارزش مرسوله باید عدد صحیح بین ۲٬۰۰۰ تا ۲۱۴٬۷۴۸٬۳۶۴ تومان باشد.'; }
        if ($boxTypeId <= 0) { $errors['box_type_id'] = 'نوع بسته‌بندی را انتخاب کنید.'; }

        // enum محلی service_type: پیشتاز=1، ویژه=3، اکسپرس=5 (زیرِ 10 → معتبر)
        if ($serviceTypeRaw !== 0 && !in_array($serviceTypeRaw, [1, 3, 5], true)) {
            $errors['service_type'] = 'روش ارسال نامعتبر است.';
        }

        if ($errors !== []) {
            wp_send_json_error(['message' => 'ورودی‌ها کامل نیست.', 'errors' => $errors, 'code' => 'validation']);
        }

        // ارزش تومان → ریال (همیشه ×۱۰)
        $valueRial = \SyncBasalam\SalamGate\Utils\Units::formTomanToRial($valueToman);

        try {
            $result = (new \SyncBasalam\SalamGate\Services\CalculatorService())
                ->estimate(
                    $senderCityId,
                    $receiverCityId,
                    $weightGrams,
                    $valueRial,
                    $boxTypeId,
                    $serviceTypeRaw > 0 ? $serviceTypeRaw : null
                );
            wp_send_json_success($result);
        } catch (SalamGateException $exception) {
            wp_send_json_error(self::errorPayload($exception));
        }
    }

    // ---------------------------------------------------------------

    /**
     * ورودی خام → نرمال‌شده (ارقام فارسی/عربی پیش از اعتبارسنجی؛ اعداد هویتی رشته می‌مانند).
     */
    private static function collectInput(): array
    {
        $post = static function (string $key): string {
            return isset($_POST[$key]) && (is_string($_POST[$key]) || is_int($_POST[$key])) ? trim((string) wp_unslash($_POST[$key])) : '';
        };
        $toEnglish = static fn (string $v): string => Digits::toEnglish($v);

        return [
            'mobile'               => Digits::normalizeMobile($toEnglish($post('mobile'))),
            'national_code'        => $toEnglish($post('national_code')),
            'national_code_serial' => strtoupper($toEnglish($post('national_code_serial'))),
            'birth_jy'             => self::formInteger($post('birth_jy'), 1, 3177),
            'birth_jm'             => self::formInteger($post('birth_jm'), 1, 12),
            'birth_jd'             => self::formInteger($post('birth_jd'), 1, 31),
            'full_name'            => $post('full_name'),
            'city_id'              => self::formInteger($post('city_id'), 1, 2147483647),
            'address'              => $post('address'),
            'address_detail'       => $post('address_detail'),
            'postal_code'          => $toEnglish($post('postal_code')),
            'phone'                => Digits::normalizeMobile($toEnglish($post('mobile'))),
            'provider_location_id' => self::formInteger($post('provider_location_id'), 1, 2147483647),
            'provider_node_id'     => self::formInteger($post('provider_node_id'), 1, 2147483647),
            'shop_title'           => $post('shop_title'),
            'pay_method'           => self::formInteger($post('pay_method'), 1, 2147483647),
            'expected_activation_cost_rial' => self::formInteger($post('expected_activation_cost_rial'), 0, 9007199254740991),
            'accept_rules'         => $post('accept_rules') === '1',
        ];
    }

    /**
     * اعتبارسنجی سمت سرور (نقشه دقیق قواعد پارت ۴). خروجی: field => message.
     *
     * @return array<string, string>
     */
    public static function validate(array $input): array
    {
        $errors = [];

        if (!is_string($input['mobile'] ?? null) || !preg_match('/^09\d{9}$/D', $input['mobile'])) {
            $errors['mobile'] = 'شماره موبایل باید با 09 شروع شود و ۱۱ رقم باشد.';
        }
        if (!is_string($input['national_code'] ?? null) || !preg_match('/^\d{10}$/D', $input['national_code'])) {
            $errors['national_code'] = 'کد ملی باید دقیقاً ۱۰ رقم باشد.';
        }
        if (!is_string($input['national_code_serial'] ?? null) || !preg_match('/^[A-Z0-9]{10}$/D', $input['national_code_serial'])) {
            $errors['national_code_serial'] = 'سریال کارت ملی باید دقیقاً ۱۰ کاراکتر لاتین حرف/عدد باشد.';
        }
        // کدملی نباید خودکار نقش سریال را بازی کند — فیلدهای جدا با اعتبارسنجی جدا.

        $today = explode('-', current_time('Y-m-d'));
        $currentYear = JalaliDate::toJalali((int) $today[0], (int) $today[1], (int) $today[2])['jy'];
        if (!is_int($input['birth_jy'] ?? null) || $input['birth_jy'] < 1301 || $input['birth_jy'] > $currentYear
            || !is_int($input['birth_jm'] ?? null) || !is_int($input['birth_jd'] ?? null)
            || !JalaliDate::isValidJalali($input['birth_jy'], $input['birth_jm'], $input['birth_jd'])) {
            $errors['birth_date'] = 'تاریخ تولد نامعتبر است.';
        } else {
            $iso = JalaliDate::jalaliToIso($input['birth_jy'], $input['birth_jm'], $input['birth_jd']);
            $age = $iso !== null ? JalaliDate::ageOn($iso) : null;
            if ($age === null || $age < 18 || $age > 100) {
                $errors['birth_date'] = 'سن باید بین ۱۸ تا ۱۰۰ سال باشد.';
            }
        }

        if (!is_string($input['full_name'] ?? null) || mb_strlen(trim($input['full_name'])) < 3) {
            $errors['full_name'] = 'نام و نام خانوادگی فرستنده را کامل وارد کنید.';
        }
        if (!is_int($input['city_id'] ?? null) || $input['city_id'] <= 0 || $input['city_id'] > 2147483647) {
            $errors['city_id'] = 'شهر (باسلام/سلام‌گیت) را انتخاب کنید.';
        }
        if (!is_string($input['address'] ?? null) || mb_strlen(trim($input['address'])) < 10
            || mb_strlen(trim($input['address'])) > 512) {
            $errors['address'] = 'نشانی کامل را بین ۱۰ تا ۵۱۲ نویسه وارد کنید.';
        }
        if (!is_string($input['address_detail'] ?? null) || mb_strlen(trim($input['address_detail'])) < 3
            || mb_strlen(trim($input['address_detail'])) > 255) {
            $errors['address_detail'] = 'جزئیات نشانی (پلاک، طبقه و واحد) را بین ۳ تا ۲۵۵ نویسه وارد کنید.';
        }
        if (!is_string($input['postal_code'] ?? null) || !preg_match('/^\d{10}$/D', $input['postal_code'])) {
            $errors['postal_code'] = 'کد پستی باید دقیقاً ۱۰ رقم باشد.';
        }

        if (!is_int($input['provider_location_id'] ?? null) || $input['provider_location_id'] <= 0 || $input['provider_location_id'] > 2147483647) {
            $errors['provider_location_id'] = 'منطقه پستی را انتخاب کنید.';
        }
        if (!is_int($input['provider_node_id'] ?? null) || $input['provider_node_id'] <= 0 || $input['provider_node_id'] > 2147483647) {
            $errors['provider_node_id'] = 'مرکز تحویل را انتخاب کنید.';
        }

        if (!is_int($input['pay_method'] ?? null) || !PayMethods::isValidShopActivationMethod($input['pay_method'])) {
            $errors['pay_method'] = 'روش پرداخت هزینه فعال‌سازی را انتخاب کنید (تراز غرفه‌دار یا کیف پول).';
        }

        if (($input['accept_rules'] ?? null) !== true) {
            $errors['accept_rules'] = 'پذیرش قوانین برای ثبت الزامی است.';
        }

        return $errors;
    }

    /**
     * ساخت دقیق body مطابق RegisterShopRequest — کلیدها و نام‌ها عین قرارداد.
     */
    public static function buildRequestBody(array $input): array
    {
        $birthIso = JalaliDate::jalaliToIso($input['birth_jy'], $input['birth_jm'], $input['birth_jd']);

        $body = [
            'address' => [
                'city_id'    => $input['city_id'],   // شناسه شهر عمومی — نه provider_location_id
                'full_name'  => $input['full_name'],
                // Native registration has one supported address string. Keep
                // the separate UI detail without sending an unsupported key.
                'address'    => trim($input['address']) . "\n" . trim($input['address_detail']),
                'postal_code'=> $input['postal_code'],
                'phone'      => $input['mobile'],
                'latitude'   => null,                 // بدون ورود معتبر — null (نه صفر/مختصات فرضی)
                'longitude'  => null,
            ],
            'mobile'               => $input['mobile'],
            'national_code'        => $input['national_code'],
            // نام فیلد ثبت national_code_serial است (پروفایل national_card_serial دارد) — map شده.
            'national_code_serial' => $input['national_code_serial'],
            'birth_date'           => $birthIso, // ISO میلادی YYYY-MM-DD
            'provider_node_id'     => $input['provider_node_id'],
            'provider_location_id' => $input['provider_location_id'],
            'provider_id'          => self::POST_PROVIDER_ID,
            'pay_method'           => $input['pay_method'], // 8|2 — نه enum مرسوله
        ];

        if (isset($input['shop_title']) && is_string($input['shop_title']) && $input['shop_title'] !== '') {
            $body['shop_title'] = $input['shop_title'];
        }

        return $body;
    }

    /**
     * @param array<int, array{id:int, title:string, children?: array}> $tree
     * @return array<int, array{id:int, title:string, children: array<int, array{id:int, title:string}>}>
     */
    private static function compactTree(array $tree): array
    {
        $compact = [];
        foreach ($tree as $province) {
            if (!is_array($province)) {
                continue;
            }
            $cities = [];
            foreach ((array) ($province['children'] ?? []) as $city) {
                if (is_array($city)) {
                    $cities[] = ['id' => (int) ($city['id'] ?? 0), 'title' => (string) ($city['title'] ?? '')];
                }
            }
            $compact[] = [
                'id' => (int) ($province['id'] ?? 0),
                'title' => (string) ($province['title'] ?? ''),
                'children' => $cities,
            ];
        }

        return $compact;
    }

    private static function mentionsBalance(SalamGateException $exception): bool
    {
        foreach ($exception->getErrors() as $error) {
            $haystack = $error['message'] . ' ' . $error['location'];
            if (str_contains($haystack, 'balance') || str_contains($haystack, 'vendor_balance')
                || str_contains($haystack, 'user_wallet') || str_contains($haystack, 'موجودی')
            ) {
                return true;
            }
        }

        return false;
    }

    /** Exact IDs only: malformed suffixes, booleans, floats and arrays never become a valid choice. */
    private static function formInteger($value, int $minimum, int $maximum): ?int
    {
        return Units::parseBoundedFormInteger($value, $minimum, $maximum);
    }

    private static function providerData($body): ?array
    {
        if (!is_array($body) || self::formInteger($body['id'] ?? null, 1, 2147483647) !== self::POST_PROVIDER_ID
            || !is_string($body['title'] ?? null) || trim($body['title']) === '') return null;
        return ['id' => self::POST_PROVIDER_ID, 'title' => $body['title'],
            'activation_cost' => self::formInteger($body['activation_cost'] ?? null, 0, 9007199254740991)];
    }

    /** Activation uses 8/2; balance endpoints use 20/21. Never send the latter to registration. */
    private static function activationBalance(int $method, bool $fresh): array
    {
        $balanceMethod = $method === PayMethods::SHOP_ACTIVATION_VENDOR_BALANCE
            ? PayMethods::PARCEL_VENDOR_BALANCE : PayMethods::PARCEL_WALLET;
        $balance = (new PaymentBalanceService())->get($balanceMethod, $fresh);
        return ['method' => $method, 'balance_rial' => $balance['balance_rial'],
            // Debt must not look like available credit, but the original exact
            // negative amount is retained for the final sufficiency comparison.
            'display_balance_rial' => max(0, $balance['balance_rial']),
            'fetched_at' => $balance['fetched_at']];
    }

    /** Fresh provider price, not a browser-supplied amount; the service remains the charging authority. */
    private static function currentActivationPrice(): int
    {
        $response = ClientFactory::make()->get('/v1/providers/' . self::POST_PROVIDER_ID);
        $provider = self::providerData($response['body'] ?? null);
        if ($provider === null || $provider['activation_cost'] === null) {
            throw new SalamGateException('هزینه معتبر فعال‌سازی دریافت نشد؛ ثبت یا پرداخت انجام نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        return $provider['activation_cost'];
    }

    /** Derive province from the canonical city tree; do not equate postal region IDs with city IDs. */
    private static function validateRegistrationReferences(array $input): void
    {
        $tree = (new ReferenceDataService())->getProvinceTree();
        $provinceId = null;
        foreach ($tree as $province) {
            foreach ($province['children'] ?? [] as $city) {
                if (self::formInteger($city['id'] ?? null, 1, 2147483647) === $input['city_id']) {
                    $provinceId = self::formInteger($province['id'] ?? null, 1, 2147483647);
                    break 2;
                }
            }
        }
        if ($provinceId === null) self::referenceError('city_id', 'شهر فرستنده معتبر نیست؛ استان و شهر را دوباره انتخاب کنید.');

        // Final confirmation bypasses the reference cache/stale fallback. Failed
        // fresh membership checks never submit a registration or charge.
        $response = ClientFactory::make()->get('/v1/providers/' . self::POST_PROVIDER_ID . '/locations', ['province_id' => $provinceId]);
        $locations = self::referenceRows($response['body'] ?? null);
        if (!self::containsReference($locations, $input['provider_location_id'])) {
            self::referenceError('provider_location_id', 'منطقه پستی به استان آدرس فرستنده تعلق ندارد؛ دوباره انتخاب کنید.');
        }
        $response = ClientFactory::make()->get('/v1/providers/' . self::POST_PROVIDER_ID . '/nodes', ['city_id' => $input['provider_location_id']]);
        $nodes = self::referenceRows($response['body'] ?? null);
        if (!self::containsReference($nodes, $input['provider_node_id'])) {
            self::referenceError('provider_node_id', 'مرکز تحویل در منطقه پستی انتخاب‌شده موجود نیست؛ دوباره انتخاب کنید.');
        }
        foreach ($nodes as $node) {
            if (self::formInteger($node['id'], 1, 2147483647) === $input['provider_node_id']
                && isset($node['provider_location'])
                && (!is_array($node['provider_location'])
                    || self::formInteger($node['provider_location']['id'] ?? null, 1, 2147483647) !== $input['provider_location_id'])) {
                self::referenceError('provider_node_id', 'مرکز تحویل به این منطقه پستی تعلق ندارد؛ دوباره انتخاب کنید.');
            }
        }
    }

    private static function referenceRows($body): array
    {
        if ($body === null) return []; // Native reference endpoints return null for no coverage.
        if (!is_array($body) || !Arrays::isList($body) || count($body) > 10000) throw self::invalidReferences();
        $seen = [];
        foreach ($body as $row) {
            $id = is_array($row) ? self::formInteger($row['id'] ?? null, 1, 2147483647) : null;
            if ($id === null || isset($seen[$id])) throw self::invalidReferences();
            $seen[$id] = true;
        }
        return $body;
    }

    private static function containsReference(array $rows, int $id): bool
    {
        foreach ($rows as $row) if (self::formInteger($row['id'], 1, 2147483647) === $id) return true;
        return false;
    }

    private static function invalidReferences(): SalamGateException
    {
        return new SalamGateException('فهرست مناطق یا مراکز پستی معتبر دریافت نشد؛ ثبت یا پرداخت انجام نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }

    private static function referenceError(string $field, string $message): void
    {
        throw new SalamGateException($message, 422, [['location' => 'body.' . $field, 'message' => $message]], SalamGateException::KIND_VALIDATION);
    }

    private static function fieldErrors(array $errors): array
    {
        $rows = [];
        foreach ($errors as $field => $message) $rows[] = ['location' => 'body.' . $field, 'message' => $message];
        return $rows;
    }

    private static function isDuplicateRegistration(SalamGateException $exception): bool
    {
        if (!$exception->isKind(SalamGateException::KIND_VALIDATION)) return false;
        foreach ($exception->getErrors() as $error) {
            if (!in_array($error['location'], ['user', 'body.user'], true)) continue;
            $message = $error['message'];
            if (preg_match('/duplicate|already[ _-]?(?:exists|registered)|shop-already-exists-in-provider/i', $message)
                || (str_contains($message, 'قبلاً') && (str_contains($message, 'ثبت') || str_contains($message, 'وجود')))
                || (str_contains($message, 'از قبل') && str_contains($message, 'ثبت'))) return true;
        }
        return false;
    }

    private static function errorPayload(SalamGateException $exception): array
    {
        return [
            'message' => $exception->firstMessage(),
            'errors'  => $exception->getErrors(),
            'kind'    => $exception->getKind(),
        ];
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }

        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
        }
    }
}
