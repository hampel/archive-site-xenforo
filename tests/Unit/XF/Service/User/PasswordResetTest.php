<?php

namespace Tests\Unit\XF\Service\User;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * An archived account has no password, and a reset would give it one - undoing the archive from
 * the outside. So the add-on refuses to start a reset for an account it has archived.
 *
 * **An archive row is the test, not the authentication scheme.** Core creates XF:NoPassword
 * accounts for registrations through a connected account, and core's own
 * AbstractAuth::hasPassword() docblock says such a member moves off that scheme by requesting a
 * password by email - this very path, which core's canTriggerConfirmation() allows deliberately.
 * Refusing it by scheme broke the one route those members have.
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

	/**
	 * The regression: a member with no password and no archive row is core's business, and core
	 * allows the reset - it is how a connected-account member gets a password at all.
	 */
	public function test_a_passwordless_member_who_was_never_archived_is_left_to_core(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$user->Auth->setNoPassword();
		$user->Auth->save();
		$this->app()->em()->clearEntityCache();
		$user = $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser']);

		$this->assertTrue($this->canTrigger($user, $error), $error ? (string) $error : '');
	}
}
