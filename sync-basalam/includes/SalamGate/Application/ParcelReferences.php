<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Services\ReferenceDataService;

defined('ABSPATH') || exit;

final class ParcelReferences
{
    private ReferenceDataService $references;

    public function __construct(?ReferenceDataService $references = null)
    {
        $this->references = $references ?? new ReferenceDataService();
    }

    public function load(): array
    {
        $service = $this->references;
        $result = ['box_types' => [], 'provinces' => [], 'errors' => [], 'stale' => []];
        foreach (['box_types' => 'getBoxTypesWithState', 'provinces' => 'getProvinceTreeWithState'] as $key => $method) {
            try {
                $reference = $service->$method();
                $result[$key] = $reference['data'];
                $result['stale'][$key] = $reference['stale'];
                if ($result[$key] === []) { $result['errors'][$key] = 'اطلاعات مرجع دریافت نشد؛ دوباره تلاش کنید.'; }
            } catch (\Throwable $exception) {
                $result['errors'][$key] = 'اطلاعات مرجع دریافت نشد؛ دوباره تلاش کنید.';
            }
        }
        $result['ready'] = $result['errors'] === [];
        return OperationResult::success($result);
    }
}
