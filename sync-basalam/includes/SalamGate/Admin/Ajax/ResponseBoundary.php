<?php
namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\OperationResult;

defined('ABSPATH') || exit;

/** Additive response metadata; existing payload fields and HTTP statuses are retained. */
final class ResponseBoundary
{
    public static function success($data = null, ?int $status = null): void { self::send(true, $data, $status); }
    public static function error($data = null, ?int $status = null): void { self::send(false, $data, $status); }

    private static function send(bool $ok, $data, ?int $status): void
    {
        $action = $_REQUEST['action'] ?? $_POST['action'] ?? '';
        $definition = is_string($action) ? ActionRegistry::get($action) : null;
        $payload = OperationResult::describe($ok, is_array($data) ? $data : ['message' => is_string($data) ? $data : ''],
            $definition === null || $definition->operationKind() !== 'read');
        if ($ok) {
            if ($status !== null) wp_send_json_success($payload, $status);
            else wp_send_json_success($payload);
        } else {
            if ($status !== null) wp_send_json_error($payload, $status);
            else wp_send_json_error($payload);
        }
    }
}
