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
            $data['protectedUsers'] = $this->getArchiveRepo()->protectedUserIds();
        }

        return parent::setupData($data);
    }

	/**
	 * The accounts this pass will act on: every account on the forum, except the protected ones.
	 *
	 * **An empty protected list refuses the whole run.** Archiving every account, administrators
	 * included, is the one outcome this add-on must never produce by accident, and it is what an
	 * unprotected run would produce. Both CLI commands abort for that reason; this covers the
	 * paths that do not go through them - `xf:rebuild`, or anything else enqueuing the job
	 * directly - since the ACP button is reachable only by a super admin, who is therefore always
	 * in the list it enqueues.
	 *
	 * Returning no ids completes the job, having touched nobody, and the logged error is the
	 * record. `archive:validate` reports the same state as a failure.
	 *
	 * The `?: [0]` below is then unreachable, and deliberate. `$db->quote([])` returns an empty
	 * string, so an empty list built `NOT IN ()` - a SQL syntax error rather than a no-op - and a
	 * query that cannot be malformed is worth two characters.
	 */
	protected function getNextIds($start, $batch)
	{
		$protected = $this->data['protectedUsers'];

		if (!$protected)
		{
			\XF::logError('ArchiveSite: ' . \XF::phrase('hampel_archivesite_no_protected_users_found_error'));

			return [];
		}

		$db = $this->app->db();

		return $db->fetchAllColumn($db->limit(
			"
				SELECT user_id
				FROM xf_user
				WHERE user_id > ?
				AND user_id NOT IN (" . $db->quote($protected ?: [0]) . ")
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