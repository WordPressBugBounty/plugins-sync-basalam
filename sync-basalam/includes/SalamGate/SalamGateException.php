<?php

namespace SyncBasalam\SalamGate;

defined('ABSPATH') || exit;

/**
 * خطای ارتباط با سرویس سلام‌گیت — با تفکیک نوع (kind) برای تصمیم‌گیری لایه‌های بالا.
 *
 * envelope خطای سرویس:
 * {"message": "...", "errors": [{"location": "body.method", "message": "..."}]}
 *
 * location همان نام فیلد/محل خطاست و باید تا UI حفظ شود.
 */
class SalamGateException extends \RuntimeException
{
    /** خطای شبکه/اتصال/timeout — وضعیت سرویس نامعلوم است. */
    public const KIND_TRANSPORT = 'transport';
    /** توکن نامعتبر/منقضی — اتصال باید از نو برقرار شود. */
    public const KIND_UNAUTHENTICATED = 'unauthenticated';
    /** توکن معتبر ولی مجوز/اسکوپ کافی ندارد. */
    public const KIND_FORBIDDEN = 'forbidden';
    /** منبع وجود ندارد (مثلاً پروفایل ثبت‌نشده). */
    public const KIND_NOT_FOUND = 'not_found';
    /** خطای اعتبارسنجی — errors دارای نام فیلد است. */
    public const KIND_VALIDATION = 'validation';
    /** تعداد درخواست زیاد. */
    public const KIND_RATE_LIMITED = 'rate_limited';
    /** خطای سمت سرویس. */
    public const KIND_SERVER = 'server';
    /** پاسخ 2xx با بدنه نامعتبر (خالی/null/JSON خراب) — موفقیت فرض نمی‌شود. */
    public const KIND_UNEXPECTED_RESPONSE = 'unexpected_response';

    /** @var array<int, array{location: string, message: string}> */
    private array $errors;

    private int $status;

    private string $kind;

    /** Locally asserted pre-HTTP evidence; never populated from upstream JSON/status. */
    private bool $definitelyNotDispatched;

    /**
     * @param array<int, array{location?: string, message?: string}> $errors
     */
    public function __construct(string $message = '', int $status = 0, array $errors = [], ?string $kind = null, bool $definitelyNotDispatched = false)
    {
        parent::__construct($message);

        $this->status = $status;
        $this->kind = $kind ?? self::kindForStatus($status);
        $this->definitelyNotDispatched = $definitelyNotDispatched;
        $this->errors = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $this->errors[] = [
                'location' => (string) ($error['location'] ?? ''),
                'message'  => (string) ($error['message'] ?? ''),
            ];
        }
    }

    public static function kindForStatus(int $status): string
    {
        if ($status === 401) {
            return self::KIND_UNAUTHENTICATED;
        }
        if ($status === 403) {
            return self::KIND_FORBIDDEN;
        }
        if ($status === 404) {
            return self::KIND_NOT_FOUND;
        }
        if ($status === 422) {
            return self::KIND_VALIDATION;
        }
        if ($status === 429) {
            return self::KIND_RATE_LIMITED;
        }
        if ($status >= 500) {
            return self::KIND_SERVER;
        }

        return $status === 0 ? self::KIND_TRANSPORT : self::KIND_SERVER;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isKind(string $kind): bool
    {
        return $this->kind === $kind;
    }

    public function isDefinitelyNotDispatched(): bool
    {
        return $this->definitelyNotDispatched;
    }

    /**
     * @return array<int, array{location: string, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * اولین پیام خطای موجود؛ در نبود آن، پیام خود اکسپشن.
     */
    public function firstMessage(): string
    {
        foreach ($this->errors as $error) {
            if ($error['message'] !== '') {
                return $error['message'];
            }
        }

        return $this->getMessage();
    }

    /**
     * خطاهایی که location آن‌ها دقیقاً با ورودی برابر است (نام فیلد).
     *
     * @return array<int, array{location: string, message: string}>
     */
    public function errorsAt(string $location): array
    {
        return array_values(array_filter(
            $this->errors,
            static fn (array $error): bool => $error['location'] === $location
        ));
    }
}
