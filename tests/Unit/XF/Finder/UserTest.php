<?php

namespace Tests\Unit\XF\Finder;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * Archived accounts are `disabled`, so core's isValidUser() would drop them from member counts and
 * statistics, and an archived forum's figures would collapse overnight. The override counts an
 * archived user as valid when their state before archiving was valid - and only then.
 */
class UserTest extends TestCase
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

	/**
	 * activeUsers() means "has a password and no archive row", not "valid" - an account an admin
	 * disabled without archiving qualifies - so the valid case has to ask for one.
	 */
	protected function aValidActiveUser(): User
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')
			->activeUsers()
			->where('user_state', 'valid')
			->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs an active, non-protected user whose state is valid');
		}

		return $user;
	}

	protected function archive(User $user): void
	{
		$this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->archiveUser($user);
		$this->app()->em()->clearEntityCache();
	}

	protected function isValid(int $userId): bool
	{
		return (bool) $this->app()->finder('XF:User')
			->isValidUser()
			->where('user_id', $userId)
			->total();
	}

	public function test_an_archived_user_who_was_valid_still_counts(): void
	{
		$user = $this->aValidActiveUser();

		$this->archive($user);

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => 'disabled']);
		$this->assertTrue($this->isValid($user->user_id));
	}

	/**
	 * The boundary: an account that was not valid before archiving must not become valid by being
	 * archived.
	 */
	public function test_an_archived_user_who_was_not_valid_does_not_count(): void
	{
		$user = $this->anActiveUser();
		$user->user_state = 'moderated';
		$user->save();

		$this->archive($user);

		$this->assertDatabaseHas('xf_archivesite_user', [
			'user_id' => $user->user_id,
			'previous_state' => 'moderated',
		]);
		$this->assertFalse($this->isValid($user->user_id));
	}

	public function test_a_disabled_user_who_was_never_archived_does_not_count(): void
	{
		$user = $this->anActiveUser();
		$user->user_state = 'disabled';
		$user->save();

		$this->assertFalse($this->isValid($user->user_id));
	}

	/**
	 * The method replaces core's rather than calling it, so the rest of core's condition is this
	 * add-on's to keep: a banned account never counts, archived or not.
	 */
	public function test_a_banned_archived_user_who_was_valid_does_not_count(): void
	{
		$user = $this->aValidActiveUser();
		$user->is_banned = true;
		$user->save();

		$this->archive($user);

		$this->assertFalse($this->isValid($user->user_id));
	}

	/**
	 * And core's recently-active half, which the members-online and statistics widgets ask for: an
	 * archived account last seen a year ago is a member, and not a recent one.
	 */
	public function test_recently_active_still_means_recently_active_for_an_archived_user(): void
	{
		$user = $this->aValidActiveUser();
		$this->archive($user);
		$this->app()->db()->update('xf_user', ['last_activity' => \XF::$time - 365 * 86400], 'user_id = ?', $user->user_id);
		$recentlyValid = function () use ($user)
		{
			return (bool) $this->app()->finder('XF:User')->isValidUser(true)->where('user_id', $user->user_id)->total();
		};

		$this->assertTrue($this->isValid($user->user_id));
		$this->assertFalse($recentlyValid(), 'an account idle for a year counted as recently active');

		$this->app()->db()->update('xf_user', ['last_activity' => \XF::$time], 'user_id = ?', $user->user_id);
		$this->assertTrue($recentlyValid(), 'an archived account active today did not count');
	}
}
