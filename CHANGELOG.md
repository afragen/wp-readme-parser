# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
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
