<?php

/**
 * Outlet and article keys for a few URLs.
 *
 *     php examples/basic.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\OutletKey;

$urls = [
    'https://m.economictimes.com/news/x?utm_source=feed',
    'https://auto.economictimes.indiatimes.com/news/y',
    'https://timesofindia.indiatimes.com/india/a-headline/articleshow/123456789.cms',
    'https://m.timesofindia.indiatimes.com/city/delhi/a-headline/amp_articleshow/123456789.cms',
    'https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/news/asia/x',
    'https://hindi.news18.com/news/x/',
    'https://news.google.com/rss/articles/abc',
];

printf("map version %s, scheme %d\n\n", OutletKey::mapVersion(), OutletKey::SCHEME_VERSION);
foreach ($urls as $url) {
    printf("%s\n  outlet  %s\n  article %s\n", $url, OutletKey::outlet($url), OutletKey::article($url));
}
