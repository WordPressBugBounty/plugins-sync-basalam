<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Domain\AccountStateResolver;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\ShopCheckCache;
use SyncBasalam\SalamGate\Services\ShopsService;

defined('ABSPATH') || exit;

/** Local stale-tab prefilter, NOT authorization for creating, paying or printing a parcel. */
final class AccountAccess
{

    public static function guard(string $action): void
    {
        // Module calls this only for its registered actions. New remote actions
        // require an active account by default instead of silently escaping a list.
        $definition = ActionRegistry::get($action);
        if ($definition !== null && in_array($definition->accountRequirement(), ['control', 'independent'], true)) return;

        // Invalid credentials still reach the controller's ORIGINAL capability
        // and nonce checks, without exposing cached account details here. This
        // prefilter cannot grant either capability or bypass order/receipt guards.
        $request = $action === 'salam_gate_label_preview' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'GET'
            ? $_GET : $_POST;
        $nonce = $request['_wpnonce'] ?? null;
        if ((!current_user_can('manage_options') && !current_user_can('manage_woocommerce'))
            || !is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'basalam_pro_nonce')) return;

        $settings = get_option('sync_basalam_settings', []);
        $token = is_array($settings) ? ($settings['token'] ?? null) : null;
        if (!is_string($token) || trim($token) === '') {
            self::reject('اتصال باسلام برقرار نیست؛ ابتدا از تنظیمات ووسلام متصل شوید.', 'not_connected');
            return;
        }
        $failure = ShopCheckCache::forToken($token);
        if ($failure !== null) {
            self::reject($failure->firstMessage(), $failure->errorsAt('auth.scope') !== [] ? 'missing_scope' : 'account_check_failed');
            return;
        }

        // A historical active snapshot only lets the existing controller run.
        // Fresh account/payment/ownership guards remain authoritative. Expiry
        // alone must not turn this prefilter into another /v1/shops caller.
        $cached = ConnectionCache::lastForTokenForDisplay(ShopsService::CACHE_NAME, $token);
        $shops = $cached['data']['shops'] ?? null;
        $state = is_array($shops) ? AccountStateResolver::stateForShops($shops) : null;
        if ($shops === [] && in_array($cached['data']['registration_state'] ?? null, ['pending', 'unknown'], true)) {
            $state = AccountStateResolver::STATE_PENDING;
        }
        $requirement = $definition !== null ? $definition->accountRequirement() : 'active';
        $activation = $requirement === 'bootstrap'
            && is_string($_POST['purpose'] ?? null) && wp_unslash($_POST['purpose']) === 'activation';
        $registration = $activation || $requirement === 'registration';
        $references = $requirement === 'references';

        // Registration is an exception to active-shop readiness, never to a
        // known access denial. Reference pickers are also needed by new accounts.
        if ($state === AccountStateResolver::STATE_NOT_REGISTERED && ($registration || $references)) return;
        if ($state === AccountStateResolver::STATE_INACTIVE && $requirement === 'activate') return;

        if (!$registration && $requirement !== 'activate' && $state === AccountStateResolver::STATE_ACTIVE) {
            foreach ($shops as $shop) {
                if (is_array($shop) && (int) ($shop['status_id'] ?? 0) === ShopStatuses::ACTIVE
                    && (int) ($shop['id'] ?? 0) > 0) return;
            }
        }
        self::reject('برای ادامه، وضعیت حساب سلام‌گیت را در بخش ارسال مرسوله بررسی کنید.', 'account_unavailable');
    }

    private static function reject(string $message, string $reason): void
    {
        // 409 is a LOCAL unavailable-state response, not another provider 403.
        ResponseBoundary::error(['message' => $message, 'code' => 'account_unavailable', 'reason' => $reason,
            'kind' => 'validation', 'errors' => [],
            'request_dispatched' => false], 409);
    }
}
