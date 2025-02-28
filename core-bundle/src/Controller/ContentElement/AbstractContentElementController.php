<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\AbstractFragmentController;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractContentElementController extends AbstractFragmentController
{
    public function __invoke(Request $request, ContentModel $model, string $section, array $classes = null): Response
    {
        $template = $this->createTemplate($model, 'ce_'.$this->getType());

        $this->addDefaultDataToTemplate(
            $template,
            $model->row(),
            $section,
            $classes ?? [],
            $request->attributes->get('templateProperties', []),
            $this->isBackendScope($request)
        );

        $this->tagResponse($model);

        return $this->getResponse($template, $model, $request);
    }

    protected function addSharedMaxAgeToResponse(Response $response, ContentModel $model): void
    {
        $time = time();
        $min = [];

        if ('' !== $model->start && $model->start > $time) {
            $min[] = (int) $model->start - $time;
        }

        if ('' !== $model->stop && $model->stop > $time) {
            $min[] = (int) $model->stop - $time;
        }

        if (empty($min)) {
            return;
        }

        $response->setSharedMaxAge(min($min));
    }

    /**
     * Add default content element data to the template context.
     *
     * @param array<string, mixed> $modelData
     * @param array<string>        $classes
     * @param array<string, mixed> $properties
     */
    protected function addDefaultDataToTemplate(FragmentTemplate $template, array $modelData = [], string $section = 'main', array $classes = [], array $properties = [], bool $asOverview = false): void
    {
        if ($this->isLegacyTemplate($template->getName())) {
            // Legacy fragments
            $this->addHeadlineToTemplate($template, $modelData['headline'] ?? null);
            $this->addCssAttributesToTemplate($template, $template->getName(), $modelData['cssID'] ?? null, $classes);
            $this->addPropertiesToTemplate($template, $properties);
            $this->addSectionToTemplate($template, $section);

            return;
        }

        $headlineData = StringUtil::deserialize($modelData['headline'] ?? [] ?: '', true);
        $attributesData = StringUtil::deserialize($modelData['cssID'] ?? [] ?: '', true);

        $template->setData([
            'type' => $this->getType(),
            'template' => $template->getName(),
            'as_overview' => $asOverview,
            'data' => $modelData,
            'section' => $section,
            'properties' => $properties,
            'attributes' => (new HtmlAttributes())
                ->setIfExists('id', $attributesData[0] ?? null)
                ->addClass($attributesData[1] ?? '', ...$classes),
            'headline' => [
                'text' => $headlineData['value'] ?? '',
                'tagName' => $headlineData['unit'] ?? 'h1',
            ],
        ]);
    }

    abstract protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}
