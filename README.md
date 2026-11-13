# outlet-key

outlet-key tells you which newsroom owns a news URL and which article it is. Mobile, AMP, regional, language and legacy hosts of one outlet give one outlet key, sibling titles under one parent domain stay separate, and the same article reached through an AMP path, a tracking query or a trailing slash gives one article key.

Measured on PHP 8.3, one core, 100,000 mixed URLs (`examples/benchmark.php`): the bundled map loads and validates in under 1 ms, `outletKey` takes about 9 µs and `articleKey` about 25 µs per URL.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Install

With Composer:

```sh
composer require indianopinion/outlet-key
```

Without Composer, copy or clone the repository and require the bootstrap file. It registers an autoloader for the `IndianOpinion\OutletKey` namespace and nothing else.

```php
require '/path/to/outlet-key/bootstrap.php';
```

Requirements: PHP 8.1 or later. No runtime dependencies. `ext-intl` and `ext-mbstring` are not used, so keys never depend on which extensions are loaded.

## Quick start

```php
use IndianOpinion\OutletKey\OutletKey;

OutletKey::outlet('https://m.economictimes.com/news/x');
// economictimes.indiatimes.com

OutletKey::outlet('https://timesofindia.indiatimes.com/india/x');
// timesofindia.indiatimes.com (a separate newsroom under the same portal)

OutletKey::article('https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/news/x/amp?utm_source=feed');
// scmp.com/news/x

OutletKey::article('https://www.ndtv.com/india-news/some-headline-1234567');
// ndtv.com/a/1234567 (an id rule: survives a change of section or slug)
```

Use the classes when you need your own map, overrides, your own site or a Public Suffix List:

```php
use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;

$map = OutletMap::bundled()->withOverrides([
    'aliases' => ['example-mirror.com' => 'example.com'],
]);
$resolver = new OutletResolver($map, ['www.my-site.example']);
$keyer = new ArticleKeyer($resolver);

$key = $resolver->outletKey($url);
if ($key !== '' && !$resolver->isAggregator($key) && !$resolver->isOwnOutlet($key)) {
    // a newsroom that can corroborate a story
}
```

The empty string is the empty key. It means "unknown" and is never an outlet. `sameOutlet('', '')` is false.

Command line:

```sh
vendor/bin/outlet-key 'https://m.economictimes.com/news/x/amp?utm_source=feed'
# economictimes.indiatimes.com	economictimes.indiatimes.com/news/x

vendor/bin/outlet-key --explain 'https://m.economictimes.com/news/x/amp'
cat urls.txt | vendor/bin/outlet-key -
vendor/bin/outlet-key validate path/to/outlets.json
```

`validate` exits 0 for a valid map, 1 for an invalid one and 2 for a usage error. Messages go to stderr. `--map <path>` keys with another map and `--psl <path>` with a Public Suffix List file.

More in [examples/](examples/): counting corroborating newsrooms, overrides, hashed storage, a Public Suffix List and the benchmark.

## Public interface

| class | what it does |
|---|---|
| `OutletKey` | static facade over the bundled map: `outlet()`, `article()`, `outletHash()`, `articleHash()`, `mapVersion()`, `explain()`, constant `SCHEME_VERSION` |
| `OutletResolver` | `outletKey()`, `host()`, `registrableDomain()`, `isAggregator()`, `isOwnOutlet()`, `sameOutlet()`, `outletHash()`, `explain()` |
| `ArticleKeyer` | `articleKey()`, `slugKey()`, `sameArticle()`, `articleHash()`, `explain()` |
| `OutletMap` | `bundled()`, `fromFile()`, `fromJson()`, `fromArray()`, `withOverrides()`, `version()`, `toArray()` |
| `PublicSuffixList` | optional: `fromFile()`, `fromString()` |
| `Trace` | read-only list of `{step, value, note}`, plus `toArray()`, `find()` and `__toString()` |
| `KeyHash` | sha256 hex of a key, covering `SCHEME_VERSION` and the kind of key |

Exceptions all extend `OutletKeyException` (a `RuntimeException`): `InvalidMapException`, `InvalidSuffixListException`, `InputTooLongException`, `RuleRuntimeException`.

## Configuration

| setting | default | where |
|---|---|---|
| map | bundled `data/outlets.json` | `OutletResolver` constructor, CLI `--map` |
| overrides | none | `OutletMap::withOverrides()` |
| own hosts | none | `OutletResolver` constructor, second argument |
| Public Suffix List | none (the map's suffix rule) | `OutletResolver` constructor, third argument, CLI `--psl` |
| max input length | 8192 bytes | constant |
| AMP cache suffix | `.cdn.ampproject.org` | constant |

No environment variables. No global state except the memoised bundled map.

Overrides union lists, merge tables with your entries winning, and delete entries listed under `remove`. The merged map is validated again, so a mistake raises `InvalidMapException` instead of quietly changing keys.

## How it works

Outlet key, in order:

1. Prepare the input: trim, decode HTML entities once, drop the scheme, user info, port and fragment. Lowercase the host, drop one trailing dot, convert IDN labels to A-labels with a built-in Punycode encoder. Unwrap a Google AMP cache URL once.
2. Strip mirror labels (`www`, `m`, `amp` and the rest of the map's list) from the left, never below two labels.
3. Pass-through hosts such as `news.google.com` are kept whole.
4. Take the registrable domain: the map's explicit suffixes, then two-letter country codes with a known second level (`bbc.co.uk`, `pib.gov.in`), then the last two labels.
5. Portal bases (`indiatimes.com`, `blogspot.com` and others) keep the one label to their left as a separate publication, unless it is a language label.
6. Look the result up once in the alias table (`ndtv.in` gives `ndtv.com`).

IPv4 and IPv6 literals are returned as they are. An unusable host gives the empty key.

Article key: the outlet key, then the path with AMP markers, index files, repeated and trailing slashes removed, then the kept query. Tracking parameters always go. On a slug path only id parameters (`id`, `p`, `story` and the rest) survive. On a script path (`/`, `.php`, `.aspx` and others) every non-tracking parameter survives. Outlets with an id rule key as `outlet/a/<id>`, and slug outlets key as `outlet/s/<slug key>`.

`explain()` lists every one of these decisions. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the modules and the decisions behind them, and [docs/PRD.md](docs/PRD.md) for every case.

## Storing keys

A code change that alters any key bumps `OutletKey::SCHEME_VERSION`. A map release bumps the map's `version`. Keys move when either changes, so store both beside the key, or store `KeyHash` digests (which cover the scheme version) with the map version beside them. During a transition, check the old and the new key, or duplicates slip through for everything stored before the change.

## Limitations

- No network access, ever. Redirect wrappers (Google News `articles/` links, link shorteners) are not unwrapped; resolve them first.
- The registrable-domain rule is small and explicit. Pass a Public Suffix List if you need the full one; only its ICANN section is used unless you ask for the private section too.
- Non-ASCII letters in hosts and paths are not case-folded or NFC-normalised. Feeds emit them consistently in practice.
- Paths keep their case unless the outlet is listed in `caseInsensitivePaths`. AMP and index markers match in any case.
- Query values on script paths are kept. If you key URLs that carry tokens in the query, strip them first.
- A map is code: its patterns run on every URL. Load maps only from sources you trust.
- Aggregators keep their own outlet key. Exclude them yourself when you count corroborating newsrooms (`isAggregator()`).

## FAQ

**Why is `timesofindia.indiatimes.com` not `indiatimes.com`?** `indiatimes.com` is a portal base: the label to its left names a separate newsroom. Counting by registrable domain would merge two newsrooms.

**Why does `hindi.news18.com` give `news18.com` without a map entry?** Unmapped hosts fall back to their registrable domain. Most of the world needs no entry.

**A URL got the wrong key. What now?** Run `outlet-key --explain <url>` to see which step decided, then open an issue with the outlet map entry template. See [CONTRIBUTING.md](CONTRIBUTING.md).

**Why is there no `countOutlets()`?** Counting is a caller policy: whether to drop aggregators, your own site, or duplicate articles first. `examples/count-corroboration.php` shows the pattern in a dozen lines.

**Does it need `ext-intl`?** No. IDN conversion is built in, and a test proves the output is the same under `php -n`.

## Contributing

Map entries are the most useful contribution and need no PHP. See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues as described in [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE](LICENSE).
