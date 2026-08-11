<?php

namespace SyncBasalam\Services;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

/**
 * Basalam only accepts jpg, jpeg, png, webp, gif, bmp and jfif images.
 * Media libraries can hold AVIF or other unsupported image files, and the media upload-request
 * endpoint rejects them with 422 «نوع MIME پشتیبانی نمی‌شود».
 * Those files are transcoded to JPEG before the upload starts.
 */
class ImageFormatNormalizer
{
    public const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'jfif'];

    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/x-windows-bmp',
    ];

    private const TARGET_MIME = 'image/jpeg';
    private const QUALITY_STEPS = [82, 70, 60];
    private const MAX_DIMENSION = 2500;

    /**
     * Returns the path of a converted temporary JPEG file, or null when the file
     * is already in a format Basalam accepts and can be uploaded as is.
     */
    public function normalize(string $filePath, int $maxSize): ?string
    {
        if (!file_exists($filePath)) return null;

        $mimeType = $this->detectMimeType($filePath);
        if ($this->isSupported($filePath, $mimeType)) return null;

        $converted = $this->convertToJpeg($filePath, $mimeType, $maxSize);

        if ($converted === null) {
            Logger::error('تبدیل تصویر به فرمت مورد پذیرش باسلام ناموفق بود.', [
                'file' => basename($filePath),
                'mime_type' => $mimeType,
                'imagick' => class_exists('Imagick'),
                'gd_avif' => function_exists('imagecreatefromavif'),
            ]);

            $detectedType = $mimeType ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            throw new \RuntimeException(esc_html(
                'فرمت این تصویر (' . $detectedType . ') مورد پذیرش باسلام نیست'
                . ' و تبدیل خودکار آن روی سرور شما ممکن نشد. لطفاً تصویر را با فرمت JPG یا WEBP در سایت بارگذاری کنید'
                . ' یا از هاست خود بخواهید پشتیبانی فرمت تصویر را در Imagick یا GD فعال کند.'
            ));
        }

        Logger::info('تصویر برای آپلود به باسلام به فرمت JPEG تبدیل شد.', [
            'file' => basename($filePath),
            'mime_type' => $mimeType,
        ]);

        return $converted;
    }

    private function convertToJpeg(string $filePath, string $mimeType, int $maxSize): ?string
    {
        $converters = ['convertWithImageEditor', 'convertWithImagick', 'convertWithGd'];

        foreach ($converters as $converter) {
            foreach (self::QUALITY_STEPS as $quality) {
                $target = $this->makeTempPath();
                $result = $this->$converter($filePath, $target, $mimeType, $quality);

                if ($result === null) {
                    $this->deleteFile($target);
                    break;
                }

                $size = filesize($result);
                if ($size !== false && $size > 0 && $size <= $maxSize) return $result;

                $this->deleteFile($result);
                if ($result !== $target) $this->deleteFile($target);
            }
        }

        return null;
    }

    private function convertWithImageEditor(string $source, string $target, string $mimeType, int $quality): ?string
    {
        if (!function_exists('wp_get_image_editor')) return null;

        $args = $mimeType !== '' ? ['mime_type' => $mimeType] : [];
        $editor = wp_get_image_editor($source, $args);

        if (is_wp_error($editor)) return null;

        $editor->set_quality($quality);

        $size = $editor->get_size();
        $width = isset($size['width']) ? (int) $size['width'] : 0;
        $height = isset($size['height']) ? (int) $size['height'] : 0;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $editor->resize(self::MAX_DIMENSION, self::MAX_DIMENSION, false);
        }

        $saved = $editor->save($target, self::TARGET_MIME);

        if (is_wp_error($saved) || !is_array($saved)) return null;

        // An image_editor_output_format filter can force another extension, so trust the returned path.
        $savedPath = !empty($saved['path']) ? (string) $saved['path'] : $target;
        $savedExtension = strtolower(pathinfo($savedPath, PATHINFO_EXTENSION));

        if (!file_exists($savedPath) || !in_array($savedExtension, self::SUPPORTED_EXTENSIONS, true)) {
            $this->deleteFile($savedPath);
            return null;
        }

        return $savedPath;
    }

    private function convertWithImagick(string $source, string $target, string $mimeType, int $quality): ?string
    {
        if (!class_exists('Imagick')) return null;

        try {
            $imagick = new \Imagick();
            $imagick->readImage($source);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));

            $flattened = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->clear();
            $imagick = $flattened;

            $width = (int) $imagick->getImageWidth();
            $height = (int) $imagick->getImageHeight();

            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                $ratio = self::MAX_DIMENSION / max($width, $height);
                $imagick->resizeImage(
                    max(1, (int) round($width * $ratio)),
                    max(1, (int) round($height * $ratio)),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage();
            $imagick->writeImage($target);
            $imagick->clear();
        } catch (\Throwable $e) {
            Logger::debug('تبدیل تصویر با Imagick ناموفق بود: ' . $e->getMessage(), ['file' => basename($source)]);
            return null;
        }

        return file_exists($target) ? $target : null;
    }

    private function convertWithGd(string $source, string $target, string $mimeType, int $quality): ?string
    {
        if (!function_exists('imagejpeg') || !function_exists('imagecreatefromstring')) return null;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for image conversion; WP_Filesystem cannot feed GD.
        $contents = file_get_contents($source);
        if ($contents === false) return null;

        $image = @imagecreatefromstring($contents);
        unset($contents);

        if (!$image) return null;

        $canvas = null;

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            $ratio = max($width, $height) > self::MAX_DIMENSION ? self::MAX_DIMENSION / max($width, $height) : 1;
            $targetWidth = max(1, (int) round($width * $ratio));
            $targetHeight = max(1, (int) round($height * $ratio));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            if (!$canvas) return null;

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            $saved = imagejpeg($canvas, $target, $quality);
            if (!$saved) return null;
        } finally {
            if ($canvas) imagedestroy($canvas);
            imagedestroy($image);
        }

        return file_exists($target) ? $target : null;
    }

    private function detectMimeType(string $filePath): string
    {
        if (function_exists('wp_get_image_mime')) {
            $mimeType = wp_get_image_mime($filePath);
            if (is_string($mimeType) && $mimeType !== '') return strtolower($mimeType);
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                    return strtolower($mimeType);
                }
            }
        }

        $fileType = wp_check_filetype($filePath);

        return !empty($fileType['type']) ? strtolower((string) $fileType['type']) : '';
    }

    private function isSupported(string $filePath, string $mimeType): bool
    {
        if (in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) return true;

        // A known image type that is not on the list (avif, heic, tiff, ...) always needs conversion.
        if (strpos($mimeType, 'image/') === 0) return false;

        // Unknown mime type: fall back to the extension so nothing that worked before is converted.
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    private function makeTempPath(): string
    {
        return trailingslashit(sys_get_temp_dir()) . uniqid('basalam_image_', true) . '.jpg';
    }

    private function deleteFile(string $path): void
    {
        if ($path !== '' && file_exists($path)) unlink($path);
    }
}
