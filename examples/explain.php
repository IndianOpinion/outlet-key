<?php

/**
 * Print why a URL got its keys.
 *
 *     php examples/explain.php 'https://auto.economictimes.indiatimes.com/a/amp?utm_source=x'
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\OutletKey;

$url = $argv[1] ?? 'https://auto.economictimes.indiatimes.com/a/amp?utm_source=x';
echo OutletKey::explain($url);
