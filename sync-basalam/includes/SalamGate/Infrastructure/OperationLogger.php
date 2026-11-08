<?php
namespace SyncBasalam\SalamGate\Infrastructure;

defined('ABSPATH') || exit;

/** Allowlisted diagnostics only: never log messages, URLs, tokens, request or response bodies. */
final class OperationLogger
{
    public static function failure(string $operation, \Throwable $error): string
    {
        try { $id = bin2hex(random_bytes(8)); }
        catch (\Throwable $ignored) { $id = substr(hash('sha256', uniqid('', true)), 0, 16); }
        $record = ['correlation_id' => $id, 'operation' => preg_replace('/[^a-z0-9_]/', '', $operation),
            'exception_type' => get_class($error), 'code' => (int) $error->getCode()];
        try {
            if (class_exists(\SyncBasalam\Logger\Logger::class)) {
                \SyncBasalam\Logger\Logger::warning('SalamGate operation failed: ' . json_encode($record));
            }
        } catch (\Throwable $ignored) { /* Diagnostics must never replace the operation outcome. */ }
        return $id;
    }
}
