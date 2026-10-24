<?php namespace Hampel\ArchiveSite\Job;

use XF\Entity\User;

class RestoreUsers extends ArchiveUsers
{
    /**
     * @return void
     */
    protected function processUser(User $user)
    {
        $this->getArchiveRepo()->restoreUser($user);
    }

    /**
     * @return \XF\Phrase
     */
    protected function getActionPhrase()
    {
        return \XF::phrase('hampel_archivesite_restoring');
    }
}