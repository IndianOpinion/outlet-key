# outlet-key: product requirements

Status: draft for build 0.1.0. Package `indianopinion/outlet-key`.
Namespace `IndianOpinion\OutletKey`.

outlet-key answers two questions about a news URL. Which newsroom owns it?
Which article is it? Mobile, AMP, regional, language and legacy hosts of one
outlet give one outlet key. Two newsrooms that share a parent domain give
two. The same article reached through an AMP path, a query string, a
fragment or a trailing slash gives one article key.

## 1. Problem

Anything that counts sources, corroborates a story or deduplicates a feed
needs to know when two URLs are the same newsroom or the same article. The
host string is the wrong answer in both directions.

- `m.economictimes.com`, `economictimes.indiatimes.com` and
  `auto.economictimes.indiatimes.com` are one newsroom. Counted by host they
  are three, so a single-source story looks corroborated three times.
- `timesofindia.indiatimes.com` and `economictimes.indiatimes.com` are two
  newsrooms. Counted by registrable domain they are one, so a genuinely
  corroborated story looks single-source.
- `amp.scmp.com` and `scmp.com` are one. `hindi.news18.com` and
  `news18.com` are one.
- One article arrives as `/story/x`, `/story/x/`, `/amp/story/x`,
  `/story/x?utm_source=feed`, `/story/x.amp.html` and a Google AMP cache
  URL. Keyed by raw URL these are six articles, ingested six times.
- Some outlets file one article under two section paths or two slugs, with
  the same numeric id. Some republish one article on sister hosts under a
  new id with the same headline.

Measured result: before article ids were honoured, a 30-day scan of one
production source table found 7 published pieces that listed one article
twice, across four Indian outlets.

Without this library each consumer writes its own prefix strip. The
strips drift apart, one outlet ends up with two identities, and anything
keyed on outlet (a source count, a per-outlet history) forks silently.

## 2. Goals and non-goals

Goals

1. One pure function from URL or bare host to an outlet key.
2. One pure function from URL to an article key.
3. A public, versioned, reviewable outlet map as a data file, with a
   validator and a documented contribution path.
4. Deterministic output: the same input and the same map give the same key
   on every PHP version and every host, with or without optional
   extensions.
5. A trace function that says why a URL got its key.
6. Zero runtime dependencies. Usable with or without Composer.

Non-goals

- No network access, ever. The library never fetches a URL, follows a
  redirect or resolves DNS.
- No unwrapping of redirect wrappers (Google News `articles/` links, link
  shorteners). That needs a fetch. Callers resolve them first; the sister
  project `gnews-unwrap` does that for Google News.
- No full Public Suffix List. The registrable-domain rule is small and
  explicit (see 4.3). The map can add suffixes.
- No canonical-URL discovery from page HTML (`rel=canonical`).
- No judgement of outlet quality, ownership or bias. The map records
  identity only.
- No storage. The caller stores keys.

## 3. Users and setting

- Engineers building news aggregators, corroboration counters, feed
  importers, citation checkers and analytics that group by publisher.
- Runs in-process in PHP 8.1+: CLI jobs, web requests, WordPress plugins,
  queue workers. No daemon, no server.
- The outlet map is maintained in public by people who know Indian
  newsrooms. Contributors may not write PHP; a map change is a JSON edit
  plus test fixture lines.

## 4. Behaviour

Terms. A *label* is one dot-separated part of a host. The *registrable
domain* is the result of 4.3. The *map* is the data file of section 6.
The *empty key* is the empty string `''`; it means "unknown" and is never an
outlet.

### 4.1 Input preparation (shared by both keys)

1. Leading and trailing whitespace is trimmed. HTML entities are decoded
   once (`&amp;` becomes `&`), because feed URLs often arrive
   entity-encoded.
2. An empty input after trimming gives the empty key for both functions.
3. An input with no scheme is read as a bare host or host plus path.
   Leading slashes are dropped (`//host/path` works). It is then parsed as
   if it began `http://`. Schemes are matched case-insensitively.
4. Any scheme is accepted for parsing (`http`, `https`, `feed`,
   anything matching RFC 3986 scheme syntax). The scheme never appears in
   either key, so `http` and `https` give the same keys.
5. User info (`user:pass@`) is discarded and never appears in a key.
6. The port is discarded from both keys. `example.com:443`,
   `example.com:8080` and `example.com` give the same outlet key and,
   for the same path, the same article key. Rationale: newsrooms do not
   publish different articles per port; a port in a news URL is
   misconfiguration noise.
7. The host is lowercased (ASCII) and one trailing dot is removed
   (`example.com.` equals `example.com`).
8. Inputs longer than 8192 bytes are refused with
   `InputTooLongException`. No key is computed from a truncated input.

### 4.2 Host forms

9. IDN. A host containing non-ASCII characters is converted label by label
   to its A-label (Punycode, RFC 3492, prefix `xn--`). The conversion is
   built into the library so results do not depend on `ext-intl`.
   Case folding of non-ASCII letters is not performed (see open question
   2). A host already in A-label form is kept as it is, lowercased.
   `हिन्दी.example` and `xn--...example` give the same key.
10. A host that is not valid UTF-8, or whose label exceeds 63 bytes after
   conversion, or whose total length exceeds 253 bytes, is unparseable.
   Outlet key: empty key. Article key: see case 31.
11. IPv4 literal hosts are returned as the outlet key unchanged. No label
   stripping, no map lookup.
12. IPv6 literal hosts (`[2001:db8::1]`) are returned lowercased without
   the brackets. No compression or expansion is applied.
13. Google AMP cache. A host ending in `.cdn.ampproject.org` whose path
   starts `/<one letter>/` or `/<one letter>/s/` is replaced by the host
   in the next path segment: `www-x-com.cdn.ampproject.org/c/s/www.x.com/a`
   reads as host `www.x.com`. If the path does not match, the cache host
   is kept and keyed normally (it becomes `ampproject.org`, which callers
   can see in the trace). The unwrap happens once; a nested cache URL is
   not unwrapped again.

### 4.3 Outlet key

The steps run in this order. Each one is a case.

14. Mirror labels. While the host has more than two labels and its first
   label is in the map's mirror list (default: `www`, `www1`, `www2`,
   `www3`, `ww`, `m`, `mobile`, `amp`, `wap`, `mob`, `touch`, `lite`), the
   first label is dropped. `www.m.example.com` becomes `example.com`.
15. Mirror labels are only stripped from the left. `news.m.example.com`
   keeps its inner `m`. A host is never reduced below two labels:
   `m.com` stays `m.com`.
16. Pass-through hosts. If the host after step 14 is in the map's
   pass-through list (default: `news.google.com`), it is returned whole.
   It is not reduced to its registrable domain. A wrapper must never merge
   with its parent company's own outlet.
17. Registrable domain. A host of two labels or fewer is its own
   registrable domain. Otherwise:
   a. If the host ends in a suffix from the map's explicit suffix list,
      the registrable domain is that suffix plus one label.
   b. Else if the top-level label is two letters and the second-level label
      is in the map's second-level list (default: `co`, `com`, `net`,
      `org`, `edu`, `ac`, `gov`, `govt`, `nic`, `mil`, `res`, `gen`,
      `firm`, `ind`, `ltd`, `plc`, `sch`, `info`, `biz`, `int`, `or`,
      `ne`, `go`, `nhs`, `police`), the registrable domain is the last
      three labels. `pib.gov.in` stays `pib.gov.in`. `bbc.co.uk` stays
      `bbc.co.uk`.
   c. Else it is the last two labels.
18. Unmapped fallback. A host with no portal rule and no alias gets its
   registrable domain as the outlet key. This is how regional and section
   subdomains collapse: `hindi.news18.com` and `kolkata.news18.com` both
   give `news18.com`. Most of the world needs no map entry.
19. Portal bases. If the host is longer than its registrable domain and the
   registrable domain is in the map's portal list (default:
   `indiatimes.com`, `india.com`, `blogspot.com`, `wordpress.com`,
   `substack.com`, `medium.com`, `github.io`, `tumblr.com`), the label
   directly left of the registrable domain names a separate publication.
   The key becomes that label plus the registrable domain.
   `timesofindia.indiatimes.com` and `economictimes.indiatimes.com` stay
   two outlets. `auto.economictimes.indiatimes.com` gives
   `economictimes.indiatimes.com`, because only the nearest label counts.
20. A portal label that is a language label (map list; default `hindi`,
   `english`, `en`, `hi`, `tamil`, `telugu`, `bengali`, `bangla`,
   `marathi`, `gujarati`, `kannada`, `malayalam`, `punjabi`, `odia`,
   `oriya`, `urdu`, `assamese`) is a language edition of the portal itself,
   not a publication. The key is the registrable domain.
21. A bare portal host (`indiatimes.com`, `www.indiatimes.com`) gives the
   registrable domain.
22. Aliases. The result of steps 17 to 21 is looked up once in the map's
   alias table. If present, the alias target is the outlet key. This joins
   one newsroom across two registrable domains: `economictimes.com` gives
   `economictimes.indiatimes.com`, `ndtv.in` gives `ndtv.com`,
   `bbc.co.uk` gives `bbc.com`. Lookup is one hop; the validator forbids
   chains.
23. Bare-host and URL input agree: `outletKey('m.economictimes.com')`
   equals `outletKey('https://m.economictimes.com/news/x')`.
24. Aggregators. `isAggregator(key)` is true when the outlet key is in the
   map's aggregator list (default: `news.google.com`, `yahoo.com`,
   `msn.com`, `flipboard.com`, `dailyhunt.in`, `newsbreak.com`,
   `inshorts.com`). The outlet key itself is not changed. Callers that
   count corroborating newsrooms must exclude aggregators; the README says
   so.
25. Callers can exclude their own site from counts with
   `isOwnOutlet(key)`, configured by listing hosts at construction. The
   own hosts are keyed through the same pipeline, so `www.` and `m.`
   variants of the caller's site match.

### 4.4 Article key

26. The article key is `outlet key` + `normalised path` + optional
   `?` + `kept query`, or a special form from cases 36 and 37. Scheme,
   port, user info and fragment never appear.
27. AMP cache URLs are unwrapped as in case 13, keeping the original query
   string, and keyed as the inner URL. A cache URL and its origin URL give
   the same article key.
28. Path normalisation, in this order:
   a. Percent-decode the path once. Lowercase ASCII letters.
   b. `/amp_articleshow/` becomes `/articleshow/`.
   c. A trailing `/amp/1` (with or without slash) is removed.
   d. A leading `/amp` segment is removed.
   e. Any other whole `/amp` segment is removed (`/story/amp`,
      `/x/amp/y`).
   f. `.amp.html`, `-amp.html`, `.amp.htm`, `-amp.htm` become `.html` or
      `.htm`.
   g. A trailing `.amp` is removed.
   h. A trailing `/index.html`, `/index.htm` or `/index.php` is removed.
   i. Runs of slashes collapse to one.
   j. Trailing slashes are removed. An empty path becomes `/`.
29. `amp` is matched only as a whole segment or the stated suffix forms.
   `/campaign/` and `/amplify/` are untouched.
30. After decoding, bytes that are not valid UTF-8, spaces, `?` and `#` are
   re-encoded as uppercase `%XX`, so a key is printable, single-line and
   unambiguous. A Hindi slug and its percent-encoded form give one key.
31. Unparseable URL (no host after case 3, or case 10): the article key is
   the trimmed input, ASCII-lowercased. It still deduplicates exact
   repeats. The trace marks it `unparsed`.
32. Fragments are always dropped.
33. Query keys are lowercased. Values keep their case.
34. Tracking parameters are always dropped: any key starting `utm_`, and
   the map's tracking list (default: `fbclid`, `gclid`, `dclid`,
   `msclkid`, `ref`, `ref_src`, `referrer`, `source`, `src`, `from`,
   `cmp`, `cmpid`, `amp`, `outputtype`, `ito`, `s_cid`, `ncid`, `mc_cid`,
   `mc_eid`, `igshid`, `spm`, `share`, `shared`, `fromapp`, `pfrom`,
   `smid`, `_gl`, `rss`, `rssfeed`, `feed`, `feedtype`, `tbref`, `ocid`,
   `sr_share`, `at_medium`, `at_campaign`, `intcmp`,
   `__twitter_impression`). Empty values and valueless keys are dropped.
35. On a slug path, only id-bearing keys survive (map list; default: `p`,
   `id`, `story`, `storyid`, `story_id`, `article`, `articleid`,
   `article_id`, `newsid`, `news_id`, `nid`, `sid`, `aid`, `pid`, `prid`,
   `contentid`, `content_id`, `postid`, `post_id`, `v`). On a script
   path, every non-tracking key survives. A script path is `/`, or a path
   ending `.aspx`, `.asp`, `.php`, `.jsp`, `.cfm`, `.cgi` or `.do`. So
   `/?p=123` and `/release.aspx?PRID=9` keep their identity, and
   `/story/x?page=2` loses `page`.
36. Article-id rules. If the outlet key has an id rule in the map and the
   normalised path matches it, the article key is `outlet/a/<id>`. The id
   survives a change of section path or slug. Seed rules:
   - `ndtv.com`: path ends `-<6+ digits>`.
   - `timesofindia.indiatimes.com`: `/articleshow/<6+ digits>` with optional
     `.cms` at the end.
   - `thehindu.com`, `thehindubusinessline.com`: `/article<6+ digits>.ece`
     at the end.
   - `deccanherald.com`: path ends `-<6+ digits>`.
   - `timesnownews.com`: path ends `-article-<6+ digits>`.
   The query is not part of an id-form key.
37. Slug outlets. If the outlet key is in the map's slug-outlet list
   (seed: `economictimes.indiatimes.com`, `nasa.gov`) and the slug key of
   the path (case 38) is not empty, the article key is `outlet/s/<slug
   key>`. This catches a sister host republishing under a new id with the
   same headline. Slug rules run before id rules.
38. Slug key, from a normalised path:
   a. Split into non-empty segments. Pop trailing segments that are
      `articleshow`, `amp`, or zero to three letters followed by five or
      more digits with an optional `.cms`, `.html` or `.htm`.
   b. No segment left: empty slug key.
   c. Take the last segment. Remove a final `.cms`, `.html`, `.htm` or
      `.php`.
   d. Split on anything that is not `a-z` or `0-9`.
   e. Drop stop words (map list; default `a`, `an`, `the`, `to`, `of`,
      `in`, `on`, `for`, `as`, `at`, `by`, `and`, `or`, `is`, `are`, `was`,
      `were`, `be`, `its`, `it`, `with`, `from`, `into`, `amid`, `over`,
      `this`, `that`).
   f. Number words `one` to `ten` become digits.
   g. A token longer than three characters that ends in `s` but not `ss`
      loses the `s`. `bets` meets `bet`, `others` meets `other`; `class`
      is kept.
   h. Fewer than six tokens left: empty slug key (too short to name one
      article).
   i. Any token in the recurring-format list (default `today`, `live`,
      `liveblog`, `update`, `latest`, `horoscope`, `weather`, `highlight`,
      `scorecard`) gives an empty slug key. These slugs repeat on
      different days.
   j. Otherwise the tokens joined by `-`.
   A non-Latin slug yields few ASCII tokens, gets an empty slug key and
   falls through to the plain key. That is intended.
39. A slug outlet whose slug key is empty falls through to id rules, then
   to the plain key.
40. Repeated query keys: the last value wins (see open question 4). Array
   syntax keys (`a[]=1`) are kept literally as key `a[]`, subject to the
   same keep rules.
41. Query keys are not rewritten. A key containing `.` or a space keeps it
   (after percent-decoding and lowercasing). Kept pairs are sorted by key,
   byte order, and re-encoded per RFC 3986 with `%20` for space.
42. Article keys from different outlets never collide, because the outlet
   key is the prefix and outlet keys contain no `/`.

### 4.5 Map loading and failure modes

43. The bundled map loads on first use and is cached per process.
44. A map that is not valid JSON, fails the schema, or fails a validator
   rule (section 6) raises `InvalidMapException` naming the file, the
   JSON pointer and the rule. The library fails closed on a bad map:
   keys from a half-loaded map would be silently wrong and would poison
   stored data.
45. An id rule pattern that does not compile, or has other than one
   capturing group, is a map error (case 44). A PCRE runtime error
   (backtrack limit) while keying raises `RuleRuntimeException`; it is not
   swallowed.
46. URL input never raises except for case 8 and case 45. Bad URLs give
   documented fallbacks (empty outlet key, unparsed article key). This is
   fail open for input, because one malformed feed item must not stop a
   batch.
47. Overrides. `OutletMap::withOverrides()` merges a caller array onto a
   map: lists are unioned, tables are merged with caller entries winning,
   and a `remove` section deletes entries. The merged map is validated
   again.

### 4.6 Trace

48. `explain(url)` returns an ordered list of steps with the value after
   each: input, prepared host, AMP unwrap, mirror strip, pass-through,
   registrable domain (and which rule), portal, alias, final key, and for
   articles each path rewrite that fired, the query decisions per key, and
   whether an id or slug rule fired. It also names the map version.

## 5. Public interface

All classes are `final`. All methods are pure given a map.

`IndianOpinion\OutletKey\OutletMap`

- `static bundled(): self` the shipped `data/outlets.json`.
- `static fromFile(string $path): self`
- `static fromArray(array $data): self`
- `withOverrides(array $overrides): self` returns a new map.
- `version(): string` the map's `version` field.
- `toArray(): array` the validated, normalised content.

`IndianOpinion\OutletKey\OutletResolver`

- `__construct(?OutletMap $map = null, array $ownHosts = [])`
  null means the bundled map.
- `outletKey(string $urlOrHost): string`
- `host(string $urlOrHost): string` the prepared host after cases 1 to 13,
  before any stripping.
- `registrableDomain(string $host): string`
- `isAggregator(string $outletKey): bool`
- `isOwnOutlet(string $outletKey): bool`
- `sameOutlet(string $a, string $b): bool` true only when both keys are
  non-empty and equal.
- `explain(string $urlOrHost): Trace`

`IndianOpinion\OutletKey\ArticleKeyer`

- `__construct(?OutletResolver $resolver = null)`
- `articleKey(string $url): string`
- `slugKey(string $path): string`
- `sameArticle(string $a, string $b): bool`
- `explain(string $url): Trace`

`IndianOpinion\OutletKey\OutletKey` (static facade over the bundled map,
memoised)

- `OutletKey::outlet(string $urlOrHost): string`
- `OutletKey::article(string $url): string`
- `OutletKey::countOutlets(iterable $urls): int` distinct non-empty,
  non-aggregator outlet keys after collapsing article duplicates.
- Constant `OutletKey::SCHEME_VERSION = 1`. It changes whenever a code
  change alters any key for any input. Map changes are tracked by the map
  version.

`IndianOpinion\OutletKey\Trace`: read-only list of `{step, value, note}`
plus `toArray()` and `__toString()`.

Exceptions, all extending `IndianOpinion\OutletKey\OutletKeyException`
(itself extending `RuntimeException`): `InvalidMapException`,
`InputTooLongException`, `RuleRuntimeException`.

CLI `bin/outlet-key`:

- `outlet-key <url>...` prints `outlet<TAB>article` per line.
- `outlet-key --explain <url>` prints the trace.
- `outlet-key validate [path]` validates a map (default the bundled one).
  Exit 0 valid, 1 invalid, 2 usage error. Messages go to stderr.
- `outlet-key --map <path>` uses another map.
- Reads URLs from stdin, one per line, when given `-`.

Without Composer: `require 'path/to/outlet-key/bootstrap.php';` registers
an autoloader for the namespace and nothing else.

## 6. Data

The library stores nothing at runtime. It ships one data file.

### 6.1 `data/outlets.json`

UTF-8 JSON, two-space indent, keys sorted, one entry per line in lists, so
diffs are one line per change. A JSON Schema lives at
`data/outlets.schema.json`. Top-level fields:

| field | type | meaning |
|---|---|---|
| `version` | string `YYYY.MM.DD[.n]` | map release, bumped on every change |
| `mirrorLabels` | list of labels | case 14 |
| `languageLabels` | list of labels | case 20 |
| `secondLevelLabels` | list of labels | case 17b |
| `suffixes` | list of multi-label suffixes | case 17a |
| `portalBases` | list of registrable domains | case 19 |
| `passThrough` | list of hosts | case 16 |
| `aliases` | object, outlet key to outlet key | case 22 |
| `aggregators` | list of outlet keys | case 24 |
| `articleIdRules` | object, outlet key to PCRE pattern with one capture | case 36 |
| `slugOutlets` | list of outlet keys | case 37 |
| `slug` | object: `stopWords`, `numberWords`, `recurringWords`, `minTokens` (6) | case 38 |
| `query` | object: `idParams`, `trackingParams`, `trackingPrefixes` (`["utm_"]`), `scriptExtensions` | cases 34, 35 |
| `notes` | object, key to free text (evidence link, date added) | humans only, ignored by code |

Patterns are full PCRE strings with delimiters and no `e` modifier,
applied to the normalised path.

### 6.2 Validator rules

- Every host, label and key is lowercase ASCII (A-labels for IDN), with no
  scheme, path, port or trailing dot.
- No duplicates within a list. Lists and object keys are sorted.
- An alias key is never also an alias target (no chains, no cycles), and
  never equals its own target.
- An alias target, id-rule key or slug outlet is a valid outlet key: it is
  unchanged when passed through cases 14 to 21 without aliases.
- A mirror label is not also a language label.
- Every pattern compiles, has exactly one capturing group, and matches the
  example paths listed for it in `notes` if any.
- `minTokens` is an integer from 2 to 20.

### 6.3 Retention and uninstall

Nothing is written, so there is nothing to clean up. Callers that store
keys should store `SCHEME_VERSION` and the map version beside them, or a
hash of the key with those versions (section 14).

### 6.4 Contribution path for map entries

- Issue template `.github/ISSUE_TEMPLATE/outlet_map_entry.md`: outlet name,
  hosts involved, which change (alias, portal, id rule, slug outlet,
  aggregator, suffix), and evidence: two public URLs of the same article or
  a masthead or about page showing one newsroom. No personal data.
- Pull request: edit `data/outlets.json`, add a `notes` entry with the
  evidence link and date, add at least one line per affected host to
  `tests/fixtures/outlets.tsv` (`input<TAB>expected outlet key`) and, for
  article rules, to `tests/fixtures/articles.tsv`
  (`input<TAB>expected article key`), and run `composer validate-map` and
  the tests.
- CI runs the validator and every fixture line.
- The CHANGELOG has a `Map` subsection per release listing added, changed
  and removed entries. A change that alters existing keys (a new alias or
  portal) is called out, because stored keys move.
- CONTRIBUTING.md explains all of this for someone who has never written
  PHP.
- Removal of an entry needs the same evidence as adding one.

## 7. Configuration and sensible defaults

| setting | default | where |
|---|---|---|
| map | bundled `data/outlets.json` | constructor or `--map` |
| overrides | none | `withOverrides()` |
| own hosts | none | `OutletResolver` constructor |
| max input length | 8192 bytes | constant, not configurable in 0.1.0 |
| AMP cache suffix | `.cdn.ampproject.org` | code constant |

Every list in 6.1 is a default that a caller can extend or trim through
overrides. No environment variables. No global state except the memoised
bundled map.

## 8. Security and privacy

- No network, no file writes, no shell, no `eval`, no `@`.
- Regex patterns come only from a map. URL input is never compiled into a
  pattern. Patterns run on a path of at most 8192 bytes, and PCRE errors
  are raised, not ignored.
- The map is reviewed code. A caller who loads a map from an untrusted
  source accepts its patterns; the README says so.
- Keys drop user info, so credentials in URLs never reach a stored key.
  Query values on script paths are kept; a caller keying URLs that carry
  tokens in the query should strip them first. The README says so.
- The map holds only public facts about newsrooms and their hosts. No
  personal data is accepted into it.

## 9. Performance budget

On PHP 8.3, one core:

- Loading and validating the bundled map: under 5 ms, once per process.
- `outletKey`: under 20 microseconds per URL, warm.
- `articleKey`: under 60 microseconds per URL, warm.
- Memory: under 2 MB for a map of 5,000 entries.
- No I/O after the map is loaded. Lookups are hash-table, not linear scans.

A benchmark script in `examples/` reports these on 100,000 mixed URLs.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `ext-json` only (core). Does not use `ext-intl` or
  `ext-mbstring`, so output never depends on which extensions are loaded.
- No framework. Works inside WordPress, Laravel, Symfony or a plain script.
- Hosts: any. No cache interaction.
- Results are stable across releases for the same `SCHEME_VERSION` and map
  version.

## 11. Observability

- The library does not log. It is a pure function.
- `explain()` and `outlet-key --explain` show every decision (section 4.6).
- `OutletMap::version()` and `OutletKey::SCHEME_VERSION` let an operator
  see which rules produced a stored key.
- `outlet-key validate` is the map health check, run in CI.

## 12. Acceptance tests

Each test names the case it proves. Expected keys are exact.

1. (1) ` https://amp.scmp.com/x?a=1&amp;utm_source=y ` trims and decodes
   entities; outlet `scmp.com`.
2. (2) `''` and `'   '` give `''` for both functions.
3. (3) `m.economictimes.com`, `//m.economictimes.com/x` and
   `HTTPS://M.ECONOMICTIMES.COM/x` all give `economictimes.indiatimes.com`.
4. (4) `http://x.com/a` and `https://x.com/a` give equal article keys.
5. (5) `https://u:p@x.com/a` gives article key `x.com/a`.
6. (6) `x.com:8080/a` and `x.com/a` give equal outlet and article keys.
7. (7) `example.com.` gives `example.com`.
8. (8) an 8193-byte URL raises `InputTooLongException`; 8192 bytes does
   not.
9. (9) a Devanagari-label host and its A-label form give the same outlet
   key, which starts `xn--`.
10. (10) a host with a 64-byte label and a host with invalid UTF-8 give
    outlet key `''`.
11. (11) `http://10.0.0.1/a` gives outlet `10.0.0.1`.
12. (12) `http://[2001:DB8::1]/a` gives outlet `2001:db8::1`.
13. (13) `https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/a`
    gives `scmp.com`; `https://x.cdn.ampproject.org/zz` gives
    `ampproject.org`.
14. (14) `www.m.example.com` gives `example.com`; each default mirror label
    is stripped.
15. (15) `news.m.example.com` gives `example.com` via fallback with the
    trace showing no mirror strip; `m.com` gives `m.com`.
16. (16) `news.google.com/rss/articles/x` gives `news.google.com`, not
    `google.com`.
17. (17) `pib.gov.in`, `www.bbc.co.uk`, `a.b.example.co.in` give
    `pib.gov.in`, `bbc.com` (after alias), `example.co.in`; a suffix added
    through overrides changes the registrable domain as described.
18. (18) `hindi.news18.com` and `kolkata.news18.com` give `news18.com`.
19. (19) `timesofindia.indiatimes.com` and `economictimes.indiatimes.com`
    differ; `auto.economictimes.indiatimes.com` gives
    `economictimes.indiatimes.com`.
20. (20) `hindi.indiatimes.com` gives `indiatimes.com`.
21. (21) `www.indiatimes.com` gives `indiatimes.com`.
22. (22) `economictimes.com`, `ndtv.in`, `bbc.co.uk` give their targets.
23. (23) bare host and full URL agree for every line in
    `tests/fixtures/outlets.tsv`.
24. (24) `isAggregator('msn.com')` true; outlet key of
    `www.msn.com/a` is `msn.com`.
25. (25) with own hosts `['example.org']`, `isOwnOutlet` is true for the
    key of `m.example.org`.
26. (26) `https://www.example.com/a/b/?utm_source=x#c` gives
    `example.com/a/b`.
27. (27) an AMP cache URL and its origin URL give the same article key.
28. (28) each rewrite a to j has its own input; for example `/amp/story/x`,
    `/story/x/amp`, `/story/x.amp.html`, `/story/x.amp`,
    `/x/index.html`, `//story//x//` and `/story/x/` all key as their
    plain form; `/story/x/amp/1` keys as `/story/x`, and
    `/amp_articleshow/123.cms` keys as `/articleshow/123.cms`.
29. (29) `/campaign/x` and `/amplify/x` are unchanged.
30. (30) a path with `%E0%A4%...` and the same path in raw UTF-8 give one
    key; invalid bytes come back as uppercase `%XX`.
31. (31) `http://` and `not a url` give their trimmed lowercase input as
    article key, and the trace says `unparsed`.
32. (32) `/a#x` and `/a` agree.
33. (33) `/?P=Abc` keeps `p=Abc`.
34. (34) every default tracking key and `utm_anything` is dropped; `?q=`
    with an empty value is dropped.
35. (35) `/story/x?page=2&id=5` keeps only `id=5`; `/?p=123` keeps
    `p=123`; `/release.aspx?PRID=9&page=2` keeps both.
36. (36) each seed id rule: two section paths with the same id give one key
    of form `outlet/a/<id>`; a path without an id gives the plain key.
37. (37) two economictimes vertical hosts with different ids and the same
    six-word headline slug give one `outlet/s/...` key.
38. (38) slug key: `amazon-to-bet-3-billion-on-india-cloud-push` meets
    `amazon-bets-three-billion-on-india-cloud-push`; a five-token slug gives
    `''`; a slug containing `live` gives `''`; `class` keeps its `s`.
39. (39) a slug outlet URL with a short slug falls through to the plain
    key.
40. (40) `?id=1&id=2` keeps `id=2`; `?a[]=1` on a script path keeps
    `a[]=1` encoded.
41. (41) `/x.php?b.c=1&a=2` keeps key `b.c` and sorts `a` first.
42. (42) two outlets with identical paths give different keys.
43. (43) the bundled map loads once; a second call does not reread the
    file (assert through a counting stream wrapper or injected loader).
44. (44) broken JSON, a chained alias, an unsorted list and an uppercase
    host each raise `InvalidMapException` naming the rule.
45. (45) a pattern with two groups is rejected at load; a catastrophic
    pattern under a lowered backtrack limit raises
    `RuleRuntimeException` at keying.
46. (46) a batch of 1,000 random byte strings never raises.
47. (47) overrides add an alias, remove a portal base and are revalidated;
    an override that creates a chain raises.
48. (48) `explain()` on `auto.economictimes.indiatimes.com/a/amp` lists the
    mirror, portal and amp steps in order and the map version.
49. (Fixtures) every line of `tests/fixtures/outlets.tsv` and
    `articles.tsv` passes. CI runs `validate` on the bundled map.
50. (Determinism) the fixture run gives identical output with
    `ext-intl` and `ext-mbstring` disabled.

## 13. Open questions

The builder answers these in `docs/ARCHITECTURE.md`.

1. Should an optional Public Suffix List file be supported as an
   alternative to case 17, loaded only when the caller passes one? Default
   answer if unsure: no, keep `suffixes` in the map.
2. Non-ASCII case folding and Unicode normalisation (NFC) of IDN labels
   and paths without `ext-intl`. Is a small built-in table worth it, or is
   "feeds emit lowercase" enough? Document the choice.
3. Path lowercasing. Some outlets have case-sensitive ids in the path.
   Should the map gain a per-outlet `caseSensitivePath` list? 0.1.0 may
   leave it out if documented.
4. Repeated query keys: last wins, or keep all values sorted?
5. Should `countOutlets` live here, or is it a caller concern?
6. Map release cadence: map changes ship as patch releases of the package,
   or should the map also be published as a standalone versioned file?
7. Should the facade expose a stable hashed form (`sha256` of version plus
   key) for storage, or leave hashing to callers?

## 14. Lessons from production

1. Count newsrooms, not hosts. A host string over-counts mirrors and
   under-counts sibling titles under one portal.
2. Use one helper everywhere. Every consumer (dedupe, counting, labels,
   per-outlet history) must call the same function. Two normalisers drift,
   and one outlet ends up with two histories.
3. Strip mirror labels from the left only, and never below two labels.
4. A language edition is the same newsroom. A sibling title under a portal
   is not. Only the label nearest the portal decides.
5. Aliases are one hop. Chains hide mistakes and cycles hang.
6. The empty key means unknown. Never count it as an outlet and never
   treat two empty keys as the same outlet.
7. A redirect wrapper keeps its full host. Reducing it to its parent
   company merges it with an unrelated outlet.
8. AMP cache URLs carry the real host in the path. Unwrap before keying.
9. Changing key rules moves stored keys. Version them, and during a
   transition check both the old and the new key, or duplicates slip
   through for every item stored before the change.
10. Hash the key, not the raw URL. A raw-URL hash that only strips the
    scheme and `www.` lets mobile hosts, AMP paths, tracking queries and
    trailing slashes ingest as separate items.
11. Prefer a stable numeric id over the slug for outlets that file one
    article under several section paths or slugs.
12. Match on headline slug only for named outlets, only for slugs of six
    or more meaningful tokens, and never for live blogs or daily pages.
    Recurring slugs name different articles on different days.
13. A slug path already names the article, so only id-bearing query keys
    survive there. A script endpoint is named by its query, so everything
    but tracking survives there.
14. Do not use a generic query parser that rewrites key names (dots and
    spaces become underscores in some parsers). Parse the query yourself.
15. Fail closed on a bad map, fail open on a bad URL. A bad map corrupts
    every key; a bad URL is one item.
16. Keep the map in data, reviewed like code, with fixtures beside every
    entry. An unexplained entry cannot be safely removed later.
