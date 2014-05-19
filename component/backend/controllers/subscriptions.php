<?php
/**
 *  @package AkeebaSubs
 *  @copyright Copyright (c)2010-2014 Nicholas K. Dionysopoulos
 *  @license GNU General Public License version 3, or later
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

class AkeebasubsControllerSubscriptions extends F0FController
{
	public function browse($cachable = false) {
		// When groupbydate is set to 1 we force a JSON view which returns the
		// sales info (subscriptions, net amount) grouped by date. You can use
		// the since/until or other filter in the URL to filter the whole lot
		$groupbydate = $this->input->getInt('groupbydate',0);
		$groupbylevel = $this->input->getInt('groupbylevel',0);
		if(($groupbydate == 1) || ($groupbylevel == 1)) {
			if(JFactory::getUser()->guest) {
				return false;
			} else {
				$list = $this->getThisModel()
					->savestate(0)
					->limit(0)
					->limitstart(0)
					->getItemList();
				header('Content-type: application/json');
				echo json_encode($list);
				JFactory::getApplication()->close();
			}
		}

		// Limit what a front-end user can do
		if(JFactory::getApplication()->isSite()) {
			if(JFactory::getUser()->guest) {
				return false;
			} else {
				$this->input->set('user_id',JFactory::getUser()->id);
			}
		}

		// If it's the back-end CSV view, force no limits
		if(JFactory::getApplication()->isAdmin() && ($this->input->getCmd('format','html') == 'csv')) {
			$this->getThisModel()
				->savestate(0)
				->limit(0)
				->limitstart(0);
		}

		return parent::browse($cachable);
	}

	public function publish()
	{
                $app= JFactory::getApplication();
                $app->enqueueMessage("Please go in edit view to control the activation state (what you clicked it's not a toggle button)",'error');
		$this->noop();
	}

	public function unpublish()
	{
                $app= JFactory::getApplication();
                $app->enqueueMessage("Please go in edit view to control the activation state (what you clicked it's not a toggle button)",'error');
		$this->noop();
	}


	public function noop()
	{
		if($customURL = $this->input->getString('returnurl','')) $customURL = base64_decode($customURL);
		$url = !empty($customURL) ? $customURL : 'index.php?option='.$this->component.'&view='.F0FInflector::pluralize($this->view);
		$this->setRedirect($url);
	}

	protected function onBeforeApplySave(&$data)
	{
		$result = parent::onBeforeSave();

		if ($result)
		{
			$subcustom = $this->input->get('subcustom', array(), 'array');

			if (!array_key_exists('params', $data))
			{
				$data['params'] = array();
			}

			if (!array_key_exists('subcustom', $data['params']))
			{
				$data['params']['subcustom'] = array();
			}

			$data['params']['subcustom'] = array_merge($data['params']['subcustom'], $subcustom);
		}

		return $result;
	}
}
