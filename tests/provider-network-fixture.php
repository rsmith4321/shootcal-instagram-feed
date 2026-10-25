<?php
/** Real cache-read/shortcode regression and paired browser fixture. All HTTP is rejected. */
declare(strict_types=1);
if (!defined('WP_CLI') || !WP_CLI) exit(1);
use ShootCalInstagramFeed\{Config, Image_Cache, Shortcode};
use const ShootCalInstagramFeed\{OPTION_KEY, CACHE_KEY};
require_once __DIR__ . '/completed-image-fixture.php';
$options = Config::defaults(); $options['instagram_account_id'] = '17841499990000123';
$cache = ['account' => ['id' => $options['instagram_account_id'], 'username' => 'fixture.account'], 'fetched_at' => 1792886400, 'items' => []];
for ($i = 1; $i <= 30; ++$i) {
    $cache['items'][] = ['id' => 'no-provider-' . $i, 'caption' => 'Synthetic portrait ' . $i, 'hashtags' => ['fixture'],
        'image_url' => 'https://safe.cdninstagram.com/fixture-' . $i . '.jpg', 'permalink' => 'https://www.instagram.com/p/Preview' . $i . '/',
        'timestamp' => '2026-10-01T12:00:00Z', 'media_type' => $i === 1 ? 'VIDEO' : ($i === 2 ? 'CAROUSEL_ALBUM' : 'IMAGE'), 'product_type' => 'FEED'];
}
$fixture = shootcal_completed_image_fixture($cache);
$manifest = $fixture['manifest']; $property = new ReflectionProperty(Image_Cache::class, 'manifest');
$option_filter = static fn() => $options; $cache_filter = static fn() => $cache;
$image_filter = static function() use (&$manifest) { return $manifest; };
$http_calls = 0;
$http_filter = static function() use (&$http_calls) { ++$http_calls; throw new RuntimeException('Rendering attempted HTTP'); };
add_filter('pre_option_' . OPTION_KEY, $option_filter);
add_filter('pre_option_' . CACHE_KEY, $cache_filter);
add_filter('pre_option_' . Image_Cache::OPTION, $image_filter);
add_filter('pre_http_request', $http_filter);
$fixtures = []; $checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($label); };
$key = static fn(int $i): string => hash('sha256', $options['instagram_account_id'] . ':no-provider-' . $i);
try {
    foreach (['completed', 'partial', 'empty', 'missingManifest', 'missingFile', 'missingVariant', 'badVariant'] as $mode) {
        $manifest = $fixture['manifest']; $omitted = []; $removed = null;
        if ($mode === 'partial') { $omitted = [1, 4, 21]; foreach ($omitted as $i) unset($manifest['items'][$key($i)]); }
        if ($mode === 'empty') { $manifest = ['items' => []]; $omitted = range(1, 30); }
        if ($mode === 'missingManifest') { $manifest = []; $omitted = range(1, 30); }
        if ($mode === 'missingFile') {
            $removed = $fixture['files'][1]; $bytes = file_get_contents($removed); unlink($removed); $omitted = [2];
        }
        if ($mode === 'missingVariant') { $manifest['items'][$key(5)]['variants'][] = ['file' => $key(5) . '-640.jpg', 'width' => 640, 'height' => 400]; $omitted = [5]; }
        if ($mode === 'badVariant') { $manifest['items'][$key(3)]['variants'][0]['file'] = $key(2) . '-320.jpg'; $omitted = [3]; }
        $property->setValue(null, null);
        try {
            $html = (new Shortcode())->render(['hashtag' => 'fixture', 'limit' => 6, 'mobile_limit' => 4, 'columns' => 5, 'more' => 'true', 'follow' => 'true']);
            $static = (new Shortcode())->render(['hashtag' => 'fixture', 'limit' => 6, 'columns' => 5, 'more' => 'false']);
            $expected = array_values(array_filter(range(1, 30), static fn(int $i): bool => !in_array($i, $omitted, true)));
            $total = count($expected);
            $initial = preg_replace('~<template[^>]*>.*?</template>~s', '', $html);
            $check(!preg_match('~(?:cdninstagram|fbcdn|graph\.facebook|graph\.instagram)~i', $html . $static), $mode . ': no provider address in any initial or deferred HTML');
            $check(substr_count($html, '<a class="shootcal-instagram-feed__item') === $total, $mode . ': count only completed posts');
            $check(substr_count($initial, '<img ') === min(6, $total) && substr_count($html, '<template ') === max(0, $total - 6), $mode . ': initial and deferred counts after readiness');
            $check(substr_count($static, '<img ') === min(6, $total), $mode . ': static limit fills from completed posts');
            preg_match_all('~href="https://www.instagram.com/p/Preview(\d+)/"~', $html, $links);
            $check(array_map('intval', $links[1]) === $expected, $mode . ': completed source order preserved');
            $check(substr_count($html, '<img ') === $total && !str_contains($html, 'src=""'), $mode . ': no empty tiles');
            $check($http_calls === 0, $mode . ': no render-time download');
            $items = array_map(static fn(int $i): array => ['id' => (string)$i, 'alt' => 'Synthetic portrait ' . $i, 'permalink' => 'https://www.instagram.com/p/Preview' . $i . '/'], $expected);
            $fixtures[$mode] = ['html' => $html, 'total' => $total, 'initial' => 6, 'mobileInitial' => 4, 'columns' => 5, 'items' => $items];
        } finally { if ($removed !== null) file_put_contents($removed, $bytes); }
    }
    $fixtures['_checks'] = $checks;
    $fixtures['assets'] = ['css' => file_get_contents(dirname(__DIR__) . '/assets/feed.css'), 'js' => file_get_contents(dirname(__DIR__) . '/assets/feed.js')];
    echo wp_json_encode($fixtures, JSON_THROW_ON_ERROR);
} finally {
    remove_filter('pre_option_' . OPTION_KEY, $option_filter);
    remove_filter('pre_option_' . CACHE_KEY, $cache_filter);
    remove_filter('pre_option_' . Image_Cache::OPTION, $image_filter);
    remove_filter('pre_http_request', $http_filter);
    shootcal_completed_image_cleanup($fixture);
}
