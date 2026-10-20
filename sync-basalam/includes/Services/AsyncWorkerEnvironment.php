<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

/** Server evidence only; HTTP headers supplied by the client are never trusted. */
class AsyncWorkerEnvironment
{
    /** null means there is not enough evidence to identify the web server. */
    public function isLiteSpeed(): ?bool
    {
        // The LiteSpeed module branch and its guarded fallback are exclusive.
        $positive = $this->hasFlag('SYNCBASALAM_LITESPEED');
        $negative = $this->hasFlag('SYNCBASALAM_NOT_LITESPEED');
        if ($positive && $negative) return null;
        if ($positive) return true;
        if ($negative) return false;

        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? getenv('SERVER_SOFTWARE') ?: ''));
        if (strpos($software, 'litespeed') !== false) return true;
        foreach (['apache', 'nginx', 'caddy', 'microsoft-iis'] as $server) {
            if (strpos($software, $server) !== false) return false;
        }

        return null;
    }

    public function shouldInstallProbe(): bool
    {
        $server = $this->isLiteSpeed();
        // CloudLinux's Apache mod_lsapi reports this same SAPI. It is a reason
        // to install a conditional probe, never proof that LiteSpeed is active.
        return $server === true || ($server === null && PHP_SAPI === 'litespeed');
    }

    public function hasFlag(string $name): bool
    {
        foreach ([$name, 'REDIRECT_' . $name] as $key) {
            $values = [$_SERVER[$key] ?? null, getenv($key)];
            if (function_exists('apache_getenv')) $values[] = apache_getenv($key);
            foreach ($values as $value) {
                if ((string) $value === '1') return true;
            }
        }
        return false;
    }
}
