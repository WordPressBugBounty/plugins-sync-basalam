<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/**
 * Bundled provider directory, independent of account credentials and activation.
 *
 * The release snapshot is exported by maintainers from all provinces and the
 * complete active-node endpoint. A missing or invalid file is a local data error:
 * never fall back to an authenticated API request on the merchant's behalf.
 */
final class PostOfficeCatalog
{
    public const SCHEMA_VERSION = 1;
    public const PROVIDER_ID = 1;
    private const MAX_BYTES = 15000000;

    /** @var array<string, array> Request-local decoded catalog cache. */
    private static array $catalogs = [];
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 3) . '/assets/salam-gate/data/post-offices.json';
    }

    /** @return array<int, array> Original public province/city tree. */
    public function getProvinceTree(): array
    {
        return $this->read()['provinces'];
    }

    /** @return array<int, array> Provider IDs, not public city IDs. */
    public function getProviderLocations(int $provinceId): array
    {
        $catalog = $this->read();
        if (!array_key_exists($provinceId, $catalog['locations_by_province'])) {
            throw new SalamGateException('استان انتخاب‌شده معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION, true);
        }
        return $catalog['locations_by_province'][$provinceId];
    }

    /** @return array<int, array> Full original node records, including contact fields. */
    public function getProviderNodes(int $providerLocationId): array
    {
        $catalog = $this->read();
        if (!array_key_exists($providerLocationId, $catalog['nodes_by_location'])) {
            throw new SalamGateException('منطقه پستی انتخاب‌شده معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION, true);
        }
        return $catalog['nodes_by_location'][$providerLocationId];
    }

    /** Active upstream records with no authoritative province assignment. */
    public function getUnassignedNodes(): array
    {
        return $this->read()['unassigned_nodes'];
    }

    /** @return array{schema_version:int, provider_id:int, source:string, fetched_at:string, counts:array} */
    public function getMetadata(): array
    {
        $catalog = $this->read();
        return [
            'schema_version' => $catalog['schema_version'],
            'provider_id' => $catalog['provider_id'],
            'source' => $catalog['source'],
            'fetched_at' => $catalog['fetched_at'],
            'counts' => $catalog['counts'],
        ];
    }

    private function read(): array
    {
        if (isset(self::$catalogs[$this->path])) {
            return self::$catalogs[$this->path];
        }
        $size = is_file($this->path) && is_readable($this->path) ? filesize($this->path) : false;
        if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
            $this->unavailable();
        }
        $json = file_get_contents($this->path);
        if (!is_string($json)) {
            $this->unavailable();
        }
        $catalog = json_decode($json, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($catalog) || !self::isValid($catalog)) {
            $this->unavailable();
        }
        self::$catalogs[$this->path] = $catalog;
        return $catalog;
    }

    private function unavailable(): void
    {
        throw new SalamGateException(
            'فهرست مراکز تحویل در دسترس نیست؛ فایل‌های افزونه را به‌روزرسانی کنید.',
            0,
            [],
            SalamGateException::KIND_UNEXPECTED_RESPONSE,
            true
        );
    }

    /** Validate completeness relationships before serving any partial directory. */
    public static function isValid(array $catalog): bool
    {
        if (($catalog['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($catalog['provider_id'] ?? null) !== self::PROVIDER_ID
            || ($catalog['source'] ?? null) !== 'https://salam-gate.basalam.com'
            || ($catalog['complete'] ?? null) !== true
            || !is_string($catalog['fetched_at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $catalog['fetched_at'])
            || strtotime($catalog['fetched_at']) === false
            || !self::isList($catalog['provinces'] ?? null)
            || count($catalog['provinces']) !== 31
            || !is_array($catalog['locations_by_province'] ?? null)
            || !is_array($catalog['nodes_by_location'] ?? null)
            || !self::isList($catalog['unassigned_nodes'] ?? null)
            || !is_array($catalog['counts'] ?? null)) {
            return false;
        }

        $provinceIds = [];
        $publicIds = [];
        $locationIds = [];
        foreach ($catalog['provinces'] as $province) {
            if (!self::validLocationTree($province, $publicIds, null)
                || !array_key_exists($province['id'], $catalog['locations_by_province'])) {
                return false;
            }
            $provinceIds[(int) $province['id']] = true;
            $locations = $catalog['locations_by_province'][$province['id']];
            if (!self::isList($locations)) {
                return false;
            }
            foreach ($locations as $location) {
                if (!self::validIdentifier($location) || isset($locationIds[$location['id']])
                    || !array_key_exists($location['id'], $catalog['nodes_by_location'])) {
                    return false;
                }
                $locationIds[(int) $location['id']] = true;
            }
        }
        if (count($provinceIds) !== count($catalog['locations_by_province'])
            || count($locationIds) !== count($catalog['nodes_by_location'])) {
            return false;
        }

        $nodeIds = [];
        $emptyLocations = 0;
        foreach ($catalog['nodes_by_location'] as $locationId => $nodes) {
            if (!isset($locationIds[$locationId]) || !self::isList($nodes)) {
                return false;
            }
            $emptyLocations += $nodes === [] ? 1 : 0;
            foreach ($nodes as $node) {
                if (!self::validNode($node) || isset($nodeIds[$node['id']])
                    || (int) $node['provider_location']['id'] !== (int) $locationId) {
                    return false;
                }
                $nodeIds[(int) $node['id']] = true;
            }
        }
        foreach ($catalog['unassigned_nodes'] as $node) {
            if (!self::validNode($node) || isset($nodeIds[$node['id']])) {
                return false;
            }
            $nodeIds[(int) $node['id']] = true;
        }

        return count($nodeIds) > 0
            && ($catalog['counts']['provinces'] ?? null) === count($provinceIds)
            && ($catalog['counts']['provider_locations'] ?? null) === count($locationIds)
            && ($catalog['counts']['nodes'] ?? null) === count($nodeIds)
            && ($catalog['counts']['unassigned_nodes'] ?? null) === count($catalog['unassigned_nodes'])
            && ($catalog['counts']['empty_provider_locations'] ?? null) === $emptyLocations;
    }

    private static function validNode($node): bool
    {
        if (!self::validIdentifier($node) || !self::validIdentifier($node['provider_location'] ?? null)
            || (array_key_exists('is_active', $node) && $node['is_active'] !== true)) {
            return false;
        }
        foreach (['address', 'postal_code', 'phone', 'owner'] as $field) {
            if (array_key_exists($field, $node) && $node[$field] !== null && !is_string($node[$field])) {
                return false;
            }
        }
        return true;
    }

    private static function validLocationTree($node, array &$seen, ?int $parentId, int $depth = 0): bool
    {
        if ($depth > 8 || count($seen) >= 10000 || !self::validIdentifier($node)
            || isset($seen[$node['id']]) || !self::isList($node['children'] ?? null)
            || ($parentId !== null && array_key_exists('parent_id', $node) && (int) $node['parent_id'] !== $parentId)) {
            return false;
        }
        $seen[(int) $node['id']] = true;
        foreach ($node['children'] as $child) {
            if (!self::validLocationTree($child, $seen, (int) $node['id'], $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    private static function validIdentifier($item): bool
    {
        if (!is_array($item) || !is_string($item['title'] ?? null) || trim($item['title']) === '') {
            return false;
        }
        $id = $item['id'] ?? null;
        return (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)))
            && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private static function isList($items): bool
    {
        return is_array($items) && ($items === [] || array_keys($items) === range(0, count($items) - 1));
    }
}
