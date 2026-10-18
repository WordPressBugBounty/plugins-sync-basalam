<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Read the PDF of an exact, account-owned label job. Never accept a browser-supplied file URL. */
final class LabelPreviewService
{
    public const MAX_BYTES = 20971520;

    public static function read(int $requestId): string
    {
        if ($requestId <= 0) { throw new SalamGateException('شناسه فایل برچسب معتبر نیست.', 422); }
        $identity = ConnectionCache::identityHash();
        $label = (new ParcelService())->getLabelStatus($requestId);
        ConnectionCache::assertCurrentIdentity($identity);
        if (($label['status'] ?? '') !== 'completed' || ($label['request_id'] ?? null) !== $requestId
            || !is_string($label['url'] ?? null)) {
            throw new SalamGateException('فایل برچسب هنوز برای نمایش آماده نیست.', 422);
        }
        $bytes = self::localUpload($label['url']);
        if ($bytes === null) {
            // A completed job's storage file can briefly be unavailable. Retry
            // this read only, never generate a second label or repeat payment.
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                ConnectionCache::assertCurrentIdentity($identity);
                $response = wp_safe_remote_get($label['url'], ['timeout'=>12, 'redirection'=>3,
                    'limit_response_size'=>self::MAX_BYTES + 1, 'headers'=>['Accept'=>'application/pdf']]);
                $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
                if ($status === 200 || !in_array($status, [0, 404, 409, 425, 429, 500, 502, 503, 504], true) || $attempt === 1) break;
                usleep(200000);
            }
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                throw new SalamGateException('فایل PDF برچسب دریافت نشد؛ به فهرست مرسوله‌ها برگردید و دوباره چاپ برچسب را بزنید.', 502);
            }
            $bytes = wp_remote_retrieve_body($response);
        }
        ConnectionCache::assertCurrentIdentity($identity);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES || !str_starts_with($bytes, '%PDF-')) {
            throw new SalamGateException('فایل دریافت‌شده برای پیش‌نمایش برچسب معتبر نیست.', 422);
        }
        return $bytes;
    }

    private static function localUpload(string $url): ?string
    {
        $uploads = wp_upload_dir(null, false);
        $base = parse_url($uploads['baseurl']); $target = parse_url($url);
        $prefix = rtrim($base['path'] ?? '', '/') . '/';
        if (!is_array($target) || ($target['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || ($target['host'] ?? '') !== ($base['host'] ?? '') || ($target['port'] ?? null) !== ($base['port'] ?? null)
            || !str_starts_with($target['path'] ?? '', $prefix)) { return null; }
        $relative = rawurldecode(substr($target['path'], strlen($prefix)));
        if (str_contains($relative, "\0")) { return null; }
        $root = realpath($uploads['basedir']);
        $path = $root !== false ? realpath($root . '/' . $relative) : false;
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)
            || filesize($path) > self::MAX_BYTES) { return null; }
        $bytes = file_get_contents($path);
        return is_string($bytes) ? $bytes : null;
    }
}
