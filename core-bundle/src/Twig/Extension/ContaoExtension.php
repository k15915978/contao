<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Twig\Extension;

use Contao\BackendTemplateTrait;
use Contao\CoreBundle\InsertTag\ChunkedText;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Twig\Inheritance\DynamicExtendsTokenParser;
use Contao\CoreBundle\Twig\Inheritance\DynamicIncludeTokenParser;
use Contao\CoreBundle\Twig\Inheritance\TemplateHierarchyInterface;
use Contao\CoreBundle\Twig\Interop\ContaoEscaper;
use Contao\CoreBundle\Twig\Interop\ContaoEscaperNodeVisitor;
use Contao\CoreBundle\Twig\Interop\PhpTemplateProxyNodeVisitor;
use Contao\CoreBundle\Twig\ResponseContext\AddTokenParser;
use Contao\CoreBundle\Twig\ResponseContext\DocumentLocation;
use Contao\CoreBundle\Twig\Runtime\FigureRendererRuntime;
use Contao\CoreBundle\Twig\Runtime\HighlighterRuntime;
use Contao\CoreBundle\Twig\Runtime\HighlightResult;
use Contao\CoreBundle\Twig\Runtime\InsertTagRuntime;
use Contao\CoreBundle\Twig\Runtime\LegacyTemplateFunctionsRuntime;
use Contao\CoreBundle\Twig\Runtime\PictureConfigurationRuntime;
use Contao\CoreBundle\Twig\Runtime\SchemaOrgRuntime;
use Contao\FrontendTemplateTrait;
use Contao\Template;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\Extension\EscaperExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @experimental
 */
final class ContaoExtension extends AbstractExtension
{
    private array $contaoEscaperFilterRules = [];

    public function __construct(private Environment $environment, private TemplateHierarchyInterface $hierarchy)
    {
        $contaoEscaper = new ContaoEscaper();

        /** @var EscaperExtension $escaperExtension */
        $escaperExtension = $environment->getExtension(EscaperExtension::class);
        $escaperExtension->setEscaper('contao_html', [$contaoEscaper, 'escapeHtml']);
        $escaperExtension->setEscaper('contao_html_attr', [$contaoEscaper, 'escapeHtmlAttr']);

        // Use our escaper on all templates in the "@Contao" and "@Contao_*"
        // namespaces, as well as the existing bundle templates we're already
        // shipping.
        $this->addContaoEscaperRule('%^@Contao(_[a-zA-Z0-9_-]*)?/%');
        $this->addContaoEscaperRule('%^@Contao(Core|Installation)/%');

        // Mark classes as safe for HTML that already escape their output themselves
        $escaperExtension->addSafeClass(HtmlAttributes::class, ['html', 'contao_html']);
        $escaperExtension->addSafeClass(HighlightResult::class, ['html', 'contao_html']);
    }

    /**
     * Adds a Contao escaper rule.
     *
     * If a template name matches any of the defined rules, it will be processed
     * with the "contao_html" escaper strategy. Make sure your rule will only
     * match templates with input encoded contexts!
     */
    public function addContaoEscaperRule(string $regularExpression): void
    {
        if (\in_array($regularExpression, $this->contaoEscaperFilterRules, true)) {
            return;
        }

        $this->contaoEscaperFilterRules[] = $regularExpression;
    }

    public function getNodeVisitors(): array
    {
        return [
            // Enables the "contao_twig" escaper for Contao templates with
            // input encoding
            new ContaoEscaperNodeVisitor(
                fn () => $this->contaoEscaperFilterRules
            ),
            // Allows rendering PHP templates with the legacy framework by
            // installing proxy nodes
            new PhpTemplateProxyNodeVisitor(self::class),
        ];
    }

    public function getTokenParsers(): array
    {
        return [
            // Overwrite the parsers for the "extends" and "include" tags to
            // additionally support the Contao template hierarchy
            new DynamicExtendsTokenParser($this->hierarchy),
            new DynamicIncludeTokenParser($this->hierarchy),
            // Add a parser for the Contao specific "add" tag
            new AddTokenParser(self::class),
        ];
    }

    public function getFunctions(): array
    {
        $includeFunctionCallable = $this->getTwigIncludeFunction()->getCallable();

        return [
            // Overwrite the "include" function to additionally support the
            // Contao template hierarchy
            new TwigFunction(
                'include',
                function (Environment $env, $context, $template, $variables = [], $withContext = true, $ignoreMissing = false, $sandboxed = false /* we need named arguments here */) use ($includeFunctionCallable) {
                    $args = \func_get_args();
                    $args[2] = DynamicIncludeTokenParser::adjustTemplateName((string) $template, $this->hierarchy);

                    return $includeFunctionCallable(...$args);
                },
                ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['all']]
            ),
            new TwigFunction(
                'attrs',
                static fn (iterable|string|HtmlAttributes|null $attributes = null): HtmlAttributes => new HtmlAttributes($attributes),
            ),
            new TwigFunction(
                'contao_figure',
                [FigureRendererRuntime::class, 'render'],
                ['is_safe' => ['html']]
            ),
            new TwigFunction(
                'picture_config',
                [PictureConfigurationRuntime::class, 'fromArray']
            ),
            new TwigFunction(
                'insert_tag',
                [InsertTagRuntime::class, 'renderInsertTag'],
            ),
            new TwigFunction(
                'add_schema_org',
                [SchemaOrgRuntime::class, 'add']
            ),
            new TwigFunction(
                'contao_sections',
                [LegacyTemplateFunctionsRuntime::class, 'renderLayoutSections'],
                ['needs_context' => true, 'is_safe' => ['html']]
            ),
            new TwigFunction(
                'contao_section',
                [LegacyTemplateFunctionsRuntime::class, 'renderLayoutSection'],
                ['needs_context' => true, 'is_safe' => ['html']]
            ),
        ];
    }

    public function getFilters(): array
    {
        $escaperFilter = static function (Environment $env, $string, $strategy = 'html', $charset = null, $autoescape = false) {
            if ($string instanceof ChunkedText) {
                $parts = [];

                foreach ($string as [$type, $chunk]) {
                    $parts[] = ChunkedText::TYPE_RAW === $type ?
                        $chunk : twig_escape_filter($env, $chunk, $strategy, $charset);
                }

                return implode('', $parts);
            }

            return twig_escape_filter($env, $string, $strategy, $charset, $autoescape);
        };

        return [
            // Overwrite the "escape" filter to additionally support chunked text
            new TwigFilter(
                'escape',
                $escaperFilter,
                ['needs_environment' => true, 'is_safe_callback' => 'twig_escape_filter_is_safe']
            ),
            new TwigFilter(
                'e',
                $escaperFilter,
                ['needs_environment' => true, 'is_safe_callback' => 'twig_escape_filter_is_safe']
            ),
            new TwigFilter(
                'insert_tag',
                [InsertTagRuntime::class, 'replaceInsertTags']
            ),
            new TwigFilter(
                'insert_tag_raw',
                [InsertTagRuntime::class, 'replaceInsertTagsChunkedRaw']
            ),
            new TwigFilter(
                'highlight',
                [HighlighterRuntime::class, 'highlight'],
            ),
            new TwigFilter(
                'highlight_auto',
                [HighlighterRuntime::class, 'highlightAuto'],
            ),
        ];
    }

    /**
     * @see \Contao\CoreBundle\Twig\Interop\PhpTemplateProxyNode
     * @see \Contao\CoreBundle\Twig\Interop\PhpTemplateProxyNodeVisitor
     *
     * @internal
     */
    public function renderLegacyTemplate(string $name, array $blocks, array $context): string
    {
        $template = Path::getFilenameWithoutExtension($name);

        $partialTemplate = new class($template) extends Template {
            use FrontendTemplateTrait;
            use BackendTemplateTrait;

            public function setBlocks(array $blocks): void
            {
                $this->arrBlocks = array_map(static fn ($block) => \is_array($block) ? $block : [$block], $blocks);
            }

            public function parse(): string
            {
                return $this->inherit();
            }

            protected function renderTwigSurrogateIfExists(): string|null
            {
                return null;
            }
        };

        $partialTemplate->setData($context);
        $partialTemplate->setBlocks($blocks);

        return $partialTemplate->parse();
    }

    /**
     * @see \Contao\CoreBundle\Twig\ResponseContext\AddNode
     * @see \Contao\CoreBundle\Twig\ResponseContext\AddTokenParser
     *
     * @internal
     */
    public function addDocumentContent(string|null $identifier, string $content, DocumentLocation $location): void
    {
        // TODO: This should make use of the response context in the future.
        if (DocumentLocation::head === $location) {
            if (null !== $identifier) {
                $GLOBALS['TL_HEAD'][$identifier] = $content;
            } else {
                $GLOBALS['TL_HEAD'][] = $content;
            }

            return;
        }

        if (DocumentLocation::endOfBody === $location) {
            if (null !== $identifier) {
                $GLOBALS['TL_BODY'][$identifier] = $content;
            } else {
                $GLOBALS['TL_BODY'][] = $content;
            }
        }
    }

    private function getTwigIncludeFunction(): TwigFunction
    {
        foreach ($this->environment->getExtension(CoreExtension::class)->getFunctions() as $function) {
            if ('include' === $function->getName()) {
                return $function;
            }
        }

        throw new \RuntimeException(sprintf('The %s class was expected to register the "include" Twig function but did not.', CoreExtension::class));
    }
}
