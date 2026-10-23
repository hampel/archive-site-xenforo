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
 * **The archive row is what makes an account archived, not its authentication scheme.** XenForo
 * creates XF:NoPassword accounts itself for a registration through a connected account, so the
 * scheme alone said "archived" about members who never were - and because the denial returns
 * before recordFailedAttempt(), it also exempted them from the brute-force limit. Both halves are
 * asserted below.
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

	/**
	 * Core clears the failures for this login from this address only, so this is what a
	 * successful login empties - earlier rows from elsewhere are the forum's own and stay.
	 */
	protected function attemptsFromHere(string $login): int
	{
		return (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_login_attempt WHERE login = ? AND ip_address = ?",
			[$login, \XF\Util\Ip::stringToBinary('127.0.0.1')]
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

	/**
	 * The shape XenForo itself creates for a registration through a connected account: no
	 * password, and nothing this add-on has ever touched.
	 */
	protected function aPasswordlessUserWhoWasNeverArchived(): User
	{
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
		$user = $repo->activeUsers()->fetchOne();
		if (!$user || !$user->Auth)
		{
			$this->markTestSkipped('needs an active, non-protected user with an authentication row');
		}

		$user->Auth->setNoPassword();
		$user->Auth->save();
		$this->app()->em()->clearEntityCache();

		return $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser']);
	}

	/**
	 * An active, unprotected member whose password the test knows, set through XenForo's own
	 * setPassword() inside the transaction.
	 */
	protected function aMemberWithThePassword(string $password): User
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne();
		if (!$user || !$user->Auth)
		{
			$this->markTestSkipped('needs an active, non-protected user with an authentication row');
		}

		$user->Auth->setPassword($password);
		$user->Auth->save();
		$this->app()->em()->clearEntityCache();

		return $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser', 'Profile']);
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

	/**
	 * The regression. A member whose account has no password but no archive row is not archived,
	 * so they get core's message - and their attempt is counted, which the old condition skipped
	 * on their behalf.
	 */
	public function test_a_passwordless_member_who_was_never_archived_gets_core_s_failure(): void
	{
		$user = $this->aPasswordlessUserWhoWasNeverArchived();
		$before = $this->attempts($user->username);

		$this->assertNull($this->validate($user->username, 'not the password', $error));

		$this->assertSame('incorrect_password', $error->getName());
		$this->assertSame($before + 1, $this->attempts($user->username), 'the attempt was not recorded');
	}

	// ---- the success path: a copy of core, so a broken one locks out every account ----------

	/**
	 * The half of the copy nothing else exercises. Every assertion above is about a refusal, so a
	 * validate() that refused everyone would pass all of them while no account - an administrator
	 * of an archived forum included - could log in.
	 */
	public function test_a_member_with_the_right_password_is_logged_in_and_their_failures_cleared(): void
	{
		$user = $this->aMemberWithThePassword('the-right-password');
		$before = $this->attemptsFromHere($user->username);
		$this->validate($user->username, 'not-the-password', $ignored);
		$this->assertSame($before + 1, $this->attemptsFromHere($user->username), 'the failed attempt was not recorded first');

		$result = $this->validate($user->username, 'the-right-password', $error);

		$this->assertInstanceOf(User::class, $result);
		$this->assertSame($user->user_id, $result->user_id);
		$this->assertNull($error);
		$this->assertSame(0, $this->attemptsFromHere($user->username), 'a successful login did not clear the failures');
	}

	/**
	 * What keeps an archived forum administrable: a protected account is never archived, so it
	 * logs in with its password like any other.
	 */
	public function test_a_protected_user_with_the_right_password_is_logged_in(): void
	{
		$user = $this->aMemberWithThePassword('the-right-password');
		$this->setConfig('archiveSite', ['protectedUsers' => [$user->user_id]]);

		$result = $this->validate($user->username, 'the-right-password', $error);

		$this->assertInstanceOf(User::class, $result);
		$this->assertSame($user->user_id, $result->user_id);
		$this->assertNull($error);
	}

	/**
	 * Reproduced from core: a hash made with weaker settings than the forum now uses is rehashed
	 * on a successful login, without moving the password date - it is not a password change.
	 */
	public function test_an_outdated_password_hash_is_upgraded_on_a_successful_login(): void
	{
		$user = $this->aMemberWithThePassword('the-right-password');
		$weak = password_hash('the-right-password', PASSWORD_BCRYPT, ['cost' => 4]);
		$this->app()->db()->update(
			'xf_user_authenticate',
			['scheme_class' => 'XF:Core12', 'data' => serialize(['hash' => $weak])],
			'user_id = ?',
			$user->user_id
		);
		// in the past, since the setPassword() above stamped this request's time - which is what a
		// regression moving the date would write too
		$passwordDate = \XF::$time - 86400;
		$this->app()->db()->update('xf_user_profile', ['password_date' => $passwordDate], 'user_id = ?', $user->user_id);
		$this->app()->em()->clearEntityCache();
		$this->assertTrue(
			$this->app()->em()->find('XF:UserAuth', $user->user_id)->getAuthenticationHandler()->isUpgradable(),
			'the planted hash has to be one XenForo would upgrade, or this asserts nothing'
		);

		$this->assertInstanceOf(User::class, $this->validate($user->username, 'the-right-password', $error));

		$this->app()->em()->clearEntityCache();
		$auth = $this->app()->em()->find('XF:UserAuth', $user->user_id);
		$this->assertNotSame($weak, $auth->data['hash'] ?? null, 'the hash was not upgraded');
		$this->assertFalse($auth->getAuthenticationHandler()->isUpgradable());
		$this->assertTrue($auth->authenticate('the-right-password'));
		$this->assertSame(
			$passwordDate,
			$this->app()->em()->find('XF:UserProfile', $user->user_id)->password_date
		);
	}

	/**
	 * Reproduced from core: an empty login is refused before any lookup. Whether an attempt is
	 * recorded cannot be asserted - XenForo will not save one with an empty login, and swallows
	 * the failure - so the phrase is what tells this guard from the not-found path below it.
	 */
	public function test_an_empty_login_is_refused_before_any_lookup(): void
	{
		$this->assertNull($this->validate('', 'whatever', $error));

		$this->assertSame('requested_user_not_found', $error->getName());
	}
}
