<?php

/**
 * Report map load time, per-URL key time and memory on 100,000 mixed URLs.
 *
 *     php examples/benchmark.php [count]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;

$count = max(1, (int) ($argv[1] ?? 100000));

// The first load also autoloads and compiles the classes (opcache makes
// that free in production); the second is the steady cost of reading and
// validating the file.
$t = hrtime(true);
$map = OutletMap::fromFile(OutletMap::bundledPath());
$coldMs = (hrtime(true) - $t) / 1e6;
$t = hrtime(true);
$map = OutletMap::fromFile(OutletMap::bundledPath());
$loadMs = (hrtime(true) - $t) / 1e6;

$resolver = new OutletResolver($map);
$keyer = new ArticleKeyer($resolver);

$templates = [
    'https://m.economictimes.com/news/x%d?utm_source=feed',
    'https://auto.economictimes.indiatimes.com/news/amazon-to-bet-%d-billion-on-india-cloud-push/articleshow/123456.cms',
    'https://timesofindia.indiatimes.com/india/a/articleshow/%d.cms',
    'https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/news/%d',
    'https://hindi.news18.com/news/x-%d/amp/',
    'https://www.ndtv.com/india-news/some-headline-%d',
    'https://pib.gov.in/PressReleasePage.aspx?PRID=%d&page=2',
    'https://www.example.co.in/%%E0%%A4%%B9/%d?id=5&ref=x#frag',
];
$urls = [];
for ($i = 0; $i < $count; $i++) {
    $urls[] = sprintf($templates[$i % count($templates)], 100000 + $i);
}

// Warm up: autoload, PCRE cache.
foreach (array_slice($urls, 0, 1000) as $url) {
    $keyer->articleKey($url);
}

$t = hrtime(true);
foreach ($urls as $url) {
    $resolver->outletKey($url);
}
$outletUs = (hrtime(true) - $t) / 1e3 / $count;

$t = hrtime(true);
foreach ($urls as $url) {
    $keyer->articleKey($url);
}
$articleUs = (hrtime(true) - $t) / 1e3 / $count;

printf("PHP %s, %d URLs\n", PHP_VERSION, $count);
printf("map load, first        %.2f ms   (includes class loading)\n", $coldMs);
printf("map load and validate  %.2f ms   (budget 5 ms)\n", $loadMs);
printf("outletKey              %.2f us   (budget 20 us)\n", $outletUs);
printf("articleKey             %.2f us   (budget 60 us)\n", $articleUs);
printf("peak memory            %.2f MB\n", memory_get_peak_usage() / 1048576);
