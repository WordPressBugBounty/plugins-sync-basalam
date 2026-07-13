<?php

namespace SyncBasalam\Jobs\Exceptions;

use Exception;

defined('ABSPATH') || exit;

abstract class JobException extends Exception
{
    /** @var array|null Raw (decoded) API response body attached to this error, when available. */
    protected $responseData = null;

    abstract public function shouldRetry(): bool;

    public function setResponseData(?array $responseData): self
    {
        $this->responseData = $responseData;
        return $this;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

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
