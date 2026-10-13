<?php
/** Standalone synthetic renderer regression. No WordPress bootstrap/autoloader, real configuration or store. */
declare(strict_types=1);

namespace {
    function fixture_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function absint($value): int { return abs((int) $value); }
    function shortcode_atts(array $defaults, array $raw, string $tag): array {
        if ($tag !== 'shootcal_instagram_feed') throw new \RuntimeException('Unexpected shortcode');
        return array_replace($defaults, array_intersect_key($raw, $defaults));
    }
    function sanitize_html_class(string $value): string { return preg_replace('/[^A-Za-z0-9_-]/', '', $value); }
    function esc_attr(string $value): string { return fixture_escape($value); }
    function esc_url(string $value): string { return fixture_escape($value); }
    function esc_html(string $value): string { return fixture_escape($value); }
    function __(string $value, string $domain): string {
        if ($domain !== 'shootcal-social-feed') throw new \RuntimeException('Unexpected translation domain');
        return $value;
    }
    function esc_html_e(string $value, string $domain): void { echo esc_html(__($value, $domain)); }
    function esc_attr_e(string $value, string $domain): void { echo esc_attr(__($value, $domain)); }
    function wp_unique_id(string $prefix): string { static $id = 0; return $prefix . ++$id; }
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
    function wp_trim_words(string $value, int $limit, string $suffix): string {
        $words = preg_split('/\s+/', trim($value));
        return count($words) <= $limit ? $value : implode(' ', array_slice($words, 0, $limit)) . $suffix;
    }
    function get_option(string $key): string {
        if ($key !== 'date_format') throw new \RuntimeException('Unexpected option access');
        return 'Y-m-d';
    }
    function wp_date(string $format, int $timestamp): string { return gmdate($format, $timestamp); }
    function current_user_can(string $capability): bool { throw new \RuntimeException('Unexpected admin/empty path'); }
    function rest_url(string $route): string { throw new \RuntimeException('Unexpected dynamic endpoint path'); }
    function add_shortcode(string $tag, $callback): void { throw new \RuntimeException('Unexpected registration'); }
}

namespace ShootCalInstagramFeed {
    final class Config {
        public static function get(): array {
            return ['default_hashtag' => '', 'display_limit' => 1, 'columns' => 1, 'instagram_account_id' => 'synthetic-account'];
        }
    }
    final class Feed_Store {
        public static array $cache = [];
        public static int $calls = 0;
        public static function cache_for_account(string $id): array {
            if ($id !== 'synthetic-account') throw new \RuntimeException('Unexpected account');
            ++self::$calls;
            return self::$cache;
        }
    }
    final class Hashtag_Filter {
        public static function normalize_list(string $value): ?array { return \ShootCal\Instagram\V1\FeedRules::normalizeList($value); }
        public static function filter_list(array $items, array $include, array $exclude): array { return \ShootCal\Instagram\V1\FeedRules::select($items, $include, $exclude); }
    }
    final class Image_Cache {
        public static int $calls = 0;
        public static function attributes(string $account, array $item, int $columns): array {
            if ($account !== 'synthetic-account' || ($columns !== 1 && (!defined('SCIF_TABLET_FIXTURE') || $columns < 1 || $columns > 6))) throw new \RuntimeException('Unexpected image arguments');
            ++self::$calls;
            return ['src' => $item['image_url'], 'srcset' => ''];
        }
    }
    final class Assets {
        public static int $calls = 0;
        public static function enqueue(): void { ++self::$calls; }
        public static function enqueue_dynamic(): void { if (!defined('SCIF_TABLET_FIXTURE')) throw new \RuntimeException('Unexpected asset/dynamic path'); }
    }
    final class Feeds {
        public static function get(int $id): ?array { throw new \RuntimeException('Unexpected saved-feed path'); }
        public static function to_shortcode_attributes(array $preset): array { throw new \RuntimeException('Unexpected preset'); }
    }
}

namespace {
    // Explicit paths only: caller must pass the exact reviewed plugin root, no discovery/fallback.
    if ($argc !== 2 && !($argc === 3 && $argv[2] === '--tablet-fixtures')) { fwrite(STDERR, "Usage: php fixture.php /exact/plugin/root [--tablet-fixtures]\n"); exit(2); }
    $root = rtrim($argv[1], '/');
    if (!is_dir($root)) { fwrite(STDERR, "Missing explicit plugin root\n"); exit(2); }
    define('ABSPATH', __DIR__ . '/inert-no-wordpress/');
    require $root . '/shared/instagram-feed-core/FeedRules.php';
    require $root . '/includes/class-shortcode.php';

    if (($argv[2] ?? '') === '--tablet-fixtures') {
        define('SCIF_TABLET_FIXTURE', true);
        \ShootCalInstagramFeed\Feed_Store::$cache = ['account' => ['id' => 'synthetic-account', 'username' => 'fixture.account'], 'items' => []];
        for ($i = 0; $i < 12; $i++) {
            \ShootCalInstagramFeed\Feed_Store::$cache['items'][] = ['media_type' => 'IMAGE', 'caption' => 'Synthetic tile',
                'image_url' => 'https://images.invalid/tile-' . $i . '.jpg', 'permalink' => 'https://www.instagram.com/p/synthetic_' . $i . '/'];
        }
        $fixtures = [];
        foreach ([1, 2, 3, 5] as $columns) {
            $fixtures[(string)$columns] = (new \ShootCalInstagramFeed\Shortcode())->render(['limit' => 5, 'mobile_limit' => 4, 'columns' => $columns, 'dynamic' => 'false', 'more' => 'true', 'follow' => 'false']);
            $fixtures['static-' . $columns] = (new \ShootCalInstagramFeed\Shortcode())->render(['limit' => 4, 'columns' => $columns, 'dynamic' => 'false', 'more' => 'false', 'follow' => 'false']);
        }
        echo json_encode($fixtures, JSON_THROW_ON_ERROR);
        exit(0);
    }

    $checks = 0; $failures = 0;
    $check = static function (bool $ok, string $case, string $message) use (&$checks, &$failures): void {
        ++$checks;
        if (!$ok) { ++$failures; fwrite(STDERR, "FAIL {$case}: {$message}\n"); }
    };
    $cases = [
        ['VIDEO', 'VIDEO', 'FEED', ' (video)', true],
        ['REELS', 'IMAGE', 'REELS', ' (video)', true],
        ['IMAGE', 'IMAGE', 'FEED', '', false],
        ['CAROUSEL_ALBUM', 'CAROUSEL_ALBUM', 'FEED', ' (multiple photos)', true],
    ];
    try {
        foreach ($cases as [$case, $media, $product, $suffix, $indicator]) {
            $beforeFailures = $failures;
            $permalink = 'https://www.instagram.com/p/synthetic-' . $case . '/';
            $thumbnail = 'https://images.invalid/synthetic-' . $case . '.jpg';
            \ShootCalInstagramFeed\Feed_Store::$cache = [
                'account' => ['id' => 'synthetic-account', 'username' => 'fixture.account'],
                'items' => [['media_type' => $media, 'product_type' => $product, 'caption' => 'Synthetic caption',
                    'image_url' => $thumbnail, 'permalink' => $permalink, 'timestamp' => '2026-10-01T12:00:00Z']],
            ];
            \ShootCalInstagramFeed\Feed_Store::$calls = \ShootCalInstagramFeed\Image_Cache::$calls = \ShootCalInstagramFeed\Assets::$calls = 0;
            $html = (new \ShootCalInstagramFeed\Shortcode())->render(['limit' => 1, 'columns' => 1, 'dynamic' => 'false', 'more' => 'false', 'follow' => 'false']);
            preg_match_all('/<a\b[^>]*class="shootcal-instagram-feed__item"[^>]*>/', $html, $anchors);
            $anchor = $anchors[0][0] ?? '';
            preg_match('/<span\b[^>]*class="shootcal-instagram-feed__type[^\"]*"[^>]*>.*?<\/span>/s', $html, $badgeMatch);
            $badge = $badgeMatch[0] ?? null;
            $check(count($anchors[0]) === 1, $case, 'one rendered post');
            $check(str_contains($anchor, 'href="' . fixture_escape($permalink) . '"'), $case, 'cached permalink preserved');
            $check(str_contains($html, 'src="' . fixture_escape($thumbnail) . '"'), $case, 'cached thumbnail preserved');
            $check(str_contains($anchor, 'aria-label="View Instagram post by @fixture.account from 2026-10-01' . $suffix . '"'), $case, 'account/date prefix plus desired media suffix');
            $check(($badge !== null) === $indicator, $case, 'media indicator presence');
            $check($case === 'IMAGE' ? $badge === null : ($case === 'CAROUSEL_ALBUM' ? str_contains($badge ?? '', '<svg') : str_contains($badge ?? '', '>Video</span>')), $case, 'media indicator content');
            $check($badge === null || str_contains($badge, 'aria-hidden="true"'), $case, 'present badge decorative');
            $check(\ShootCalInstagramFeed\Assets::$calls === 1, $case, 'inert asset enqueue only');
            $check(\ShootCalInstagramFeed\Image_Cache::$calls === 1, $case, 'inert thumbnail adapter once');
            $check(\ShootCalInstagramFeed\Feed_Store::$calls === 1, $case, 'synthetic cache once');
            echo ($failures === $beforeFailures ? 'PASS ' : 'FAIL ') . $case . "\n";
        }
    } catch (\Throwable $error) {
        fwrite(STDERR, 'STOP unexpected fixture path: ' . $error->getMessage() . "\n"); exit(2);
    }
    echo "RESULT cases=4 checks={$checks} failures={$failures}\n";
    exit($failures === 0 ? 0 : 1);
}
