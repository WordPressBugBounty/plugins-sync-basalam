<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\AccountService;
use SyncBasalam\SalamGate\Services\ActivationIdentityService;
use SyncBasalam\SalamGate\Services\PaymentBalanceService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ShopsService;

defined('ABSPATH') || exit;

/** AccountBootstrap: explicit inputs, returned outcomes, no HTTP response termination. */
final class AccountBootstrap
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function bootstrap(): array
    {

        try {
            $activation = isset($this->input['purpose']) && is_string($this->input['purpose'])
                && wp_unslash($this->input['purpose']) === 'activation';
            return OperationResult::success($this->bootstrapData($activation));
        } catch (SalamGateException $exception) {
            // 401/403 ≠ «هنوز ثبت‌نام نکرده» — خطای مجوز جداگانه برمی‌گردد.
            return OperationResult::failure((new RegistrationInput($this->input, $this->context))->errorPayload($exception));
        }
    }

    /** @deprecated P3 compatibility alias; use AccountBootstrap::bootstrapData(). See docs/salam-gate/compatibility.md. */
    public function bootstrapViaTest(bool $activation = false): array
    {
        return $this->bootstrapData($activation);
    }

    public function bootstrapData(bool $activation = false): array
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
            $providerResponse = ClientFactory::make()->get(sprintf('/v1/providers/%d', AccountOnboarding::POST_PROVIDER_ID));
            $provider = $this->providerData($providerResponse['body'] ?? null);
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
                    $balance = $this->activationBalance($method, true);
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
            'provinces' => $provinces === null ? null : $this->compactTree($provinces),
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

    public function postOfficesReferences(): array
    {

        $fresh = isset($this->input['fresh']) && is_string($this->input['fresh'])
            && wp_unslash($this->input['fresh']) === '1';

        try {
            $references = (new ReferenceDataService())->getProvinceTreeWithState($fresh);
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new RegistrationInput($this->input, $this->context))->errorPayload($exception));
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        } catch (\Throwable $exception) {
            return OperationResult::failure([
                'message' => 'دریافت فهرست استان‌ها و شهرها ناموفق بود؛ دوباره تلاش کنید.',
                'kind' => SalamGateException::KIND_TRANSPORT,
            ]);
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        }

        return OperationResult::success([
            'provinces' => $this->compactTree($references['data']),
            'stale' => $references['stale'],
        ]);
    }

    public function providerLocations(): array
    {

        $provinceId = (new RegistrationInput($this->input, $this->context))->formInteger($this->input['province_id'] ?? null, 1, 2147483647);

        if ($provinceId === null) {
            return OperationResult::failure(['message' => 'استان نامعتبر است.']);
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        }

        try {
            $locations = (new ReferenceDataService())->getProviderLocations($provinceId);
            return OperationResult::success(['locations' => $locations, 'has_coverage' => $locations !== []]);
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new RegistrationInput($this->input, $this->context))->errorPayload($exception));
        }
    }

    public function providerNodes(): array
    {

        $locationId = (new RegistrationInput($this->input, $this->context))->formInteger($this->input['provider_location_id'] ?? null, 1, 2147483647);

        if ($locationId === null) {
            return OperationResult::failure(['message' => 'منطقه پستی نامعتبر است.']);
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        }

        try {
            $nodes = (new ReferenceDataService())->getProviderNodes($locationId);
            return OperationResult::success(['nodes' => $nodes]);
        } catch (SalamGateException $exception) {
            return OperationResult::failure((new RegistrationInput($this->input, $this->context))->errorPayload($exception));
        }
    }

    public function compactTree(array $tree): array
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

    public function providerData($body): ?array
    {
        if (!is_array($body) || (new RegistrationInput($this->input, $this->context))->formInteger($body['id'] ?? null, 1, 2147483647) !== AccountOnboarding::POST_PROVIDER_ID
            || !is_string($body['title'] ?? null) || trim($body['title']) === '') return null;
        return ['id' => AccountOnboarding::POST_PROVIDER_ID, 'title' => $body['title'],
            'activation_cost' => (new RegistrationInput($this->input, $this->context))->formInteger($body['activation_cost'] ?? null, 0, 9007199254740991)];
    }

    public function activationBalance(int $method, bool $fresh): array
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

    public function currentActivationPrice(): int
    {
        $response = ClientFactory::make()->get('/v1/providers/' . AccountOnboarding::POST_PROVIDER_ID);
        $provider = $this->providerData($response['body'] ?? null);
        if ($provider === null || $provider['activation_cost'] === null) {
            throw new SalamGateException('هزینه معتبر فعال‌سازی دریافت نشد؛ ثبت یا پرداخت انجام نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        return $provider['activation_cost'];
    }
}
