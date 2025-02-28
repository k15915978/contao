<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

/**
 * Front end content element "accordion".
 */
class ContentAccordion extends ContentElement
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'ce_accordionSingle';

	/**
	 * Generate the content element
	 */
	protected function compile()
	{
		$this->Template->text = StringUtil::encodeEmail($this->text);
		$this->Template->addImage = false;
		$this->Template->addBefore = false;

		// Add an image
		if ($this->addImage)
		{
			$figure = System::getContainer()
				->get('contao.image.studio')
				->createFigureBuilder()
				->from($this->singleSRC)
				->setSize($this->size)
				->setMetadata($this->objModel->getOverwriteMetadata())
				->enableLightbox((bool) $this->fullsize)
				->buildIfResourceExists();

			if (null !== $figure)
			{
				$figure->applyLegacyTemplateData($this->Template, $this->imagemargin, $this->floating);
			}
		}

		$classes = StringUtil::deserialize($this->mooClasses, true) + array(null, null);

		$this->Template->toggler = $classes[0] ?: 'toggler';
		$this->Template->accordion = $classes[1] ?: 'accordion';
		$this->Template->headlineStyle = $this->mooStyle;
		$this->Template->headline = $this->mooHeadline;
	}
}
