<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

/**
 * Circuit Breaker for Basalam API requests.
 *
 * States:
 *   CLOSED    — normal operation, requests pass through.
 *   OPEN      — requests are blocked after too many consecutive failures.
 *   HALF_OPEN — one probe request is allowed after the cooldown period to test recovery.
 *
 * State is persisted in a single WordPress option so it survives across requests/cron jobs.
 */
class CircuitBreaker
{
    const STATE_CLOSED    = 'closed';
    const STATE_OPEN      = 'open';
    const STATE_HALF_OPEN = 'half_open';

    const OPTION_KEY = 'sync_basalam_circuit_breaker';

    /**
     * Request-local cache to avoid repeated DB reads when multiple API service
     * instances are created in the same request lifecycle.
     */
    private static ?array $requestStateCache = null;

    /** Number of consecutive failures before the circuit opens. */
    private int $failureThreshold;

    /** Seconds to wait in OPEN state before moving to HALF_OPEN. */
    private int $recoveryTimeout;

    /** Seconds of inactivity in CLOSED state after which failure_count resets to 0. */
    private int $closedResetInterval;

    private array $state;

    public function __construct(int $failureThreshold = 10, int $recoveryTimeout = 60, int $closedResetInterval = 300)
    {
        $this->failureThreshold    = $failureThreshold;
        $this->recoveryTimeout     = $recoveryTimeout;
        $this->closedResetInterval = $closedResetInterval;
        $this->state               = $this->loadState();
    }

    /**
     * Returns true when a request should be allowed through.
     * Throws a CircuitBreakerOpenException when the circuit is OPEN.
     */
    public function isAllowed(): bool
    {
        $currentState = $this->state['state'];

        if ($currentState === self::STATE_CLOSED) return true;

        if ($currentState === self::STATE_OPEN) {
            if ($this->recoveryTimeoutElapsed()) {
                $this->transitionTo(self::STATE_HALF_OPEN);
                return true;
            }

            throw new CircuitBreakerOpenException('سرویس باسلام موقتاً در دسترس نیست. لطفاً چند دقیقه دیگر تلاش کنید.', 503);
        }

        // HALF_OPEN: allow the single probe request through.
        return true;
    }

    /**
     * Records a successful request and resets the failure counter.
     */
    public function recordSuccess(): void
    {
        $this->state['failure_count'] = 0;
        $this->state['last_failure']  = null;
        $this->transitionTo(self::STATE_CLOSED);
    }

    /**
     * Records a failed request and opens the circuit when the threshold is reached.
     */
    public function recordFailure(): void
    {
        $this->state['failure_count']++;
        $this->state['last_failure'] = time();

        if ($this->state['state'] === self::STATE_HALF_OPEN) {
            $this->transitionTo(self::STATE_OPEN);
            return;
        }

        if ($this->state['failure_count'] >= $this->failureThreshold) {
            $this->transitionTo(self::STATE_OPEN);
        }

        $this->saveState();
    }

    public function getState(): string
    {
        return $this->state['state'];
    }

    public function getFailureCount(): int
    {
        return $this->state['failure_count'];
    }

    public function reset(): void
    {
        $this->state = $this->defaultState();
        $this->saveState();
    }

    private function recoveryTimeoutElapsed(): bool
    {
        if (empty($this->state['last_failure'])) {
            return true;
        }

        return (time() - $this->state['last_failure']) >= $this->recoveryTimeout;
    }

    private function transitionTo(string $newState): void
    {
        $previous = $this->state['state'];

        if ($newState === self::STATE_CLOSED) {
            $this->state = $this->defaultState();
        } else {
            $this->state['state'] = $newState;
        }

        $this->saveState();
    }

    private function loadState(): array
    {
        if (is_array(self::$requestStateCache)) {
            $state = self::$requestStateCache;
        } else {
            $stored = get_option(self::OPTION_KEY, null);
            if (!is_array($stored)) {
                $state = $this->defaultState();
            } else {
                $state = array_merge($this->defaultState(), $stored);
            }

            self::$requestStateCache = $state;
        }

        // Reset failure_count every 30 minutes while the circuit stays CLOSED.
        if (
            $state['state'] === self::STATE_CLOSED &&
            $state['failure_count'] > 0 &&
            !empty($state['last_failure']) &&
            (time() - $state['last_failure']) >= $this->closedResetInterval
        ) {
            $state['failure_count'] = 0;
            $state['last_failure']  = null;
            update_option(self::OPTION_KEY, $state, false);
            self::$requestStateCache = $state;
        }

        return $state;
    }

    private function saveState(): void
    {
        self::$requestStateCache = $this->state;
        update_option(self::OPTION_KEY, $this->state, false);
    }

    private function defaultState(): array
    {
        return [
            'state'         => self::STATE_CLOSED,
            'failure_count' => 0,
            'last_failure'  => null,
        ];
    }
}
