<?php

namespace Tests\Unit\XF\Service\User;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * An archived account has no password, and a reset would give it one - undoing the archive from
 * the outside. So the add-on refuses to start a reset for any account whose auth is XF:NoPassword.
 */
class PasswordResetTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function anActiveUser(): User
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}

		return $user;
	}

	protected function canTrigger(User $user, &$error = null): bool
	{
		return $this->app()->service('XF:User\PasswordReset', $user)->canTriggerConfirmation($error);
	}

	public function test_an_archived_user_cannot_start_a_reset(): void
	{
		$user = $this->anActiveUser();
		$this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->archiveUser($user);
		$this->app()->em()->clearEntityCache();
		$user = $this->app()->em()->find('XF:User', $user->user_id, ['Auth']);

		$this->assertFalse($this->canTrigger($user, $error));

		$this->assertSame('hampel_archivesite_password_reset_denied', $error->getName());
	}

	/**
	 * An archived account with no authentication row is refused like any other, and reading its
	 * missing Auth must not raise a warning on the way.
	 */
	public function test_an_archived_account_with_no_auth_record_cannot_start_a_reset(): void
	{
		$user = $this->anActiveUser();
		$this->app()->db()->delete('xf_user_authenticate', 'user_id = ?', $user->user_id);
		$this->app()->em()->clearEntityCache();
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
		$repo->archiveUser($this->app()->em()->find('XF:User', $user->user_id, ['ArchivedUser']));
		$this->app()->em()->clearEntityCache();
		$user = $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser']);

		$this->assertFalse($this->canTrigger($user, $error));

		$this->assertSame('hampel_archivesite_password_reset_denied', $error->getName());
	}

	/**
	 * The refusal is keyed on the password being absent, not on the user being unprotected, so an
	 * ordinary member who still has a password is left to core.
	 */
	public function test_a_user_with_a_password_is_left_to_core(): void
	{
		$user = $this->anActiveUser();

		$allowed = $this->canTrigger($user, $error);

		$this->assertTrue($allowed, 'core refused: ' . ($error ? $error->getName() : 'no error'));
	}
}
