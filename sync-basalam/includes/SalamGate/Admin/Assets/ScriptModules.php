<?php
namespace SyncBasalam\SalamGate\Admin\Assets;


defined('ABSPATH') || exit;

final class ScriptModules
{
    public static function forScreen(string $page, string $view, bool $orderScreen): array
    {
        if ($orderScreen) { return ['order-modal']; }
        if ($page === 'salam_gate_status') { return ['status']; }
        $modules = ['status', 'account', 'activation'];
        if ($page === 'salam_gate_parcels') { $modules[] = 'parcels'; }
        elseif ($view === 'send') { $modules = array_merge($modules, ['send', 'order-modal']); }
        elseif (in_array($view, ['settings', 'post-offices', 'calculator'], true)) { $modules[] = $view; }
        elseif (!in_array($view, ['activation', 'tracking'], true)) { $modules[] = 'dashboard'; }
        return $modules;
    }

    public static function enqueue(string $page, string $view, bool $orderScreen): void
    {
        foreach (self::forScreen($page, $view, $orderScreen) as $name) {
            AssetRegistrar::script('woosalam-sg-page-' . $name);
        }
    }
}
