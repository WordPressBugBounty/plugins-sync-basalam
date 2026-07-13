<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

class DescriptionErrorSanitizer
{
    private const SEPARATOR = '[\s\x{200c}\x{00a0}\-\_\.\–\—]*';

    public static function extractDescriptionValues(?array $responseData): array
    {
        if (!is_array($responseData)) return [];

        $messages = $responseData['messages'] ?? null;
        if (!is_array($messages)) return [];

        $values = [];

        foreach ($messages as $message) {
            if (!is_array($message)) continue;

            $fields = $message['fields'] ?? [];
            if (!is_array($fields) || !in_array('description', $fields, true)) continue;

            $detected = $message['detected'] ?? [];
            if (!is_array($detected)) continue;

            foreach ($detected as $item) {
                foreach (self::valuesFromDetectedItem($item) as $value) {
                    $values[$value] = $value;
                }
            }
        }

        $values = array_values($values);
        usort($values, static function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        return $values;
    }

    private static function valuesFromDetectedItem($item): array
    {
        if (is_string($item)) {
            $item = trim($item);
            return $item !== '' ? [$item] : [];
        }

        if (!is_array($item)) return [];

        $found = [];

        $snippet = $item['snippet'] ?? '';
        if (is_string($snippet) && $snippet !== '') {
            if (preg_match_all('/<em>(.*?)<\/em>/isu', $snippet, $matches)) {
                foreach ($matches[1] as $match) {
                    $value = trim(wp_strip_all_tags(html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($value !== '') $found[] = $value;
                }
            }
        }

        $value = $item['value'] ?? '';
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') $found[] = $value;
        }

        return $found;
    }

    public static function sanitize(string $description, array $values): string
    {
        $result = $description;

        foreach ($values as $value) {
            if (!is_string($value)) continue;
            $result = self::removeValue($result, $value);
        }

        $result = preg_replace('/[ \t]{2,}/u', ' ', $result);
        $result = preg_replace('/\n{3,}/u', "\n\n", $result);

        return trim($result);
    }

    private static function removeValue(string $text, string $value): string
    {
        $value = trim($value);
        if ($value === '') return $text;

        $pattern = self::buildPattern($value);
        if ($pattern !== null) {
            $replaced = preg_replace($pattern, ' ', $text);
            if (is_string($replaced) && $replaced !== $text) return $replaced;
        }

        $replaced = str_ireplace($value, ' ', $text);

        return is_string($replaced) ? $replaced : $text;
    }

    private static function buildPattern(string $value): ?string
    {
        $segments = preg_split('/[\s\x{200c}\x{00a0}\-\_\.\–\—]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($segments)) return null;

        $escaped = array_map(static function ($segment) {
            return preg_quote($segment, '/');
        }, $segments);

        $pattern = '/' . implode(self::SEPARATOR, $escaped) . '/iu';

        if (@preg_match($pattern, '') === false) return null;

        return $pattern;
    }
}
