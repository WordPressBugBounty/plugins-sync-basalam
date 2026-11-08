<?php
namespace SyncBasalam\SalamGate\Application;

defined('ABSPATH') || exit;

/** Validation failure from a nested identity/input resolver; never terminates PHP. */
final class WorkflowFailure extends \RuntimeException
{
    private array $result;

    public function __construct(array $payload, ?int $status = null)
    {
        parent::__construct((string) ($payload['message'] ?? 'Workflow validation failed.'));
        $this->result = OperationResult::failure($payload, $status);
    }

    public function result(): array { return $this->result; }
}
