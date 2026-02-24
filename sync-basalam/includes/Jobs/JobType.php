<?php

namespace SyncBasalam\Jobs;

defined('ABSPATH') || exit;

interface JobType
{
    public function getType(): string;

    public function getPriority(): int;

    public function execute(array $payload);

    public function canRun(): bool;
}
