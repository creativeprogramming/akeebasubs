<?php
/**
 *  @package AkeebaSubs
 *  @copyright Copyright (c)2010-2011 Nicholas K. Dionysopoulos
 *  @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

class AkeebasubsModelSubscribes extends FOFModel
{
	/**
	 * List of European states
	 *
	 * @var array
	 */
	private $european_states = array('AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK');
	
	/**
	 * Raw HTML source of the payment form, as returned by the payment plugin
	 *
	 * @var string
	 */
	private $paymentForm = '';
	
	/**
	 * File handle
	 *
	 * @var resource
	 */
	protected $_urand;
	
	/**
	 * @var int|null Coupon ID used in the price calculation
	 */
	protected $_coupon_id = null;
	
	/**
	 * @var int|null Upgrade ID used in the price calculation
	 */
	protected $_upgrade_id = null;
	
	/**
	 * We cache the results of all time-consuming operations, e.g. vat validation, subscription membership calculation,
	 * tax calculations, etc into this array, saved in the user's session.
	 * @var array
	 */
	private $_cache = array();
	
	public function __construct($config = array()) {
		parent::__construct($config);
		
		$session = JFactory::getSession();
		$encodedCacheData = $session->get('validation_cache_data', null, 'com_akeebasubs');
		if(!is_null($encodedCacheData)) {
			$this->_cache = json_decode($encodedCacheData, true);
		} else {
			$this->_cache = array();
		}
		
		// Load the state from cache, GET or POST variables
		if(!array_key_exists('state',$this->_cache)) {
			$this->_cache['state'] = array(
				'paymentmethod'	=> '',
				'username'		=> '',
				'password'		=> '',
				'password2'		=> '',
				'name'			=> '',
				'email'			=> '',
				'address1'		=> '',
				'address2'		=> '',
				'country'		=> 'XX',
				'state'			=> '',
				'city'			=> '',
				'zip'			=> '',
				'isbusiness'	=> '',
				'businessname'	=> '',
				'vatnumber'		=> '',
				'coupon'		=> '',
				'occupation'	=> '',
				'custom'		=> array()
			);
		}
		
		$rawDataCache = $this->_cache['state'];
		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$rawData = array_merge($rawDataCache, $rawDataGet, $rawDataPost);
		if(!empty($rawData)) foreach($rawData as $k => $v) {
			if(substr($k,0,1) == chr(0)) continue; // Don't ask...
			$this->setState($k, $v);
		}
		
		// Save the new state data in the cache
		$this->_cache['state'] = (array)($this->getState());
		$encodedCacheData = json_encode($this->_cache);
		$session->set('validation_cache_data', $encodedCacheData, 'com_akeebasubs');
	}
	
	private function getStateVariables()
	{
		$session = JFactory::getSession();
		$firstRun = $session->get('firstrun', true, 'com_akeebasubs');
		if($firstRun) {
			$session->set('firstrun', false, 'com_akeebasubs');
		}
		
		return (object)array(
			'firstrun'			=> $firstRun,
			'slug'				=> $this->getState('slug','','string'),
			'id'				=> $this->getState('id',0,'int'),
			'paymentmethod'		=> $this->getState('paymentmethod','none','cmd'),
			'processorkey'		=> $this->getState('processorkey','','raw'),
			'username'			=> $this->getState('username','','string'),
			'password'			=> $this->getState('password','','raw'),
			'password2'			=> $this->getState('password2','','raw'),
			'name'				=> $this->getState('name','','string'),
			'email'				=> $this->getState('email','','string'),
			'address1'			=> $this->getState('address1','','string'),
			'address2'			=> $this->getState('address2','','string'),
			'country'			=> $this->getState('country','','cmd'),
			'state'				=> $this->getState('state','','cmd'),
			'city'				=> $this->getState('city','','string'),
			'zip'				=> $this->getState('zip','','string'),
			'isbusiness'		=> $this->getState('isbusiness','','int'),
			'businessname'		=> $this->getState('businessname','','string'),
			'occupation'		=> $this->getState('occupation','','string'),
			'vatnumber'			=> $this->getState('vatnumber','','cmd'),
			'coupon'			=> $this->getState('coupon','','string'),
			'custom'			=> $this->getState('custom','','raw'),
			'opt'				=> $this->getState('opt','','cmd')
		);
	}
	
	/**
	 * Performs a validation
	 */
	public function getValidation()
	{
		$response = new stdClass();
		
		$state = $this->getStateVariables();
		
		if($state->slug && empty($state->id)) {
			 $list = FOFModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			 if(!empty($list)) {
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			 } else {
				$state->id = 0;
			 }
		}
		
		switch($state->opt)
		{
			case 'username':
				$response->validation = $this->_validateUsername();
				break;
				
			default:
				$response->validation = $this->_validateState();
				$response->validation->username = $this->_validateUsername()->username;
				$response->price = $this->_validatePrice();
				
				// Get the results from the custom validation
				$response->custom_validation = array();
				$response->custom_valid = true;
				jimport('joomla.plugin.helper');
				JPluginHelper::importPlugin('akeebasubs');
				$app = JFactory::getApplication();
				$jResponse = $app->triggerEvent('onValidate', array($state));
				if(is_array($jResponse) && !empty($jResponse)) {
					foreach($jResponse as $pluginResponse) {
						if(!is_array($pluginResponse)) continue;
						if(!array_key_exists('valid', $pluginResponse)) continue;
						if(!array_key_exists('custom_validation', $pluginResponse)) continue;
						$response->custom_valid = $response->custom_valid && $pluginResponse['valid'];
						$response->custom_validation = array_merge($response->custom_validation, $pluginResponse['custom_validation']);
						if(array_key_exists('data', $pluginResponse)) {
							$state = $pluginResponse['data'];
						}
					}
				}
				break;
		}
		return $response;
	}
	
	/**
	 * Validates the username for uniqueness
	 */
	private function _validateUsername()
	{
		$state = $this->getStateVariables();
		
		$ret = (object)array('username' => false);
		$username = $state->username;
		if(empty($username)) return $ret;
		$myUser = JFactory::getUser();
		$user = FOFModel::getTmpInstance('Jusers','AkeebasubsModel')
			->username($username)
			->getFirstItem();
		
		if($myUser->guest) {
			if(empty($user->username)) {
				$ret->username = true;
			} else {
				// If it's a blocked user, we should allow reusing the username;
				// this would be a user who tried to subscribe, closed the payment
				// window and came back to re-register. However, if the validation
				// field is non-empty, this is a manually blocked user and should
				// not be allowed to subscribe again.
				if($user->block) {
					if(!empty($user->activation)) {
						$ret->username = true;
					} else {
						$ret->username = false;
					}
				} else {
					$ret->username = false;
				}
			}
			
		} else {
			$ret->username = ($user->username == $myUser->username);
		}
		return $ret;
	}
	
	/**
	 * Validates the state data for completeness
	 */
	private function _validateState()
	{
		$state = $this->getStateVariables();
		
		// 1. Basic checks
		$ret = array(
			'name'			=> !empty($state->name),
			'email'			=> !empty($state->email),
			'address1'		=> !empty($state->address1),
			'country'		=> !empty($state->country),
			'state'			=> !empty($state->state),
			'city'			=> !empty($state->city),
			'zip'			=> !empty($state->zip),
			'businessname'	=> !empty($state->businessname),
			'vatnumber'		=> !empty($state->vatnumber),
			'coupon'		=> !empty($state->coupon)
		);
		
		$ret['rawDataForDebug'] = (array)$state;
		
		// Name validation; must contain AT LEAST two parts (name/surname)
		// separated by a space
		if(!empty($state->name)) {
			$name = trim($state->name);
			$nameParts = explode(" ", $name);
			if(count($nameParts) < 2) $ret['name'] = false;
		}
		
		// Email validation
		if(!empty($state->email)) {
			$list = FOFModel::getTmpInstance('Jusers','AkeebasubsModel')
				->email($state->email)
				->getItemList();
			$validEmail = true;
			foreach($list as $item) {
				if($item->email == $state->email) {
					if($item->id != JFactory::getUser()->id) {
						if(!$item->block) {
							// Email belongs to a non-blocked user; this is not allowed.
							$validEmail = false;
							break;
						} else {
							// Email belongs to a blocked user. Allow reusing it,
							// if the user is not activated yet. The idea is that
							// a newly created user is blocked and has the activation
							// field filled in. This is a user who failed to complete
							// his subscription. If the validation field is empty, it
							// is a user blocked by the administrator who should not
							// be able to subscribe again!
							if(empty($item->activation)) {
								$validEmail = false;
								break;
							}
						}
					}
				}
			}
			$ret['email'] = $validEmail;
		} else {
			$ret['email'] = false;
		}
		
		// 2. Country validation
		if($ret['country']) {
			require_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/helpers/select.php';
			$ret['country'] = array_key_exists($state->country, AkeebasubsHelperSelect::$countries);
		}
		
		// 3. State validation
		if(in_array($state->country,array('US','CA'))) {
			require_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/helpers/select.php';
			$ret['state'] = array_key_exists($state->state, AkeebasubsHelperSelect::$states);
		} else {
			$ret['state'] = true;
		}
		
		// 4. Business validation
		if(!$state->isbusiness) {
			$ret['businessname'] = true;
			$ret['vatnumber'] = false;
		} else {
			// Do I have to check the VAT number?
			if(in_array($state->country, $this->european_states)) {
				// If the country has two rules with VIES enabled/disabled and a non-zero VAT,
				// we will skip VIES validation. We'll also skip validation if there are no
				// rules for this country (the default tax rate will be applied)
				$taxrules = FOFModel::getTmpInstance('Taxrules','AkeebasubsModel')
					->enabled(1)
					->country($state->country)
					->filter_order('ordering')
					->filter_order_Dir('ASC')
					->limit(0)
					->limitstart(0)
					->getList();
				$catchRules = 0;
				$lastVies = null;
				if(!empty($taxrules)) foreach($taxrules as $rule) {
					if( empty($rule->state) && empty($rule->city) && $rule->taxrate && ($lastVies != $rule->vies) ) {
						$catchRules++;
						$lastVies = $rule->vies;
					}
				}
				$mustCheck = ($catchRules < 2) && ($catchRules > 0);
			
				if($mustCheck) {
					// Validate VAT number
					$vat = trim(strtoupper($state->vatnumber));
					$country = ($state->country == 'GR') ? 'EL' : $state->country;
					// (remove the country prefix if present)
					if(substr($vat,0,2) == $country) $vat = trim(substr($vat,2));
					
					$url = 'http://isvat.appspot.com/'.$country.'/'.$vat.'/';
					
					// Is the validation already cached?
					$key = $country.$vat;
					$ret['vatnumber'] = null;
					if(array_key_exists('vat', $this->_cache)) {
						if(array_key_exists($key, $this->_cache['vat'])) {
							$ret['vatnumber'] = $this->_cache['vat'][$key];
						}
					}				
					
					if(is_null($ret['vatnumber']))
					{
						$res = @file_get_contents($url);
						if($res === false) {
							$ch = curl_init($url);
							curl_setopt($ch, CURLOPT_HEADER, 0);
							curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
							$res = @curl_exec($ch);
						}
		
						if($res !== false) {
							$res = @json_decode($res);
						}
						
						$ret['vatnumber'] = $res === true;
						
						if(!array_key_exists('vat', $this->_cache)) {
							$this->_cache['vat'] = array();
						}
						$this->_cache['vat'][$key] = $ret['vatnumber'];
						$encodedCacheData = json_encode($this->_cache);
						
						$session = JFactory::getSession();
						$session->set('validation_cache_data', $encodedCacheData, 'com_akeebasubs');
					}
					$ret['novatrequired'] = false;
				} else {
					$ret['novatrequired'] = true;
				}
			}
		}
		
		// 5. Coupon validation
		$ret['coupon'] = $this->_validateCoupon(true);
		
		return (object)$ret;
	}
	
	/**
	 * Calculates the level's price applicable to the specific user and the
	 * actual state information
	 */
	private function _validatePrice()
	{
		$state = $this->getStateVariables();
		
		// Get the default price value
		$level = FOFModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->setId($state->id)
			->getItem();
		$netPrice = (float)$level->price;

		// Coupon discount
		$couponDiscount = 0;
		$validCoupon = $this->_validateCoupon(false);
		
		$couponDiscount = 0;
		if($validCoupon) {
			$list = FOFModel::getTmpInstance('Coupons','AkeebasubsModel')
				->coupon(strtoupper($state->coupon))
				->getItemList();
			$coupon = array_pop($list);
				
			switch($coupon->type) {
				case 'value':
					$couponDiscount = (float)$coupon->value;
					if($couponDiscount > $netPrice) $couponDiscount = $netPrice;
					if($couponDiscount <= 0) $couponDiscount = 0;
					break;
					
				case 'percent':
					$percent = (float)$coupon->value / 100.0;
					if( $percent <= 0 ) $percent = 0;
					if( $percent > 1 ) $percent = 1;
					$couponDiscount = $percent * $netPrice;
					break;
			}
		} else {
			$this->_coupon_id = null;
		}
		
		// Upgrades (auto-rule) validation
		$autoDiscount = 0;
		$autoDiscount = $this->_getAutoDiscount();
		
		$useCoupon = false;
		$useAuto = false;
		if($validCoupon) {
			if($autoDiscount > $couponDiscount) {
				$discount = $autoDiscount;
				$useAuto = true;
				$this->_coupon_id = null;
			} else {
				$discount = $couponDiscount;
				$useCoupon = true;
				$this->_upgrade_id = null;
			}	
		} else {
			$this->_coupon_id = null;
			$discount = $autoDiscount;
			$useAuto = true;
		}
		
		$discount = $useCoupon ? $couponDiscount : $autoDiscount;
		
		// Get the applicable tax rule
		$taxRule = $this->_getTaxRule();
		
		return (object)array(
			'net'		=> sprintf('%1.02f',$netPrice),
			'discount'	=> sprintf('%1.02f',$discount),
			'taxrate'	=> sprintf('%1.02f',(float)$taxRule->taxrate),
			'tax'		=> sprintf('%1.02f',0.01 * $taxRule->taxrate * ($netPrice - $discount)),
			'gross'		=> sprintf('%1.02f',($netPrice - $discount) + 0.01 * $taxRule->taxrate * ($netPrice - $discount)),
			'usecoupon'	=> $useCoupon ? 1 : 0,
			'useauto'	=> $useAuto ? 1 : 0,
			'couponid'	=> is_null($this->_coupon_id) ? 0 : $this->_coupon_id,
			'upgradeid'	=> is_null($this->_upgrade_id) ? 0 : $this->_upgrade_id
		);
	}
	
	/**
	 * Validates a coupon code, making sure it exists, it's activated, it's not expired,
	 * it applies to the specific subscription and user.
	 */
	private function _validateCoupon($validIfNotExists = true)
	{
		static $couponCode = null;
		static $valid = false;
		static $couponid = null;
		
		$state = $this->getStateVariables();
		$this->_coupon_id = null;
	
		if($state->coupon) {
			if($state->coupon == $couponCode) {
				$this->_coupon_id = $valid ? $couponid : null;
				return $valid;
			}
		}

		$valid = $validIfNotExists;		
		if($state->coupon) {
			$couponCode = $state->coupon;
			$valid = false;
			
			$coupons = FOFModel::getTmpInstance('Coupons','AkeebasubsModel')
				->coupon(strtoupper($state->coupon))
				->getItemList();
			$coupon = empty($coupons) ? null : array_pop($coupons);
				
			if(is_object($coupon)) {
				$valid = false;
				if($coupon->enabled && (strtoupper($coupon->coupon) == strtoupper($couponCode)) ) {
					// Check validity period
					jimport('joomla.utilities.date');
					$jFrom = new JDate($coupon->publish_up);
					$jTo = new JDate($coupon->publish_down);
					$jNow = new JDate();
					
					$valid = ($jNow->toUnix() >= $jFrom->toUnix()) && ($jNow->toUnix() <= $jTo->toUnix());
					
					// Check levels list
					if($valid && !empty($coupon->subscriptions)) {
						$levels = explode(',', $coupon->subscriptions);
						$valid = in_array($state->id, $levels);
					}
					
					// Check user
					if($valid && $coupon->user) {
						$user_id = JFactory::getUser()->id;
						$valid = $user_id == $coupon->user;
					}
					
					// Check hits limit
					if($valid && $coupon->hitslimit) {
						// Get the real coupon hits
						$hits = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
							->coupon_id($coupon->akeebasubs_coupon_id)
							->paystate('C')
							->limit(0)
							->limistart(0)
							->getTotal();
						if($coupon->hitslimit >= 0) {
							$valid = $hits < $coupon->hitslimit;
							if(($coupon->hits != $hits) || ($hits >= $coupon->hitslimit)) {
								$coupon->hits = $hits;
								$coupon->enabled = $hits < $coupon->hitslimit;
								$coupon->save();
							}
						}
					}
				} else {
					$valid = false;
				}
			}
		}
		
		$this->_coupon_id = $valid ? $couponid : null;
		return $valid;
	}
	
	/**
	 * Loads any relevant upgrade (auto discount) rules and returns the max
	 * discount possible under those rules.
	 *
	 * @return array Discount type and value
	 */
	private function _getAutoDiscount()
	{
		$state = $this->getStateVariables();
		
		// Check that we do have a user (if there's no logged in user, we have
		// no subscription information, ergo upgrades are not applicable!)
		$user_id = JFactory::getUser()->id;
		if(empty($user_id)) {
			$this->_upgrade_id = null;
			return 0;
		}
		
		// Get applicable auto-rules
		$autoRules = FOFModel::getTmpInstance('Upgrades','AkeebasubsModel')
			->to_id($state->id)
			->enabled(1)
			->limit(0)
			->limitstart(0)
			->getItemList();
			
		if(empty($autoRules)) {
			$this->_upgrade_id = null;
			return 0;
		}
		
		// Get the user's list of subscriptions
		$subscriptions = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
			->user_id($user_id)
			->enabled(1)
			->limit(0)
			->limitstart(0)
			->getList();
			
		if(empty($subscriptions)) {
			$this->_upgrade_id = null;
			return 0;
		}
		
		$subs = array();
		jimport('joomla.utilities.date');
		$jNow = new JDate();
		$uNow = $jNow->toUnix();
		foreach($subscriptions as $subscription) {
			$jFrom = new JDate($subscription->publish_up);
			$uFrom = $jFrom->toUnix();
			$presence = $uNow - $uFrom;
			$subs[$subscription->akeebasubs_level_id] = $presence;
		}
		
		// Get the current subscription level's net worth
		$level = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
			->setId($state->id)
			->getItem();
		$net = (float)$level->price;
		
		if($net == 0) {
			$this->_upgrade_id = null;
			return 0;
		}
		
		$discount = 0;
		$this->_upgrade_id = null;
		
		foreach($autoRules as $rule) {
			if(!array_key_exists($rule->from_id, $subs)) continue;
			if($subs[$rule->from_id] < $rule->min_presence*86400) continue;
			if($subs[$rule->from_id] > $rule->max_presence*86400) continue;
			
			switch($rule->type) {
				case 'value':
					if($rule->value > $discount) {
						$discount = $rule->value;
						$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					}
					break;
					
				case 'percent':
					$newDiscount = $net * (float)$rule->value / 100.00;
					if($newDiscount > $discount) {
						$discount = $newDiscount;
						$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					}
					break;
			}
		}
		
		return $discount;
	}
	
	/**
	 * Gets the applicable tax rule based on the state variables
	 */
	private function _getTaxRule()
	{
		// Do we have a VIES registered VAT number?
		$validation = $this->_validateState();
		$state = $this->getStateVariables();
		$isVIES = $validation->vatnumber && in_array($state->country, $this->european_states);
		
		// Load the tax rules
		$taxrules = FOFModel::getTmpInstance('Taxrules', 'AkeebasubsModel')
			->enabled(1)
			->filter_order('ordering')
			->filter_order_Dir('ASC')
			->limit(0)
			->limitstart(0)
			->getItemList();

		$bestTaxRule = (object)array(
			'match'		=> 0,
			'fuzzy'		=> 0,
			'taxrate'	=> 0
		);
			
		foreach($taxrules as $rule)
		{
			// For each rule, get the match and fuzziness rating. The best, least fuzzy and last match wins.
			$match = 0;
			$fuzzy = 0;
			
			if(empty($rule->country)) {
				$match++;
				$fuzzy++;
			} elseif($rule->country == $state->country) {
				$match++;
			}
			
			if(empty($rule->state)) {
				$match++;
				$fuzzy++;
			} elseif($rule->state == $state->state) {
				$match++;
			}
			
			if(empty($rule->city)) {
				$match++;
				$fuzzy++;
			} elseif(strtolower(trim($rule->city)) == strtolower(trim($state->city))) {
				$match++;
			}
			
			if( ($rule->vies && $isVIES) || (!$rule->vies && !$isVIES)) {
				$match++;
			}
			
			if(
				($bestTaxRule->match < $match) ||
				( ($bestTaxRule->match == $match) && ($bestTaxRule->fuzzy > $fuzzy) )
			) {
				if($match == 0) continue;
				$bestTaxRule->match = $match;
				$bestTaxRule->fuzzy = $fuzzy;
				$bestTaxRule->taxrate = $rule->taxrate;
				$bestTaxRule->id = $rule->akeebasubs_taxrule_id;
			}
		}
		return $bestTaxRule;
	}
	
	/**
	 * Gets a list of payment plugins and their titles
	 */
	public function getPaymentPlugins()
	{
		jimport('joomla.plugin.helper');
		JPluginHelper::importPlugin('akpayment');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentGetIdentity');

		return $jResponse; // name, title
	}
	
	/**
	 * Processes the form data and creates a new subscription
	 */
	public function createNewSubscription()
	{
		// Step #1. Check the validity of the user supplied information
		// ----------------------------------------------------------------------
		$validation = $this->getValidation();
		$state = $this->getStateVariables();
		
		// Iterate the core validation rules
		$isValid = true;
		foreach($validation->validation as $key => $validData)
		{
			require_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/helpers/cparams.php';
			if(!AkeebasubsHelperCparams::getParam('personalinfo',1)) {
				if(!in_array($key, array('username','email','name'))) continue;
			}
			// An invalid (not VIES registered) VAT number is not a fatal error
			if($key == 'vatnumber') continue;
			// A wrong coupon code is not a fatal error
			if($key == 'coupon') continue;
			// A missing business occupation is not a fatal error either
			if($key == 'occupation') continue;
			// This is a dummy key which must be ignored
			if($key == 'novatrequired') continue;
			
			$isValid = $isValid && $validData;
			if(!$isValid) {
				if($key == 'username') {
					$user = JFactory::getUser();
					if($user->username == $state->username) {
						$isValid = true;
					} else {
						break;
					}
				}
				break;
			}
		}
		// Make sure custom fields also validate
		$isValid = $isValid && $validation->custom_valid;
		
		if(!$isValid) return false;

		// Step #2. Check that the payment plugin exists or return false
		// ----------------------------------------------------------------------
		$plugins = $this->getPaymentPlugins();
		$found = false;
		if(!empty($plugins)) {
			foreach($plugins as $plugin) {
				if($plugin->name == $state->paymentmethod) {
					$found = true;
					break;
				}
			}
		}
		if(!$found) return false;
		
		// Reset the session flag, so that future registrations will merge the
		// data from the database
		JFactory::getSession()->set('firstrun', true, 'com_akeebasubs');

		// Step #3. Create or update a user record
		// ----------------------------------------------------------------------
		$user = JFactory::getUser();
		
		if($user->id == 0) {
			// Check for an existing, blocked, unactivated user with the same
			// username or email address.
			$user1 = FOFModel::getTmpInstance('Jusers','AkeebasubsModel')
				->username($state->username)
				->block(1)
				->getFirstItem();
			$user2 = FOFModel::getTmpInstance('Jusers','AkeebasubsModel')
				->email($state->email)
				->block(1)
				->getFirstItem();
			$id1 = $user1->id;
			$id2 = $user2->id;
			// Do we have a match?
			if($id1 || $id2) {
				if($id1 == $id2) {
					// Username and email match with the blocked user; reuse that
					// user, please.
					$user = JFactory::getUser($user1->id);
				} else {
					// Remove the last subscription for $user2 (it will be an unpaid one)
					$submodel = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel');
					$substodelete = $submodel
						->user_id($id2)
						->getList();
					if(!empty($substodelete)) foreach($substodelete as $subtodelete) {
						$subtable = $submodel->getTable();
						$subtable->delete($subtodelete->akeebasubs_subscription_id);
					}
					
					// Remove $user2 and set $user to $user1 so that it gets updated
					$user2->delete($id2);
					$user = JFactory::getUser($user1->id);
				}
			}
		}
		
		if(is_null($user->id) || ($user->id == 0)) {
			// New user
			$params = array(
				'name'			=> $state->name,
				'username'		=> $state->username,
				'email'			=> $state->email,
				'password'		=> $state->password,
				'password2'		=> $state->password2
			);
			
			$user = JFactory::getUser(0);

			jimport('joomla.application.component.helper');
			$usersConfig = &JComponentHelper::getParams( 'com_users' );
			$newUsertype = $usersConfig->get( 'new_usertype' );
			
			if(version_compare(JVERSION, '1.6.0', 'ge')) {
				// get the New User Group from com_users' settings
				if(empty($newUsertype)) $newUsertype = 2;
				$params['groups'] = array($newUsertype);
			} else {
				if (!$newUsertype) {
					$newUsertype = 'Registered';
				}
				$acl =& JFactory::getACL();
				$params['gid'] = $acl->get_group_id( '', $newUsertype, 'ARO' );
			}
			
			$params['sendEmail'] = 0;
			
			// Set the user's default language to whatever the site's current language is
			$params['params'] = array(
				'language'	=> JFactory::getConfig()->getValue('config.language')
			);
			
			// We always block the user, so that only a successful payment or
			// clicking on the email link activates his account. This is to
			// prevent spam registrations when the subscription form is abused.
			jimport('joomla.user.helper');
			$params['block'] = 1;
			$params['activation'] = JUtility::getHash( JUserHelper::genRandomPassword() );
			
			$userIsSaved = false;
			$user->bind($params);
			$userIsSaved = $user->save();
		} else {
			// Remove unpaid subscriptions on the same level for this user
			$unpaidSubs = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
				->user_id($user->id)
				->paystate('N','X')
				->getItemList();
			if(!empty($unpaidSubs)) foreach($unpaidSubs as $unpaidSub) {
				$table = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')->getTable();
				$table->delete($unpaidSub->akeebasubs_subscription_id);
			}
			
			// Update existing user's details
			$userRecord = FOFModel::getTmpInstance('Jusers','AkeebasubsModel')
				->setId($user->id)
				->getItem();
			
			$updates = array(
				'name'			=> $state->name,
				'email'			=> $state->email
			);
			if(!empty($state->password) && ($state->password = $state->password2)) {
				jimport('joomla.user.helper');
				$salt = JUserHelper::genRandomPassword(32);
				$pass = JUserHelper::getCryptedPassword($state->password, $salt);
				$updates['password'] = $pass.':'.$salt;
			}
			if(!empty($state->username)) {
				$updates['username'] = $state->username;
			}
			$userIsSaved = $userRecord->save($updates);			
		}
		
		if(!$userIsSaved) {
			JError::raiseWarning('', JText::_( $user->getError())); // ...raise a Warning
			return false;
		}
		
		// Step #4. Create or add user extra fields
		// ----------------------------------------------------------------------
		// Find an existing record
		$list = FOFModel::getTmpInstance('Users','AkeebasubsModel')
			->user_id($user->id)
			->getItemList();

		if(!count($list)) {
			$id = 0;
		} else {
			$thisUser = array_pop($list);
			$id = $thisUser->akeebasubs_user_id;
		}
		$data = array(
			'akeebasubs_user_id' => $id,
			'user_id'		=> $user->id,
			'isbusiness'	=> $state->isbusiness ? 1 : 0,
			'businessname'	=> $state->businessname,
			'occupation'	=> $state->occupation,
			'vatnumber'		=> $state->vatnumber,
			'viesregistered' => $validation->validation->vatnumber,
			// @todo Ask for tax authority
			'taxauthority'	=> '',
			'address1'		=> $state->address1,
			'address2'		=> $state->address2,
			'city'			=> $state->city,
			'state'			=> $state->state,
			'zip'			=> $state->zip,
			'country'		=> $state->country,
			'params'		=> json_encode($state->custom)
		);
		FOFModel::getTmpInstance('Users','AkeebasubsModel')
			->setId($id)
			->getItem()
			->save($data);
		
		// Step #5. Check for existing subscription records and calculate the subscription expiration date
		// ----------------------------------------------------------------------
		$subscriptions = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
			->user_id($user->id)
			->level($state->id)
			->enabled(1)
			->getList();
			
		$jNow = new JDate();
		$now = $jNow->toUnix();
		$mNow = $jNow->toMySQL();
		
		if(empty($subscriptions)) {
			$startDate = $now;
		} else {
			$startDate = $now;
			foreach($subscriptions as $row) {
				// Only take into account active subscriptions
				if(!$row->enabled) continue;
				// Calculate the expiration date
				$jDate = new JDate($row->publish_down);
				$expiryDate = $jDate->toUnix();
				// If the subscription expiration date is earlier than today, ignore it
				if($expiryDate < $now) continue;
				// If the previous subscription's expiration date is later than the current start date,
				// update the start date to be one second after that.
				if($expiryDate > $startDate) {
					$startDate = $expiryDate + 1;
				}
				// Also mark the old subscription as "communicated". We don't want
				// to spam our users with subscription renewal notices or expiration
				// notification after they have effectively renewed!
				FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
					->setId($row->akeebasubs_subscription_id)
					->getItem()
					->save(array(
						'contact_flag' => 3
					));
			}
		}
		
		// Step #6. Create a new subscription record
		// ----------------------------------------------------------------------
		$level = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
			->setId($state->id)
			->getItem();
		$duration = (int)$level->duration * 3600 * 24;
		$endDate = $startDate + $duration;

		$jStartDate = new JDate($startDate);
		$mStartDate = $jStartDate->toMySQL();
		$jEndDate = new JDate($endDate);
		$mEndDate = $jEndDate->toMySQL();
		
		$data = array(
			'akeebasubs_subscription_id' => null,
			'user_id'				=> $user->id,
			'akeebasubs_level_id'	=> $state->id,
			'publish_up'			=> $mStartDate,
			'publish_down'			=> $mEndDate,
			'notes'					=> '',
			'enabled'				=> ($validation->price->gross == 0),
			'processor'				=> ($validation->price->gross == 0) ? 'none' : $state->paymentmethod,
			'processor_key'			=> ($validation->price->gross == 0) ? $this->_uuid(true) : '',
			'state'					=> ($validation->price->gross == 0) ? 'C' : 'N',
			'net_amount'			=> $validation->price->net - $validation->price->discount,
			'tax_amount'			=> $validation->price->tax,
			'gross_amount'			=> $validation->price->gross,
			'created_on'			=> $mNow,
			'params'				=> '',
			'akeebasubs_coupon_id'	=> $validation->price->couponid,
			'akeebasubs_upgrade_id'	=> $validation->price->upgradeid,
			'contact_flag'			=> 0,
			'prediscount_amount'	=> $validation->price->net,
			'discount_amount'		=> $validation->price->discount,
			'first_contact'			=> '0000-00-00 00:00:00',
			'second_contact'		=> '0000-00-00 00:00:00'
		);
				
		$subscription = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
			->getTable();
		$subscription->reset();
		$subscription->akeebasubs_subscription_id = 0;
		$subscription->_dontCheckPaymentID = true;
		$result = $subscription->save($data);
		$this->_item = $subscription;

		// Step #7. Hit the coupon code, if a coupon is indeed used
		// ----------------------------------------------------------------------
		if($validation->price->couponid) {
			FOFModel::getTmpInstance('Coupons','AkeebasubsModel')
				->setId($validation->price->couponid)
				->getItem()
				->hit();
		}
		
		// Step #8. Call the specific plugin's onAKPaymentNew() method and get the redirection URL,
		//          or redirect immediately on auto-activated subscriptions
		// ----------------------------------------------------------------------
		if($subscription->gross_amount != 0) {
			// Non-zero charges; use the plugins
			$app = JFactory::getApplication();
			$jResponse = $app->triggerEvent('onAKPaymentNew',array(
				$state->paymentmethod,
				$user,
				$level,
				$subscription
			));
			if(empty($jResponse)) return false;
			
			foreach($jResponse as $response) {
				if($response === false) continue;
				
				$this->paymentForm = $response;
			}
		} else {
			// Zero charges; just redirect
			$app = JFactory::getApplication();
			$slug = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->setId($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
			$app->redirect( str_replace('&amp;','&', JRoute::_('index.php?option=com_akeebasubs&layout=default&view=message&slug='.$slug.'&layout=order')) );
			return false;
		}
		
		// Return true
		// ----------------------------------------------------------------------
		return true;
	}
	
	/**
	 * Runs a payment callback
	 */
	public function runCallback()
	{
		$state = $this->getStateVariables();
		
		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$data = array_merge($rawDataGet, $rawDataPost);
		
		$dummy = $this->getPaymentPlugins();
		
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentCallback',array(
			$state->paymentmethod,
			$data
		));
		if(empty($jResponse)) return false;
		
		$status = false;
		
		foreach($jResponse as $response)
		{
			$status = $status || $response;
		}
		
		return $status;
	}
	
	/**
	 * Get the form set by the active payment plugin
	 */
	public function getForm()
	{
		return $this->paymentForm;
	}
	
	/**
	 * Returns the state data.
	 */
	public function getData()
	{
		return $this->getStateVariables();
	}
	
	/**
	 * Generates a Universally Unique IDentifier, version 4.
	 *
	 * This function generates a truly random UUID.
	 *
	 * @paream boolean	If TRUE return the uuid in hex format, otherwise as a string
	 * @see http://tools.ietf.org/html/rfc4122#section-4.4
	 * @see http://en.wikipedia.org/wiki/UUID
	 * @return string A UUID, made up of 36 characters or 16 hex digits.
	 */
	protected function _uuid($hex = false) 
	{
	    $pr_bits = false;
	 	if (is_resource ( $this->_urand )) {
	     	$pr_bits .= @fread ( $this->_urand, 16 );
	   	}
	    
	    if (! $pr_bits) 
	    {
	        $fp = @fopen ( '/dev/urandom', 'rb' );
	        if ($fp !== false) 
	        {
	            $pr_bits .= @fread ( $fp, 16 );
	            @fclose ( $fp );
	        } 
	        else 
	        {
	            // If /dev/urandom isn't available (eg: in non-unix systems), use mt_rand().
	            $pr_bits = "";
	            for($cnt = 0; $cnt < 16; $cnt ++) {
	                $pr_bits .= chr ( mt_rand ( 0, 255 ) );
	            }
	        }
	    }
	    
	    $time_low = bin2hex ( substr ( $pr_bits, 0, 4 ) );
	    $time_mid = bin2hex ( substr ( $pr_bits, 4, 2 ) );
	    $time_hi_and_version = bin2hex ( substr ( $pr_bits, 6, 2 ) );
	    $clock_seq_hi_and_reserved = bin2hex ( substr ( $pr_bits, 8, 2 ) );
	    $node = bin2hex ( substr ( $pr_bits, 10, 6 ) );
	   
	    /**
	     * Set the four most significant bits (bits 12 through 15) of the
	     * time_hi_and_version field to the 4-bit version number from
	     * Section 4.1.3.
	     * @see http://tools.ietf.org/html/rfc4122#section-4.1.3
	     */
	    $time_hi_and_version = hexdec ( $time_hi_and_version );
	    $time_hi_and_version = $time_hi_and_version >> 4;
	    $time_hi_and_version = $time_hi_and_version | 0x4000;
	   
	    /**
	     * Set the two most significant bits (bits 6 and 7) of the
	     * clock_seq_hi_and_reserved to zero and one, respectively.
	     */
	    $clock_seq_hi_and_reserved = hexdec ( $clock_seq_hi_and_reserved );
	    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
	    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;
	   
	    //Either return as hex or as string
	    $format = $hex ? '%08s%04s%04x%04x%012s' : '%08s-%04s-%04x-%04x-%012s';
	    
	    return sprintf ( $format, $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node );
	}
}