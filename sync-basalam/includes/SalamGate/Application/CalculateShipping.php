<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** CalculateShipping: explicit inputs, returned outcomes, no HTTP response termination. */
final class CalculateShipping
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function calculate(): array
    {

        $post = function (string $key): string {
            return isset($this->input[$key]) && (is_string($this->input[$key]) || is_int($this->input[$key])) ? trim((string) wp_unslash($this->input[$key])) : '';
        };

        $senderCityId = (int) Digits::toEnglish($post('sender_city_id'));
        $receiverCityId = (int) Digits::toEnglish($post('receiver_city_id'));
        $weightGrams = Units::parseBoundedFormInteger($this->input['weight'] ?? null, 50, 30000);
        $valueToman = Units::parseBoundedFormInteger($this->input['value_toman'] ?? null, 2000, 214748364, true);
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
            return OperationResult::failure(['message' => 'ورودی‌ها کامل نیست.', 'errors' => $errors, 'code' => 'validation']);
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
            return OperationResult::success($result);
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new RegistrationInput($this->input, $this->context))->errorPayload($exception));
        }
    }
}
