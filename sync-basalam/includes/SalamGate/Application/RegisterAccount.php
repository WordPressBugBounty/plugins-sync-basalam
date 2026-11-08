<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\AccountService;
use SyncBasalam\SalamGate\Services\ActivationIdentityService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ShopsService;

defined('ABSPATH') || exit;

/** RegisterAccount: explicit inputs, returned outcomes, no HTTP response termination. */
final class RegisterAccount
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function register(): array
    {

        $outcome = $this->registerOutcome((new RegistrationInput($this->input, $this->context))->collectInput());

        if ($outcome['ok']) {
            return OperationResult::success($outcome['payload']);
        }

        return OperationResult::failure($outcome['payload']);
    }

    /** @deprecated P3 payload-only alias; use RegisterAccount::registerOutcome(). See docs/salam-gate/compatibility.md. */
    public function registerViaTest(array $input): array
    {
        $outcome = $this->registerOutcome($input);

        return $outcome['payload'];
    }

    public function registerOutcome(array $input): array
    {
        $errors = (new RegistrationInput($this->input, $this->context))->validate($input);

        if ($errors !== []) {
            // داده فرم حفظ می‌شود (کلاینت مقدار را نگه می‌دارد) — خطاها با نام فیلد برمی‌گردند.
            return [
                'ok' => false,
                'payload' => [
                    'message' => 'اطلاعات وارد‌شده کامل نیست؛ موارد مشخص‌شده را اصلاح کنید.',
                    'errors'  => (new RegistrationInput($this->input, $this->context))->fieldErrors($errors),
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
            $price = (new AccountBootstrap($this->input, $this->context))->currentActivationPrice();
            $expected = (new RegistrationInput($this->input, $this->context))->formInteger($input['expected_activation_cost_rial'] ?? null, 0, 9007199254740991);
            if ($expected === null || $expected !== $price) {
                return ['ok' => false, 'payload' => [
                    'code' => 'activation_cost_changed',
                    'message' => 'هزینه فعال‌سازی را دوباره بررسی و تأیید کنید؛ هنوز ثبت یا پرداختی انجام نشده است.',
                    'activation_cost_rial' => $price,
                ]];
            }
            $this->validateRegistrationReferences($input);
            $account->assertSnapshot($profile);
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => (new RegistrationInput($this->input, $this->context))->errorPayload($exception) + [
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

        $body = (new RegistrationInput($this->input, $this->context))->buildRequestBody($input);

        $shopsService = new ShopsService();
        try {
            $shop = $shopsService->register($body, function () use ($input, $price, $account, $profile): void {
                $account->assertSnapshot($profile);
                // A valid zero activation fee does not require available funds.
                if ($price === 0) return;
                try {
                    $balance = (new AccountBootstrap($this->input, $this->context))->activationBalance($input['pay_method'], true);
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
            $payload = (new RegistrationInput($this->input, $this->context))->errorPayload($exception);
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
            } elseif ($this->isDuplicateRegistration($exception)) {
                $payload['code'] = 'duplicate_registration';
                $payload['message'] = 'برای این کاربر/سرویس‌دهنده با این کدپستی قبلاً ثبت انجام شده است.';
            } elseif ($this->mentionsBalance($exception)) {
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

    public function validateRegistrationReferences(array $input): void
    {
        $tree = (new ReferenceDataService())->getProvinceTree();
        $provinceId = null;
        foreach ($tree as $province) {
            foreach ($province['children'] ?? [] as $city) {
                if ((new RegistrationInput($this->input, $this->context))->formInteger($city['id'] ?? null, 1, 2147483647) === $input['city_id']) {
                    $provinceId = (new RegistrationInput($this->input, $this->context))->formInteger($province['id'] ?? null, 1, 2147483647);
                    break 2;
                }
            }
        }
        if ($provinceId === null) $this->referenceError('city_id', 'شهر فرستنده معتبر نیست؛ استان و شهر را دوباره انتخاب کنید.');

        // Final confirmation bypasses the reference cache/stale fallback. Failed
        // fresh membership checks never submit a registration or charge.
        $response = ClientFactory::make()->get('/v1/providers/' . AccountOnboarding::POST_PROVIDER_ID . '/locations', ['province_id' => $provinceId]);
        $locations = $this->referenceRows($response['body'] ?? null);
        if (!$this->containsReference($locations, $input['provider_location_id'])) {
            $this->referenceError('provider_location_id', 'منطقه پستی به استان آدرس فرستنده تعلق ندارد؛ دوباره انتخاب کنید.');
        }
        $response = ClientFactory::make()->get('/v1/providers/' . AccountOnboarding::POST_PROVIDER_ID . '/nodes', ['city_id' => $input['provider_location_id']]);
        $nodes = $this->referenceRows($response['body'] ?? null);
        if (!$this->containsReference($nodes, $input['provider_node_id'])) {
            $this->referenceError('provider_node_id', 'مرکز تحویل در منطقه پستی انتخاب‌شده موجود نیست؛ دوباره انتخاب کنید.');
        }
        foreach ($nodes as $node) {
            if ((new RegistrationInput($this->input, $this->context))->formInteger($node['id'], 1, 2147483647) === $input['provider_node_id']
                && isset($node['provider_location'])
                && (!is_array($node['provider_location'])
                    || (new RegistrationInput($this->input, $this->context))->formInteger($node['provider_location']['id'] ?? null, 1, 2147483647) !== $input['provider_location_id'])) {
                $this->referenceError('provider_node_id', 'مرکز تحویل به این منطقه پستی تعلق ندارد؛ دوباره انتخاب کنید.');
            }
        }
    }

    public function referenceRows($body): array
    {
        if ($body === null) return []; // Native reference endpoints return null for no coverage.
        if (!is_array($body) || !Arrays::isList($body) || count($body) > 10000) throw $this->invalidReferences();
        $seen = [];
        foreach ($body as $row) {
            $id = is_array($row) ? (new RegistrationInput($this->input, $this->context))->formInteger($row['id'] ?? null, 1, 2147483647) : null;
            if ($id === null || isset($seen[$id])) throw $this->invalidReferences();
            $seen[$id] = true;
        }
        return $body;
    }

    public function containsReference(array $rows, int $id): bool
    {
        foreach ($rows as $row) if ((new RegistrationInput($this->input, $this->context))->formInteger($row['id'], 1, 2147483647) === $id) return true;
        return false;
    }

    public function invalidReferences(): SalamGateException
    {
        return new SalamGateException('فهرست مناطق یا مراکز پستی معتبر دریافت نشد؛ ثبت یا پرداخت انجام نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }

    public function referenceError(string $field, string $message): void
    {
        throw new SalamGateException($message, 422, [['location' => 'body.' . $field, 'message' => $message]], SalamGateException::KIND_VALIDATION);
    }

    public function mentionsBalance(SalamGateException $exception): bool
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

    public function isDuplicateRegistration(SalamGateException $exception): bool
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
}
