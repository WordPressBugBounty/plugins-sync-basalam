<?php

namespace SyncBasalam\Jobs\Exceptions;

defined('ABSPATH') || exit;

class NonRetryableException extends JobException
{
    public function shouldRetry(): bool
    {
        return false;
    }

    public static function validationFailed(string $message = 'خطای اعتبار سنجی'): self
    {
        return new self($message, 400);
    }

    public static function productNotFound(int $productId): self
    {
        return new self("محصولی با آیدی {$productId} یافت نشد.", 404);
    }

    public static function invalidData(string $message = 'اطلاعات صحیح نیست'): self
    {
        return new self($message, 422);
    }

    public static function unauthorized(string $message = 'دسترسی غیرمجاز'): self
    {
        return new self($message, 401);
    }

    public static function permanent(string $message = 'خطایی رخ داد'): self
    {
        return new self($message, 0);
    }
}
