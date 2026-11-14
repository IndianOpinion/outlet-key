<?php

/**
 * Prints the keys of every fixture line, using only bootstrap.php. Run by
 * DeterminismTest under `php -n`, which loads no extensions from php.ini,
 * so neither ext-intl nor ext-mbstring is available.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\OutletKey\ArticleKeyer;

$keyer = new ArticleKeyer();
$resolver = $keyer->resolver();
foreach (['outlets.tsv', 'articles.tsv'] as $name) {
    $lines = file(__DIR__ . '/fixtures/' . $name, FILE_IGNORE_NEW_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $input = explode("\t", $line)[0];
        echo $resolver->outletKey($input), "\t", $keyer->articleKey($input), "\n";
    }
}
echo 'extensions:', extension_loaded('intl') ? ' intl' : '', extension_loaded('mbstring') ? ' mbstring' : '', "\n";
