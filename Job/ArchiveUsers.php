<?php namespace Hampel\ArchiveSite\Job;

use XF\Entity\User;
use XF\Job\AbstractRebuildJob;

class ArchiveUsers extends AbstractRebuildJob
{
	protected $defaultData = [
		'protectedUsers' => [],
	];

    protected function setupData(array $data)
    {
        if (empty($data['protectedUsers']))
        {
            $data['protectedUsers'] = $this
                ->getArchiveRepo()
                ->protectedUsers()
                ->pluckFrom('user_id')
                ->fetch()
                ->toArray();
        }

        return parent::setupData($data);
    }

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
		$user = $this->app->em()->find('XF:User', $id, ['Auth', 'ArchivedUser']);
		if (!$user)
		{
			return;
		}

		$db = $this->app->db();

		$db->beginTransaction();

		$this->processUser($user);

		$db->commit();
	}

    protected function processUser(User $user)
    {
        $this->getArchiveRepo()->archiveUser($user);
    }

	public function getStatusMessage()
	{
		$actionPhrase = $this->getActionPhrase();
		$typePhrase = $this->getStatusType();
		return sprintf('%s... %s (%s)', $actionPhrase, $typePhrase, $this->data['start']);
	}

    protected function getActionPhrase()
    {
        return \XF::phrase('hampel_archivesite_archiving');
    }

	protected function getStatusType()
	{
		return \XF::phrase('hampel_archivesite_user_ids');
	}

    /**
     * @return \Hampel\ArchiveSite\Repository\ArchiveUsers
     */
    protected function getArchiveRepo()
    {
        return $this->app->repository('Hampel\ArchiveSite:ArchiveUsers');
    }
}