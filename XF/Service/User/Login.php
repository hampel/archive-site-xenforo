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
			// Only an account that has actually been archived is told the site is archived. It has no
			// password, so there is nothing to guess and no attempt to count. Anyone who still has a
			// password takes core's path below, so their failures are recorded and XenForo's
			// brute-force limit applies to them.
			if ($auth
				&& $auth->scheme_class == 'XF:NoPassword'
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