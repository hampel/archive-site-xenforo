<?php namespace Hampel\ArchiveSite\Repository;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Mvc\Entity\Repository;

class Archive extends Repository
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
}
