<?php

namespace SyncBasalam\Jobs\Exceptions;

use Exception;

defined('ABSPATH') || exit;

abstract class JobException extends Exception
{
    abstract public function shouldRetry(): bool;

    public function getErrorContext(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
