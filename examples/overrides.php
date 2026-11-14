<?php

/**
 * Extend or trim the bundled map at runtime. The merged map is validated
 * again, so a mistake raises InvalidMapException instead of corrupting keys.
 *
 *     php examples/overrides.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\InvalidMapException;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;

$map = OutletMap::bundled()->withOverrides([
    'aliases' => ['example-mirror.com' => 'example.com'],
    'articleIdRules' => ['example.com' => '~/story/([0-9]{4,})(?:/|$)~'],
    'caseInsensitivePaths' => ['example.com'],
    'remove' => ['portalBases' => ['medium.com']],
]);
$keyer = new ArticleKeyer(new OutletResolver($map));

foreach (['https://www.example-mirror.com/Story/12345/Some-Headline', 'https://someone.medium.com/x'] as $url) {
    printf("%s\n  %s\n", $url, $keyer->articleKey($url));
}

try {
    OutletMap::bundled()->withOverrides(['aliases' => ['ndtv.com' => 'ndtvnews.com']]);
} catch (InvalidMapException $e) {
    printf("\nrejected as expected: %s\n", $e->getMessage());
}
