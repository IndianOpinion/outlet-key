<?php

/**
 * Use a Public Suffix List file instead of the built-in suffix rule. The
 * library never downloads it: fetch https://publicsuffix.org/list/public_suffix_list.dat
 * yourself, pin it, and pass the path.
 *
 *     php examples/public-suffix-list.php /path/to/public_suffix_list.dat
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\OutletKey\OutletResolver;
use IndianOpinion\OutletKey\PublicSuffixList;

$path = $argv[1] ?? __DIR__ . '/../tests/fixtures/public_suffix_list.sample.dat';
$psl = PublicSuffixList::fromFile($path);
$withList = new OutletResolver(null, [], $psl);
$builtIn = new OutletResolver();

printf("%d rules from %s\n\n", $psl->count(), $path);
foreach (['www.city.kawasaki.jp', 'news.example.foo.kawasaki.jp', 'www.bbc.co.uk'] as $host) {
    printf("%-30s built-in %-25s list %s\n", $host, $builtIn->outletKey($host), $withList->outletKey($host));
}
