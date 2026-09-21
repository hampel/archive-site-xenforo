<?php namespace Hampel\ArchiveSite\XF\Service\User;

class PasswordReset extends XFCP_PasswordReset
{
	public function canTriggerConfirmation(&$error = null)
	{
		if (!parent::canTriggerConfirmation($error))
		{
			// already have an error
			return false;
		}

		$auth = $this->user->Auth;
		if (($auth && $auth->scheme_class == 'XF:NoPassword') || $this->user->ArchivedUser)
		{
			// don't allow people with no passwords to reset passwords
			$error = \XF::phrase('hampel_archivesite_password_reset_denied', ['boardTitle' => $this->app->options()->boardTitle]);
			return false;
		}

		return true;
	}
}
