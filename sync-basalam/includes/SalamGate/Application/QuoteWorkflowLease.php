<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

final class QuoteWorkflowLease
{
    public static function run(int $parcelId, callable $work, callable $unavailable): array
    {
        $key = 'salam_gate_quote_workflow_' . $parcelId;
        $lease = OptionLock::acquire($key);
        if ($lease === null) { return $unavailable(); }
        try {
            $renew = static function () use ($key, &$lease): bool {
                $next = OptionLock::renew($key, $lease);
                if ($next === null) { return false; }
                $lease = $next;
                return true;
            };
            return $work($renew);
        } finally {
            OptionLock::release($key, $lease);
        }
    }
}
