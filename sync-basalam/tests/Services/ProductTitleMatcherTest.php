<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\Products\ProductTitleMatcher;

require_once dirname(__DIR__, 2) . '/includes/Services/Products/ProductTitleMatcher.php';

class ProductTitleMatcherTest extends TestCase
{
    public function testNormalizesArabicCharactersAndSeparators(): void
    {
        self::assertSame(
            'قفسه حمام کارین',
            ProductTitleMatcher::normalize("قفسه‌ حمام کارين")
        );
    }

    public function testAcceptsAProductCodeSuffixAsTheSameTitle(): void
    {
        self::assertSame(
            90,
            ProductTitleMatcher::score(
                'طی زمین شوی کارین مدل فانتزی کد 50',
                'طی زمین شوی کارین مدل فانتزی'
            )
        );
    }

    public function testSelectsTheStrongestSearchResult(): void
    {
        $match = ProductTitleMatcher::bestMatch(
            'طی زمین شوی کارین مدل فانتزی کد 50',
            [
                [
                    'id' => 100,
                    'title' => 'طی زمین شوی کارین مدل ساده',
                ],
                [
                    'id' => 200,
                    'title' => 'طی زمین شوی کارین مدل فانتزی',
                ],
            ]
        );

        self::assertSame(200, $match['id']);
    }

    public function testRejectsAWeakSearchResult(): void
    {
        self::assertNull(
            ProductTitleMatcher::bestMatch(
                'آب مرکبات گیر دستی',
                [
                    [
                        'id' => 100,
                        'title' => 'پنکه برقی و شارژی ریموت دار',
                    ],
                ]
            )
        );
    }
}
