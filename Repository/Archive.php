<?php namespace Hampel\ArchiveSite\Repository;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class Archive extends Repository
{

	public function protectedUsers()
	{
		return $this->finder('XF:User')
		            ->with('Admin')
                    ->whereOr([
                        ['Admin.is_super_admin', '=', '1'],
                        ['user_id', '=', ProtectedUsers::get()]
                    ])
                    ->fetch();
	}

	public function archivedUsers($limit = null)
	{
		return $this->finder('XF:User')
		            ->with('Auth')
                    ->where('Auth.scheme_class', '=', 'XF:NoPassword')
                    ->fetch($limit);
	}

	public function activeUsers($limit = null)
	{
		return $this->finder('XF:User')
		            ->where('user_id', '!=', ProtectedUsers::get())
		            ->where('Auth.scheme_class', '!=', 'XF:NoPassword')
                    ->fetch($limit)
                    ->filter(function(User $user) {
                        return !$user->is_super_admin;
                    });
	}
}
