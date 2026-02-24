<?php

namespace SyncBasalam\Jobs\Exceptions;

defined('ABSPATH') || exit;


class RetryableException extends JobException
{
    public function shouldRetry(): bool
    {
        return true;
    }

    public static function apiTimeout(string $message = 'درخواست با تایم اوت مواجه شد.'): self
    {
        return new self($message, 408);
    }

    public static function networkError(string $message = 'خطای ارتباط شبکه ای'): self
    {
        return new self($message, 503);
    }

    public static function rateLimit(string $message = 'خطای rate limit رخ داد.'): self
    {
        return new self($message, 429);
    }

    public static function serverError(string $message = 'خطای سرور'): self
    {
        return new self($message, 500);
    }

    public static function temporary(string $message = 'خطای موقتی'): self
    {
        return new self($message, 0);
    }
}
