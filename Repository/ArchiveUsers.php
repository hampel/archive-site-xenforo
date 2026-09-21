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
	 * IDs of every user the archive process must leave alone - each super admin, plus anything
	 * listed in $config['archiveSiteProtectedUsers'].
	 *
	 * The finders below exclude this list rather than testing Admin.is_super_admin themselves:
	 * the Admin relation is a left join, so a non-admin has NULL there and a comparison against
	 * it would exclude every ordinary user instead of the admins. Excluding by ID also keeps one
	 * definition of "protected" for the finders, the archive job and archiveUser()/restoreUser().
	 *
	 * @return array
	 */
	public function protectedUserIds()
	{
		return array_values($this->protectedUsers()->pluckFrom('user_id')->fetch()->toArray());
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function partiallyArchivedUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth', true)
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', $this->protectedUserIds())
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
            ->where('user_id', '!=', $this->protectedUserIds())
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
            ->where('user_id', '!=', $this->protectedUserIds())
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

        // archiving disables the account and removes the password. It deliberately leaves all
        // content, profile fields and personal details intact, so that restoreUser() can put
        // the account back: this is not a data-erasure mechanism.

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
