# Contributing to outlet-key

Thank you for helping. There are two kinds of contribution: map entries (no PHP needed) and code. Both go through a pull request against `main`.

## Map entries: no PHP needed

The outlet map is `data/outlets.json`. It records which hosts belong to which newsroom. Most hosts need no entry: an unmapped host falls back to its registrable domain, so `hindi.news18.com` already gives `news18.com`.

Add an entry when two registrable domains are one newsroom (an alias), a parent domain hosts separate newsrooms (a portal base), an outlet files one article under several paths with one numeric id (an id rule), or a sister host republishes with a new id and the same headline (a slug outlet).

1. Open an issue with the "Outlet map entry" template first if you are unsure. Say which hosts are involved and which change you want.
2. Bring evidence: two public URLs of the same article, or a masthead or about page that shows one newsroom. No personal data.
3. Edit `data/outlets.json`:
   - Keep every list and every object sorted. Use lowercase hosts with no scheme, path, port or trailing dot. Write IDN hosts in A-label form (`xn--...`).
   - Add a `notes` entry keyed by the outlet, with `added` (today's date), `text` (what and why), `evidence` (the evidence link) and, for an id rule, `examples` (paths the rule must match).
   - Bump `version` to today's date as `YYYY.MM.DD`, or add `.1`, `.2` for a second change on the same day.
4. Add at least one line per affected host to `tests/fixtures/outlets.tsv` (`input<TAB>expected outlet key`). For article rules, add lines to `tests/fixtures/articles.tsv` (`input<TAB>expected article key`). Use a real tab character.
5. Check it. If you have PHP: `composer install`, then `composer validate-map` and `composer test`. If you do not, open the pull request anyway: CI runs the same checks and tells you what failed.

An alias target is never itself an alias key (no chains), and an alias never points at itself. The validator explains any rule you break, with a JSON pointer to the entry.

A change that moves existing keys (a new alias or portal base) is called out in the CHANGELOG under `Map`, because callers who store keys see them change. Removing an entry needs the same evidence as adding one.

`outlet-key --explain <url>` shows which step decided a key. Paste its output in the issue or pull request.

## Code

Setup:

```sh
git clone https://github.com/IndianOpinion/outlet-key.git
cd outlet-key
composer install
```

Checks (all must pass; CI runs them on PHP 8.1, 8.2 and 8.3):

```sh
composer test           # PHPUnit: unit and data suites
composer analyse        # PHPStan level 8
composer cs             # PHP_CodeSniffer, PSR-12
composer validate-map   # the bundled map
composer check          # all of the above
```

Rules:

- PHP 8.1 syntax only: no readonly classes, DNF types or `true` type.
- `declare(strict_types=1);` in every file. No runtime dependencies. No `eval`, no shell, no `@`, no network.
- Do not use `ext-intl` or `ext-mbstring`. A test runs the fixtures under `php -n` and compares.
- Every behaviour change comes with a test that names the PRD case it proves.
- A change that alters any key for any input bumps `OutletKey::SCHEME_VERSION` and gets a CHANGELOG entry.
- Short sentences, plain words, British spelling in docs and comments.

## Pull requests

- One topic per pull request. Fill in the template.
- Keep commits readable. A sign-off line is not required.
- A maintainer reviews every pull request, map entries included.

By contributing you agree that your work is released under the MIT licence of this project, and you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
