<?php
namespace SyncBasalam\SalamGate\Application;

defined('ABSPATH') || exit;

final class OperationResult
{
    public static function describe(bool $ok, array $payload, bool $mutation): array
    {
        $code = is_string($payload['code'] ?? null) ? $payload['code'] : ($ok ? 'ok' : 'operation_failed');
        $dispatched = is_bool($payload['request_dispatched'] ?? null) ? $payload['request_dispatched'] : null;
        $uncertain = in_array($code, ['pay_unknown', 'quote_unknown', 'register_unknown', 'create_unknown', 'update_unknown', 'cancel_unknown', 'label_unknown', 'unknown'], true)
            || in_array($payload['payment_state'] ?? '', ['unknown', 'pending'], true)
            || in_array($payload['registration_state'] ?? '', ['unknown', 'pending'], true)
            || in_array($payload['creation_state'] ?? '', ['unknown', 'pending'], true);
        $state = $uncertain ? 'unknown' : ($ok ? 'succeeded' : 'failed');
        // Missing evidence after a mutation is conservative. Text never supplies evidence.
        if (!$ok && $mutation && $dispatched !== false && empty($payload['no_charge_confirmed'])
            && in_array($payload['kind'] ?? '', ['', 'transport', 'unexpected_response', 'server'], true)) $state = 'unknown';
        $retry = $state === 'unknown' || $code === 'already_paid' ? 'reconcile_only'
            : (!$mutation ? 'manual_read' : ($dispatched === false || !empty($payload['no_charge_confirmed']) ? 'confirm_again' : 'never_automatic'));
        $payload['code'] = $code;
        $payload['operation'] = ['code' => $code, 'state' => $state, 'dispatched' => $dispatched, 'retry_policy' => $retry];
        if (!$ok) {
            $payload += ['kind' => 'validation', 'errors' => [], 'request_dispatched' => $dispatched];
        }
        return $payload;
    }

    public static function success(array $payload, ?int $status = null): array
    {
        return ['ok' => true, 'payload' => $payload, 'status' => $status];
    }

    public static function failure(array $payload, ?int $status = null): array
    {
        return ['ok' => false, 'payload' => $payload, 'status' => $status];
    }
}
