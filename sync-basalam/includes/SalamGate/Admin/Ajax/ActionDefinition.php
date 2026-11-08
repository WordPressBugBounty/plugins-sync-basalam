<?php
namespace SyncBasalam\SalamGate\Admin\Ajax;

defined('ABSPATH') || exit;

final class ActionDefinition
{
    private array $callback;
    private array $capabilities;
    private bool $allCapabilities;
    private string $account;
    private string $kind;
    private bool $site;

    public function __construct(array $callback, array $capabilities, bool $allCapabilities, string $account, string $kind, bool $site)
    {
        if ($capabilities === [] || !in_array($account, ['active', 'control', 'independent', 'registration', 'bootstrap', 'references', 'activate'], true)
            || !in_array($kind, ['read', 'mutation', 'quote'], true)) throw new \InvalidArgumentException('Missing AJAX action policy.');
        $this->callback = $callback;
        $this->capabilities = $capabilities;
        $this->allCapabilities = $allCapabilities;
        $this->account = $account;
        $this->kind = $kind;
        $this->site = $site;
    }

    public function callback(): array { return $this->callback; }
    public function capabilities(): array { return $this->capabilities; }
    public function needsAllCapabilities(): bool { return $this->allCapabilities; }
    public function nonceAction(): string { return 'basalam_pro_nonce'; }
    public function accountRequirement(): string { return $this->account; }
    public function operationKind(): string { return $this->kind; }
    public function requiresSite(): bool { return $this->site; }
}
