<?php namespace Hampel\ArchiveSite\Job;

use XF\Entity\User;

class RestoreUsers extends ArchiveUsers
{
    protected function processUser(User $user)
    {
        $this->getArchiveRepo()->restoreUser($user);
    }

    protected function getActionPhrase()
    {
        return \XF::phrase('hampel_archivesite_restoring');
    }
}