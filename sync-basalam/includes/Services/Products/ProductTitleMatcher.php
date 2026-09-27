<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

/**
 * Finds a safe title match between a WooCommerce product and a Basalam result.
 *
 * Basalam's search endpoint is deliberately used before this matcher. The
 * matcher only decides whether one of those search results is close enough to
 * connect; it must not turn an arbitrary search result into a connection.
 */
final class ProductTitleMatcher
{
    private const MIN_MATCH_SCORE = 80;
    private const MIN_COMMON_TOKENS = 3;
    private const MIN_PREFIX_LENGTH = 12;

    public static function normalize(string $title): string
    {
        $title = strtr($title, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'ۀ' => 'ه',
            'ة' => 'ه',
            "‌" => ' ',
        ]);

        $title = mb_strtolower(trim($title), 'UTF-8');
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title);
        $title = preg_replace('/\s+/u', ' ', (string) $title);

        return trim((string) $title);
    }

    /**
     * Return a score from 0 to 100. Scores below 80 are intentionally not
     * considered safe enough for an automatic connection.
     */
    public static function score(string $wooTitle, string $basalamTitle): int
    {
        $wooTitle = self::normalize($wooTitle);
        $basalamTitle = self::normalize($basalamTitle);

        if ($wooTitle === '' || $basalamTitle === '') return 0;
        if ($wooTitle === $basalamTitle) return 100;

        $shortTitle = mb_strlen($wooTitle, 'UTF-8') <= mb_strlen($basalamTitle, 'UTF-8')
            ? $wooTitle
            : $basalamTitle;
        $longTitle = $shortTitle === $wooTitle ? $basalamTitle : $wooTitle;

        if (
            mb_strlen($shortTitle, 'UTF-8') >= self::MIN_PREFIX_LENGTH
            && str_starts_with($longTitle, $shortTitle)
        ) {
            return 90;
        }

        $wooTokens = array_values(array_unique(explode(' ', $wooTitle)));
        $basalamTokens = array_values(array_unique(explode(' ', $basalamTitle)));
        $commonTokens = count(array_intersect($wooTokens, $basalamTokens));

        if ($commonTokens < self::MIN_COMMON_TOKENS) return 0;

        $wooCoverage = $commonTokens / count($wooTokens);
        $basalamCoverage = $commonTokens / count($basalamTokens);
        $coverage = min($wooCoverage, $basalamCoverage);

        if ($coverage < 0.8) return 0;

        return min(89, 70 + (int) round($coverage * 20));
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<string, mixed>|null
     */
    public static function bestMatch(string $wooTitle, array $products): ?array
    {
        $bestProduct = null;
        $bestScore = 0;

        foreach ($products as $product) {
            if (!is_array($product) || empty($product['title']) || empty($product['id'])) continue;

            $score = self::score($wooTitle, (string) $product['title']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestProduct = $product;
            }
        }

        return $bestScore >= self::MIN_MATCH_SCORE ? $bestProduct : null;
    }
}
