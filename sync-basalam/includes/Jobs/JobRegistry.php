<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Jobs\Types\BulkUpdateProductsJob;
use SyncBasalam\Jobs\Types\UpdateAllProductsJob;
use SyncBasalam\Jobs\Types\UpdateSingleProductJob;
use SyncBasalam\Jobs\Types\CreateSingleProductJob;
use SyncBasalam\Jobs\Types\CreateAllProductsJob;
use SyncBasalam\Jobs\Types\AutoConnectProductsJob;
use SyncBasalam\Jobs\Types\FetchOrdersJob;

defined('ABSPATH') || exit;

class JobRegistry
{
    private $jobTypes = [];

    public function __construct(array $jobTypes = [])
    {
        if (empty($jobTypes)) {
            $this->registerDefaultJobs();
            return;
        }

        foreach ($jobTypes as $jobType) {
            if ($jobType instanceof JobType) {
                $this->register($jobType);
            }
        }
    }

    private function registerDefaultJobs(): void
    {
        $container = syncBasalamContainer();
        $this->register($container->get(BulkUpdateProductsJob::class));
        $this->register($container->get(UpdateAllProductsJob::class));
        $this->register($container->get(UpdateSingleProductJob::class));
        $this->register($container->get(CreateSingleProductJob::class));
        $this->register($container->get(CreateAllProductsJob::class));
        $this->register($container->get(AutoConnectProductsJob::class));
        $this->register($container->get(FetchOrdersJob::class));
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
