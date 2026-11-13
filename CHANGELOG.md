# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

Each release lists code changes and, under `Map`, changes to `data/outlets.json`. A map change that moves existing keys is called out, because stored keys move with it.

## [Unreleased]

## [0.1.0] - 2026-11-13

### Added

- `OutletResolver`: URL or bare host to outlet key, with mirror labels, pass-through hosts, registrable domains, portal bases, language labels and one-hop aliases. IDN hosts keyed as A-labels without `ext-intl`. IPv4 and IPv6 literals kept as they are. Google AMP cache URLs unwrapped once.
- `ArticleKeyer`: URL to article key, with AMP and index path rewrites, tracking parameters dropped, id parameters kept on slug paths, per-outlet article-id rules and slug matching.
- `OutletMap`: bundled, file, JSON and array maps, validated on load, with `withOverrides()`.
- Optional `PublicSuffixList`, ICANN section by default.
- `KeyHash` and hash helpers on every entry point, `explain()` traces, and the `OutletKey` facade with `SCHEME_VERSION` 1.
- `bin/outlet-key` command: keys, `--explain`, stdin, `--map`, `--psl` and `validate`.
- `data/outlets.schema.json` (JSON Schema draft-07) and a validator for the rules the schema cannot express.

### Map

- First map, version `2026.11.12`: mirror, language and second-level labels, portal bases, `news.google.com` as pass-through, aliases for `economictimes.com`, `ndtv.in` and `bbc.co.uk`, seven aggregators, id rules for six outlets and two slug outlets.

[Unreleased]: https://github.com/IndianOpinion/outlet-key/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/outlet-key/releases/tag/v0.1.0
