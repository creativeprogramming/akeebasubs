<?php
/**
 *  @package AkeebaSubs
 *  @copyright Copyright (c)2010-2014 Nicholas K. Dionysopoulos
 *  @license GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

class F0FFormHeaderAkeebasubsemailtemplatekey extends F0FFormHeaderFieldselectable
{
	protected function getOptions()
	{
		static $options = null;

		if (is_null($options))
		{
			if (!class_exists('AkeebasubsHelperEmail'))
			{
				require_once JPATH_ROOT.'/components/com_akeebasubs/helpers/email.php';
			}

			$options = AkeebasubsHelperEmail::getEmailKeys(1);
		}

		reset($options);

		return $options;
	}
}
