<?php namespace Hampel\ArchiveSite\XF\Mail;

use Hampel\ArchiveSite\Config\ProtectedUsers;

class Mail extends XFCP_Mail
{
	public function setToUser(\XF\Entity\User $user)
	{
		if (!$user->is_super_admin && !ProtectedUsers::isProtected($user->user_id))
		{
			$this->setupError = new \Exception("Trying to send email to archived user (ID: $user->user_id)");

			return $this;
		}

		parent::setToUser($user);

		return $this;
	}
}
