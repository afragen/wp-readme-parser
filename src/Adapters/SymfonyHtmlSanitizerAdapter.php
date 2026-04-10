<?php

declare(strict_types=1);

/**
 * @copyright 2024 Andy Fragen
 * @license   MIT
 * @link      https://github.com/afragen/wp-readme-parser
 */

namespace Fragen\WP_Readme_Parser\Adapters;

use Fragen\WP_Readme_Parser\Contracts\HtmlSanitizerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Wraps symfony/html-sanitizer to satisfy HtmlSanitizerInterface.
 *
 * The allowed element/attribute set mirrors the original WP.org parser's
 * wp_kses() allowlist exactly.
 */
class SymfonyHtmlSanitizerAdapter implements HtmlSanitizerInterface
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('a',          ['href', 'title', 'rel'])
            ->allowElement('blockquote', ['cite'])
            ->allowElement('br')
            ->allowElement('p')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('em')
            ->allowElement('strong')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('dl')
            ->allowElement('dt',         ['id'])
            ->allowElement('dd')
            ->allowElement('li')
            ->allowElement('h3')
            ->allowElement('h4');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
