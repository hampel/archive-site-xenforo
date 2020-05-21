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
	public function archivedUsers()
	{
		return $this->finder('XF:User')
		            ->with('Auth')
                    ->where('Auth.scheme_class', '=', 'XF:NoPassword');
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function activeUsers()
	{
		return $this->finder('XF:User')
		            ->where('user_id', '!=', ProtectedUsers::get())
		            ->where('Auth.scheme_class', '!=', 'XF:NoPassword');
	}

	public function archiveUser(User $user)
	{
		// clear User Remember records so cookies no longer work
		$this->repository('XF:UserRemember')->clearUserRememberRecords($user->user_id);

		// delete all connected accounts to prevent login
		$this->db()->query("
			DELETE FROM xf_user_connected_account
			WHERE user_id = ?
		", $user->user_id);

		// remove users password so they can no longer log in
		$user->Auth->setNoPassword();
		return $user->Auth->save();
	}

	public function restoreUser(User $user)
	{
		// reset a users password so they can log in again (after they do a forgot password)
		$user->Auth->resetPassword();
		return $user->Auth->save();
	}
}
