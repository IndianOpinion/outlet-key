## What and why

## Checklist

- [ ] `composer check` passes (tests, PHPStan, PHPCS, map validation), or CI is green
- [ ] Tests name the PRD case they prove
- [ ] CHANGELOG updated under Unreleased
- [ ] If any key changes for any input: `OutletKey::SCHEME_VERSION` bumped and called out

For map entries:

- [ ] Evidence linked in a `notes` entry with today's date
- [ ] One fixture line per affected host in `tests/fixtures/outlets.tsv` (and `articles.tsv` for article rules)
- [ ] Map `version` bumped
- [ ] Says whether existing keys move
