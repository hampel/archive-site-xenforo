<?php

namespace Hampel\ArchiveSite\Job;

use XF\Job\AbstractRebuildJob;

class ArchiveUsers extends AbstractRebuildJob
{
	protected $defaultData = [
		'protectedUsers' => [],
	];

	protected function getNextIds($start, $batch)
	{
		$db = $this->app->db();

		return $db->fetchAllColumn($db->limit(
			"
				SELECT user_id
				FROM xf_user
				WHERE user_id > ?
				AND user_id NOT IN (" . $db->quote($this->data['protectedUsers']) . ")
				ORDER BY user_id
			", $batch
		), $start);
	}

	protected function rebuildById($id)
	{
		/** @var \XF\Entity\User $user */
		$user = $this->app->em()->find('XF:User', $id, ['Auth']);
		if (!$user)
		{
			return;
		}

		$db = $this->app->db();

		$db->beginTransaction();

		\XF::repository('Hampel\ArchiveSite:ArchiveUsers')->archiveUser($user);

		$db->commit();
	}

	public function getStatusMessage()
	{
		$actionPhrase = \XF::phrase('hampel_archivesite_archiving');
		$typePhrase = $this->getStatusType();
		return sprintf('%s... %s (%s)', $actionPhrase, $typePhrase, $this->data['start']);
	}

	protected function getStatusType()
	{
		return \XF::phrase('hampel_archivesite_users');
	}
}