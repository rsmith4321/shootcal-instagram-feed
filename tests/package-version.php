<?php
/** Read-only release metadata consistency; creates no package or WordPress state. */
declare(strict_types=1);
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/shootcal-instagram-feed.php');
$readme = file_get_contents($root . '/readme.txt');
preg_match('/^ \* Version:\s+(\S+)/m', $plugin, $header);
preg_match("/const VERSION\s*=\s*'([^']+)'/", $plugin, $constant);
preg_match('/^Stable tag:\s+(\S+)/m', $readme, $stable);
if (($header[1] ?? '') !== '0.6.6' || ($constant[1] ?? '') !== $header[1] || ($stable[1] ?? '') !== $header[1]) {
    throw new RuntimeException('Plugin header, runtime constant and stable tag must all identify 0.6.6.');
}
preg_match('/== Changelog ==\s+= 0\.6\.6 =\s+(.*?)(?=\n= |\z)/s', $readme, $changelog);
if (empty($changelog[1]) || str_contains($changelog[1], '—')) {
    throw new RuntimeException('The new release needs customer-facing changelog text without em dashes.');
}
$exclusions = file($root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach (['tests', 'AGENTS.md', 'shared/instagram-feed-core/verify.php'] as $excluded) {
    if (!in_array($excluded, $exclusions, true)) throw new RuntimeException('Release must exclude ' . $excluded);
}
echo "package-version: header, constant, stable tag, changelog and test exclusions passed (0.6.6)\n";
