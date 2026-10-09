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

		if ($this->user->ArchivedUser)
		{
			// An archived account must not reset its way back in - a reset would give it a
			// password, undoing the archive from the outside.
			//
			// The archive row is the test, not the authentication scheme. Core creates
			// XF:NoPassword accounts for registrations through a connected account, and core's own
			// AbstractAuth::hasPassword() docblock says such a user moves off that scheme by
			// requesting a password by email - which is this very path. Core's
			// canTriggerConfirmation() allows it deliberately, checking only the time limit and
			// the email address, so refusing it here broke the one route those members have.
			$error = \XF::phrase('hampel_archivesite_password_reset_denied', ['boardTitle' => $this->app->options()->boardTitle]);
			return false;
		}

		return true;
	}
}
