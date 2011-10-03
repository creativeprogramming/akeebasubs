<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2011 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('KOOWA') or die('');

class ComAkeebasubsModelJusers extends KModelTable
{
	public function __construct(KConfig $config)
	{
		parent::__construct($config);

		$this->_state
			->insert('block'	, 'int')
			->insert('email'	, 'email')
			->insert('username' , 'string', null, true);
	}

	protected function _buildQueryWhere(KDatabaseQuery $query)
	{
		$state = $this->_state;
		
		if(empty($state->limit) || ($state->limit > 100)) {
			$query->limit(10);
			$this->_state->limit = 10;
		}

		if(is_numeric($state->block)) {
			$query->where('tbl.block','=', $state->block);
		}
		
		if($state->email) {
			$query->where('tbl.email','=', $state->email);
		}
		
		if($state->username) {
			$query->where('tbl.username','=', $state->username);
		}
		
		if($state->search)
		{
			$search = '%'.$state->search.'%';
			$query
				->where('name', 'LIKE',  $search)
				->where('username', 'LIKE',  $search, 'OR')
				->where('email', 'LIKE',  $search, 'OR');
		}
		
		parent::_buildQueryWhere($query);
	}
}