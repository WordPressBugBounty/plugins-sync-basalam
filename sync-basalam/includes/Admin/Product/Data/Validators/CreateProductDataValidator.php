<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

class CreateProductDataValidator
{
    public function validate(array $productData, int $productId): array
    {
        $categoryId = $productData['category_id'] ?? null;
        if (!is_numeric($categoryId) || intval($categoryId) <= 0) {
            return [
                'valid' => false,
                'message' => 'دسته بندی معتبر نیست، از قابلیت اتصال دسته بندی استفاده کنید یا نام محصول را بهبود دهید.',
            ];
        }

        $photoId = $productData['photo'] ?? null;
        if (!is_numeric($photoId) || intval($photoId) <= 0) {
            return [
                'valid' => false,
                'message' => 'آپلود تصویر اصلی محصول به باسلام ناموفق بود و شناسه تصویر تولید نشد.',
            ];
        }

        return [
            'valid' => true,
            'message' => sprintf('اطلاعات ایجاد محصول %d معتبر است.', $productId),
        ];
    }
}
