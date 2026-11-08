<?php
namespace SyncBasalam\SalamGate\Application;


defined('ABSPATH') || exit;

/** AccountOnboarding: explicit inputs, returned outcomes, no HTTP response termination. */
final class AccountOnboarding
{
    public const POST_PROVIDER_ID = 1;
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function bootstrap(): array
    {
        return (new AccountBootstrap($this->input, $this->context))->bootstrap();
    }

    /** @deprecated P3 compatibility alias; use AccountBootstrap::bootstrapData(). See docs/salam-gate/compatibility.md. */
    public function bootstrapViaTest(bool $activation = false): array
    {
        return (new AccountBootstrap($this->input, $this->context))->bootstrapData($activation);
    }

    public function postOfficesReferences(): array
    {
        return (new AccountBootstrap($this->input, $this->context))->postOfficesReferences();
    }

    public function providerLocations(): array
    {
        return (new AccountBootstrap($this->input, $this->context))->providerLocations();
    }

    public function providerNodes(): array
    {
        return (new AccountBootstrap($this->input, $this->context))->providerNodes();
    }

    public function register(): array
    {
        return (new RegisterAccount($this->input, $this->context))->register();
    }

    /** @deprecated P3 payload-only alias; use RegisterAccount::registerOutcome(). See docs/salam-gate/compatibility.md. */
    public function registerViaTest(array $input): array
    {
        return (new RegisterAccount($this->input, $this->context))->registerViaTest($input);
    }

    public function calculate(): array
    {
        return (new CalculateShipping($this->input, $this->context))->calculate();
    }

    public function validate(array $input): array
    {
        return (new RegistrationInput($this->input, $this->context))->validate($input);
    }

    public function buildRequestBody(array $input): array
    {
        return (new RegistrationInput($this->input, $this->context))->buildRequestBody($input);
    }
}
