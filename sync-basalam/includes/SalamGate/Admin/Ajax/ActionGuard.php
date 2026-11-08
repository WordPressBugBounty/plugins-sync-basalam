<?php
namespace SyncBasalam\SalamGate\Admin\Ajax;

defined('ABSPATH') || exit;

final class ActionGuard
{
    public static function forController(string $controller, ?array $request = null): void
    {
        $policy = null;
        foreach (ActionRegistry::all() as $definition) {
            if ($definition->callback()[0] !== $controller) continue;
            // Current controllers share credentials. Reject accidental future mixed policies.
            if ($policy !== null && ($policy->capabilities() !== $definition->capabilities()
                || $policy->needsAllCapabilities() !== $definition->needsAllCapabilities())) {
                throw new \LogicException('Controller requires an explicit action credential policy.');
            }
            $policy = $definition;
        }
        if ($policy === null) throw new \LogicException('Unregistered shipping controller.');
        $request = $request ?? $_POST;
        $nonce = $request['_wpnonce'] ?? null;
        $grants = array_map('current_user_can', $policy->capabilities());
        $allowed = $policy->needsAllCapabilities() ? !in_array(false, $grants, true) : in_array(true, $grants, true);
        if (!$allowed || !is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), $policy->nonceAction())) {
            ResponseBoundary::error(['message' => 'دسترسی غیرمجاز؛ اعتبار صفحه یا مجوز حساب را بررسی کنید.',
                'code' => 'access_denied', 'kind' => 'validation', 'errors' => [], 'request_dispatched' => false], 403);
        }
    }
}
