<?php

namespace Hampel\ArchiveSite\XF\Service\User;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Entity\User;

class Login extends XFCP_Login
{
	public function validate($password, &$error = null)
	{
		if (!strlen($this->login))
		{
			$error = \XF::phrase('requested_user_not_found');
			return null;
		}

		$user = $this->getUser();
		if (!$user)
		{
			$this->recordFailedAttempt();

			$error = \XF::phrase('requested_user_x_not_found', ['name' => $this->login]);
			return null;
		}

		if (!strlen($password))
		{
			// don't log an attempt if they don't provide a password

			$error = \XF::phrase('incorrect_password');
			return null;
		}

		$auth = $user->Auth;
		if (!$auth || !$auth->authenticate($password))
		{
			// Only an account this add-on has actually archived is told the site is archived, and
			// the archive row is what says so - not the authentication scheme. XenForo creates
			// XF:NoPassword accounts itself, for a registration through a connected account that
			// never set a password, and another add-on can have its own passwordless scheme; none
			// of those accounts is archived, and telling their owner the site is archived is both
			// wrong and a throttle bypass, since this branch returns before recordFailedAttempt().
			//
			// The second half is the security property: an archived account has no password to
			// guess, which is the only reason its failure can skip the count. An account that
			// somehow still has a usable one takes core's path below, archived or not.
			$handler = $auth ? $auth->getAuthenticationHandler() : null;
			$archivedAndPasswordless = $user->ArchivedUser && !($handler && $handler->hasPassword());

			if ($archivedAndPasswordless
				&& !ProtectedUsers::isProtected($user->user_id)
				&& !$user->getIsSuperAdmin()
			)
			{
				$error = \XF::phrase('hampel_archivesite_login_denied', ['boardTitle' => $this->app->options()->boardTitle]);
				return null;
			}

			$this->recordFailedAttempt();

			$error = \XF::phrase('incorrect_password');
			return null;
		}

		if ($this->allowPasswordUpgrade)
		{
			/** @var \XF\Entity\UserAuth $userAuth */
			$userAuth = $user->Auth;
			if ($userAuth->getAuthenticationHandler()->isUpgradable())
			{
				$userAuth->getBehavior('XF:ChangeLoggable')->setOption('enabled', false);
				$userAuth->setPassword($password, null, false); // don't update the password date as this isn't a real change
				$userAuth->save();
			}
		}

		$this->clearFailedAttempts();

		return $user;
	}
}