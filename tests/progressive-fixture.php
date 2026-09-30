<?php
/** Synthetic render-only fixture. Run with wp eval-file; no stored options change. */
declare(strict_types=1);
if (!defined('WP_CLI') || !WP_CLI) exit(1);
use ShootCalInstagramFeed\{Config, Shortcode};
use const ShootCalInstagramFeed\{OPTION_KEY, CACHE_KEY};
$options = Config::defaults();
$options['instagram_account_id'] = '123456';
$cache = ['account' => ['id' => '123456', 'username' => 'preview'], 'fetched_at' => 1787910000, 'items' => []];
for ($i = 1; $i <= 30; $i++) {
    $cache['items'][] = ['id' => (string)$i, 'caption' => 'Preview image ' . $i, 'hashtags' => [],
        'image_url' => '/qa/instagram-tile.svg?image=' . $i, 'permalink' => 'https://www.instagram.com/p/Preview' . $i . '/',
        'timestamp' => '2026-09-01T12:00:00Z', 'media_type' => 'IMAGE', 'product_type' => 'FEED'];
}
add_filter('pre_option_' . OPTION_KEY, static fn() => $options);
add_filter('pre_option_' . CACHE_KEY, static fn() => $cache);
$fixtures = [];
for ($columns = 1; $columns <= 6; $columns++) {
    $fixtures[$columns] = (new Shortcode())->render(['limit' => 6, 'columns' => $columns, 'more' => 'true', 'dynamic' => 'false']);
    for ($initial = 1; $initial <= 6; $initial++) {
        $fixtures[$columns . '-' . $initial] = (new Shortcode())->render(['limit' => $initial, 'columns' => $columns, 'more' => 'true', 'dynamic' => 'false']);
    }
}
$fixtures['phone'] = (new Shortcode())->render(['limit' => 5, 'mobile_limit' => 4, 'columns' => 5, 'more' => 'true', 'dynamic' => 'false']);
echo wp_json_encode($fixtures);
