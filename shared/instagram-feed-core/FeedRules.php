<?php
declare(strict_types=1);

namespace ShootCal\Instagram\V1;

/** Shared, IO-free feed rules. SPDX-License-Identifier: GPL-2.0-or-later */
final class FeedRules
{
    public const VERSION = '1.0.0';
    public const MAX_ITEMS = 30;
    public const MAX_COLUMNS = 6;
    public const MAX_TOKEN_CODEPOINTS = 100;
    public const MAX_TOKEN_BYTES = 255;
    public const MAX_CAPTION_BYTES = 65535;
    public const MAX_TAGS = 100;

    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (str_starts_with($value, '#')) $value = substr($value, 1);
        if ($value === '' || preg_match('/^[\p{L}\p{N}\p{M}_]+$/uD', $value) !== 1) return null;
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $length = preg_match_all('/./us', $value);
        return $length !== false && $length <= self::MAX_TOKEN_CODEPOINTS && strlen($value) <= self::MAX_TOKEN_BYTES
            ? $value : null;
    }

    /** @return list<string>|null */
    public static function normalizeList(string $value): ?array
    {
        if (strlen($value) > 26000) return null;
        $entries = preg_split('/[,\s]+/u', trim($value));
        if ($entries === false) return null;
        $tags = [];
        foreach ($entries as $entry) {
            if ($entry === '' || $entry === '#') continue;
            $tag = self::normalize($entry);
            if ($tag === null || $tag === '') return null;
            $tags[$tag] = true;
            if (count($tags) > self::MAX_TAGS) return null;
        }
        return array_map('strval', array_keys($tags));
    }

    /** @return list<string> */
    public static function extract(string $caption): array
    {
        if (strlen($caption) > self::MAX_CAPTION_BYTES) return [];
        if (!preg_match_all('/(?<![\p{L}\p{N}\p{M}_])#([\p{L}\p{N}\p{M}_]+)/u', $caption, $matches)) return [];
        $tags = [];
        foreach ($matches[1] as $entry) {
            $tag = self::normalize($entry);
            if ($tag === null || $tag === '') continue;
            $tags[$tag] = true;
            if (count($tags) >= self::MAX_TAGS) break;
        }
        return array_map('strval', array_keys($tags));
    }

    public static function matches(array $tags, array $include, array $exclude = []): bool
    {
        return ($include === [] || array_intersect($include, $tags) !== [])
            && array_intersect($exclude, $tags) === [];
    }

    /** Preserve the cache order; exclude always wins. */
    public static function select(array $items, array $include, array $exclude = [], int $limit = self::MAX_ITEMS): array
    {
        $selected = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $tags = is_array($item['hashtags'] ?? null) ? $item['hashtags'] : self::extract((string)($item['caption'] ?? ''));
            if (!self::matches($tags, $include, $exclude)) continue;
            $selected[] = $item;
            if (count($selected) >= max(1, $limit)) break;
        }
        return $selected;
    }

    public static function columns(int $columns): int
    {
        return max(1, min(self::MAX_COLUMNS, $columns));
    }
}
