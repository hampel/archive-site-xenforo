<?php

namespace Tests\Unit\Repository;

use Hampel\ArchiveSite\Repository\ArchiveUsers;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * Every test here archives or restores a real account, so each one runs inside a transaction
 * that is rolled back afterwards. The accounts are borrowed from the forum rather than created:
 * with add-on isolation on, XenForo builds a user INSERT from the structure it has loaded, so a
 * NOT NULL column that some other installed add-on adds to a user table has no value and the
 * save fails. Borrowing inside a transaction touches nothing that survives the test.
 */
class ArchiveUsersTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function repo(): ArchiveUsers
	{
		return $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
	}

	protected function anActiveUser(): User
	{
		$user = $this->repo()->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}

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

	/**
	 * Re-read from the database, so an assertion cannot pass on the in-memory entity alone.
	 */
	protected function reload(int $userId): User
	{
		$this->app()->em()->clearEntityCache();

		return $this->app()->em()->find('XF:User', $userId, ['Auth', 'ArchivedUser']);
	}

	/**
	 * An ordinary account with its authentication row removed, inside the test's transaction.
	 */
	protected function anAccountWithNoAuthRecord(): User
	{
		$user = $this->anActiveUser();
		$this->app()->db()->delete('xf_user_authenticate', 'user_id = ?', $user->user_id);

		return $this->reload($user->user_id);
	}

	protected function idsOf(\XF\Mvc\Entity\Finder $finder): array
	{
		return array_map('intval', array_values($finder->pluckFrom('user_id')->fetch()->toArray()));
	}

	// ---- the protected set ----------------------------------------------------------------------

	public function test_every_super_admin_is_protected(): void
	{
		$admin = $this->aSuperAdmin();

		$this->assertContains($admin->user_id, $this->repo()->protectedUserIds());
	}

	public function test_a_config_listed_user_is_protected(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSite', ['protectedUsers' => [$user->user_id]]);

		$this->assertContains($user->user_id, $this->repo()->protectedUserIds());
	}

	/**
	 * The deprecated flat key has to reach the finders too, not only Config\ProtectedUsers, since
	 * an install upgrading from 2.0.2 or earlier keeps using it. The test above covers the current
	 * key through the same method, so the pair is deliberate rather than duplication.
	 */
	public function test_a_user_listed_under_the_deprecated_flat_key_is_protected(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);

		$this->assertContains($user->user_id, $this->repo()->protectedUserIds());
	}

	public function test_protected_user_ids_is_a_plain_list(): void
	{
		// the job passes this straight to $db->quote(), and the finders to a NOT IN
		$ids = $this->repo()->protectedUserIds();

		$this->assertSame(array_values($ids), $ids);
	}

	// ---- the finders ----------------------------------------------------------------------------

	/**
	 * The root cause of a regression: super admins used to leak into activeUsers(). The symptom
	 * users saw - a page total that disagreed with its rows - lived in the controller, which
	 * filtered them out in PHP after taking the total, so it is covered there rather than here.
	 * A finder's own total() and fetch() agree whether or not it leaks.
	 */
	public function test_active_users_never_includes_a_super_admin(): void
	{
		$admin = $this->aSuperAdmin();

		$this->assertNotContains($admin->user_id, $this->idsOf($this->repo()->activeUsers()));
	}

	public function test_no_protected_user_appears_in_any_other_set(): void
	{
		$protected = $this->repo()->protectedUserIds();

		foreach (['activeUsers', 'archivedUsers', 'partiallyArchivedUsers'] as $method)
		{
			$leaked = array_intersect($protected, $this->idsOf($this->repo()->$method()));
			$this->assertSame([], array_values($leaked), "$method() contains a protected user");
		}
	}

	// ---- archiving ------------------------------------------------------------------------------

	public function test_archiving_disables_the_account_and_removes_its_password(): void
	{
		$user = $this->anActiveUser();
		$previousState = $user->user_state;

		$this->assertTrue($this->repo()->archiveUser($user));

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => 'disabled']);
		$this->assertDatabaseHas('xf_user_authenticate', [
			'user_id' => $user->user_id,
			'scheme_class' => 'XF:NoPassword',
		]);
		$this->assertDatabaseHas('xf_archivesite_user', [
			'user_id' => $user->user_id,
			'previous_state' => $previousState,
		]);
	}

	public function test_archiving_moves_a_user_from_active_to_archived(): void
	{
		$user = $this->anActiveUser();

		$this->repo()->archiveUser($user);

		$this->assertNotContains($user->user_id, $this->idsOf($this->repo()->activeUsers()));
		$this->assertContains($user->user_id, $this->idsOf($this->repo()->archivedUsers()));
	}

	public function test_archiving_clears_remember_me_records(): void
	{
		$user = $this->anActiveUser();
		$this->app()->db()->insert('xf_user_remember', [
			'user_id' => $user->user_id,
			'remember_key' => str_repeat('a', 32),
			'start_date' => \XF::$time,
			'expiry_date' => \XF::$time + 86400,
		]);

		$this->repo()->archiveUser($user);

		$this->assertDatabaseMissing('xf_user_remember', ['user_id' => $user->user_id]);
	}

	public function test_archiving_refuses_a_super_admin(): void
	{
		$admin = $this->aSuperAdmin();
		$stateBefore = $admin->user_state;

		$this->assertFalse($this->repo()->archiveUser($admin));

		$this->assertDatabaseHas('xf_user', ['user_id' => $admin->user_id, 'user_state' => $stateBefore]);
		$this->assertDatabaseMissing('xf_archivesite_user', ['user_id' => $admin->user_id]);
	}

	/**
	 * The per-user check is the safety net beneath the finders and the job. The single-user CLI
	 * commands rely on it and nothing else.
	 */
	public function test_archiving_refuses_a_config_protected_user(): void
	{
		$user = $this->anActiveUser();
		$stateBefore = $user->user_state;
		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);

		$this->assertFalse($this->repo()->archiveUser($user));

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => $stateBefore]);
		$this->assertDatabaseMissing('xf_archivesite_user', ['user_id' => $user->user_id]);
	}

	// ---- restoring ------------------------------------------------------------------------------

	public function test_restoring_puts_the_previous_state_back(): void
	{
		$user = $this->anActiveUser();
		$previousState = $user->user_state;
		$this->repo()->archiveUser($user);

		$this->assertTrue($this->repo()->restoreUser($this->reload($user->user_id)));

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => $previousState]);
		$this->assertDatabaseMissing('xf_archivesite_user', ['user_id' => $user->user_id]);
	}

	/**
	 * Deliberate, not an oversight: the original hash is gone the moment the account is
	 * archived, so a restored user must complete a password reset before logging in.
	 */
	public function test_restoring_does_not_give_back_the_original_password(): void
	{
		$user = $this->anActiveUser();
		$originalAuth = $user->Auth->data;
		$this->repo()->archiveUser($user);

		$this->repo()->restoreUser($this->reload($user->user_id));

		$restored = $this->reload($user->user_id);
		$this->assertNotSame('XF:NoPassword', $restored->Auth->scheme_class);
		$this->assertNotEquals($originalAuth, $restored->Auth->data);
	}

	/**
	 * The regression. archiveUser() created the archive row separately and never hydrated the
	 * relation, so the same entity went on reading ArchivedUser as null. Archiving it again then
	 * tried to insert a second row, and restoring it saw no row and did nothing - which is what a
	 * restore job did when it ran in the same queue request as the archive job, skipping every
	 * account and reporting success. No reload here, deliberately: the stale entity is the point.
	 */
	public function test_the_same_entity_can_be_archived_twice_and_then_restored(): void
	{
		$user = $this->anActiveUser();
		$previousState = $user->user_state;

		$this->assertTrue($this->repo()->archiveUser($user));
		$this->assertNotNull($user->ArchivedUser, 'the entity does not know it was archived');
		$this->assertTrue($this->repo()->archiveUser($user));
		$this->assertDatabaseCount('xf_archivesite_user', 1, ['user_id' => $user->user_id]);

		$this->assertTrue($this->repo()->restoreUser($user));

		$this->assertNull($user->ArchivedUser, 'the entity does not know it was restored');
		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => $previousState]);
		$this->assertDatabaseMissing('xf_archivesite_user', ['user_id' => $user->user_id]);
	}

	// ---- accounts with no authentication record -------------------------------------------------

	/**
	 * The regression for the finders: every one inner-joined Auth, so an account with no
	 * authentication row was in no set at all - in no count and no list, while the archive job,
	 * which walks every account, still reached it.
	 */
	public function test_every_unprotected_account_is_in_exactly_one_set(): void
	{
		$this->anAccountWithNoAuthRecord();
		$protected = $this->repo()->protectedUserIds();
		$sets = [];
		foreach (['activeUsers', 'archivedUsers', 'partiallyArchivedUsers'] AS $method)
		{
			$sets[$method] = $this->idsOf($this->repo()->$method());
		}

		$all = array_map('intval', $this->app()->db()->fetchAllColumn('SELECT user_id FROM xf_user'));
		foreach (array_diff($all, $protected) AS $id)
		{
			$in = array_keys(array_filter($sets, function ($ids) use ($id) { return in_array($id, $ids); }));
			$this->assertCount(1, $in, "user $id is in " . ($in ? implode(', ', $in) : 'no set'));
		}
	}

	public function test_an_account_with_no_auth_record_moves_from_active_to_archived(): void
	{
		$user = $this->anAccountWithNoAuthRecord();
		$this->assertContains($user->user_id, $this->idsOf($this->repo()->activeUsers()));

		$this->assertTrue($this->repo()->archiveUser($user));

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => 'disabled']);
		$this->assertContains($user->user_id, $this->idsOf($this->repo()->archivedUsers()));
		$this->assertNotContains($user->user_id, $this->idsOf($this->repo()->activeUsers()));
	}

	public function test_an_account_with_no_auth_record_can_be_restored(): void
	{
		$user = $this->anAccountWithNoAuthRecord();
		$stateBefore = $user->user_state;
		$this->repo()->archiveUser($user);

		$this->assertTrue($this->repo()->restoreUser($this->reload($user->user_id)));

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'user_state' => $stateBefore]);
		$this->assertDatabaseMissing('xf_archivesite_user', ['user_id' => $user->user_id]);
	}

	public function test_restoring_refuses_a_super_admin(): void
	{
		$admin = $this->aSuperAdmin();

		$this->assertFalse($this->repo()->restoreUser($admin));
	}
}
