<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * The bin/outlet-key command, as a class so it can be tested in-process.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    private const USAGE = <<<'TXT'
        Usage:
          outlet-key [--map <path>] [--psl <path>] <url>...   print outlet<TAB>article per URL
          outlet-key [--map <path>] [--psl <path>] -          read URLs from stdin, one per line
          outlet-key [--map <path>] [--psl <path>] --explain <url>
          outlet-key validate [path]                         validate a map (default: bundled)
          outlet-key --help | --version

        TXT;

    /** @var resource */
    private $in;
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct($in, $out, $err)
    {
        $this->in = $in;
        $this->out = $out;
        $this->err = $err;
    }

    /**
     * @param list<string> $args arguments without the program name
     */
    public function run(array $args): int
    {
        $mapPath = null;
        $pslPath = null;
        $explain = false;
        $urls = [];

        if ($args === []) {
            return $this->usage('no URL given');
        }
        if ($args[0] === 'validate') {
            if (count($args) > 2) {
                return $this->usage('validate takes at most one path');
            }

            return $this->validate($args[1] ?? null);
        }

        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $arg = $args[$i];
            if ($arg === '--help' || $arg === '-h') {
                fwrite($this->out, self::USAGE);

                return self::EXIT_OK;
            }
            if ($arg === '--version') {
                fwrite($this->out, sprintf("outlet-key scheme %d\n", OutletKey::SCHEME_VERSION));

                return self::EXIT_OK;
            }
            if ($arg === '--map' || $arg === '--psl') {
                if (!isset($args[$i + 1])) {
                    return $this->usage($arg . ' needs a path');
                }
                if ($arg === '--map') {
                    $mapPath = $args[++$i];
                } else {
                    $pslPath = $args[++$i];
                }
                continue;
            }
            if ($arg === '--explain') {
                $explain = true;
                continue;
            }
            if ($arg === '--') {
                array_push($urls, ...array_slice($args, $i + 1));
                break;
            }
            if ($arg !== '-' && strncmp($arg, '-', 1) === 0) {
                return $this->usage('unknown option ' . $arg);
            }
            $urls[] = $arg;
        }
        if ($urls === []) {
            return $this->usage('no URL given');
        }
        if ($explain && count($urls) !== 1) {
            return $this->usage('--explain takes exactly one URL');
        }

        try {
            $map = $mapPath === null ? OutletMap::bundled() : OutletMap::fromFile($mapPath);
            $psl = $pslPath === null ? null : PublicSuffixList::fromFile($pslPath);
        } catch (OutletKeyException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }
        $keyer = new ArticleKeyer(new OutletResolver($map, [], $psl));

        if ($explain) {
            try {
                fwrite($this->out, (string) $keyer->explain($urls[0] === '-' ? $this->firstLine() : $urls[0]));
            } catch (OutletKeyException $e) {
                fwrite($this->err, $e->getMessage() . "\n");

                return self::EXIT_FAILURE;
            }

            return self::EXIT_OK;
        }

        $status = self::EXIT_OK;
        foreach ($urls as $url) {
            if ($url === '-') {
                while (($line = fgets($this->in)) !== false) {
                    $line = rtrim($line, "\r\n");
                    if (trim($line) === '') {
                        continue;
                    }
                    $status = max($status, $this->keyOne($keyer, $line));
                }
                continue;
            }
            $status = max($status, $this->keyOne($keyer, $url));
        }

        return $status;
    }

    private function keyOne(ArticleKeyer $keyer, string $url): int
    {
        try {
            $outlet = $keyer->resolver()->outletKey($url);
            $article = $keyer->articleKey($url);
        } catch (OutletKeyException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }
        fwrite($this->out, $outlet . "\t" . $article . "\n");

        return self::EXIT_OK;
    }

    private function validate(?string $path): int
    {
        $label = $path ?? OutletMap::bundledPath();
        try {
            $map = $path === null ? OutletMap::fromFile(OutletMap::bundledPath()) : OutletMap::fromFile($path);
        } catch (InvalidMapException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }
        fwrite($this->err, sprintf("%s: valid, version %s\n", $label, $map->version()));

        return self::EXIT_OK;
    }

    private function firstLine(): string
    {
        $line = fgets($this->in);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private function usage(string $why): int
    {
        fwrite($this->err, 'outlet-key: ' . $why . "\n" . self::USAGE);

        return self::EXIT_USAGE;
    }
}
