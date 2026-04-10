<?php

declare(strict_types=1);

/**
 * @copyright 2026 Andy Fragen
 * @license   MIT
 * @link      https://github.com/afragen/wp-readme-parser
 */

namespace Fragen\WP_Readme_Parser;

use Fragen\WP_Readme_Parser\Adapters\ParsedownAdapter;
use Fragen\WP_Readme_Parser\Adapters\SymfonyHtmlSanitizerAdapter;
use Fragen\WP_Readme_Parser\Contracts\HtmlSanitizerInterface;
use Fragen\WP_Readme_Parser\Contracts\MarkdownConverterInterface;

/**
 * WordPress.org Plugin Readme Parser.
 *
 * Parses a WordPress plugin readme.txt file into structured data without
 * any WordPress dependencies.
 *
 * Behaviour follows the readme.txt specification documented at:
 * https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
 *
 * Differences from a WordPress-native environment:
 *  - HTML sanitization via symfony/html-sanitizer (replaces wp_kses).
 *  - Markdown via erusev/parsedown (replaces the internal WP.org Markdown class).
 *  - Contributor slugs validated by format only; no live WordPress.org DB lookup.
 *  - The WP_CORE_STABLE_BRANCH upper-bound check on "Tested up to" is not enforced.
 *
 * @package Fragen\WP_Readme_Parser
 * @license MIT
 */
class Parser
{
    // -------------------------------------------------------------------------
    // Public result properties
    // -------------------------------------------------------------------------

    public string|false $name         = '';
    public array  $tags              = [];
    public string $requires          = '';
    public string $tested            = '';
    public string $requires_php      = '';
    public array  $contributors      = [];
    public string $stable_tag        = '';
    public string $donate_link       = '';
    public string $short_description = '';
    public string $license           = '';
    public string $license_uri       = '';
    public array  $sections          = [];
    public array  $upgrade_notice    = [];
    public array  $screenshots       = [];
    public array  $faq               = [];
    public string $raw_contents      = '';

    /**
     * Screenshot asset URLs, keyed by filename (e.g. 'screenshot-1.png' => 'https://…').
     * Supplied at construction time; used by screenshotsAsList().
     *
     * @var array<string, string>
     */
    public array $assets = [];

    /**
     * Warning flags set when specific parsing anomalies are encountered.
     *
     * Keys used:
     *   invalid_plugin_name_header    — The === Plugin Name === header was missing / wrong.
     *   ignored_tags                  — Tags that were removed (e.g. "plugin", "wordpress").
     *   too_many_tags                 — More than 5 tags were supplied.
     *   contributor_ignored           — One or more contributor slugs were invalid.
     *   requires_php_header_ignored   — Requires PHP value was not a valid x.y[.z] version.
     *   requires_header_ignored       — Requires at least value was invalid.
     *   tested_header_ignored         — Tested up to value was invalid.
     *   license_missing               — No License header was found.
     *   invalid_license               — License appears to be GPL-incompatible.
     *   unknown_license               — License could not be identified as compatible.
     *   no_short_description_present  — Short description was inferred from the description body.
     *   trimmed_short_description     — Short description was truncated to 150 chars.
     *   trimmed_section_*             — A section was truncated to its word limit.
     */
    public array $warnings = [];

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Sections we always recognise by name. */
    protected array $expected_sections = [
        'description',
        'installation',
        'faq',
        'screenshots',
        'changelog',
        'upgrade_notice',
        'other_notes',
    ];

    /** Section-name aliases: from => to. */
    protected array $alias_sections = [
        'frequently_asked_questions' => 'faq',
        'change_log'                 => 'changelog',
        'screenshot'                 => 'screenshots',
    ];

    /** Valid readme header keys (normalised) => property name. */
    protected array $valid_headers = [
        'tested'            => 'tested',
        'tested up to'      => 'tested',
        'requires'          => 'requires',
        'requires at least' => 'requires',
        'requires php'      => 'requires_php',
        'tags'              => 'tags',
        'contributors'      => 'contributors',
        'donate link'       => 'donate_link',
        'stable tag'        => 'stable_tag',
        'license'           => 'license',
        'license uri'       => 'license_uri',
    ];

    /** Tags silently removed from the tags list. */
    protected array $ignore_tags = ['plugin', 'wordpress'];

    /** Maximum lengths for individual fields. */
    protected array $maximum_field_lengths = [
        'short_description' => 150,
        'section'           => 2500,
        'section-changelog' => 5000,
        'section-faq'       => 5000,
    ];

    // -------------------------------------------------------------------------
    // Injected dependencies
    // -------------------------------------------------------------------------

    private HtmlSanitizerInterface    $sanitizerAdapter;
    private MarkdownConverterInterface $markdownAdapter;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string                          $input     A file path, URL, or the raw contents of a readme.
     * @param HtmlSanitizerInterface|null     $sanitizer Custom HTML sanitizer; defaults to the Symfony adapter.
     * @param MarkdownConverterInterface|null $markdown  Custom Markdown converter; defaults to the Parsedown adapter.
     * @param array<string, string>           $assets    Screenshot asset map: filename → URL (e.g. ['screenshot-1.png' => 'https://…']).
     *                                                   Used by screenshotsAsList(). Leave empty to skip image rendering.
     */
    public function __construct(
        string $input = '',
        ?HtmlSanitizerInterface $sanitizer = null,
        ?MarkdownConverterInterface $markdown = null,
        array $assets = [],
    ) {
        $this->sanitizerAdapter = $sanitizer ?? new SymfonyHtmlSanitizerAdapter();
        $this->markdownAdapter  = $markdown  ?? new ParsedownAdapter();
        $this->assets           = $assets;

        if ($input === '') {
            return;
        }

        $looksLikePath = strlen($input) <= PHP_MAXPATHLEN && !str_contains($input, "\n");

        if (
            ($looksLikePath && file_exists($input)) ||
            $this->isRemoteUrl($input) ||
            preg_match('!^data:text/plain!i', $input)
        ) {
            $this->parseFile($input);
        } else {
            $this->parseContents($input);
        }
    }

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    protected function parseFile(string $fileOrUrl): bool
    {
        $isRemote = $this->isRemoteUrl($fileOrUrl);

        $context = stream_context_create([
            'http' => [
                'user_agent'      => 'WP-Readme-Parser/1.0',
                'timeout'         => 10,
                'max_redirects'   => 5,
                'follow_location' => true,
            ],
        ]);

        $contents = file_get_contents($fileOrUrl, false, $context);

        if ($contents === false) {
            return false;
        }

        // Guard against unexpectedly large remote files (limit: 1 MB).
        if ($isRemote && strlen($contents) > 1_048_576) {
            return false;
        }

        return $this->parseContents($contents);
    }

    protected function parseContents(string $contents): bool
    {
        $this->raw_contents = $contents;

        // Split into lines, handling all line-ending styles.
        // preg_split can return false on a catastrophic regex failure; fall back to a
        // single-element array so the rest of the pipeline degrades gracefully.
        if (preg_match('!!u', $contents)) {
            $lines = preg_split('!\R!u', $contents) ?: [$contents];
        } else {
            $lines = preg_split('!\R!', $contents) ?: [$contents];
        }

        $lines = array_map([$this, 'stripNewlines'], $lines);

        // Strip UTF-8 BOM.
        if (str_starts_with($lines[0] ?? '', "\xEF\xBB\xBF")) {
            $lines[0] = substr($lines[0], 3);
        }

        // Convert UTF-16 LE files.
        if (str_starts_with($lines[0] ?? '', "\xFF\xFE")) {
            $lines = array_map(
                fn($l) => mb_convert_encoding($l, 'UTF-8', 'UTF-16'),
                $lines
            );
        }

        // --- Plugin name ---
        $line       = $this->getFirstNonWhitespace($lines);
        $this->name = $this->sanitizeText(trim($line, "#= \t\0\x0B"));

        // Guard: the first line looked like a header field, not a plugin name.
        if ($this->parsePossibleHeader($line, onlyValid: true)) {
            array_unshift($lines, $line);
            $this->warnings['invalid_plugin_name_header'] = true;
            $this->name = false;
        }

        // Strip GitHub-style underline (=== or ---).
        if (!empty($lines) && '' === trim($lines[0] ?? '', '=-')) {
            array_shift($lines);
        }

        // Handle readmes that literally say "=== Plugin Name ===" as the title.
        if (strtolower((string) $this->name) === 'plugin name') {
            $this->warnings['invalid_plugin_name_header'] = true;
            $this->name = false;

            $line = $this->getFirstNonWhitespace($lines);

            if (strlen($line) < 50 && !$this->parsePossibleHeader($line, onlyValid: true)) {
                $this->name = $this->sanitizeText(trim($line, "#= \t\0\x0B"));
            } else {
                array_unshift($lines, $line);
            }
        }

        // --- Header block ---
        $headers          = [];
        $line             = $this->getFirstNonWhitespace($lines);
        $lastLineWasBlank = false;

        do {
            $header = $this->parsePossibleHeader($line);

            if (!$header) {
                if ($line === '') {
                    $lastLineWasBlank = true;
                    continue;
                }
                // Non-blank, non-header line → start of the short description.
                break;
            }

            [$key, $value] = $header;

            if (isset($this->valid_headers[$key])) {
                $headers[$this->valid_headers[$key]] = $value;
            } elseif ($lastLineWasBlank) {
                // Jumped over a blank line and landed on an unknown key: went too far.
                break;
            }

            $lastLineWasBlank = false;
        } while (($line = array_shift($lines)) !== null);

        array_unshift($lines, $line);

        // Populate properties from parsed headers.
        $this->applyHeaders($headers);

        // --- Short description ---
        while (($line = array_shift($lines)) !== null) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($this->isH2Heading($trimmed)) {
                array_unshift($lines, $line);
                break;
            }
            $this->short_description .= $line . ' ';
        }
        $this->short_description = trim($this->short_description);

        // --- Body sections ---
        $this->sections = array_fill_keys($this->expected_sections, '');
        $current = $sectionName = $sectionTitle = '';

        while (($line = array_shift($lines)) !== null) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $current .= "\n";
                continue;
            }

            // H2-level heading (== or ##, but not ###).
            if ($this->isH2Heading($trimmed)) {
                if ($sectionName !== '') {
                    $this->sections[$sectionName] .= trim($current);
                }

                $current      = '';
                $sectionTitle = trim($line, "#= \t");
                $sectionName  = strtolower(str_replace(' ', '_', $sectionTitle));

                if (isset($this->alias_sections[$sectionName])) {
                    $sectionName = $this->alias_sections[$sectionName];
                }

                if (!in_array($sectionName, $this->expected_sections, true)) {
                    $current     .= '<h3>' . $sectionTitle . '</h3>';
                    $sectionName  = 'other_notes';
                }
                continue;
            }

            $current .= $line . "\n";
        }

        if ($sectionName !== '') {
            $this->sections[$sectionName] .= trim($current);
        }

        $this->sections = array_filter($this->sections);

        // Fallback: use short description as description body.
        if (empty($this->sections['description'])) {
            $this->sections['description'] = $this->short_description;
        }

        // Merge other_notes into description.
        if (!empty($this->sections['other_notes'])) {
            $this->sections['description'] .= "\n" . $this->sections['other_notes'];
            unset($this->sections['other_notes']);
        }

        // Upgrade notices → own array.
        if (isset($this->sections['upgrade_notice'])) {
            $this->upgrade_notice = $this->parseSubSection($this->sections['upgrade_notice']);
            $this->upgrade_notice = array_map([$this, 'sanitizeText'], $this->upgrade_notice);
            unset($this->sections['upgrade_notice']);
        }

        // Enforce word limits on sections.
        foreach ($this->sections as $section => $content) {
            $limitKey = "section-{$section}";
            if (!isset($this->maximum_field_lengths[$limitKey])) {
                $limitKey = 'section';
            }

            $trimmed = $this->trimLength($content, $limitKey, 'words');
            if ($content !== $trimmed) {
                $this->warnings["trimmed_section_{$section}"] = true;
            }
            $this->sections[$section] = $trimmed;
        }

        // FAQ → own array, rendered as <dl>.
        if (isset($this->sections['faq'])) {
            $this->faq             = $this->parseSubSection($this->sections['faq']);
            $this->sections['faq'] = '';
        }

        // Markdown → HTML.
        $this->sections       = array_map([$this, 'parseMarkdown'], $this->sections);
        $this->upgrade_notice = array_map([$this, 'parseMarkdown'], $this->upgrade_notice);
        $this->faq            = array_map([$this, 'parseMarkdown'], $this->faq);

        // Short description fallback from rendered description.
        if (!$this->short_description && !empty($this->sections['description'])) {
            $filtered = array_filter(explode("\n", $this->sections['description']));
            $this->short_description                        = reset($filtered);
            $this->warnings['no_short_description_present'] = true;
        }

        // Sanitize and trim short description.
        $this->short_description = $this->sanitizeText($this->short_description);
        $this->short_description = $this->parseMarkdown($this->short_description);
        $this->short_description = strip_tags($this->short_description);
        $trimmedShort            = $this->trimLength($this->short_description, 'short_description');
        if ($trimmedShort !== $this->short_description) {
            if (empty($this->warnings['no_short_description_present'])) {
                $this->warnings['trimmed_short_description'] = true;
            }
            $this->short_description = $trimmedShort;
        }

        // Screenshots → indexed array.
        if (isset($this->sections['screenshots'])) {
            preg_match_all('#<li>(.*?)</li>#is', $this->sections['screenshots'], $matches, PREG_SET_ORDER);
            if ($matches) {
                $i = 1;
                foreach ($matches as $match) {
                    $this->screenshots[$i++] = $this->filterHtml($match[1]);
                }
            }
            unset($this->sections['screenshots']);
        }

        // Render FAQ as <dl>.
        if (!empty($this->faq)) {
            if (isset($this->faq[''])) {
                $this->sections['faq'] .= $this->faq[''];
                unset($this->faq['']);
            }

            if ($this->faq) {
                $this->sections['faq'] .= "\n<dl>\n";
                foreach ($this->faq as $question => $answer) {
                    $slug                   = rawurlencode(trim(strtolower($question)));
                    $this->sections['faq'] .= "<dt id='{$slug}'><h3>{$question}</h3></dt>\n<dd>{$answer}</dd>\n";
                }
                $this->sections['faq'] .= "\n</dl>\n";
            }
        }

        // Final HTML sanitization pass.
        $this->sections = array_map([$this, 'filterHtml'], $this->sections);

        return true;
    }

    // -------------------------------------------------------------------------
    // Header helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to parse a line as a `Key: Value` header pair.
     *
     * @return false|array{string, string}
     */
    protected function parsePossibleHeader(string $line, bool $onlyValid = false): false|array
    {
        if (!str_contains($line, ':') || str_starts_with($line, '#') || str_starts_with($line, '=')) {
            return false;
        }

        [$key, $value] = explode(':', $line, 2);
        $key   = strtolower(trim($key, " \t*-\r\n"));
        $value = trim($value, " \t*-\r\n");

        if ($onlyValid && !isset($this->valid_headers[$key])) {
            return false;
        }

        return [$key, $value];
    }

    /**
     * Map parsed header values onto instance properties.
     */
    protected function applyHeaders(array $headers): void
    {
        if (!empty($headers['tags'])) {
            $tags = $this->splitCsvHeader($headers['tags']);

            $ignored = array_values(array_intersect($tags, $this->ignore_tags));
            if ($ignored) {
                $this->warnings['ignored_tags'] = $ignored;
                $tags = array_values(array_diff($tags, $this->ignore_tags));
            }

            if (count($tags) > 5) {
                $this->warnings['too_many_tags'] = array_slice($tags, 5);
                $tags = array_slice($tags, 0, 5);
            }

            $this->tags = $tags;
        }

        if (!empty($headers['requires'])) {
            $this->requires = $this->sanitizeRequiresVersion($headers['requires']);
        }
        if (!empty($headers['tested'])) {
            $this->tested = $this->sanitizeTestedVersion($headers['tested']);
        }
        if (!empty($headers['requires_php'])) {
            $this->requires_php = $this->sanitizeRequiresPhp($headers['requires_php']);
        }
        if (!empty($headers['contributors'])) {
            $this->contributors = $this->sanitizeContributors(
                $this->splitCsvHeader($headers['contributors'])
            );
        }
        if (!empty($headers['stable_tag'])) {
            $this->stable_tag = $this->sanitizeStableTag($headers['stable_tag']);
        }
        if (!empty($headers['donate_link'])) {
            $url = $headers['donate_link'];
            // Accept only safe http/https URLs; silently discard anything else.
            if (preg_match('!^https?://!i', $url)) {
                $this->donate_link = $url;
            }
        }
        if (!empty($headers['license'])) {
            // Extract a trailing URL from the license field if no separate URI was given.
            if (empty($headers['license_uri']) && preg_match('!(https?://\S+)!i', $headers['license'], $url)) {
                $headers['license_uri'] = trim($url[1], " -*\t\n\r\n(");
                $headers['license']     = trim(str_replace($url[1], '', $headers['license']), " -*\t\n\r\n(");
            }
            $this->license = $headers['license'];
        }
        if (!empty($headers['license_uri'])) {
            $this->license_uri = $headers['license_uri'];
        }

        // License validation.
        if (!$this->license) {
            $this->warnings['license_missing'] = true;
        } else {
            $result = $this->validateLicense($this->license);
            if ($result !== true) {
                $this->warnings[$result] = $this->license;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    protected function sanitizeText(string $text): string
    {
        return trim($this->encode(strip_tags($text)));
    }

    /**
     * Sanitize contributor slugs.
     *
     * Unlike the original, we do not validate against WordPress.org user accounts.
     * Invalid-looking slugs (containing spaces or disallowed characters) are warned
     * and dropped.
     */
    protected function sanitizeContributors(array $users): array
    {
        foreach ($users as $i => $name) {
            $name = ltrim($name, '@');

            // A WordPress.org username/nicename is lowercase alphanumeric + hyphens/dots/underscores.
            if (!preg_match('/^[a-z0-9._-]+$/i', $name)) {
                $this->warnings['contributor_ignored'] ??= [];
                $this->warnings['contributor_ignored'][] = $name;
                unset($users[$i]);
                continue;
            }

            $users[$i] = strtolower($name);
        }

        return array_values($users);
    }

    protected function sanitizeStableTag(string $stableTag): string
    {
        $stableTag = trim($stableTag, "\"' ");
        $stableTag = preg_replace('!^/?tags/!i', '', $stableTag) ?? $stableTag;
        $stableTag = preg_replace('![^a-z0-9_.-]!i', '', $stableTag) ?? $stableTag;

        if (str_starts_with($stableTag, '.')) {
            $stableTag = "0{$stableTag}";
        }

        return $stableTag;
    }

    protected function sanitizeRequiresPhp(string $version): string
    {
        $version = trim($version);

        if ($version && !preg_match('!^\d+(\.\d+){1,2}$!', $version)) {
            $this->warnings['requires_php_header_ignored'] = true;
            return '';
        }

        return $version;
    }

    protected function sanitizeTestedVersion(string $version): string
    {
        return $this->sanitizeVersionHeader(
            $version,
            ['WordPress', 'WP'],
            'tested_header_ignored'
        );
    }

    protected function sanitizeRequiresVersion(string $version): string
    {
        return $this->sanitizeVersionHeader(
            $version,
            ['WordPress', 'WP', 'or higher', 'and above', '+'],
            'requires_header_ignored'
        );
    }

    /**
     * Shared version-string sanitizer used by sanitizeTestedVersion() and
     * sanitizeRequiresVersion(). Strips known prefix/suffix phrases, removes
     * pre-release suffixes (e.g. `-RC1`), then validates the remainder as
     * `x.y` or `x.y.z`. Sets $warningKey and returns '' if validation fails.
     *
     * @param string   $version     Raw header value.
     * @param string[] $stripPhrases Case-insensitive phrases to remove before validation.
     * @param string   $warningKey  Warning array key to set on failure.
     */
    private function sanitizeVersionHeader(
        string $version,
        array $stripPhrases,
        string $warningKey,
    ): string {
        $version = trim($version);

        if ($version === '') {
            return $version;
        }

        $version = trim(str_ireplace($stripPhrases, '', $version));

        // Strip pre-release suffixes (-alpha, -RC1, -beta2, …).
        [$version] = explode('-', $version);
        $version = trim($version);

        if (!preg_match('!^\d+\.\d(\.\d+)?$!', $version)) {
            $this->warnings[$warningKey] = true;
            return '';
        }

        return $version;
    }

    // -------------------------------------------------------------------------
    // License validation
    // -------------------------------------------------------------------------

    public function validateLicense(string $license): true|string
    {
        // Normalize keyword lists once per process and cache them.
        static $normalizedCompatible   = null;
        static $normalizedIncompatible = null;

        $normalize = static function (string $s): string {
            $s = strtolower($s);
            $s = str_replace(['licence', 'clauses', 'creative commons'], ['license', 'clause', 'cc'], $s);
            $s = preg_replace('/(version |v)([0-9])/i', '$2', $s) ?? $s;
            $s = preg_replace('/(\s*[^a-z0-9. ]+\s*)/i', '', $s) ?? $s;
            return (string) preg_replace('/\s+/', '', $s);
        };

        if ($normalizedCompatible === null) {
            $normalizedCompatible = array_map($normalize, [
                'GPL', 'General Public License',
                'MIT', 'ISC', 'Expat',
                'Apache 2', 'Apache License 2',
                'X11', 'Modified BSD', 'New BSD', '3 Clause BSD', 'BSD 3',
                'FreeBSD', 'Simplified BSD', '2 Clause BSD', 'BSD 2',
                'MPL', 'Mozilla Public License',
                'WTFPL',
                'Public Domain', 'CC0', 'Unlicense',
                'CC BY',
                'zlib',
            ]);
        }

        if ($normalizedIncompatible === null) {
            $normalizedIncompatible = array_map($normalize, [
                '4 Clause BSD', 'BSD 4 Clause',
                'Apache 1',
                'CC BY-NC', 'CC-NC', 'NonCommercial',
                'CC BY-ND', 'NoDerivative',
                'EUPL', 'OSL',
                'Personal use', 'without permission', 'without prior auth', 'you may not',
                'Proprietary', 'proprietary',
            ]);
        }

        $normalizedLicense = $normalize($license);

        foreach ($normalizedIncompatible as $keyword) {
            if (str_contains($normalizedLicense, $keyword)) {
                return 'invalid_license';
            }
        }

        foreach ($normalizedCompatible as $keyword) {
            if (str_contains($normalizedLicense, $keyword)) {
                return true;
            }
        }

        return 'unknown_license';
    }

    // -------------------------------------------------------------------------
    // Sub-section parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a section body into an associative array of Heading => Content.
     * Used for FAQ and Upgrade Notice sections.
     */
    protected function parseSubSection(string|array $lines): array
    {
        if (!is_array($lines)) {
            $lines = explode("\n", $lines);
        }

        $trimmedLines = array_map('trim', $lines);
        $return       = [];
        $key          = '';
        $value        = '';

        // Decide whether headings are Markdown/wiki-style or bold-style (**text**).
        $headingStyle = 'bold';
        foreach ($trimmedLines as $t) {
            if ($t && ($t[0] === '#' || $t[0] === '=')) {
                $headingStyle = 'heading';
                break;
            }
        }

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line    = $lines[$i];
            $trimmed = $trimmedLines[$i];

            if ($trimmed === '') {
                $value .= "\n";
                continue;
            }

            $isHeading = match ($headingStyle) {
                'heading' => $trimmed[0] === '#' || $trimmed[0] === '=',
                'bold'    => str_starts_with($trimmed, '**') && str_ends_with($trimmed, '**'),
            };

            if ($isHeading) {
                if ($value !== '') {
                    $return[$key] = trim($value);
                }
                $value = '';
                $key   = trim($line, $trimmed[0] . " \t");
                continue;
            }

            $value .= $line . "\n";
        }

        if ($key !== '' || $value !== '') {
            $return[$key] = trim($value);
        }

        return $return;
    }

    // -------------------------------------------------------------------------
    // Post-processing helpers
    // -------------------------------------------------------------------------

    /**
     * Collect all parsed properties into an associative array and apply the
     * standard post-processing pipeline:
     *
     *   1. Contributors are expanded into structured profile records.
     *   2. FAQ entries are rendered as <h4> headings.
     *   3. Wiki-style sub-headings in changelog, description, and installation
     *      sections are promoted to <h4> elements.
     *   4. Screenshots are rendered as a linked <ol> when asset URLs are available.
     *
     * @return array<string, mixed>
     */
    public function parseData(): array
    {
        $data = [];

        foreach (get_object_vars($this) as $property => $value) {
            $data[$property] = ($property === 'contributors')
                ? $this->createContributors($value)
                : $value;
        }

        $data = $this->faqAsH4($data);

        foreach (['changelog', 'description', 'installation'] as $section) {
            $data = $this->readmeSectionAsH4($section, $data);
        }

        return $this->screenshotsAsList($data);
    }

    /**
     * Build a structured profile record for each contributor slug.
     *
     * WordPress.org exposes two public, stable endpoints for contributor data:
     *   - Profile page:  https://profiles.wordpress.org/{slug}
     *   - Avatar image:  https://wordpress.org/grav-redirect.php?user={slug}
     *
     * The returned array is keyed by slug; each value contains:
     *   display_name — the slug itself (human-readable name is not available
     *                  without a live API call).
     *   profile      — protocol-relative URL to the contributor's WP.org profile.
     *   avatar       — URL to the contributor's WP.org avatar image.
     *
     * @param  string[] $slugs Contributor slugs (already sanitized by the parser).
     * @return array<string, array{display_name: string, profile: string, avatar: string}>
     */
    public function createContributors(array $slugs): array
    {
        $result = [];

        foreach ($slugs as $slug) {
            $result[$slug] = [
                'display_name' => $slug,
                'profile'      => '//profiles.wordpress.org/' . $slug,
                'avatar'       => 'https://wordpress.org/grav-redirect.php?user=' . rawurlencode($slug),
            ];
        }

        return $result;
    }

    /**
     * Re-render FAQ entries as <h4> headings followed by their answers.
     *
     * The core parser emits FAQ content as a <dl> (definition list). This helper
     * rebuilds sections['faq'] using <h4> headings, which render more naturally
     * in most plugin-display contexts.
     *
     * Returns $data unchanged when no FAQ entries are present.
     *
     * @param  array<string, mixed> $data Parsed readme data array.
     * @return array<string, mixed>
     */
    public function faqAsH4(array $data): array
    {
        if (empty($data['faq'])) {
            return $data;
        }

        $html = '';
        foreach ($data['faq'] as $question => $answer) {
            $html .= "<h4>{$question}</h4>\n{$answer}\n";
        }

        $data['sections']['faq'] = $html;

        return $data;
    }

    /**
     * Promote Parsedown-rendered wiki headings to <h4> elements.
     *
     * Within section bodies, changelog entries and similar content often use
     * the `= Version =` heading syntax from the readme.txt format. Because this
     * is not standard Markdown, Parsedown emits them as `<p>= Version =</p>`
     * rather than heading elements. This method corrects that by replacing the
     * pattern `<p>=…=</p>` with `<h4>…</h4>`.
     *
     * Sections that already contain <h4> elements are skipped to avoid
     * double-processing.
     *
     * @param  string               $section Key of the section to process (e.g. 'changelog').
     * @param  array<string, mixed> $data    Parsed readme data array.
     * @return array<string, mixed>
     */
    public function readmeSectionAsH4(string $section, array $data): array
    {
        $content = $data['sections'][$section] ?? '';

        if ($content === '' || str_contains($content, '<h4>')) {
            return $data;
        }

        $replaced = preg_replace(
            '/<p>=([^=]+)=<\/p>/',
            '<h4>$1</h4>',
            $content
        );

        // preg_replace returns null only on a regex error; fall back to original content.
        $data['sections'][$section] = $replaced ?? $content;

        return $data;
    }

    /**
     * Render the screenshots index as an <ol> of linked images.
     *
     * Iterates over $data['screenshots'] (a 1-based integer-keyed array of
     * caption strings) and matches each index to a file in the asset map whose
     * name begins with `screenshot-{index}`. When a match is found, an <li>
     * element is emitted containing a linked thumbnail and a caption paragraph.
     *
     * The asset map is taken from $this->assets (set at construction time).
     * If no assets are available, or no screenshot captions were parsed, the
     * $data array is returned unchanged.
     *
     * All URLs and caption text are HTML-encoded before output.
     *
     * @param  array<string, mixed> $data Parsed readme data array.
     * @return array<string, mixed>
     */
    public function screenshotsAsList(array $data): array
    {
        if (empty($data['screenshots']) || empty($this->assets)) {
            return $data;
        }

        $items = '';
        foreach ($data['screenshots'] as $index => $caption) {
            $url = $this->findScreenshotAsset((int) $index);
            if ($url === null) {
                continue;
            }

            $safeUrl     = $this->encode($url);
            $safeCaption = $this->encode($caption);
            $items      .= "<li>"
                         . "<a href=\"{$safeUrl}\"><img src=\"{$safeUrl}\" alt=\"{$safeCaption}\"></a>"
                         . "<p>{$safeCaption}</p>"
                         . "</li>";
        }

        if ($items === '') {
            return $data;
        }

        $data['sections']['screenshots'] = "<ol>{$items}</ol>";

        return $data;
    }

    /**
     * Find the asset URL for a given screenshot index.
     *
     * Looks for a key in $this->assets that begins with `screenshot-{index}`
     * (e.g. `screenshot-1.png`, `screenshot-1.jpg`).
     *
     * @param  int         $index 1-based screenshot number.
     * @return string|null URL if found, null otherwise.
     */
    private function findScreenshotAsset(int $index): ?string
    {
        $prefix = "screenshot-{$index}";

        foreach ($this->assets as $filename => $url) {
            if (str_starts_with($filename, $prefix)) {
                return $url;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // HTML / Markdown
    // -------------------------------------------------------------------------

    protected function filterHtml(string $text): string
    {
        return trim($this->sanitizerAdapter->sanitize(trim($text)));
    }

    protected function parseMarkdown(string $text): string
    {
        return $this->markdownAdapter->toHtml($text);
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Split a comma-separated header value into a clean, re-indexed array.
     * Trims whitespace and discards empty entries.
     *
     * @return string[]
     */
    private function splitCsvHeader(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * HTML-encode a string for safe output in attributes and text content.
     * Encodes quotes and substitutes invalid UTF-8 sequences.
     */
    protected function encode(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Return true if the given string is an http:// or https:// URL.
     */
    private function isRemoteUrl(string $value): bool
    {
        return (bool) preg_match('!^https?://!i', $value);
    }

    protected function getFirstNonWhitespace(array &$lines): string
    {
        while (($line = array_shift($lines)) !== null) {
            if (trim($line) !== '') {
                return $line;
            }
        }

        return '';
    }

    protected function stripNewlines(string $line): string
    {
        return rtrim($line, "\r\n");
    }

    /**
     * Return true if $trimmed represents an H2-level heading.
     * Accepts `== Title ==` and `## Title` but NOT `### Sub`.
     */
    protected function isH2Heading(string $trimmed): bool
    {
        if ($trimmed[0] === '=' && isset($trimmed[1]) && $trimmed[1] === '=') {
            return true;
        }

        if (
            $trimmed[0] === '#' &&
            isset($trimmed[1]) && $trimmed[1] === '#' &&
            isset($trimmed[2]) && $trimmed[2] !== '#'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Trim content to a maximum length by character count or word count.
     *
     * @param string     $text   Text to trim.
     * @param string|int $length Named key in $maximum_field_lengths, or a raw integer.
     * @param string     $type   'char' or 'words'.
     */
    protected function trimLength(string $text, string|int $length = 150, string $type = 'char'): string
    {
        if (is_string($length)) {
            $length = $this->maximum_field_lengths[$length] ?? (int) $length;
        }

        if ($type === 'words') {
            $pieces = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

            // Fall back to a non-UTF-8 split if the input contains invalid UTF-8.
            if ($pieces === false) {
                $pieces = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
            }

            // Each word occupies 2 array slots (word + delimiter), except the last.
            $maxSlots = $length * 2;

            if (count($pieces) <= $maxSlots) {
                return $text;
            }

            return implode('', array_slice($pieces, 0, $maxSlots)) . ' &hellip;';
        }

        // Character-based trim: truncate, then prefer a clean sentence boundary
        // over a mid-word cut within the trailing 20% of the allowed length.
        $decoded = html_entity_decode($text);
        $strLen  = mb_strlen($decoded ?: $text);

        if ($strLen <= $length) {
            return $text;
        }

        // Truncate and append ellipsis first, then check for a cleaner sentence boundary.
        $text = mb_substr($text, 0, $length) . ' &hellip;';
        $pos  = mb_strrpos($text, '.');

        // If a sentence ends within the last 20% of the allowed length, trim to it instead.
        if ($pos !== false && $pos > (int) round(0.8 * $length) && mb_substr($text, -1) !== '.') {
            $text = mb_substr($text, 0, $pos + 1);
        }

        return trim($text);
    }
}
