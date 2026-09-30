<?php
declare(strict_types=1);
require_once __DIR__ . '/FeedRules.php';
use ShootCal\Instagram\V1\FeedRules;
$contract = json_decode(file_get_contents(__DIR__ . '/contract.json'), true, 512, JSON_THROW_ON_ERROR);
if (hash_file('sha256', __DIR__ . '/FeedRules.php') !== $contract['sha256']) throw new RuntimeException('Shared feed rules changed without updating the package contract.');
foreach ($contract['filters'] as [$input, $expected]) {
    if (FeedRules::normalizeList($input) !== $expected) throw new RuntimeException('Shared filter fixture failed: ' . $input);
}
$items = [
    ['id' => 1, 'hashtags' => ['wedding']],
    ['id' => 2, 'hashtags' => ['beach', 'private']],
    ['id' => 3, 'hashtags' => ['weddings']],
    ['id' => 4, 'hashtags' => ['beach']],
];
if (array_column(FeedRules::select($items, ['wedding', 'beach'], ['private']), 'id') !== [1, 4]
    || array_column(FeedRules::select($items, [], ['private'], 2), 'id') !== [1, 3]) {
    throw new RuntimeException('Shared include/exclude/order/limit contract failed.');
}
echo "Shared Instagram rules verified.\n";
