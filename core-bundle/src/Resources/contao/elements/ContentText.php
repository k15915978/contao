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
 * Front end content element "text".
 */
class ContentText extends ContentElement
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'ce_text';

	/**
	 * Generate the content element
	 */
	protected function compile()
	{
		// Add the static files URL to images
		if ($staticUrl = System::getContainer()->get('contao.assets.files_context')->getStaticUrl())
		{
			$path = System::getContainer()->getParameter('contao.upload_path') . '/';
			$this->text = str_replace(' src="' . $path, ' src="' . $staticUrl . $path, $this->text);
		}

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
	}
}
