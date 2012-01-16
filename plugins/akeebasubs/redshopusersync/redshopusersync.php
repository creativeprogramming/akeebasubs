<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2012 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

/**
 * A sample plugin which creates two extra fields, age group and gender.
 * The former is mandatory, the latter is not
 */
class plgAkeebasubsRedshopusersync extends JPlugin
{
	/**
	 * This method is called whenever a user starts a new subscription and
	 * Akeeba Subscriptions wants to fetch user data. You can use it to fetch
	 * user information from additional sources and return them in an array.
	 * The values in the array will replace the values stored in the user's
	 * profile.
	 * 
	 * @param object $userData The already fetched user information
	 * 
	 * @return array A key/value array with user information overrides
	 */
	public function onAKUserGetData($userData)
	{
		if(empty($userData->username)) return array();
		$user_id = JFactory::getUser($userData->username)->id;
		
		$db = JFactory::getDbo();
		// Do we have an existing RedShop user record?
		$query = FOFQueryAbstract::getNew($db)
			->select('*')
			->from($db->nameQuote('#__redshop_users_info'))
			->where($db->nameQuote('user_id') .'='. $db->quote($user_id))
			->where($db->nameQuote('address_type') .' = '. $db->quote('BT'))
		;
		$db->setQuery($query);
		$dummy = $db->loadObject();
		if(!is_object($dummy)) {
			return array();
		}
		
		// Break down address
		$addressParts = explode(',',$dummy->address, 2);
		if(count($addressParts) == 2) {
			$address1 = trim($addressParts[0]);
			$address2 = trim($addressParts[1]);
		} else {
			$address1 = trim($dummy->address);
			$address2 = '';
		}
		
		// Get state
		$slen = strlen($userData->state);
		$query = FOFQueryAbstract::getNew($db)
			->select('state_2_code')
			->from($db->nameQuote('#__redshop_state'))
			->where($db->nameQuote('state_id').' = '.$db->quote($dummy->state_code))
			;
		$db->setQuery($query);
		$state = $db->loadResult();
		
		// @todo Get country
		$query = FOFQueryAbstract::getNew($db)
			->select('country_2_code')
			->from($db->nameQuote('#__redshop_country'))
			->where($db->nameQuote('country_id').' = '.$db->quote($dummy->country_code));
		$db->setQuery($query);
		$country = $db->loadResult();
		
		$ret = array(
			'email'			=> $dummy->user_email,
			'name'			=> $dummy->firstname.' '.$dummy->lastname,
			'vatnumber'		=> $dummy->vat_number,
			'country'		=> $country,
			'address1'		=> $address1,
			'address2'		=> $address2,
			'city'			=> $dummy->city,
			'state'			=> $state,
			'zip'			=> $dummy->zipcode,
			'viesregistered'=> $dummy->tax_exempt_approved,
			'isbusiness'	=> $dummy->is_company,
			'businessname'	=> $dummy->company_name,
		);
		
		return $ret;
	}
	
	/**
	 * This method is called whenever Akeeba Subscriptions is updating the user
	 * record with new information, either during sign-up or when you manually
	 * update this information in the back-end.
	 * 
	 * In this plugin, it does nothing, but it serves as an example for any
	 * developer interested in creating, for example, a "bridge" with a social
	 * component like Community Builder or JomSocial.
	 * 
	 * @param AkeebasubsTableUser $userData The user data
	 */
	public function onAKUserSaveData($userData)
	{
		$db = JFactory::getDbo();
		// Do we have an existing RedShop user record?
		$query = FOFQueryAbstract::getNew($db)
			->select('*')
			->from($db->nameQuote('#__redshop_users_info'))
			->where($db->nameQuote('user_id') .'='. $db->quote($userData->user_id))
			->where($db->nameQuote('address_type') .'='. $db->quote('BT'))
		;
		$db->setQuery($query);
		$dummy = $db->loadObject();
		if(!is_object($dummy)) {
			$id = 0;
			$sgid = 1;
			$phone = '';
			$ean = '';
		} else {
			$id = $dummy->users_info_id;
			$sgid = $dummy->shopper_group_id;
			$phone = $dummy->phone;
			$ean = $dummy->ean_number;
		}
		
		// Break down the name
		$user = JFactory::getUser($userData->user_id);
		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];
		if(count($nameParts) > 1) {
			$lastName = $nameParts[1];
		} else {
			$lastName = '';
		}
		
		// Get the address field
		$address = $userData->address1;
		if(!empty($userData->address2)) $address .= ', '.$userData->address2;
		
		// Get the country code
		$query = FOFQueryAbstract::getNew($db)
			->select('country_id')
			->from($db->nameQuote('#__redshop_country'))
			->where($db->nameQuote('country_2_code').' = '.$db->quote($userData->country));
		$db->setQuery($query);
		$country = $db->loadResult();
		
		// Get the state code
		$slen = strlen($userData->state);
		$query = FOFQueryAbstract::getNew($db)
			->select('state_id')
			->from($db->nameQuote('#__redshop_state'))
			->where($db->nameQuote('state_'.$slen.'_code').' = '.$db->quote($userData->state))
			->where($db->nameQuote('country_id').' = '.$db->quote($country))
			;
		$db->setQuery($query);
		$state = $db->loadResult();
		
		$rsData = (object)array(
			'user_id'		=> $userData->user_id,
			'user_email'	=> $user->email,
			'address_type'	=> 'BT',
			'firstname'		=> $firstName,
			'lastname'		=> $lastName,
			'vat_number'	=> $userData->vatnumber,
			'shopper_group_id' => $sgid,
			'country_code'	=> $country,
			'address'		=> $address,
			'city'			=> $userData->city,
			'state_code'	=> $state,
			'zipcode'		=> $userData->zip,
			'tax_exempt_approved' => $userData->viesregistered,
			'tax_exempt'	=> $userData->viesregistered,
			'approved'		=> 1,
			'is_company'	=> $userData->isbusiness,
			'company_name'	=> $userData->businessname,
			'phone'			=> $phone,
			'ean_number'	=> $ean,
			'requesting_tax_exempt' => 0
		);
		
		if($id == 0) {
			$result = $db->insertObject('#__redshop_users_info', $rsData);
		} else {
			$rsData->users_info_id = $id;
			$result = $db->updateObject('#__redshop_users_info', $rsData, 'users_info_id');
		}
		return $result;
	}
}