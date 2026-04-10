<?php

declare(strict_types=1);

namespace Fragen\WP_Readme_Parser\Tests;

use Fragen\WP_Readme_Parser\Parser;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the post-processing helper methods:
 *   parseData(), createContributors(), faqAsH4(), readmeSectionAsH4(), screenshotsAsList().
 *
 * @covers \Fragen\WP_Readme_Parser\Parser
 */
class GitUpdaterHelpersTest extends ParserTestCase
{
    // -------------------------------------------------------------------------
    // parseData()
    // -------------------------------------------------------------------------

    #[Test]
    public function parse_data_returns_array_of_all_public_properties(): void
    {
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        foreach (['name', 'tags', 'requires', 'tested', 'requires_php', 'contributors',
                  'stable_tag', 'donate_link', 'short_description', 'license', 'license_uri',
                  'sections', 'upgrade_notice', 'screenshots', 'faq', 'warnings', 'raw_contents',
                  'assets'] as $key) {
            $this->assertArrayHasKey($key, $data, "parseData() must include '{$key}'");
        }
    }

    #[Test]
    public function parse_data_enriches_contributors_as_structured_array(): void
    {
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        // standard.txt has contributors: jsmith, jane-doe
        $this->assertArrayHasKey('jsmith',    $data['contributors']);
        $this->assertArrayHasKey('jane-doe',  $data['contributors']);
        $this->assertArrayHasKey('display_name', $data['contributors']['jsmith']);
        $this->assertArrayHasKey('profile',      $data['contributors']['jsmith']);
        $this->assertArrayHasKey('avatar',       $data['contributors']['jsmith']);
    }

    #[Test]
    public function parse_data_applies_faq_as_h4(): void
    {
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        $this->assertStringContainsString('<h4>', $data['sections']['faq']);
        $this->assertStringNotContainsString('<dl>', $data['sections']['faq']);
    }

    #[Test]
    public function parse_data_applies_readme_section_as_h4_to_changelog(): void
    {
        // The section heading `= 1.2.3 =` inside changelog is rendered by Parsedown
        // as `<p>=1.2.3=</p>`; readmeSectionAsH4 should promote it to `<h4>`.
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        // After conversion we expect no bare `<p>=…=</p>` patterns.
        $this->assertStringNotMatchesFormat('%A<p>=%A=%A</p>%A', $data['sections']['changelog']);
    }

    // -------------------------------------------------------------------------
    // createContributors()
    // -------------------------------------------------------------------------

    #[Test]
    public function create_contributors_returns_keyed_array_by_slug(): void
    {
        $parser = new Parser();
        $result = $parser->createContributors(['alice', 'bob-smith']);

        $this->assertArrayHasKey('alice',     $result);
        $this->assertArrayHasKey('bob-smith', $result);
    }

    #[Test]
    public function create_contributors_sets_display_name_to_slug(): void
    {
        $parser = new Parser();
        $result = $parser->createContributors(['alice']);

        $this->assertSame('alice', $result['alice']['display_name']);
    }

    #[Test]
    public function create_contributors_builds_wordpress_org_profile_url(): void
    {
        $parser = new Parser();
        $result = $parser->createContributors(['alice']);

        $this->assertSame('//profiles.wordpress.org/alice', $result['alice']['profile']);
    }

    #[Test]
    public function create_contributors_builds_gravatar_redirect_url(): void
    {
        $parser = new Parser();
        $result = $parser->createContributors(['alice']);

        $this->assertStringContainsString('wordpress.org/grav-redirect.php', $result['alice']['avatar']);
        $this->assertStringContainsString('alice', $result['alice']['avatar']);
    }

    #[Test]
    public function create_contributors_url_encodes_slug_in_avatar(): void
    {
        // Slugs are already lowercase alnum+hyphen in practice, but encoding is defensive.
        $parser = new Parser();
        $result = $parser->createContributors(['joe-bloggs']);

        $this->assertStringContainsString('joe-bloggs', $result['joe-bloggs']['avatar']);
    }

    #[Test]
    public function create_contributors_returns_empty_array_for_no_input(): void
    {
        $parser = new Parser();
        $this->assertSame([], $parser->createContributors([]));
    }

    // -------------------------------------------------------------------------
    // faqAsH4()
    // -------------------------------------------------------------------------

    #[Test]
    public function faq_as_h4_replaces_dl_with_h4_headings(): void
    {
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        // Verify <h4> is present and <dl> is not.
        $this->assertStringContainsString('<h4>', $data['sections']['faq']);
        $this->assertStringNotContainsString('<dl>', $data['sections']['faq']);
    }

    #[Test]
    public function faq_as_h4_emits_one_h4_per_question(): void
    {
        $parser = $this->parseFixture('valid/standard.txt');
        $data   = $parser->parseData();

        $count = substr_count($data['sections']['faq'], '<h4>');
        // standard.txt has 2 FAQ entries.
        $this->assertSame(2, $count);
    }

    #[Test]
    public function faq_as_h4_returns_data_unchanged_when_faq_is_empty(): void
    {
        $parser = $this->parse($this->makeReadme());
        $data   = (function () use ($parser) {
            // Build a minimal data array with no faq.
            return ['faq' => [], 'sections' => ['faq' => '']];
        })();

        $result = $parser->faqAsH4($data);
        $this->assertSame($data, $result);
    }

    // -------------------------------------------------------------------------
    // readmeSectionAsH4()
    // -------------------------------------------------------------------------

    #[Test]
    public function readme_section_as_h4_converts_paragraph_equals_pattern(): void
    {
        $parser = $this->parse($this->makeReadme());
        $data   = [
            'sections' => [
                'changelog' => "<p>= 1.0.0 =</p>\n<p>Initial release.</p>",
            ],
        ];

        $result = $parser->readmeSectionAsH4('changelog', $data);
        $this->assertStringContainsString('<h4>', $result['sections']['changelog']);
        $this->assertStringNotContainsString('<p>= 1.0.0 =</p>', $result['sections']['changelog']);
    }

    #[Test]
    public function readme_section_as_h4_does_not_double_convert_existing_h4(): void
    {
        $parser  = $this->parse($this->makeReadme());
        $original = "<h4>Already converted</h4>\n<p>Content.</p>";
        $data     = ['sections' => ['changelog' => $original]];

        $result = $parser->readmeSectionAsH4('changelog', $data);
        // Should be returned unchanged because it already contains <h4>.
        $this->assertSame($original, $result['sections']['changelog']);
    }

    #[Test]
    public function readme_section_as_h4_returns_data_unchanged_for_missing_section(): void
    {
        $parser = $this->parse($this->makeReadme());
        $data   = ['sections' => []];

        $result = $parser->readmeSectionAsH4('changelog', $data);
        $this->assertSame($data, $result);
    }

    #[Test]
    public function readme_section_as_h4_does_not_greedily_match_across_inner_equals(): void
    {
        // A heading that itself contains '=' characters (e.g. a version like '1.0=beta')
        // should not be swallowed by the inner [^=]+ pattern.
        $parser  = $this->parse($this->makeReadme());
        $data    = ['sections' => ['changelog' => "<p>= 1.0.0 =</p>\n<p>= 2.0.0 =</p>"]];
        $result  = $parser->readmeSectionAsH4('changelog', $data);

        // Both headings must be independently converted.
        $this->assertSame(2, substr_count($result['sections']['changelog'], '<h4>'));
    }

    #[Test]
    public function readme_section_as_h4_converts_multiple_headings_in_one_pass(): void
    {
        $parser = $this->parse($this->makeReadme());
        $data   = [
            'sections' => [
                'changelog' => "<p>= 2.0.0 =</p>\n<p>Big rewrite.</p>\n<p>= 1.0.0 =</p>\n<p>Initial.</p>",
            ],
        ];

        $result = $parser->readmeSectionAsH4('changelog', $data);
        $this->assertSame(2, substr_count($result['sections']['changelog'], '<h4>'));
    }

    // -------------------------------------------------------------------------
    // screenshotsAsList()
    // -------------------------------------------------------------------------

    #[Test]
    public function screenshots_as_list_builds_ol_with_asset_images(): void
    {
        $assets = [
            'screenshot-1.png' => 'https://example.com/screenshot-1.png',
            'screenshot-2.png' => 'https://example.com/screenshot-2.png',
        ];

        $parser = new Parser(
            __DIR__ . '/fixtures/valid/screenshots-assets.txt',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            $assets
        );
        $data = $parser->parseData();

        $this->assertStringContainsString('<ol>',    $data['sections']['screenshots']);
        $this->assertStringContainsString('<li>',    $data['sections']['screenshots']);
        $this->assertStringContainsString('<img',    $data['sections']['screenshots']);
        $this->assertStringContainsString('screenshot-1.png', $data['sections']['screenshots']);
    }

    #[Test]
    public function screenshots_as_list_matches_by_index(): void
    {
        $assets = [
            'screenshot-1.png' => 'https://example.com/s1.png',
            'screenshot-2.png' => 'https://example.com/s2.png',
            'screenshot-3.png' => 'https://example.com/s3.png',
        ];

        $parser = new Parser(
            __DIR__ . '/fixtures/valid/screenshots-assets.txt',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            $assets
        );
        $data = $parser->parseData();

        $this->assertStringContainsString('s2.png', $data['sections']['screenshots']);
        $this->assertStringContainsString('s3.png', $data['sections']['screenshots']);
    }

    #[Test]
    public function screenshots_as_list_html_encodes_url_and_caption(): void
    {
        $assets = [
            'screenshot-1.png' => 'https://example.com/img.png',
        ];

        $parser = new Parser(
            __DIR__ . '/fixtures/valid/screenshots-assets.txt',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            $assets
        );
        $data = $parser->parseData();

        // Caption text should be HTML-safe.
        $this->assertStringContainsString('The dashboard overview', $data['sections']['screenshots']);
        // No raw unencoded angle brackets from captions.
        $this->assertStringNotContainsString('<script>', $data['sections']['screenshots']);
    }

    #[Test]
    public function screenshots_as_list_returns_data_unchanged_when_no_assets(): void
    {
        $parser = $this->parseFixture('valid/screenshots-assets.txt');
        $data   = $parser->parseData();

        // No assets were supplied, so sections['screenshots'] should not be an <ol>.
        $this->assertStringNotContainsString('<ol>', $data['sections']['screenshots'] ?? '');
    }

    #[Test]
    public function screenshots_as_list_returns_data_unchanged_when_no_screenshots(): void
    {
        $parser = new Parser(
            '',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            ['screenshot-1.png' => 'https://example.com/s1.png']
        );

        $data   = ['screenshots' => [], 'sections' => []];
        $result = $parser->screenshotsAsList($data);
        $this->assertSame($data, $result);
    }

    #[Test]
    public function screenshots_as_list_skips_index_with_no_matching_asset(): void
    {
        // Only screenshot-1 asset provided; index 2 and 3 have no match.
        $assets = [
            'screenshot-1.png' => 'https://example.com/s1.png',
        ];

        $parser = new Parser(
            __DIR__ . '/fixtures/valid/screenshots-assets.txt',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            $assets
        );
        $data = $parser->parseData();

        // Only one <li> should be present.
        $this->assertSame(1, substr_count($data['sections']['screenshots'], '<li>'));
    }

    #[Test]
    public function screenshots_as_list_returns_data_unchanged_when_no_matching_assets(): void
    {
        // Assets exist but none start with 'screenshot-'.
        $parser = new Parser(
            __DIR__ . '/fixtures/valid/screenshots-assets.txt',
            $this->passThroughSanitizer(),
            $this->passThroughMarkdown(),
            ['banner-1544x500.png' => 'https://example.com/banner.png']
        );
        $data = $parser->parseData();

        $this->assertStringNotContainsString('<ol>', $data['sections']['screenshots'] ?? '');
    }

    // -------------------------------------------------------------------------
    // $assets constructor parameter
    // -------------------------------------------------------------------------

    #[Test]
    public function assets_are_stored_as_public_property(): void
    {
        $assets = ['screenshot-1.png' => 'https://example.com/s1.png'];
        $parser = new Parser('', null, null, $assets);
        $this->assertSame($assets, $parser->assets);
    }

    #[Test]
    public function assets_default_to_empty_array(): void
    {
        $parser = new Parser();
        $this->assertSame([], $parser->assets);
    }
}
