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
	 * The user ids a finder matches, as integers.
	 */
	protected function idsIn(\XF\Mvc\Entity\Finder $finder): array
	{
		return array_map('intval', array_values($finder->pluckFrom('user_id')->fetch()->toArray()));
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
	/**
	 * An account with no authentication row at all - a direct insert, a partial import, another
	 * add-on creating users.
	 *
	 * Its state is set to valid deliberately. The borrowed account may be `disabled` already, and
	 * since 2.2.0 shut-and-passwordless is what partiallyArchivedUsers() means, so leaving it
	 * would make this fixture a different case from the one every test using it is about.
	 */
	protected function anAccountWithNoAuthRecord(): User
	{
		$user = $this->anActiveUser();
		$user->user_state = 'valid';
		$user->save();
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

	// ---- which set an account belongs in --------------------------------------------------------

	/**
	 * The regression. A member who registered through a connected account has no password and is
	 * not shut, and until 2.2.0 the scheme alone put them in partiallyArchivedUsers() - reported
	 * to an administrator as an account whose password had been removed by some other route - and
	 * kept them out of the active count entirely.
	 */
	public function test_a_passwordless_member_who_is_not_shut_is_active(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$user->user_state = 'valid'; // the borrowed account may be disabled; this case is not
		$user->save();
		$user->Auth->setNoPassword();
		$user->Auth->save();

		$this->assertContains($user->user_id, $this->idsIn($this->repo()->activeUsers()),
			'a passwordless member who is not shut should be active');
		$this->assertNotContains($user->user_id, $this->idsIn($this->repo()->partiallyArchivedUsers()),
			'a passwordless member who is not shut is not partially archived');
	}

	/**
	 * What partially archived now means: shut, with no usable password, and no archive row - so
	 * nothing recorded what the state used to be and nothing can restore it.
	 */
	public function test_a_shut_passwordless_account_with_no_archive_row_is_partially_archived(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$user->Auth->setNoPassword();
		$user->Auth->save();
		$user->user_state = 'disabled';
		$user->save();

		$this->assertContains($user->user_id, $this->idsIn($this->repo()->partiallyArchivedUsers()));
		$this->assertNotContains($user->user_id, $this->idsIn($this->repo()->activeUsers()));
	}

	/**
	 * The gap that needed no other add-on to appear: an archived account whose scheme is anything
	 * but XF:NoPassword - an administrator set a password on it, or another add-on owns its
	 * authentication - used to be in none of the four sets, so no page listed it and no count
	 * included it, while its archive row sat there waiting to be restored.
	 */
	public function test_an_archived_account_that_has_a_password_is_still_archived(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$this->repo()->archiveUser($user);
		$user->Auth->setPassword('a password somebody set afterwards');
		$user->Auth->save();

		$this->assertContains($user->user_id, $this->idsIn($this->repo()->archivedUsers()),
			'an archived account with a password belongs to no other set either');
		$this->assertNotContains($user->user_id, $this->idsIn($this->repo()->activeUsers()));
	}

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

	/**
	 * The account awaiting approval, archived and restored in one request - which is what
	 * XF\Job\Manager::runQueue() does when both jobs are due, since it runs them through one
	 * entity manager.
	 *
	 * Archiving takes the state out of 'moderated', so core deletes the approval queue entry;
	 * restoring puts it back, so core builds a new one with getRelationOrDefault(). That hands
	 * back the deleted entity, because its guard is empty() and an object is never empty, and
	 * writing to it throws. Nothing on the forum needs to be odd for this to happen - only an
	 * account waiting to be approved, of which this forum has a hundred.
	 */
	public function test_a_moderated_account_survives_being_archived_and_restored_in_one_request(): void
	{
		$user = $this->anActiveUser();

		// core creates the approval queue entry on the way in, which is the fixture
		$user->user_state = 'moderated';
		$user->save();
		$this->assertNotNull($user->ApprovalQueue, 'core did not create the approval queue entry');

		$this->repo()->archiveUser($user);
		$this->repo()->restoreUser($user);

		$this->assertSame('moderated', $user->user_state);
		$this->assertNotNull($user->ApprovalQueue);
		$this->assertFalse($user->ApprovalQueue->isDeleted(), 'the restored account kept a deleted queue entry');

		$reloaded = $this->reload($user->user_id);
		$this->assertSame('moderated', $reloaded->user_state);
		$this->assertNotNull($reloaded->ApprovalQueue, 'the approval queue entry was not put back');
	}

	/**
	 * The regression. XenForo creates passwordless accounts itself for a registration through a
	 * connected account, and archiving overwrites whatever scheme an account had with
	 * XF:NoPassword - so restoring used to hand those accounts a real password they never had,
	 * leaving them reachable by lost-password where before they were not. The scheme recorded at
	 * archive time is what tells them apart.
	 */
	public function test_restoring_does_not_give_a_password_to_an_account_that_never_had_one(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		// the shape core creates for a connected-account registration
		$user->Auth->setNoPassword();
		$user->Auth->save();

		$this->repo()->archiveUser($user);
		$this->assertSame('XF:NoPassword', $user->ArchivedUser->previous_scheme_class,
			'the scheme was not recorded');

		$this->repo()->restoreUser($user);

		$reloaded = $this->reload($user->user_id);
		$this->assertSame('XF:NoPassword', $reloaded->Auth->scheme_class,
			'the restored account was given a password it never had');
	}

	/**
	 * And the other side of it: an ordinary member still comes back with a password, random and
	 * unknown, so they complete a reset to get in. That is the design, not an oversight.
	 */
	public function test_restoring_still_resets_the_password_of_an_account_that_had_one(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth || $user->Auth->scheme_class == 'XF:NoPassword')
		{
			$this->markTestSkipped('needs an active user who has a password');
		}

		$scheme = $user->Auth->scheme_class;
		$before = $user->Auth->data;

		$this->repo()->archiveUser($user);
		$this->assertSame($scheme, $user->ArchivedUser->previous_scheme_class);

		$this->repo()->restoreUser($user);

		$reloaded = $this->reload($user->user_id);
		$this->assertSame($scheme, $reloaded->Auth->scheme_class);
		$this->assertNotEquals($before, $reloaded->Auth->data, 'the password was not reset');
	}

	/**
	 * A row written before 2.2.0 records no scheme, and an upgrade cannot find out retroactively,
	 * so the unknown case keeps the behaviour every earlier version had.
	 */
	public function test_an_unknown_previous_scheme_is_treated_as_having_had_a_password(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$this->repo()->archiveUser($user);
		$user->ArchivedUser->previous_scheme_class = '';
		$user->ArchivedUser->save();

		$this->repo()->restoreUser($user);

		$reloaded = $this->reload($user->user_id);
		$this->assertNotSame('XF:NoPassword', $reloaded->Auth->scheme_class,
			'an account with an unrecorded scheme should still be reset, as before 2.2.0');
	}

	/**
	 * restoreUser() is reachable from archive:restore-user with any account at all, so an account
	 * nobody archived must come out untouched - resetting a member's password because somebody
	 * typed the wrong name is not a restore.
	 */
	public function test_restoring_an_account_that_was_never_archived_leaves_its_password_alone(): void
	{
		$user = $this->anActiveUser();
		if (!$user->Auth)
		{
			$this->markTestSkipped('needs an active user with an authentication row');
		}

		$scheme = $user->Auth->scheme_class;
		$before = $user->Auth->data;

		$this->assertTrue($this->repo()->restoreUser($user));

		$reloaded = $this->reload($user->user_id);
		$this->assertSame($scheme, $reloaded->Auth->scheme_class);
		$this->assertEquals($before, $reloaded->Auth->data, 'an unarchived account had its password reset');
	}

	public function test_restoring_refuses_a_super_admin(): void
	{
		$admin = $this->aSuperAdmin();

		$this->assertFalse($this->repo()->restoreUser($admin));
	}

	// ---- passkeys and two-step ------------------------------------------------------------------

	/**
	 * An account set up with two-step through XenForo's own repository rather than by inserting
	 * rows, so that what archiving clears is what the account security page creates: the provider
	 * asked for, the backup codes core adds beside it, and `user_option.use_tfa`. A trusted device
	 * is added as well, since that is a third table and the one nothing else would notice.
	 */
	protected function anAccountWithTwoStep(): User
	{
		$provider = $this->app()->em()->find('XF:TfaProvider', 'totp');
		if (!$provider)
		{
			$this->markTestSkipped('needs the totp two-step provider on the forum');
		}

		$user = $this->anActiveUser();

		$this->app()->repository('XF:Tfa')
			->enableUserTfaProvider($user, $provider, ['secret' => str_repeat('a', 16)]);

		$this->app()->db()->insert('xf_user_tfa_trusted', [
			'user_id' => $user->user_id,
			'trusted_key' => str_repeat('b', 32),
			'trusted_until' => \XF::$time + 86400,
		]);

		// the fixture is worth asserting: a test that clears nothing passes just as well
		$this->assertDatabaseHas('xf_user_tfa', ['user_id' => $user->user_id, 'provider_id' => 'totp']);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $user->user_id, 'use_tfa' => 1]);

		return $user;
	}

	/**
	 * A passkey has to be inserted: creating a real one is a WebAuthn ceremony with a device at
	 * the other end of it. The two-step provider row that XenForo maintains alongside a passkey is
	 * then built by core's own repository, which is the state a real passkey holder is in.
	 */
	protected function aPasskeyHolder(): User
	{
		$user = $this->anActiveUser();

		$this->app()->db()->insert('xf_passkey', [
			'credential_id' => str_repeat('c', 64),
			'credential_public_key' => str_repeat('d', 64),
			'user_id' => $user->user_id,
			'name' => 'test passkey',
			'aaguid' => '',
		]);
		$this->app()->em()->clearEntityCache();

		$user = $this->reload($user->user_id);
		$this->app()->repository('XF:Passkey')->updateUserPasskeyProvider($user);

		$this->assertDatabaseHas('xf_user_tfa', ['user_id' => $user->user_id, 'provider_id' => 'passkey']);

		return $user;
	}

	/**
	 * Archiving leaves no second factor behind - not the provider rows, not the trusted devices,
	 * and not the `use_tfa` flag that indexes them.
	 *
	 * **The flag is the half that would hurt.** Set with no provider rows behind it, it asks for a
	 * code nothing can issue, so an owner whose account was restored could not get in to set
	 * two-step up again.
	 */
	public function test_archiving_clears_every_two_step_record(): void
	{
		$user = $this->anAccountWithTwoStep();

		$this->repo()->archiveUser($user);

		$this->assertDatabaseMissing('xf_user_tfa', ['user_id' => $user->user_id]);
		$this->assertDatabaseMissing('xf_user_tfa_trusted', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $user->user_id, 'use_tfa' => 0]);
	}

	/**
	 * A passkey is a credential and not merely a setting: it authenticates on its own, through a
	 * controller action that never reaches the Login service this add-on replaces. An archived
	 * account that keeps one is still refused - XenForo turns away a disabled account on every
	 * public action - but it is refused with core's generic page rather than this add-on's
	 * message, and the row stays in the database for as long as the forum does.
	 */
	public function test_archiving_clears_passkeys(): void
	{
		if (\XF::$versionId < 2030000)
		{
			$this->markTestSkipped('passkeys arrived in XF 2.3');
		}

		$user = $this->aPasskeyHolder();

		$this->repo()->archiveUser($user);

		$this->assertDatabaseMissing('xf_passkey', ['user_id' => $user->user_id]);
		$this->assertDatabaseMissing('xf_user_tfa', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $user->user_id, 'use_tfa' => 0]);
	}

	/**
	 * Restoring does not put two-step back, and that is the decision rather than an omission:
	 * nothing records that the account had it, because nothing could record the secret behind it.
	 * An owner whose account comes back sets it up again, from an account that can be logged in to.
	 */
	public function test_restoring_does_not_put_two_step_back(): void
	{
		$user = $this->anAccountWithTwoStep();
		$this->repo()->archiveUser($user);

		$this->repo()->restoreUser($user);

		$this->assertDatabaseMissing('xf_user_tfa', ['user_id' => $user->user_id]);
		$this->assertDatabaseMissing('xf_user_tfa_trusted', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $user->user_id, 'use_tfa' => 0]);
	}

	/**
	 * **What a forum archived by an earlier version has to do**, and the reason it is safe.
	 * Those accounts still hold the passkeys and two-step rows nothing used to clear, and no
	 * upgrade step goes looking for them - running `archive:archive-all-users` again is the way,
	 * because the job walks every row in `xf_user` rather than only the unarchived ones.
	 *
	 * So archiving an already-archived account has to be harmless, and the part that would not be
	 * is the archive row: `previous_state` was recorded from an account that was still valid, and
	 * rewriting it with `disabled` would make every later restore a no-op. `archiveUser()` creates
	 * the row only when there is not one, which is what this asserts - with the state set
	 * explicitly first, since a borrowed account can be `disabled` to begin with and the
	 * assertion would then pass whether the row was rewritten or not.
	 */
	public function test_archiving_an_already_archived_account_clears_credentials_and_keeps_its_record(): void
	{
		$user = $this->anAccountWithTwoStep();
		$user->user_state = 'valid';
		$user->save();

		$this->repo()->archiveUser($user);
		$previousState = $user->ArchivedUser->previous_state;
		$this->assertSame('valid', $previousState, 'the fixture did not record what it meant to');

		// two-step the account still has, because the version that archived it did not clear any
		$this->app()->repository('XF:Tfa')->enableUserTfaProvider(
			$user,
			$this->app()->em()->find('XF:TfaProvider', 'totp'),
			['secret' => str_repeat('a', 16)]
		);

		$this->assertTrue($this->repo()->archiveUser($user));

		$this->assertDatabaseMissing('xf_user_tfa', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_archivesite_user', [
			'user_id' => $user->user_id,
			'previous_state' => $previousState,
		]);
	}
}
