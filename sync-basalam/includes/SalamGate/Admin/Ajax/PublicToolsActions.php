<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\CalculateShipping;
use SyncBasalam\SalamGate\Application\OperationResult;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\PostOfficeCatalog;
use SyncBasalam\SalamGate\Services\ReferenceDataService;

defined('ABSPATH') || exit;

/** Store-admin tools independent of SalamGate registration and user OAuth. */
final class PublicToolsActions
{
    public static function calculatorReferences(): void
    {
        self::respond(static function (): array {
            $references = new ReferenceDataService();
            $locations = $references->getProvinceTreeWithState();
            $boxes = $references->getBoxTypesWithState();
            return ['provinces' => $locations['data'], 'box_types' => $boxes['data'],
                'stale' => $locations['stale'] || $boxes['stale']];
        });
    }

    public static function calculate(): void
    {
        ActionGuard::forController(self::class);
        // CalculateShipping only calls the public estimator; it never creates a parcel.
        ActionResponder::run(static fn (): array => (new CalculateShipping($_POST))->calculate());
    }

    public static function postOffices(): void
    {
        self::respond(static function (): array {
            $catalog = new PostOfficeCatalog();
            return ['provinces' => $catalog->getProvinceTree(), 'catalog' => $catalog->getMetadata(),
                'unassigned_nodes' => $catalog->getUnassignedNodes(), 'stale' => false];
        });
    }

    public static function providerLocations(): void
    {
        self::respond(static function (): array {
            $locations = (new PostOfficeCatalog())->getProviderLocations(self::positiveId('province_id'));
            return ['locations' => $locations, 'has_coverage' => $locations !== []];
        });
    }

    public static function providerNodes(): void
    {
        self::respond(static fn (): array => ['nodes' => (new PostOfficeCatalog())->getProviderNodes(self::positiveId('provider_location_id'))]);
    }

    private static function positiveId(string $key): int
    {
        $value = $_POST[$key] ?? null;
        $id = (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
        if ($id === false) {
            throw new SalamGateException('استان یا منطقه پستی معتبر را انتخاب کنید.', 422, [], SalamGateException::KIND_VALIDATION, true);
        }
        return $id;
    }

    private static function respond(callable $operation): void
    {
        ActionGuard::forController(self::class);
        ActionResponder::run(static function () use ($operation): array {
            try {
                return OperationResult::success($operation());
            } catch (SalamGateException $error) {
                return OperationResult::failure(['message' => $error->firstMessage(), 'kind' => $error->getKind(),
                    'code' => 'tool_unavailable', 'errors' => []]);
            }
        });
    }
}
