<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Util\LocaleUtil;

/**
 * Provide methods to handle file meta information.
 *
 * @property array $metaFields
 */
class MetaWizard extends Widget
{
	/**
	 * Submit user input
	 * @var boolean
	 */
	protected $blnSubmitInput = true;

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'be_widget';

	/**
	 * @var array
	 */
	private $arrFieldErrors = array();

	/**
	 * Set an object property
	 *
	 * @param string $strKey   The property name
	 * @param mixed  $varValue The property value
	 */
	public function __set($strKey, $varValue)
	{
		if ($strKey == 'metaFields')
		{
			if (!ArrayUtil::isAssoc($varValue))
			{
				$varValue = array_combine($varValue, array_fill(0, \count($varValue), ''));
			}

			foreach ($varValue as $strArrKey => $varArrValue)
			{
				if (!\is_array($varArrValue))
				{
					$varValue[$strArrKey] = array('attributes' => $varArrValue);
				}
			}

			$this->arrConfiguration['metaFields'] = $varValue;
		}
		else
		{
			parent::__set($strKey, $varValue);
		}
	}

	/**
	 * Trim the values and add new languages if necessary
	 *
	 * @param mixed $varInput
	 *
	 * @return mixed
	 */
	public function validator($varInput)
	{
		if (!\is_array($varInput))
		{
			return null; // see #382
		}

		foreach ($varInput as $k=>$v)
		{
			if ($k != 'language')
			{
				if (!empty($v['link']))
				{
					$v['link'] = StringUtil::specialcharsUrl($v['link']);
				}

				foreach ($v as $kk => $vv)
				{
					$rgxp = $this->metaFields[$kk]['rgxp'] ?? null;

					if ($rgxp && !preg_match($rgxp, $vv))
					{
						$lang = LocaleUtil::formatAsLocale($k);
						$langTrans = System::getContainer()->get('contao.intl.locales')->getDisplayNames(array($lang))[$lang];
						$fieldLabel = $GLOBALS['TL_LANG']['MSC']['aw_' . $kk];

						$errorMsg = isset($this->metaFields[$kk]['rgxpErrMsg'])
							? sprintf($this->metaFields[$kk]['rgxpErrMsg'], $fieldLabel, $langTrans, $rgxp)
							: sprintf($GLOBALS['TL_LANG']['tl_files']['metaRgxpError'], $fieldLabel, $langTrans, $rgxp);

						$this->addError($errorMsg);
						$this->arrFieldErrors[$lang][$kk] = true;
					}
				}

				$varInput[$k] = array_map('trim', $v);
			}
			else
			{
				if ($v)
				{
					// Take the fields from the DCA (see #4327)
					$varInput[$v] = array_combine(array_keys($this->metaFields), array_fill(0, \count($this->metaFields), ''));
				}

				unset($varInput[$k]);
			}
		}

		return $varInput;
	}

	/**
	 * Generate the widget and return it as string
	 *
	 * @return string
	 */
	public function generate()
	{
		$count = 0;
		$return = '';

		$this->import(Database::class, 'Database');
		$this->import(BackendUser::class, 'User');

		// Only show the root page languages (see #7112, #7667)
		$objRootLangs = $this->Database->query("SELECT language FROM tl_page WHERE type='root'");
		$existing = $objRootLangs->fetchEach('language');

		foreach ($existing as $lang)
		{
			$lang = LocaleUtil::formatAsLocale($lang);

			if (!isset($this->varValue[$lang]))
			{
				$this->varValue[$lang] = array();
			}
		}

		// No languages defined in the site structure
		if (empty($this->varValue) || !\is_array($this->varValue))
		{
			return '<p class="tl_info">' . $GLOBALS['TL_LANG']['MSC']['metaNoLanguages'] . '</p>';
		}

		// Add the existing entries
		if (!empty($this->varValue))
		{
			$return = '<ul id="ctrl_' . $this->strId . '" class="tl_metawizard dcapicker">';
			$languages = System::getContainer()->get('contao.intl.locales')->getDisplayNames(array_keys($this->varValue));

			// Add the input fields
			foreach ($this->varValue as $lang=>$meta)
			{
				$return .= '
    <li data-language="' . $lang . '"><span class="lang">' . ($languages[$lang] ?? $lang) . ' ' . Image::getHtml('delete.svg', '', 'class="tl_metawizard_img" title="' . $GLOBALS['TL_LANG']['MSC']['delete'] . '" data-delete') . '</span>';

				// Take the fields from the DCA (see #4327)
				foreach ($this->metaFields as $field=>$fieldConfig)
				{
					$return .= '<label' . (isset($this->arrFieldErrors[$lang][$field]) ? ' class="error"' : '') . ' for="ctrl_' . $this->strId . '_' . $field . '_' . $count . '">' . $GLOBALS['TL_LANG']['MSC']['aw_' . $field] . '</label>';

					if (isset($fieldConfig['type']) && 'textarea' === $fieldConfig['type'])
					{
						$return .= '<textarea name="' . $this->strId . '[' . $lang . '][' . $field . ']" id="ctrl_' . $this->strId . '_' . $field . '_' . $count . '" class="tl_textarea"' . (!empty($fieldConfig['attributes']) ? ' ' . $fieldConfig['attributes'] : '') . '>' . ($meta[$field] ?? '') . '</textarea>';
					}
					else
					{
						$return .= '<input type="text" name="' . $this->strId . '[' . $lang . '][' . $field . ']" id="ctrl_' . $this->strId . '_' . $field . '_' . $count . '" class="tl_text" value="' . self::specialcharsValue($meta[$field] ?? '') . '"' . (!empty($fieldConfig['attributes']) ? ' ' . $fieldConfig['attributes'] : '') . '>';
					}

					// DCA picker
					if (isset($fieldConfig['dcaPicker']) && (\is_array($fieldConfig['dcaPicker']) || $fieldConfig['dcaPicker'] === true))
					{
						$return .= Backend::getDcaPickerWizard($fieldConfig['dcaPicker'], $this->strTable, $this->strField, $this->strId . '_' . $field . '_' . $count);
					}

					$return .= '<br>';
				}

				$return .= '
    </li>';

				++$count;
			}

			$return .= '
  </ul>';
		}

		return $return;
	}
}
