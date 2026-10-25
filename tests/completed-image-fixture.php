<?php
/** Disposable synthetic uploads for local WordPress tests, never customer media. */
declare(strict_types=1);

function shootcal_completed_image_fixture(array $cache): array {
    if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', 'shootcal-plugin-dev.local'], true)) {
        throw new RuntimeException('Disposable local WordPress only');
    }
    $uploads = wp_upload_dir();
    $directory = $uploads['basedir'] . '/shootcal-social-feed';
    if (!empty($uploads['error']) || !wp_mkdir_p($directory)) throw new RuntimeException('Fixture uploads unavailable');
    $files = []; $manifest = ['items' => []];
    $image = imagecreatetruecolor(320, 200);
    imagefill($image, 0, 0, imagecolorallocate($image, 60, 120, 180));
    try {
        foreach ($cache['items'] as $item) {
            $key = hash('sha256', (string)$cache['account']['id'] . ':' . (string)$item['id']);
            $name = $key . '-320.jpg'; $path = $directory . '/' . $name;
            if (file_exists($path)) throw new RuntimeException('Refusing to replace existing fixture media');
            $files[] = $path;
            if (!imagejpeg($image, $path, 82)) throw new RuntimeException('Fixture image write failed');
            $manifest['items'][$key] = ['variants' => [['file' => $name, 'width' => 320, 'height' => 200]]];
        }
    } catch (Throwable $e) {
        foreach ($files as $path) if (is_file($path)) unlink($path);
        throw $e;
    } finally { imagedestroy($image); }
    return ['manifest' => $manifest, 'files' => $files];
}

function shootcal_completed_image_cleanup(array $fixture): void {
    foreach ($fixture['files'] ?? [] as $path) if (is_file($path)) unlink($path);
    (new ReflectionProperty(\ShootCalInstagramFeed\Image_Cache::class, 'manifest'))->setValue(null, null);
}
