# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **`trimLength` — short description could exceed 150 characters**: the `' &hellip;'`
  ellipsis suffix (9 chars) was appended *after* truncating to the 150-char limit,
  producing output up to 159 characters. Truncation now budgets for the suffix so
  the final string is always ≤ 150 characters.
- **`trimLength` — sentence-boundary detection off-by-one**: the boundary check used
  a strict `>` comparison, so a period landing exactly at the 80 % position (e.g.
  position 120 of 150) was not treated as a clean sentence end and the ellipsis was
  appended instead of trimming at the period. Changed to `>=`.
- **`ParsedownAdapter` safe mode blocked the HTML sanitizer**: `setSafeMode(true)`
  caused Parsedown to HTML-encode all inline HTML as text, so the Symfony sanitizer
  never saw raw `<script>`, event-handler attributes, etc. Removed safe mode; the
  Symfony allowlist sanitizer (`SymfonyHtmlSanitizerAdapter`) is the correct and
  sufficient security boundary.
- **`too-many-tags` fixture had exactly 5 non-ignored tags**: after `plugin` and
  `wordpress` were removed, exactly 5 tags remained, so the `count > 5` guard never
  fired and the `too_many_tags` warning was never set. Added two additional
  non-ignored tags to the fixture so the limit is reliably exercised.
- **Screenshot tests used stub Markdown adapter**: several tests asserted that
  screenshot captions are populated and passed to `screenshotsAsList()`, but used
  `passThroughMarkdown()`, which leaves numbered-list items as raw text — no
  `<li>` tags are produced and the screenshots array stays empty. Affected tests
  now use the real Parsedown adapter (or `parseFixtureReal` / `parseReal`).
- **`assertStringNotMatchesFormat` removed in PHPUnit 13**: replaced with
  `assertStringNotContainsString('<p>=', …)` in the changelog heading test.
- **`it_handles_whitespace_only_input` false failure**: `assertEmpty($parser->sections)`
  failed because sections are always initialised with all expected keys (empty strings).
  Changed to `assertEmpty(array_filter($parser->sections))`.
- **`composer test` script used system PHPUnit**: changed to `vendor/bin/phpunit
  --no-coverage` so the project-local PHPUnit 13 is always used and the
  "no coverage driver" warning does not trigger `failOnWarning`.

- **PHP 8.5 CI failures** — three dependency constraints were tightened to resolve
  compatibility issues specific to PHP 8.4+/8.5:
  - `erusev/parsedown` bumped from `^1.7` to `^1.8`. Parsedown 1.8.0 (released
    February 2026) fixes implicit nullable parameter deprecations introduced in
    PHP 8.4 that become fatal errors in PHP 8.5.
  - `phpunit/phpunit` widened from `^11.0` to `^11.5.34 || ^12.0 || ^13.0`.
    PHPUnit 11 reached end-of-life in February 2026; this constraint lets Composer
    select the appropriate PHPUnit generation per PHP version (11.x on 8.2–8.3,
    12.x on 8.2–8.3 if preferred, 13.x on 8.4–8.5).
  - `symfony/html-sanitizer` widened from `^6.3|^7.0` to `^6.3|^7.0|^8.0`.
    The 8.0 series (released March 2026, requires PHP 8.4+) is now available and
    selected on PHP 8.4 and 8.5.
  - PHP platform constraint narrowed to `>=8.2 <8.6`; PHPUnit 11 requires PHP 8.2,
    so PHP 8.1 was removed from the supported range.
  - PHP 8.1 removed from the CI test matrix for the same reason.
  - `phpstan/phpstan` widened to `^1.11 || ^2.0` to allow the PHPStan 2.x series.

- Relicensed from GPL-2.0-or-later to MIT.
- Post-processing helper methods (`parseData`, `createContributors`, `faqAsH4`,
  `readmeSectionAsH4`, `screenshotsAsList`) rewritten as original implementations.
- `screenshotsAsList` now uses a private `findScreenshotAsset()` helper; assets must
  be passed via the `$assets` constructor parameter, not via the data array.
- `readmeSectionAsH4` regex tightened to `/<p>=([^=]+)=<\/p>/` to prevent
  greedy matching across multiple headings on adjacent lines.

### Security
- `parseFile` (remote URL fetching) now enforces a 10-second timeout, a maximum of
  5 redirects, and a 1 MB response size cap to prevent hangs and memory exhaustion.
- `donate_link` header now validates that the URL scheme is `http` or `https`; all
  other schemes (`javascript:`, `data:`, `ftp:`, `file:`, protocol-relative, etc.)
  are silently discarded.
- `sanitizeStableTag` — both `preg_replace` calls now use `?? $stableTag` fallback,
  preventing a `TypeError` in strict-types mode if either returns `null` on a regex
  engine failure.
- `preg_replace` result in `readmeSectionAsH4` is now null-checked; a regex error
  falls back to the original unmodified content instead of storing `null`.
- Removed `@` error suppressor from `preg_split` in `trimLength`; the non-UTF-8
  fallback now uses `?: [$text]` to guarantee a usable array in all cases.
- `preg_split` result in `parseContents` is now guarded with `?: [$contents]` to
  prevent passing `false` to `array_map` on a catastrophic regex failure.

### Refactoring (DRY / efficiency)
- `public string $name` corrected to `public string|false $name` to match the two
  code paths that assign `false` when the plugin name header is absent or invalid.
- `sanitizeTestedVersion` and `sanitizeRequiresVersion` refactored into a single
  private `sanitizeVersionHeader(string, array, string)` helper; the two public
  methods now delegate in two lines each.
- CSV header splitting (`array_values(array_filter(array_map(...explode(','...))))`)
  extracted into a private `splitCsvHeader(string): array` helper used for both
  tags and contributors.
- `htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` extracted into a
  `protected encode(string): string` helper; used by `sanitizeText`,
  `screenshotsAsList`, and available to subclasses.
- Duplicate `preg_match('!^https?://!i', ...)` calls (constructor + `parseFile`)
  consolidated into a private `isRemoteUrl(string): bool` helper.
- `validateLicense` keyword lists normalized with `static` local variables so the
  normalization closure runs exactly once per PHP process rather than on every call.

## [1.0.0] — 2024-01-01

### Added
- Initial release — MIT-licensed PHP implementation of the WordPress.org plugin readme parser.
- `Parser` class accepting a file path, URL, or raw readme string.
- Parses plugin name, all standard header fields (`Requires at least`, `Tested up to`,
  `Requires PHP`, `Tags`, `Contributors`, `Stable tag`, `License`, `License URI`,
  `Donate link`).
- Body section parsing: `description`, `installation`, `faq`, `screenshots`,
  `changelog`, `upgrade_notice`, plus custom sections merged into `other_notes`.
- Support for both `== Wiki-style ==` and `## Markdown-style` H2 section headings.
- Section aliases: `Frequently Asked Questions` → `faq`, `Change Log` → `changelog`,
  `Screenshot` → `screenshots`.
- FAQ parsing into associative array and `<dl>` HTML block; supports both
  `= Heading =` and `**Bold**` heading styles.
- Screenshot captions extracted into a 1-based indexed array.
- Upgrade notices extracted into a version-keyed associative array.
- Word-limit enforcement per section (2500 words general; 5000 for `changelog` and `faq`).
- Short description trimmed to 150 characters with sentence-boundary awareness.
- Warning flags for all known parsing anomalies.
- License validation against a curated list of compatible and incompatible keywords.
- Automatic extraction of a license URL embedded in the `License:` field.
- UTF-8 BOM stripping and UTF-16 LE conversion.
- `HtmlSanitizerInterface` and `MarkdownConverterInterface` contracts for dependency injection.
- `SymfonyHtmlSanitizerAdapter` wrapping `symfony/html-sanitizer` ^6.3|^7.0.
- `ParsedownAdapter` wrapping `erusev/parsedown` ^1.7.
- `parseData()` — returns all properties as a post-processed array.
- `createContributors()` — expands slugs into WP.org profile/avatar records.
- `faqAsH4()` — re-renders FAQ as `<h4>` headings.
- `readmeSectionAsH4()` — promotes Parsedown-rendered wiki headings to `<h4>`.
- `screenshotsAsList()` — renders screenshots as a linked `<ol>` from an asset map.
- PHPUnit 11 test suite split into focused test classes.
- GitHub Actions CI on PHP 8.1, 8.2, and 8.3.
- PHPStan level 6 static analysis.
- PHP CS Fixer code-style enforcement (PER-CS 2.0).

### Differences from a WordPress-native environment
- No WordPress function dependencies (`wp_kses`, `get_user_by`, `esc_html`, etc.).
- Contributor slugs validated by format only — no live WordPress.org database lookup.
- `Tested up to` upper-bound cap (`WP_CORE_STABLE_BRANCH + 0.1`) is not enforced.

[Unreleased]: https://github.com/afragen/wp-readme-parser/compare/1.0.0...HEAD
[1.0.0]: https://github.com/afragen/wp-readme-parser/releases/tag/1.0.0
