<?php
namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\WorkflowFailure;

defined('ABSPATH') || exit;

final class ActionResponder
{
    public static function run(callable $operation): void
    {
        try { $result = $operation(); }
        catch (WorkflowFailure $failure) { $result = $failure->result(); }
        catch (\Throwable $error) {
            $id = \SyncBasalam\SalamGate\Infrastructure\OperationLogger::failure('ajax_workflow', $error);
            $result = ['ok' => false, 'payload' => ['code' => 'unknown', 'kind' => 'unexpected_response',
                'message' => 'نتیجه عملیات تأیید نشد؛ وضعیت قبلی را بررسی کنید.', 'correlation_id' => $id], 'status' => 500];
        }
        // All workflow finally blocks have completed before WordPress exits.
        if ($result['ok']) {
            if (isset($result['status'])) { ResponseBoundary::success($result['payload'], $result['status']); }
            else { ResponseBoundary::success($result['payload']); }
            return;
        }
        if (isset($result['status'])) { ResponseBoundary::error($result['payload'], $result['status']); }
        else { ResponseBoundary::error($result['payload']); }
    }
}
