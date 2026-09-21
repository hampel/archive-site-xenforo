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
}
