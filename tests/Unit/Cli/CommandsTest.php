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
 * **No test here archives anybody the transaction does not put back.** The commands archive and
 * restore real accounts on whatever forum they are pointed at, and this suite runs against the
 * development forum, so most of these refuse before the confirmation or decline it, and the two
 * that answer it name a single account inside the transaction.
 *
 * **The confirmed path of the bulk commands is deliberately not tested.** They extend
 * XF\Cli\Command\Rebuild\AbstractRebuildCommand, which runs the job in the command's own process
 * through JobRunnerTrait::runJob() - a `while ($runner = $jobManager->runUnique(...))` loop that
 * runs it to completion rather than queueing it. So a confirmed archive-all archives every account
 * on the forum this suite is pointed at, and the surrounding transaction is no guard either: the
 * job manager ends the transaction on its own error paths, after which what follows is permanent.
 * That path belongs on a sandbox.
 *
 * Declining is what `['interactive' => false]` buys: the confirmation is a Symfony question with
 * no default, which a non-interactive run answers with null, and every command treats anything
 * but "yes" as abort. So a non-interactive run reaches the confirmation and stops there, which is
 * both the safe path and the one worth asserting - it is the only thing between a mistyped
 * command and an archived forum. `['inputs' => ['yes']]` answers it instead, for the two that
 * need to get past it; the framework refuses the two options together, since Symfony would
 * answer from the defaults and ignore the inputs.
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

		$this->assertCount(6, $classes, 'the add-on ships six commands');
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

	/**
	 * The confirmed path, which nothing covered until the framework could answer a question: the
	 * command finds the account, asks, is told yes, and archives it. The repository tests cover
	 * what archiving does to an account; this covers the command reaching it at all, and saying
	 * that it did.
	 */
	public function test_a_confirmed_run_archives_the_named_account(): void
	{
		$user = $this->anActiveUser();

		$tester = $this->runConsoleCommand(ArchiveUser::class, ['user' => $user->user_id], ['inputs' => ['yes']]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString($user->username, $tester->getDisplay());

		$this->app()->em()->clearEntityCache();
		$archived = $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser']);
		$this->assertNotNull($archived->ArchivedUser, 'no archive row was written');
		$this->assertSame('disabled', $archived->user_state);
	}

	/**
	 * And back again through the other command, which is the pair's whole purpose. A restored
	 * account cannot log in until it completes a password reset, by design, so what is asserted
	 * is the state coming back and the archive row going away.
	 */
	public function test_a_confirmed_run_restores_the_named_account(): void
	{
		$user = $this->anActiveUser();
		$previousState = $user->user_state;
		$this->runConsoleCommand(ArchiveUser::class, ['user' => $user->user_id], ['inputs' => ['yes']]);

		$tester = $this->runConsoleCommand(RestoreUser::class, ['user' => $user->user_id], ['inputs' => ['yes']]);

		$this->assertSame(0, $tester->getStatusCode());

		$this->app()->em()->clearEntityCache();
		$restored = $this->app()->em()->find('XF:User', $user->user_id, ['Auth', 'ArchivedUser']);
		$this->assertNull($restored->ArchivedUser, 'the archive row was left behind');
		$this->assertSame($previousState, $restored->user_state);
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

	/**
	 * Every phrase written to the console is cast to a string first.
	 *
	 * **This cannot be asserted behaviourally from here**, because the suite runs on XenForo 2.3,
	 * whose bundled Symfony declares `OutputFormatter::format(?string $message)` and so coerces a
	 * Phrase at the boundary. XenForo 2.2 ships an older Symfony that does not, and
	 * `$output->writeln(\XF::phrase(...))` there throws `Object of class XF\Language could not be
	 * converted to string` - so on this add-on's declared floor, `archive:archive-user` archived
	 * the account and then crashed printing "Done", exit 1, with a row in the server error log.
	 * Found on a 2.2 sandbox during the 2.2.1 release, from one error-log row that nearly went
	 * unread.
	 *
	 * So the guard is the pattern, not the behaviour: a scan of the commands for the form that
	 * breaks. It is the same shape as the error-log prefix test below.
	 */
	public function test_no_phrase_is_written_to_the_console_uncast(): void
	{
		$offenders = [];

		foreach (glob(dirname(__DIR__, 3) . '/Cli/Command/*.php') AS $path)
		{
			foreach (file($path) AS $number => $line)
			{
				// the four backslashes are two in the regex, which is one literal backslash: '\X'
				// would be PCRE's grapheme-cluster escape, and a guard written that way matched
				// nothing and passed against the very code it was added for
				if (preg_match('/->writeln\(\s*\\\\XF::phrase/', $line))
				{
					$offenders[] = basename($path) . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $offenders,
			'a phrase is passed to writeln() unconcatenated, which throws on XenForo 2.2');
	}
}
