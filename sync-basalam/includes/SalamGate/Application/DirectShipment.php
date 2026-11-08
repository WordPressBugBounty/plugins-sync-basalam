<?php
namespace SyncBasalam\SalamGate\Application;


defined('ABSPATH') || exit;

/** DirectShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class DirectShipment
{
    public const MARKETS = ['instagram', 'eitaa', 'bale', 'telegram', 'website', 'digikala', 'mixin', 'other'];
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function bootstrap(): array
    {
        return (new ShipmentBootstrap($this->input, $this->context))->bootstrap();
    }

    public function creationStatus(): array
    {
        return (new CreateShipment($this->input, $this->context))->creationStatus();
    }

    public function upsert(): array
    {
        return (new CreateShipment($this->input, $this->context))->upsert();
    }

    public function recoverCreatedParcel(int $externalId, string $market): ?int
    {
        return (new CreateShipment($this->input, $this->context))->recoverCreatedParcel($externalId, $market);
    }

    public function updateWebsiteOrderDraft(int $parcelId, array $body, callable $renew): array
    {
        return (new EditShipment($this->input, $this->context))->updateWebsiteOrderDraft($parcelId, $body, $renew);
    }

    public function selectWebsiteOrderQuote(int $parcelId, string $hash, callable $renew): array
    {
        return (new EditShipment($this->input, $this->context))->selectWebsiteOrderQuote($parcelId, $hash, $renew);
    }

    public function quotes(bool $listCheckout = false): array
    {
        return (new ParcelCheckout($this->input, $this->context))->quotes($listCheckout);
    }

    public function selectQuote(bool $listCheckout = false): array
    {
        return (new ParcelCheckout($this->input, $this->context))->selectQuote($listCheckout);
    }

    public function pay(bool $listCheckout = false): array
    {
        return (new ParcelCheckout($this->input, $this->context))->pay($listCheckout);
    }
}
