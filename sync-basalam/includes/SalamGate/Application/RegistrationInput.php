<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;
use SyncBasalam\SalamGate\Utils\JalaliDate;

defined('ABSPATH') || exit;

/** RegistrationInput: explicit inputs, returned outcomes, no HTTP response termination. */
final class RegistrationInput
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function collectInput(): array
    {
        $post = function (string $key): string {
            return isset($this->input[$key]) && (is_string($this->input[$key]) || is_int($this->input[$key])) ? trim((string) wp_unslash($this->input[$key])) : '';
        };
        $toEnglish = fn (string $v): string => Digits::toEnglish($v);

        return [
            'mobile'               => Digits::normalizeMobile($toEnglish($post('mobile'))),
            'national_code'        => $toEnglish($post('national_code')),
            'national_code_serial' => strtoupper($toEnglish($post('national_code_serial'))),
            'birth_jy'             => $this->formInteger($post('birth_jy'), 1, 3177),
            'birth_jm'             => $this->formInteger($post('birth_jm'), 1, 12),
            'birth_jd'             => $this->formInteger($post('birth_jd'), 1, 31),
            'full_name'            => $post('full_name'),
            'city_id'              => $this->formInteger($post('city_id'), 1, 2147483647),
            'address'              => $post('address'),
            'address_detail'       => $post('address_detail'),
            'postal_code'          => $toEnglish($post('postal_code')),
            'phone'                => Digits::normalizeMobile($toEnglish($post('mobile'))),
            'provider_location_id' => $this->formInteger($post('provider_location_id'), 1, 2147483647),
            'provider_node_id'     => $this->formInteger($post('provider_node_id'), 1, 2147483647),
            'shop_title'           => $post('shop_title'),
            'pay_method'           => $this->formInteger($post('pay_method'), 1, 2147483647),
            'expected_activation_cost_rial' => $this->formInteger($post('expected_activation_cost_rial'), 0, 9007199254740991),
            'accept_rules'         => $post('accept_rules') === '1',
        ];
    }

    public function validate(array $input): array
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

    public function buildRequestBody(array $input): array
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
            'provider_id'          => AccountOnboarding::POST_PROVIDER_ID,
            'pay_method'           => $input['pay_method'], // 8|2 — نه enum مرسوله
        ];

        if (isset($input['shop_title']) && is_string($input['shop_title']) && $input['shop_title'] !== '') {
            $body['shop_title'] = $input['shop_title'];
        }

        return $body;
    }

    public function fieldErrors(array $errors): array
    {
        $rows = [];
        foreach ($errors as $field => $message) $rows[] = ['location' => 'body.' . $field, 'message' => $message];
        return $rows;
    }

    public function formInteger($value, int $minimum, int $maximum): ?int
    {
        return Units::parseBoundedFormInteger($value, $minimum, $maximum);
    }

    public function errorPayload(SalamGateException $exception): array
    {
        return [
            'message' => $exception->firstMessage(),
            'errors'  => $exception->getErrors(),
            'kind'    => $exception->getKind(),
        ];
    }
}
