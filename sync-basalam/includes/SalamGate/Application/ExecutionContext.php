<?php
namespace SyncBasalam\SalamGate\Application;

defined('ABSPATH') || exit;

/** Server-verified execution facts, separate from untrusted form fields. */
final class ExecutionContext
{
    private bool $requireWeightReview;
    private ?array $nativeIdentity;

    private function __construct(bool $requireWeightReview, ?array $nativeIdentity)
    {
        $this->requireWeightReview = $requireWeightReview;
        $this->nativeIdentity = $nativeIdentity;
    }

    public function requiresWeightReview(): bool { return $this->requireWeightReview; }
    public function nativeIdentity(): ?array { return $this->nativeIdentity; }

    public static function individual(): self { return new self(false, null); }
    public static function bulk(?array $nativeIdentity = null): self { return new self(true, $nativeIdentity); }
}
