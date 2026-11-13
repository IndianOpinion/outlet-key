<?php

/**
 * Store a fixed-width hash of the key, with the versions beside it, instead
 * of the raw URL.
 *
 *     php examples/hash-storage.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\OutletKey;

$url = 'https://m.economictimes.com/news/x/amp?utm_source=feed';
$row = [
    'article_hash' => OutletKey::articleHash($url),
    'outlet_hash' => OutletKey::outletHash($url),
    'scheme_version' => OutletKey::SCHEME_VERSION,
    'map_version' => OutletKey::mapVersion(),
];
echo json_encode($row, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
