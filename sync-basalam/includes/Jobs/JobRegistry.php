<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Jobs\Types\BulkUpdateProductsJob;
use SyncBasalam\Jobs\Types\UpdateAllProductsJob;
use SyncBasalam\Jobs\Types\UpdateSingleProductJob;
use SyncBasalam\Jobs\Types\CreateSingleProductJob;
use SyncBasalam\Jobs\Types\CreateAllProductsJob;
use SyncBasalam\Jobs\Types\AutoConnectProductsJob;

defined('ABSPATH') || exit;

class JobRegistry
{
    private static $instance = null;
    private $jobTypes = [];

    private function __construct()
    {
        $this->registerDefaultJobs();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function registerDefaultJobs(): void
    {
        $this->register(new BulkUpdateProductsJob());
        $this->register(new UpdateAllProductsJob());
        $this->register(new UpdateSingleProductJob());
        $this->register(new CreateSingleProductJob());
        $this->register(new CreateAllProductsJob());
        $this->register(new AutoConnectProductsJob());
    }

    public function register(JobType $jobType): void
    {
        $this->jobTypes[$jobType->getType()] = $jobType;
    }

    public function get(string $type): ?JobType
    {
        return $this->jobTypes[$type] ?? null;
    }

    public function getAll(): array
    {
        return $this->jobTypes;
    }

    public function getSortedByPriority(): array
    {
        $jobTypes = $this->jobTypes;
        uasort($jobTypes, function (JobType $a, JobType $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
        return $jobTypes;
    }
}
