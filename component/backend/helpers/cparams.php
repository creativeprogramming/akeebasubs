<?php
/**
 * @package AkeebaSubs
 * @copyright Copyright (c)2010-2011 Nicholas K. Dionysopoulos
 * @license GNU General Public License version 3, or later
 */

class AkeebasubsHelperCparams
{
	public static function getParam($key, $default = null)
	{
		static $params = null;
		
		if(!is_object($params)) {
			jimport('joomla.application.component.helper');
			$component = JComponentHelper::getComponent('com_akeebasubs');
			$params = $component->params;
			if(!($params instanceof JRegistry)) {
				$params = new JParameter($params);
			}
		}
		
		return $params->getValue($key, $default);
	}
}