<?php

namespace Tests\Unit\Cli;

use Hampel\ArchiveSite\Cli\Command\ArchiveAll;
use Hampel\ArchiveSite\Cli\Command\ArchiveUser;
use Hampel\ArchiveSite\Cli\Command\RestoreAll;
use Hampel\ArchiveSite\Cli\Command\RestoreUser;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The four CLI commands, which nothing exercised until the framework's runConsoleCommand().
 *
 * **Every test here takes a path that writes nothing.** The commands archive and restore real
 * accounts on whatever forum they are pointed at, and this suite runs against the development
 * forum, so each one either refuses before the confirmation or declines it. The class is
 * transactional all the same.
 *
 * Declining is what `['interactive' => false]` buys: the confirmation is a Symfony question with
 * no default, which a non-interactive run answers with null, and every command treats anything
 * but "yes" as abort. So a non-interactive run reaches the confirmation and stops there, which is
 * both the safe path and the one worth asserting - it is the only thing between a mistyped
 * command and an archived forum.
 */
class CommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * XenForo lists commands by loading every add-on's command classes with class_exists(), so one
	 * that cannot load takes cmd.php down for the whole forum rather than for the add-on that owns
	 * it. Nothing else in this suite loads these classes at all.
	 */
	public function test_every_command_class_loads(): void
	{
		$classes = $this->assertConsoleCommandsLoad();

		$this->assertCount(4, $classes, 'the add-on ships four commands');
	}

	// ---- a single user -----------------------------------------------------------------------

	public function test_archiving_an_unknown_user_is_an_error(): void
	{
		$tester = $this->runConsoleCommand(ArchiveUser::class, ['user' => 'no-such-user-at-all'], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('not found', $tester->getDisplay());
	}

	public function test_restoring_an_unknown_user_is_an_error(): void
	{
		$tester = $this->runConsoleCommand(RestoreUser::class, ['user' => 'no-such-user-at-all'], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('not found', $tester->getDisplay());
	}

	/**
	 * The single-user commands do their own protected check, because they never consult the job's
	 * ID query - so the repository's second check is what they rely on, and this is the layer that
	 * refuses before anything is touched.
	 */
	public function test_archiving_a_super_admin_is_refused(): void
	{
		$admin = $this->aSuperAdmin();

		$tester = $this->runConsoleCommand(ArchiveUser::class, ['user' => $admin->user_id], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('is protected', $tester->getDisplay());
		$this->assertUnarchived($admin->user_id);
	}

	public function test_archiving_a_config_protected_user_is_refused(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSite', ['protectedUsers' => [$user->user_id]]);

		$tester = $this->runConsoleCommand(ArchiveUser::class, ['user' => $user->user_id], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('is protected', $tester->getDisplay());
		$this->assertUnarchived($user->user_id);
	}

	/**
	 * The account is found, is archivable, and is still not archived - because the confirmation
	 * was not given. A command that archived here would archive on any mistyped run.
	 */
	public function test_declining_the_confirmation_archives_nobody(): void
	{
		$user = $this->anActiveUser();

		$tester = $this->runConsoleCommand(ArchiveUser::class, ['user' => $user->user_id], ['interactive' => false]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('Aborting', $tester->getDisplay());
		$this->assertUnarchived($user->user_id);
	}

	// ---- every user --------------------------------------------------------------------------

	/**
	 * The guard that matters most in the add-on: with nothing protected, an archive run leaves a
	 * forum nobody can log in to. Both bulk commands abort, and the ACP's own enqueue does not -
	 * which is recorded as a defect rather than tested here.
	 *
	 * The protected list cannot be emptied through config, since every super admin is in it and
	 * this forum has some, so the repository is mocked to report an empty one.
	 */
	public function test_archive_all_aborts_when_nothing_is_protected(): void
	{
		$this->fakesJobs();
		$this->fakesErrors();
		$this->mockNothingProtected();

		$tester = $this->runConsoleCommand(ArchiveAll::class, [], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('No protected users found', $tester->getDisplay());
		$this->assertErrorLogged();
		$this->assertNoJobsQueued();
	}

	public function test_restore_all_aborts_when_nothing_is_protected(): void
	{
		$this->fakesJobs();
		$this->fakesErrors();
		$this->mockNothingProtected();

		$tester = $this->runConsoleCommand(RestoreAll::class, [], ['interactive' => false]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('No protected users found', $tester->getDisplay());
		$this->assertErrorLogged();
		$this->assertNoJobsQueued();
	}

	/**
	 * With users protected, the run reaches the confirmation, lists what it would spare, and
	 * queues nothing without a yes.
	 */
	public function test_archive_all_declining_the_confirmation_queues_no_job(): void
	{
		$this->fakesJobs();

		$tester = $this->runConsoleCommand(ArchiveAll::class, [], ['interactive' => false]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('Aborting', $tester->getDisplay());
		$this->assertNoJobsQueued();
	}

	public function test_restore_all_declining_the_confirmation_queues_no_job(): void
	{
		$this->fakesJobs();

		$tester = $this->runConsoleCommand(RestoreAll::class, [], ['interactive' => false]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('Aborting', $tester->getDisplay());
		$this->assertNoJobsQueued();
	}

	// ---- helpers -----------------------------------------------------------------------------

	protected function repo(): \Hampel\ArchiveSite\Repository\ArchiveUsers
	{
		return $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
	}

	protected function anActiveUser(): \XF\Entity\User
	{
		$user = $this->repo()->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs an active, non-protected user on the forum');
		}

		return $user;
	}

	protected function aSuperAdmin(): \XF\Entity\User
	{
		$user = $this->app()->finder('XF:User')->with('Admin', true)->where('Admin.is_super_admin', 1)->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs a super admin on the forum');
		}

		return $user;
	}

	/**
	 * A real finder that matches nobody, so the command's own total() and fetch() behave as they
	 * would on a forum with no protected users.
	 */
	protected function mockNothingProtected(): void
	{
		$empty = $this->app()->finder('XF:User')->where('user_id', 0);

		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock) use ($empty)
		{
			$mock->expects()->protectedUsers()->andReturns($empty);
		});
	}

	protected function assertUnarchived(int $userId): void
	{
		$this->app()->em()->clearEntityCache();
		$user = $this->app()->em()->find('XF:User', $userId, ['Auth', 'ArchivedUser']);

		$this->assertNull($user->ArchivedUser, 'an archive row was written');
		if ($user->Auth)
		{
			$this->assertNotSame('XF:NoPassword', $user->Auth->scheme_class, 'the password was removed');
		}
	}
}
