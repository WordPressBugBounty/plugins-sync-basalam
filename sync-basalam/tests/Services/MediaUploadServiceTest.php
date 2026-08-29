<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SyncBasalam\Services\MediaMimeType;
use SyncBasalam\Services\MediaUploadService;

class MediaUploadServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['sync_basalam_test_image_mime'],
            $GLOBALS['sync_basalam_test_filetype_mime']
        );
    }

    public function testJpegContentWinsOverNonStandardWordPressMimeAlias(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'woosalam_jpeg_');
        self::assertNotFalse($filePath);

        // A complete one-pixel JPEG image. Its temporary path deliberately has no .jpg extension.
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=',
            true
        );
        self::assertNotFalse($jpeg);
        file_put_contents($filePath, $jpeg);

        $GLOBALS['sync_basalam_test_image_mime'] = false;
        $GLOBALS['sync_basalam_test_filetype_mime'] = 'image/jpg';

        try {
            $service = (new ReflectionClass(MediaUploadService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(MediaUploadService::class, 'detectMimeType');
            $method->setAccessible(true);

            self::assertSame('image/jpeg', $method->invoke($service, $filePath));
        } finally {
            unlink($filePath);
        }
    }

    /**
     * @dataProvider mimeAliasProvider
     */
    public function testUploadioMimeAliasesAreCanonicalized(string $input, string $expected): void
    {
        self::assertSame($expected, MediaMimeType::canonicalize($input));
    }

    public function mimeAliasProvider(): array
    {
        return [
            'jpg alias' => ['image/jpg', 'image/jpeg'],
            'progressive jpg alias' => ['image/pjpeg', 'image/jpeg'],
            'png alias' => ['image/x-png', 'image/png'],
            'Microsoft bmp alias' => ['image/x-ms-bmp', 'image/bmp'],
            'Windows bmp alias' => ['image/x-windows-bmp', 'image/bmp'],
        ];
    }
}
