<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\DirectShipment;

defined('ABSPATH') || exit;

/** WordPress HTTP adapter; public action names remain backward compatible. */
class SendActions
{
    public const MARKETS = DirectShipment::MARKETS;

    public static function bootstrap(): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->bootstrap());
    }

    public static function creationStatus(): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->creationStatus());
    }

    public static function upsert(): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->upsert());
    }

    public static function recoverCreatedParcel(int $externalId, string $market): ?int
    {
        return (new DirectShipment([]))->recoverCreatedParcel($externalId, $market);
    }

    public static function updateWebsiteOrderDraft(int $parcelId, array $body, callable $renew): array
    {
        return (new DirectShipment([]))->updateWebsiteOrderDraft($parcelId, $body, $renew);
    }

    public static function selectWebsiteOrderQuote(int $parcelId, string $hash, callable $renew): array
    {
        return (new DirectShipment([]))->selectWebsiteOrderQuote($parcelId, $hash, $renew);
    }

    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }

    public static function quotes(bool $listCheckout = false): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->quotes($listCheckout));
    }

    public static function selectQuote(bool $listCheckout = false): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->selectQuote($listCheckout));
    }

    public static function pay(bool $listCheckout = false): void
    {
        self::guard();
        $workflow = new DirectShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->pay($listCheckout));
    }
}
