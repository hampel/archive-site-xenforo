<?php namespace Hampel\ArchiveSite\Repository;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class ArchiveUsers extends Repository
{
	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function protectedUsers()
	{
		return $this->finder('XF:User')
            ->with('Admin')
            ->whereOr([
                ['Admin.is_super_admin', '=', '1'],
                ['user_id', '=', ProtectedUsers::get()]
            ]);
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function partiallyArchivedUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth', true)
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', ProtectedUsers::get())
            ->where('Auth.scheme_class', '=', 'XF:NoPassword')
            ->where('ArchivedUser.previous_state', '=', null);
	}

    /**
     * @return \XF\Mvc\Entity\Finder
     */
    public function archivedUsers()
    {
        return $this->finder('XF:User')
            ->with(['Auth', 'ArchivedUser'], true)
            ->where('user_id', '!=', ProtectedUsers::get())
            ->where('Auth.scheme_class', '=', 'XF:NoPassword')
            ->where('ArchivedUser.previous_state', '!=', null);
    }

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function activeUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth', true)
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', ProtectedUsers::get())
            ->where('Auth.scheme_class', '!=', 'XF:NoPassword')
            ->where('ArchivedUser.previous_state', '=', null);
	}

	public function archiveUser(User $user)
	{
        // safety check, we shouldn't be passing these users in, but just in case
        if (ProtectedUsers::isProtected($user->user_id) || $user->is_super_admin)
        {
            return false;
        }

		// clear User Remember records so cookies no longer work
		$this->repository('XF:UserRemember')->clearUserRememberRecords($user->user_id);

        // remove users password
        $user->Auth->setNoPassword();
        $user->Auth->save();

        if (!$user->ArchivedUser)
        {
            $archivedUser = \XF::em()->create('Hampel\ArchiveSite:ArchivedUser');

            $archivedUser->bulkSet([
                'user_id' => $user->user_id,
                'previous_state' => $user->user_state,
            ]);

            $archivedUser->save();
        }

        $user->user_state = 'disabled';
        $user->save();

        /*
         * TODO:
         *
         * - RECALCULATE USER COUNT
         * - CONSIDER STILL SHOWING MEMBERS WHO HAVE BEEN ARCHIVED
         *
         * - delete banned users with zero posts
         * - delete conversations started by archived users
         * - remove DoB
         * - remove location
         * - physically remove deleted posts by deleted users
         * - physically remove deleted photos by deleted users
         * - physically remove deleted photos?
         * - physically remove deleted posts and attachments?
         * - remove bookmarks for archived users
         * - remove profile posts for archived users
         * - physically remove deleted threads
         * - clean up user profiles of archived users?
         */

        return true;
	}


	public function restoreUser(User $user)
	{
        // safety check, we shouldn't be passing these users in, but just in case
        if (ProtectedUsers::isProtected($user->user_id) || $user->is_super_admin)
        {
            return false;
        }

        // reset a users password so they can log in again (after they do a forgot password)
		$user->Auth->resetPassword();
		$user->Auth->save();

        if ($user->ArchivedUser)
        {
            $user->user_state = $user->ArchivedUser->previous_state;
            $user->save();

            $user->ArchivedUser->delete();
        }

        return true;
	}
}
