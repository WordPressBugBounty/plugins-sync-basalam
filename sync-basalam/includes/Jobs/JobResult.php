<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Jobs\Exceptions\JobException;

defined('ABSPATH') || exit;

class JobResult
{
    private $success;
    private $exception;
    private $data;

    private function __construct(bool $success, ?JobException $exception = null, array $data = [])
    {
        $this->success = $success;
        $this->exception = $exception;
        $this->data = $data;
    }

    public static function success(array $data = []): self
    {
        return new self(true, null, $data);
    }

    public static function failed(JobException $exception, array $data = []): self
    {
        return new self(false, $exception, $data);
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function shouldRetry(): bool
    {
        return !$this->success && $this->exception && $this->exception->shouldRetry();
    }

    public function getException(): ?JobException
    {
        return $this->exception;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrorMessage(): ?string
    {
        return $this->exception ? $this->exception->getMessage() : null;
    }

    public function getErrorContext(): array
    {
        return $this->exception ? $this->exception->getErrorContext() : [];
    }
}
