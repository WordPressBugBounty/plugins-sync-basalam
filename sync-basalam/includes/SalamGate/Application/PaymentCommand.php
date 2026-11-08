<?php
namespace SyncBasalam\SalamGate\Application;

defined('ABSPATH') || exit;

/** A reviewed amount and authoritative source identity, prepared under the workflow lease. */
final class PaymentCommand
{
    public int $parcelId;
    public string $market;
    public int $method;
    public array $baseline;
    public string $expectedHash;
    public int $expectedTotal;

    public function __construct(int $parcelId, string $market, int $method, array $baseline, string $expectedHash, int $expectedTotal)
    {
        $this->parcelId = $parcelId;
        $this->market = $market;
        $this->method = $method;
        $this->baseline = $baseline;
        $this->expectedHash = $expectedHash;
        $this->expectedTotal = $expectedTotal;
    }
}
