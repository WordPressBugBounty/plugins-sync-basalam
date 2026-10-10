<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

/** Request metadata only: never persist cookies, nonces, payloads or API responses. */
class AsyncWorkerHealth
{
    public const OPTION_KEY = 'sync_basalam_async_worker_health';

    private ?bool $protection = null;

    public function observeAsyncEnvironment(): void
    {
        $this->protection = $this->detectLiteSpeedProtection();
        $state = $this->getState();
        $missing = $this->protection === false;
        if (($state['protection_missing'] ?? false) === $missing) return;

        $state['protection_missing'] = $missing;
        $state['environment_checked_at'] = time();
        $this->save($state);
    }

    /** Call only while owning the runner's global lock, so a slow live worker is excluded. */
    public function recordInterruptedJob(object $job): void
    {
        $state = $this->getState();
        $interruption = [
            'job_id' => (int) $job->id,
            'started_at' => (int) $job->started_at,
        ];
        if (($state['interruption'] ?? null) === $interruption) return;

        $state['interruption'] = $interruption;
        $state['interrupted_at'] = time();
        $state['litespeed'] = $this->isLiteSpeed();
        $this->save($state);
    }

    /** A returned API rejection is distinct from an interrupted PHP worker. */
    public function recordJobReturned(object $job, float $elapsed): void
    {
        $state = $this->getState();
        $state['last_returned_job_id'] = (int) $job->id;
        $state['last_returned_at'] = time();
        $state['last_duration_seconds'] = round($elapsed, 3);
        $state['last_returned_protected'] = $this->protection;
        // On LiteSpeed, a quick job without noabort does not prove the host is repaired.
        if ($this->protection !== false) {
            unset($state['interruption'], $state['interrupted_at'], $state['litespeed']);
        }
        $this->save($state);
    }

    public function getNotice(): ?array
    {
        $state = $this->getState();
        $interrupted = !empty($state['interruption']);
        $missing = !empty($state['protection_missing']);
        if (!$interrupted && !$missing) return null;

        $litespeed = $missing || !empty($state['litespeed']);
        return [
            'class' => $interrupted ? 'notice-error' : 'notice-warning',
            'title' => $interrupted ? 'اجرای همگام‌سازی نیمه‌کاره متوقف شده است.' : 'محافظ اجرای پس‌زمینه در هاست قابل تأیید نیست.',
            'message' => $interrupted
                ? 'یک کار همگام‌سازی بیش از دو دقیقه در حالت اجرا باقی مانده، اما پردازشگر آن دیگر فعال نیست. ممکن است قیمت، موجودی یا سفارش‌ها به‌روز نشده باشند.'
                : 'در درخواست اجرای صف روی هاست LiteSpeed، محافظ جلوگیری از توقف شناسایی نشد. ممکن است کارهای همگام‌سازی نیمه‌کاره متوقف شوند.',
            'guidance' => $litespeed
                ? 'از پشتیبانی هاست بخواهید تنظیم noabort را فقط برای درخواست اجرای صف ووسلام در .htaccess یا تنظیمات وب‌سرور بررسی کند. این تنظیم مربوط به هاست است و به فعال بودن افزونهٔ LiteSpeed Cache وابسته نیست.'
                : 'از پشتیبانی هاست بخواهید علت توقف پردازش PHP و محدودیت زمان یا حافظه را بررسی کند.',
            'job_id' => (int) ($state['interruption']['job_id'] ?? 0),
        ];
    }

    private function getState(): array
    {
        $state = get_option(self::OPTION_KEY, []);
        return is_array($state) ? $state : [];
    }

    private function save(array $state): void
    {
        update_option(self::OPTION_KEY, $state, false);
    }

    protected function isLiteSpeed(): bool
    {
        return PHP_SAPI === 'litespeed';
    }

    /** null means a different PHP runtime; absence cannot rule out a global host setting. */
    protected function detectLiteSpeedProtection(): ?bool
    {
        if (!$this->isLiteSpeed()) return null;

        foreach (['noabort', 'REDIRECT_noabort'] as $name) {
            $values = [$_SERVER[$name] ?? null, getenv($name)];
            if (function_exists('apache_getenv')) $values[] = apache_getenv($name);
            foreach ($values as $value) {
                if ((string) $value === '1') return true;
            }
        }
        return false;
    }
}
