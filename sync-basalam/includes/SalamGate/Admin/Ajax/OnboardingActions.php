<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\AccountOnboarding;

defined('ABSPATH') || exit;

/** WordPress HTTP adapter; public action names remain backward compatible. */
class OnboardingActions
{
    public const POST_PROVIDER_ID = AccountOnboarding::POST_PROVIDER_ID;

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
            ResponseBoundary::error(['message' => 'فرم فعال‌سازی دریافت نشد؛ دوباره تلاش کنید.']);
            return;
        }
        ResponseBoundary::success(['html' => $html, 'state' => $data['state']]);
    }

    public static function bootstrap(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->bootstrap());
    }

    /** @deprecated P3 compatibility alias; use AccountBootstrap::bootstrapData(). See docs/salam-gate/compatibility.md. */
    public static function bootstrapViaTest(bool $activation = false): array
    {
        return (new \SyncBasalam\SalamGate\Application\AccountBootstrap([]))->bootstrapData($activation);
    }

    public static function postOfficesReferences(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->postOfficesReferences());
    }

    public static function providerLocations(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->providerLocations());
    }

    public static function providerNodes(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->providerNodes());
    }

    public static function register(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->register());
    }

    /** @deprecated P3 payload-only alias; use RegisterAccount::registerOutcome(). See docs/salam-gate/compatibility.md. */
    public static function registerViaTest(array $input): array
    {
        return (new AccountOnboarding([]))->registerViaTest($input);
    }

    public static function calculate(): void
    {
        self::guard();
        $workflow = new AccountOnboarding($_POST);
        ActionResponder::run(static fn (): array => $workflow->calculate());
    }

    public static function validate(array $input): array
    {
        return (new AccountOnboarding([]))->validate($input);
    }

    public static function buildRequestBody(array $input): array
    {
        return (new AccountOnboarding([]))->buildRequestBody($input);
    }

    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }
}
