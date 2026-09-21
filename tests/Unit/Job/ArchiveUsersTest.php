<?php

namespace Tests\Unit\Job;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The jobs are the archive run itself: every account on the forum, in batches, from the ACP button
 * or the CLI. So these run them for real against the forum's accounts - inside a transaction that
 * is rolled back, which is the only thing standing between this test and archiving the forum.
 *
 * runJobToCompletion() builds a fresh job each pass from the previous pass's data, as XenForo's
 * job manager does, and deliberately leaves the entity cache alone between jobs - as the queue
 * runner does in one request. That second point is what caught archiveUser() leaving a stale
 * relation behind, so do not add a clearEntityCache() here.
 */
class ArchiveUsersTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function repo()
	{
		return $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
	}

	/**
	 * Every account, keyed by id, with what an archive run could change.
	 */
	protected function accounts(): array
	{
		return $this->app()->db()->fetchAllKeyed(
			"SELECT user.user_id, user.user_state, auth.scheme_class, archive.previous_state
				FROM xf_user AS user
				LEFT JOIN xf_user_authenticate AS auth ON (auth.user_id = user.user_id)
				LEFT JOIN xf_archivesite_user AS archive ON (archive.user_id = user.user_id)",
			'user_id'
		);
	}

	protected function protectedIdsOrSkip(): array
	{
		$ids = $this->repo()->protectedUserIds();
		if (!$ids)
		{
			$this->markTestSkipped('needs a super admin on the forum');
		}

		return $ids;
	}

	/**
	 * An ordinary account with its authentication row removed, inside the test's transaction. Such
	 * accounts turn up on real forums - a direct insert, a partial import, an add-on that creates
	 * users - and the job used to crash on the first one it met.
	 */
	protected function anAccountWithNoAuthRecord(): int
	{
		$user = $this->repo()->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs an active, non-protected user on the forum');
		}
		$this->app()->db()->delete('xf_user_authenticate', 'user_id = ?', $user->user_id);
		$this->app()->em()->clearEntityCache();

		return $user->user_id;
	}

	protected function assertArchived(array $account, string $why): void
	{
		$this->assertSame('disabled', $account['user_state'], $why);
		// no password either way: removed, or there was never an authentication row to remove
		$this->assertContains($account['scheme_class'], ['XF:NoPassword', null], $why);
		$this->assertNotNull($account['previous_state'], $why);
	}

	protected function assertUntouched(array $before, array $after, string $why): void
	{
		$this->assertSame($before, $after, $why);
	}

	/**
	 * What the ACP button does: the controller hands the job the protected list it worked out.
	 */
	public function test_it_archives_every_unprotected_account_and_no_protected_one(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$before = $this->accounts();

		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $protected]);

		$after = $this->accounts();
		foreach ($before AS $id => $account)
		{
			if (in_array($id, $protected))
			{
				$this->assertUntouched($account, $after[$id], "protected user $id was changed");
			}
			else
			{
				$this->assertArchived($after[$id], "user $id was not archived");
			}
		}
	}

	/**
	 * What the CLI does: it passes no list, so the job has to work one out rather than archive
	 * everyone. An empty list here would spare nobody.
	 */
	public function test_given_no_protected_list_it_works_one_out(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$before = $this->accounts();

		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers');

		$after = $this->accounts();
		foreach ($protected AS $id)
		{
			$this->assertUntouched($before[$id], $after[$id], "protected user $id was changed");
		}
	}

	public function test_a_config_protected_account_is_spared(): void
	{
		$this->protectedIdsOrSkip();
		$user = $this->repo()->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs an active, non-protected user on the forum');
		}
		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);
		$before = $this->accounts();

		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $this->repo()->protectedUserIds()]);

		$this->assertUntouched($before[$user->user_id], $this->accounts()[$user->user_id], 'config-protected user was changed');
	}

	/**
	 * One account per batch, so every batch boundary is crossed - a user skipped at a boundary
	 * would stay active with nothing to say so.
	 */
	public function test_small_batches_still_reach_every_account(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$before = $this->accounts();

		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $protected, 'batch' => 1]);

		$after = $this->accounts();
		foreach (array_diff(array_keys($before), $protected) AS $id)
		{
			$this->assertArchived($after[$id], "user $id was skipped at a batch boundary");
		}
	}

	public function test_the_restore_job_puts_every_archived_account_back(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$before = $this->accounts();
		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $protected]);

		$this->runJobToCompletion('Hampel\ArchiveSite:RestoreUsers', ['protectedUsers' => $protected]);

		$after = $this->accounts();
		foreach (array_diff(array_keys($before), $protected) AS $id)
		{
			if ($before[$id]['previous_state'] !== null)
			{
				continue; // archived before the test began, so restoring it is not a return to $before
			}

			$this->assertSame($before[$id]['user_state'], $after[$id]['user_state'], "user $id state was not restored");
			$this->assertNull($after[$id]['previous_state'], "user $id still has an archive row");
			$this->assertNotSame('XF:NoPassword', $after[$id]['scheme_class'], "user $id still has no password");
		}
		$this->assertDatabaseCount('xf_archivesite_user', 0);
	}

	/**
	 * The regression: an account with no authentication row halted the whole run part-way, leaving
	 * every account after it active. It is archived like any other, minus the password it lacks.
	 */
	public function test_an_account_with_no_auth_record_is_archived_and_does_not_stop_the_run(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$noAuth = $this->anAccountWithNoAuthRecord();
		$before = $this->accounts();

		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $protected]);

		$after = $this->accounts();
		$this->assertArchived($after[$noAuth], 'the account with no auth record was not archived');
		foreach (array_diff(array_keys($before), $protected) AS $id)
		{
			$this->assertArchived($after[$id], "user $id was not archived - the run stopped early");
		}
	}

	public function test_an_account_with_no_auth_record_is_restored(): void
	{
		$protected = $this->protectedIdsOrSkip();
		$noAuth = $this->anAccountWithNoAuthRecord();
		$stateBefore = $this->accounts()[$noAuth]['user_state'];
		$this->runJobToCompletion('Hampel\ArchiveSite:ArchiveUsers', ['protectedUsers' => $protected]);

		$this->runJobToCompletion('Hampel\ArchiveSite:RestoreUsers', ['protectedUsers' => $protected]);

		$after = $this->accounts()[$noAuth];
		$this->assertSame($stateBefore, $after['user_state']);
		$this->assertNull($after['previous_state']);
	}
}
