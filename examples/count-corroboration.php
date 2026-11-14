<?php

/**
 * Count the distinct newsrooms behind a story. The library does not count
 * for you; this is the pattern it is built for: key every URL, drop unknown
 * keys, aggregators and your own site, collapse duplicate articles, then
 * count distinct outlets.
 *
 *     php examples/count-corroboration.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletResolver;

$resolver = new OutletResolver(null, ['example.org']);
$keyer = new ArticleKeyer($resolver);

$sources = [
    'https://m.economictimes.com/tech/amazon-to-bet-3-billion-on-india-cloud-push/articleshow/111111.cms',
    'https://tech.economictimes.indiatimes.com/news/amazon-bets-three-billion-on-india-cloud-push/articleshow/222222.cms',
    'https://timesofindia.indiatimes.com/business/amazon-cloud/articleshow/333333333.cms',
    'https://news.google.com/rss/articles/abc',
    'https://www.example.org/our-own-brief',
    'not a url',
];

$articles = [];
foreach ($sources as $url) {
    $outlet = $resolver->outletKey($url);
    if ($outlet === '' || $resolver->isAggregator($outlet) || $resolver->isOwnOutlet($outlet)) {
        continue;
    }
    $articles[$keyer->articleKey($url)] = $outlet;
}
$outlets = array_unique(array_values($articles));
sort($outlets);

printf("%d distinct articles from %d newsrooms: %s\n", count($articles), count($outlets), implode(', ', $outlets));
