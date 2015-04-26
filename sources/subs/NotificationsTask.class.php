<?php

/**
 * Class that centrilize the "notification" process.
 * ... or at least tries to.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.3
 *
 */

if (!defined('ELK'))
	die('No access...');

class Notifications_Task extends \ElkArte\ValuesContainer
{
	protected $_members_data = null;
	protected $_notifier_data = null;

	public function __construct($type, $id, $id_member, $data, $namespace = '')
	{
		$this->data = array(
			'notification_type' => $type,
			'namespace' => empty($namespace) ? '\\ElkArte\\sources\\subs\\MentionType\\' : rtrim($namespace, '\\') . '\\',
			'id_target' => $id,
			'id_member_from' => $id_member,
			'source_data' => $data,
			'log_time' => time()
		);

		if (!isset($this->data['source_data']['status']))
			$this->data['source_data']['status'] = 'new';

		if (isset($this->data['source_data']['id_members']))
			$this->data['source_data']['id_members'] = (array) $this->data['source_data']['id_members'];
		else
			$this->data['source_data']['id_members'] = array();
	}

	public function getMembers()
	{
		return $this->data['source_data']['id_members'];
	}

	public function setMembers($members)
	{
		$this->data['source_data']['id_members'] = (array) $members;
	}

	public function getMembersData()
	{
		if ($this->_members_data === null)
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$this->_members_data = getBasicMemberData($this->getMembers(), array('preferences' => true));
		}

		return $this->_members_data;
	}

	public function getNotifierData()
	{
		if ($this->_notifier_data === null)
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$this->_notifier_data = getBasicMemberData($this->id_member_from);
		}

		return $this->_notifier_data;
	}

	public function getLastId()
	{
		return isset($this->data['source_data']['last_id']) ? $this->data['source_data']['last_id'] : null;
	}

	public function getClass()
	{
		return $this->data['namespace'] . ucfirst($this->data['notification_type']) . '_Mention';
	}
}