# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `NativeHtmlSanitizerAdapter` — HTML sanitization via PHP's built-in `DOMDocument`,
  enforcing the WP.org `wp_kses()` element/attribute allowlist with no third-party
  dependencies. Dangerous elements (`script`, `style`, `iframe`, `object`, `embed`,
  `form`) are dropped with their content; unknown elements are stripped but their
  text content is preserved.

### Removed
- `symfony/html-sanitizer` dependency dropped entirely; `NativeHtmlSanitizerAdapter`
  is now the default, reducing production dependencies to `erusev/parsedown` only.
- `SymfonyHtmlSanitizerAdapter` removed (pass a custom `HtmlSanitizerInterface`
  implementation to the constructor if Symfony is preferred).
- `parseData()`, `createContributors()`, `faqAsH4()`, `readmeSectionAsH4()`,
  `screenshotsAsList()` removed — post-processing belongs to consuming applications
  (e.g., `git-updater/Readme_Parser`), keeping `Parser` consistent with the upstream
  WP.org class interface.
- `$assets` public property and constructor parameter removed (was added alongside
  the post-processing methods; no longer needed).

### Changed
- Minimum PHP version lowered from 8.2 to 8.0; the 8.2 floor was imposed solely by
  `symfony/html-sanitizer ^7.0` and is no longer required.
- CI test matrix extended to PHP 8.0, 8.1, 8.2, 8.3, 8.4, 8.5.
- `ParsedownAdapter` safe mode changed from `true` to `false` so raw HTML in readme
  content passes through Parsedown to the sanitizer rather than being escaped first.
- Screenshot captions are now extracted from raw section text before Markdown
  conversion (regex `^\d+\.\s+(.+)$`) instead of matching `<li>` tags after
  conversion — fixes extraction when a pass-through Markdown stub is injected.
- Description fallback no longer sets `sections['description']` to an empty string
  when `short_description` is also empty (e.g. whitespace-only input).
- Copyright year updated to 2026.
- `@var`, `@param`, and `@return` type annotations added to all array-typed
  properties and methods; resolves PHPStan `missingType.iterableValue` errors
  introduced when `checkMissingIterableValueType` was removed from PHPStan 2.x.

### Fixed
- **PHP 8.5 CI failures** — dependency constraints updated for PHP 8.4+/8.5
  compatibility:
  - `erusev/parsedown` bumped from `^1.7` to `^1.8` (fixes implicit nullable
    deprecations fatal in PHP 8.5).
  - `phpunit/phpunit` widened to `^11.5.34 || ^12.0 || ^13.0`.
  - `phpstan/phpstan` widened to `^1.11 || ^2.0`.
- `phpstan.neon` — removed `checkMissingIterableValueType: false` (parameter was
  removed in PHPStan 2.x and caused an analysis error).
- `VersionSanitizationTest` — renamed data provider `testedVersionProvider` to
  `validTestedVersionProvider`; PHPUnit 13 treated the old name as both a data
  provider and a standalone test method because it began with `test`.

### Security
- `parseFile` (remote URL fetching) now enforces a 10-second timeout, a maximum of
  5 redirects, and a 1 MB response size cap to prevent hangs and memory exhaustion.
- `donate_link` header now validates that the URL scheme is `http` or `https`; all
  other schemes (`javascript:`, `data:`, `ftp:`, `file:`, protocol-relative, etc.)
  are silently discarded.
- `sanitizeStableTag` — `preg_replace` calls now use `?? $stableTag` fallback,
  preventing a `TypeError` in strict-types mode if either returns `null`.
- `preg_split` results guarded throughout with `?: [...]` fallbacks to prevent
  passing `false` to `array_map` on a catastrophic regex failure.

### Refactoring
- `public string $name` corrected to `public string|false $name`.
- `sanitizeTestedVersion` and `sanitizeRequiresVersion` consolidated into a shared
  private `sanitizeVersionHeader()` helper.
- CSV header splitting extracted into a private `splitCsvHeader()` helper.
- `htmlspecialchars(…)` calls extracted into a `protected encode()` helper.
- Remote URL detection consolidated into a private `isRemoteUrl()` helper.

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
- `ParsedownAdapter` wrapping `erusev/parsedown` ^1.8.
- PHPUnit 11 test suite split into focused test classes.
- GitHub Actions CI on PHP 8.2 and 8.3.
- PHPStan level 6 static analysis.
- PHP CS Fixer code-style enforcement (PER-CS 2.0).

### Differences from a WordPress-native environment
- No WordPress function dependencies (`wp_kses`, `get_user_by`, `esc_html`, etc.).
- Contributor slugs validated by format only — no live WordPress.org database lookup.
- `Tested up to` upper-bound cap (`WP_CORE_STABLE_BRANCH + 0.1`) is not enforced.

[Unreleased]: https://github.com/afragen/wp-readme-parser/compare/1.0.0...HEAD
[1.0.0]: https://github.com/afragen/wp-readme-parser/releases/tag/1.0.0
