<?php

namespace Tests\Unit\XF\Service\User;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * validate() is a full copy of core rather than a call through it, because the "site archived"
 * message has to replace the authentication failure rather than follow it. A copy goes stale when
 * core changes, so these pin both halves: the add-on's message, and the core behaviour it
 * reproduces around it.
 *
 * Failed logins are recorded in xf_login_attempt, so every test runs inside a transaction.
 */
class LoginTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function validate(string $login, string $password, &$error = null)
	{
		return $this->app()->service('XF:User\Login', $login, '127.0.0.1')->validate($password, $error);
	}

	protected function attempts(string $login): int
	{
		return (int) $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_login_attempt WHERE login = ?',
			$login
		);
	}

	protected function anArchivedUser(): User
	{
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
		$user = $repo->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}

		$repo->archiveUser($user);
		$this->app()->em()->clearEntityCache();

		return $user;
	}

	protected function aSuperAdmin(): User
	{
		$user = $this->app()->finder('XF:User')
			->with('Admin', true)
			->where('Admin.is_super_admin', 1)
			->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs a super admin on the forum');
		}

		return $user;
	}

	public function test_an_archived_user_is_told_the_site_is_archived(): void
	{
		$user = $this->anArchivedUser();

		$result = $this->validate($user->username, 'any password at all', $error);

		$this->assertNull($result);
		$this->assertSame('hampel_archivesite_login_denied', $error->getName());
	}

	/**
	 * The regression. An ordinary member who still has a password - before the archive has run, or
	 * on a forum using this add-on only to hide things from guests - used to be told the site was
	 * archived, and the attempt was never recorded, so XenForo's brute-force limit never engaged
	 * for them. Their failures now take core's path.
	 */
	public function test_a_member_who_still_has_a_password_gets_the_ordinary_failure_and_it_is_recorded(): void
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}
		$before = $this->attempts($user->username);

		$result = $this->validate($user->username, 'not-the-password-' . mt_rand(), $error);

		$this->assertNull($result);
		$this->assertSame('incorrect_password', $error->getName());
		$this->assertSame($before + 1, $this->attempts($user->username));
	}

	public function test_a_protected_user_gets_the_ordinary_failure_and_it_is_recorded(): void
	{
		$admin = $this->aSuperAdmin();
		$before = $this->attempts($admin->username);

		$result = $this->validate($admin->username, 'not-the-password-' . mt_rand(), $error);

		$this->assertNull($result);
		$this->assertSame('incorrect_password', $error->getName());
		$this->assertSame($before + 1, $this->attempts($admin->username));
	}

	/**
	 * A protected account is never archived by this add-on, so having no password does not make it
	 * archived - an admin who signs in only through a connected account, for instance. It must not
	 * be told the site is archived.
	 */
	public function test_a_protected_account_without_a_password_is_not_told_the_site_is_archived(): void
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}
		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);
		$user->Auth->setNoPassword();
		$user->Auth->save();
		$this->app()->em()->clearEntityCache();

		$this->assertNull($this->validate($user->username, 'anything', $error));

		$this->assertSame('incorrect_password', $error->getName());
	}

	/**
	 * Archived like any other account, so it gets the same message - it has no password to guess
	 * either, having never had an authentication row at all.
	 */
	public function test_an_archived_account_with_no_auth_record_is_told_the_site_is_archived(): void
	{
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
		$user = $repo->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}
		$this->app()->db()->delete('xf_user_authenticate', 'user_id = ?', $user->user_id);
		$this->app()->em()->clearEntityCache();
		$repo->archiveUser($this->app()->em()->find('XF:User', $user->user_id, ['ArchivedUser']));
		$this->app()->em()->clearEntityCache();

		$this->assertNull($this->validate($user->username, 'anything', $error));

		$this->assertSame('hampel_archivesite_login_denied', $error->getName());
	}

	/**
	 * Reproduced from core: an empty password is refused without logging an attempt.
	 */
	public function test_an_empty_password_is_refused_without_recording_an_attempt(): void
	{
		$admin = $this->aSuperAdmin();
		$before = $this->attempts($admin->username);

		$this->assertNull($this->validate($admin->username, '', $error));

		$this->assertSame('incorrect_password', $error->getName());
		$this->assertSame($before, $this->attempts($admin->username));
	}

	/**
	 * Reproduced from core: an unknown login names what was typed.
	 */
	public function test_an_unknown_login_is_reported_as_not_found(): void
	{
		$this->assertNull($this->validate('no-such-user-' . mt_rand(), 'whatever', $error));

		$this->assertSame('requested_user_x_not_found', $error->getName());
	}
}
