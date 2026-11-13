# outlet-key architecture

This file describes how the code is laid out, how a URL becomes a key, and every decision taken while building 0.1.0 from [PRD.md](PRD.md), including the answers to the PRD's open questions.

## Modules

Public, in `src/`:

| class | role |
|---|---|
| `OutletKey` | Static facade over the bundled map, memoised per process. Holds `SCHEME_VERSION`. |
| `OutletResolver` | URL or host to outlet key (PRD 4.1 to 4.3). Holds the map, the caller's own hosts and an optional Public Suffix List. |
| `ArticleKeyer` | URL to article key (PRD 4.4). Wraps an `OutletResolver`, so both keys share one parse. |
| `OutletMap` | Immutable, validated map. `bundled()`, `fromFile()`, `fromJson()`, `fromArray()`, `withOverrides()`. |
| `PublicSuffixList` | Optional parsed `public_suffix_list.dat`. |
| `Trace` | Read-only list of `{step, value, note}` returned by `explain()`. |
| `KeyHash` | sha256 of a key for storage. |
| `Cli` | The `bin/outlet-key` command, as a class so tests run it in-process. |
| exceptions | `OutletKeyException` (base, a `RuntimeException`), `InvalidMapException`, `InvalidSuffixListException`, `InputTooLongException`, `RuleRuntimeException`. |

Internal, in `src/Internal/` (marked `@internal`, not part of the semver promise):

| class | role |
|---|---|
| `UrlParser` | Input preparation and host forms: PRD cases 1 to 13. Returns a `ParsedUrl`. |
| `ParsedUrl` | Prepared input, host, host type, raw path, raw query, AMP cache host, failure reason. |
| `HostRules` | Mirror strip, pass-through, registrable domain, portal and language rules: cases 14 to 21. |
| `PathNormaliser` | Path rewrites (cases 28 to 30) and the slug key (case 38). |
| `QueryFilter` | Hand-written query parser and keep rules: cases 33 to 35, 40, 41. |
| `MapValidator` | Schema and section 6.2 rules, each failure with a JSON pointer. |
| `MapData` | The validated map compiled into hash sets for O(1) lookups. |
| `Punycode` | RFC 3492 encoder. |
| `Utf8` | Byte-level UTF-8 helpers that need neither `ext-mbstring` nor `ext-intl`. |

Other files: `bootstrap.php` (autoloader for use without Composer), `bin/outlet-key`, `data/outlets.json` and `data/outlets.schema.json`, `tests/fixtures/*.tsv` (one line per map entry), `examples/`.

## Data flow

```
input string
  -> UrlParser::parse          length check, trim, entities, scheme, user info,
                               port, fragment, host forms, AMP unwrap
  -> ParsedUrl
       -> OutletResolver::keyParsed
            HostRules::preAliasKey    mirror strip, pass-through, registrable
                                      domain, portal, language
            alias lookup (one hop)
          -> outlet key
       -> ArticleKeyer (when an article key is wanted)
            PathNormaliser::normalise  decode, rewrites b to j
            slug rule, then id rule, then plain key
            QueryFilter::filter        plain key only
          -> article key
```

Every step appends to a trace array when `explain()` asked for one. When it did not, the trace argument is null and no strings are built, so the hot path pays nothing for tracing.

The map is read once. `OutletMap` validates it with `MapValidator`, then compiles it into `MapData`, whose lists are `array<string, true>` sets. After that there is no I/O and no linear scan except over the short tracking-prefix list.

## Decisions on the open questions

The answers below were given as rulings with the build task. The reasons are recorded with each.

1. **Public Suffix List: supported, optional, ICANN section only by default.** A caller can pass a `PublicSuffixList` to `OutletResolver` (or `--psl` to the command). Without one, the map's small explicit rule decides, as the PRD says. The library never downloads the list: the caller fetches and pins it, so keys do not change under them. The PRIVATE section is off by default because it lists `blogspot.com`, `github.io` and other hosting platforms as suffixes, which would bypass the portal rule; `$includePrivate = true` turns it on. When a list is passed it replaces the second-level and last-two-labels rules; the map's explicit `suffixes` still apply, and the longer of the map suffix and the list's suffix wins.

2. **No non-ASCII case folding or Unicode normalisation.** IDN labels are converted to A-labels as given; non-ASCII letters are not folded and nothing is NFC-normalised, in hosts or paths. A correct fold and NFC need Unicode tables that would dwarf the library and change with Unicode versions, which breaks the determinism goal. Feeds emit these consistently in practice. A-labels are lowercased, and so is every ASCII letter in a host.

3. **Path case is kept by default; `caseInsensitivePaths` opts an outlet in.** This replaces PRD case 28a, which lowercased every path. Some outlets have case-sensitive ids in the path, and lowercasing them merges distinct articles, which is the worse failure: a missed duplicate costs one extra item, a false merge loses an article. The map gains a `caseInsensitivePaths` list (empty in the seed map) for outlets known to serve one article under any case. AMP and index markers (`/AMP/`, `.Amp.html`, `/Index.php`) match ASCII case-insensitively whatever the setting, because they are format markers, not identity.

4. **Repeated query keys keep every value.** This replaces PRD case 40 ("last wins"). Kept pairs are sorted by key and then by value, byte order, and exact repeats collapse. Last-wins makes the key depend on parameter order and silently drops identity on script paths such as `?id=1&id=2`. Sorting all values gives one key for any order.

5. **`countOutlets()` is not in the library.** Counting is caller policy: whether to drop aggregators, the caller's own site, unknown keys or duplicate articles first, and in which order. The library gives the parts (`outletKey`, `articleKey`, `isAggregator`, `isOwnOutlet`, `sameOutlet`), and `examples/count-corroboration.php` shows the pattern. The facade also has no other method that would need a policy.

6. **The map ships inside the package.** Map changes are released as package releases: patch releases for additions that move no existing key, minor releases for changes that move keys (a new alias or portal base), with a `Map` section in the CHANGELOG either way. The map keeps its own `version` field, and `OutletMap::bundledPath()` gives callers the file if they want to copy or pin it. A separate standalone artefact was not worth a second release process in 0.1.0; it can be added later without an API change.

7. **Hashing is exposed, for both keys.** `KeyHash::outlet()` and `KeyHash::article()`, plus `outletHash()` and `articleHash()` on the resolver, the keyer and the facade, return sha256 hex of `"outlet-key/" . SCHEME_VERSION . "\n" . kind . "\n" . key`. The digest covers the scheme version and the kind of key, so an outlet key and an article key never share a hash and a code change that moves keys moves hashes. It does not cover the map version: a map release that does not touch an outlet must not move every stored hash. Callers store the map version beside the hash. The empty key hashes to the empty string, so two unknown inputs never look like one stored item.

## Other decisions taken while building

- **Input length** is checked on the raw input, before trimming, against 8192 bytes. Both `outletKey` and `articleKey` raise `InputTooLongException`, so a caller never gets a key from one function and an exception from the other for the same input.
- **Entity decoding** uses `html_entity_decode` with HTML5 entities and quotes, once. A double-encoded `&amp;amp;` stays one level encoded.
- **Schemes.** Any RFC 3986 scheme followed by `//` is dropped. One nested scheme is accepted, for feed readers' `feed:https://...`. `host:8080/path` with no scheme is read as a host and a port, not as a scheme `host`. A scheme with no authority (`mailto:`, `javascript:`) has no host.
- **Ports** must be digits. `example.com:http` is unparseable rather than silently keyed, because a non-numeric port means the input is not the URL the caller thinks it is.
- **Host characters.** URL hosts may contain ASCII letters, digits, hyphens and underscores in any position, because real feeds carry hosts such as `x_y.example.com`. Percent signs and spaces make a host unparseable. The map is stricter: its validator requires well-formed labels.
- **IDNA full stops** (U+3002, U+FF0E, U+FF61) separate labels, as IDNA requires.
- **IPv4** literals are four decimal octets of 0 to 255. Anything else (`10.0.0.999`) is a name and is keyed as one. **IPv6** literals are validated with `filter_var` (in PHP core since 5.2, present even under `php -n`), lowercased and returned without brackets or port, with no compression or expansion.
- **AMP cache unwrapping** accepts `/<letter>/<host>` and `/<letter>/s/<host>`. A first segment of exactly `s` is always the `s/` marker, so `/c/s/` with no host after it is not read as host `s`; the cache host is kept. User info and a port inside the wrapped host are dropped like any other. If the wrapped host is unusable, the cache host is kept and keyed normally, which the trace shows.
- **The fragment** is cut before the query is split, so `#x?id=5` never contributes a query.
- **Map validation at runtime is hand-written**, so the library keeps zero runtime dependencies. The JSON Schema in `data/outlets.schema.json` (draft-07) documents the format for contributors and editors. A data test suite checks the bundled map against the schema with `justinrainbow/json-schema` (a dev dependency only), checks the schema against the draft-07 meta-schema, and checks that the schema and the runtime validator reject the same broken maps.
- **Map file reading.** The map may carry a `$schema` key for editors; it is ignored. `notes` are validated (an id rule's `examples` must match its pattern) and otherwise ignored by the code.
- **Extra public API beyond section 5:** `OutletMap::fromJson()`, `OutletMap::bundledPath()`, hash helpers, `OutletKey::mapVersion()` and `OutletKey::explain()`, `ArticleKeyer::resolver()`, the third `OutletResolver` argument for a Public Suffix List and the matching `--psl` flag. Each is small and serves a documented use.
- **Performance.** Hosts that are plain ASCII names take a single-regex fast path in `UrlParser`; the label loop and Punycode run only for IDN hosts. Tracing allocates nothing unless asked for.

## Testing

- `tests/Unit`: one test or more per PRD case and acceptance test, named after the case. Fixture files are run line by line.
- `tests/Data`: the bundled map against its JSON Schema, and schema and validator agreement.
- `DeterminismTest` runs the fixtures in a child process under `php -n` (no `ext-intl`, no `ext-mbstring`) and compares the output with a normal run.
- CI runs lint, PHP_CodeSniffer (PSR-12), PHPStan level 8, map validation and PHPUnit on PHP 8.1, 8.2 and 8.3.
