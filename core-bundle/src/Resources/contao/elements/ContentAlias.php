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
 * Front end content element "alias".
 */
class ContentAlias extends ContentElement
{
	/**
	 * Parse the template
	 *
	 * @return string
	 */
	public function generate()
	{
		$objElement = ContentModel::findByPk($this->cteAlias);

		if ($objElement === null)
		{
			return '';
		}

		$strClass = static::findClass($objElement->type);

		if (!class_exists($strClass))
		{
			return '';
		}

		if (is_a($strClass, ContentProxy::class, true))
		{
			if (!empty($this->cssID[1]))
			{
				$objElement->classes = array_merge((array) $objElement->classes, array($this->cssID[1]));
			}

			/** @var ContentProxy $proxy */
			$proxy = new $strClass($objElement, $this->strColumn);

			if (!empty($this->cssID[0]))
			{
				$proxy->cssID = ' id="' . $this->cssID[0] . '"';
			}

			return $proxy->generate();
		}

		$objElement->origId = $objElement->origId ?: $objElement->id;
		$objElement->id = $this->id;
		$objElement->typePrefix = 'ce_';

		/** @var ContentElement $objElement */
		$objElement = new $strClass($objElement, $this->strColumn);

		$cssID = StringUtil::deserialize($objElement->cssID, true);

		// Override the CSS ID (see #305)
		if (!empty($this->cssID[0]))
		{
			$cssID[0] = $this->cssID[0];
		}

		// Merge the CSS classes (see #6011)
		if (!empty($this->cssID[1]))
		{
			$cssID[1] = trim(($cssID[1] ?? '') . ' ' . $this->cssID[1]);
		}

		$objElement->cssID = $cssID;

		return $objElement->generate();
	}

	/**
	 * Generate the content element
	 */
	protected function compile()
	{
	}
}
