<?php

namespace Tests\Unit\Cli;

use Hampel\ArchiveSite\Cli\Command\Config;
use Hampel\ArchiveSite\Cli\Command\Validate;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * archive:config and archive:validate - the two self-describing commands.
 *
 * The exit contract is the product, so most of this is exit codes: 1 is always a failure, a
 * warning is 0 without `--strict` and 2 with it, and `--strict` is a pure function of the
 * tallies. Neither command writes or sends anything, which is itself asserted - a `config` that
 * changed something would be the one thing nobody would look for.
 *
 * The failure and warning states are reached by making the forum look a particular way through
 * config and a stubbed repository, rather than by breaking the install: a forum with nothing
 * protected is exactly the state these exist to refuse, and it is not a state to create for real.
 */
class ReportCommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * A repository that reports nothing protected, with the three finders still answering, so the
	 * rest of the run proceeds and the failure is the one under test.
	 */
	protected function withNothingProtected(): void
	{
		$empty = $this->app()->finder('XF:User')->where('user_id', 0);

		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock) use ($empty)
		{
			$mock->shouldReceive('protectedUserIds')->andReturn([]);
			$mock->shouldReceive('activeUsers')->andReturn($empty);
			$mock->shouldReceive('archivedUsers')->andReturn($empty);
			$mock->shouldReceive('partiallyArchivedUsers')->andReturn($empty);
		});
	}

	/**
	 * A forum that has finished archiving: nothing active, something archived, nothing partial.
	 *
	 * **Needed to isolate any other warning.** The development forum has 2011 accounts that can
	 * still log in, so `archive:validate` warns there whatever else is true - and a `--strict`
	 * test that did not mock this would exit 2 for that reason and pass while asserting nothing
	 * about the warning it names.
	 */
	protected function mockFullyArchivedForum(): void
	{
		$none = $this->app()->finder('XF:User')->where('user_id', 0);
		$some = $this->app()->finder('XF:User')->where('user_id', '>', 0);

		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock) use ($none, $some)
		{
			$mock->shouldReceive('protectedUserIds')->andReturn([1]);
			$mock->shouldReceive('activeUsers')->andReturn($none);
			$mock->shouldReceive('archivedUsers')->andReturn($some);
			$mock->shouldReceive('partiallyArchivedUsers')->andReturn($none);
		});
	}

	// ---- config ------------------------------------------------------------------------------

	public function test_config_names_the_key_the_protected_list_came_from(): void
	{
		$this->setConfig('archiveSite', ['protectedUsers' => [1]]);

		$tester = $this->runConsoleCommand(Config::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString("['protectedUsers']", $tester->getDisplay());
	}

	/**
	 * The deprecated key is still read, so an install that has not moved should be told which one
	 * it is using rather than left to guess.
	 */
	public function test_config_says_when_the_list_comes_from_the_deprecated_key(): void
	{
		$this->setConfig('archiveSite', null);
		$this->setConfig('archiveSiteProtectedUsers', [1]);

		$tester = $this->runConsoleCommand(Config::class);

		$this->assertStringContainsString('deprecated', $tester->getDisplay());
	}

	public function test_config_reports_an_unset_list_as_not_set(): void
	{
		$this->setConfig('archiveSite', null);
		$this->setConfig('archiveSiteProtectedUsers', null);

		$tester = $this->runConsoleCommand(Config::class);

		$this->assertStringContainsString('not set', $tester->getDisplay());
	}

	public function test_config_changes_nothing_and_sends_nothing(): void
	{
		$this->fakesJobs();
		$this->fakesErrors();
		$this->fakesMail();

		$this->assertSame(0, $this->runConsoleCommand(Config::class)->getStatusCode());

		$this->assertNoJobsQueued();
		$this->assertErrorNotLogged();
		$this->assertMailNotSent();
	}

	// ---- validate ----------------------------------------------------------------------------

	public function test_validate_passes_on_this_forum(): void
	{
		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[ ok ] class extensions', $tester->getDisplay());
		$this->assertStringContainsString('applying', $tester->getDisplay());
	}

	/**
	 * The failure that matters: with nothing protected, an archive run leaves a forum nobody can
	 * log in to. Both bulk commands abort on it and the control panel's own button does not, so
	 * this is the check that makes the state visible wherever anyone looks.
	 */
	public function test_an_empty_protected_list_fails_the_run(): void
	{
		$this->withNothingProtected();

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('[fail] protected list', $tester->getDisplay());
	}

	/**
	 * 1 means failed with the flag and without it. --strict only moves the warning state.
	 */
	public function test_a_failure_exits_1_with_strict_as_well(): void
	{
		$this->withNothingProtected();

		$tester = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertSame(1, $tester->getStatusCode());
	}

	public function test_the_mail_switch_being_on_is_a_warning_not_a_failure(): void
	{
		$this->setConfig('archiveSite', ['allowMail' => true]);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode(), 'a warning must not fail a gate');
		$this->assertStringContainsString('[warn] mail to members', $tester->getDisplay());
	}

	public function test_strict_turns_that_warning_into_exit_2(): void
	{
		$this->mockFullyArchivedForum();
		$this->setConfig('archiveSite', ['allowMail' => true]);

		$tester = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertSame(2, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] mail to members', $tester->getDisplay());
	}

	/**
	 * The control for the test above: with the same forum and the flag off, `--strict` exits 0.
	 * Without this, a 2 could be coming from anything.
	 */
	public function test_strict_exits_0_when_there_is_nothing_to_warn_about(): void
	{
		$this->mockFullyArchivedForum();

		$tester = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
	}

	/**
	 * An id in config.php that is not a user is a mistake somebody should know about, and nothing
	 * else reports it - but it endangers nothing, so it must not fail a deploy gate.
	 */
	public function test_a_listed_id_that_is_not_a_user_warns(): void
	{
		$this->setConfig('archiveSite', ['protectedUsers' => [999999999]]);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] listed accounts', $tester->getDisplay());
	}

	public function test_unattended_is_accepted_and_changes_nothing(): void
	{
		$plain = $this->runConsoleCommand(Validate::class);
		$unattended = $this->runConsoleCommand(Validate::class, ['--unattended' => true]);

		$this->assertSame($plain->getStatusCode(), $unattended->getStatusCode());
		$this->assertSame($plain->getDisplay(), $unattended->getDisplay());
	}

	/**
	 * No check may end the run: a validation that stops at its first exception hides the rest, on
	 * the one occasion the whole list was wanted. probe() turns it into a [fail] row, logs it
	 * with the add-on's prefix, and carries on - so the rows after it still report.
	 */
	public function test_a_check_that_throws_fails_without_ending_the_run(): void
	{
		$this->fakesErrors();
		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock)
		{
			$mock->shouldReceive('protectedUserIds')->andThrow(new \RuntimeException('contrived'));
			$mock->shouldReceive('activeUsers')->andThrow(new \RuntimeException('contrived'));
			$mock->shouldReceive('archivedUsers')->andThrow(new \RuntimeException('contrived'));
			$mock->shouldReceive('partiallyArchivedUsers')->andThrow(new \RuntimeException('contrived'));
		});

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('RuntimeException: contrived', $tester->getDisplay());
		$this->assertStringContainsString('[ ok ] class extensions', $tester->getDisplay(),
			'the run stopped at the throwing check');
		$this->assertExceptionLogged(\RuntimeException::class, 'ArchiveSite: ');
	}

	/**
	 * The check the command exists for. A modification whose find string has stopped matching
	 * reports `ok` in the control panel, renders the page as stock, and raises nothing anywhere -
	 * the apply count is the only evidence there is.
	 *
	 * Its log rows are deleted rather than its find string broken, because editing a modification
	 * recompiles the install's shared templates, which no transaction rolls back. Raw SQL inside
	 * the transaction touches no entity, so nothing rebuilds and everything is undone.
	 */
	public function test_a_modification_that_applies_nowhere_fails_the_run(): void
	{
		$db = $this->app()->db();
		$id = $db->fetchOne(
			"SELECT modification_id FROM xf_template_modification
				WHERE addon_id = ? AND modification_key = ?",
			['Hampel/ArchiveSite', 'hampelArchiveSiteForumView']
		);
		$this->assertNotNull($id, 'the modification this test works on is missing');

		$db->delete('xf_template_modification_log', 'modification_id = ?', [$id]);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('[fail] modifications', $tester->getDisplay());
		$this->assertStringContainsString('hampelArchiveSiteForumView', $tester->getDisplay());
	}

	/**
	 * Switched off is not the same as broken: somebody did it on purpose, and the page renders as
	 * stock either way - so it is reported, and it does not fail a gate.
	 */
	public function test_a_disabled_modification_warns(): void
	{
		$db = $this->app()->db();
		$db->update('xf_template_modification', ['enabled' => 0], 'addon_id = ? AND modification_key = ?',
			['Hampel/ArchiveSite', 'hampelArchiveSiteBirthday']);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] disabled', $tester->getDisplay());
		$this->assertStringContainsString('hampelArchiveSiteBirthday', $tester->getDisplay());
	}

	/**
	 * Every row this add-on writes to the server error log begins with its own prefix, so that a
	 * mirror of that log can skip its own rows rather than alerting on them through the handler
	 * that just failed. A call without one is invisible until that happens.
	 */
	public function test_no_log_call_lacks_the_add_on_prefix(): void
	{
		$root = dirname(__DIR__, 3);
		$unprefixed = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

		foreach ($files AS $file)
		{
			$path = $file->getPathname();

			if ($file->getExtension() !== 'php' || strpos($path, '/vendor/') !== false || strpos($path, '/tests/') !== false)
			{
				continue;
			}

			foreach (file($path) AS $number => $line)
			{
				if (preg_match('/::log(Error|Exception)\(/', $line)
					&& strpos($line, 'ArchiveSite: ') === false
					&& strpos($line, 'errorLogPrefix') === false)
				{
					$unprefixed[] = str_replace($root . '/', '', $path) . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $unprefixed, 'a log call does not carry the add-on prefix');
	}

	// ---- how far the archiving has got --------------------------------------------------------

	/**
	 * The add-on is installed and 2011 accounts can still log in, which means archiving was set
	 * up and never run. A warning, not a failure: nothing is broken, and a gate should not be
	 * blocked by it - but somebody should know.
	 */
	public function test_a_forum_that_has_not_archived_anybody_warns(): void
	{
		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] archiving', $tester->getDisplay());
		$this->assertStringContainsString('not started', $tester->getDisplay());
	}

	/**
	 * The distinction worth drawing: a run that stopped part-way, or accounts registered since
	 * one finished, wants a different action from a forum that never started.
	 */
	public function test_a_half_archived_forum_says_incomplete(): void
	{
		$none = $this->app()->finder('XF:User')->where('user_id', 0);
		$some = $this->app()->finder('XF:User')->where('user_id', '>', 0);

		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock) use ($none, $some)
		{
			$mock->shouldReceive('protectedUserIds')->andReturn([1]);
			$mock->shouldReceive('activeUsers')->andReturn($some);
			$mock->shouldReceive('archivedUsers')->andReturn($some);
			$mock->shouldReceive('partiallyArchivedUsers')->andReturn($none);
		});

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] archiving', $tester->getDisplay());
		$this->assertStringContainsString('incomplete', $tester->getDisplay());
	}

	public function test_a_finished_forum_reports_archiving_complete(): void
	{
		$this->mockFullyArchivedForum();

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[ ok ] archiving', $tester->getDisplay());
		$this->assertStringContainsString('complete', $tester->getDisplay());
	}

	/**
	 * An account with no password and no archive row cannot be restored, because nothing recorded
	 * what its state was. Nothing can fix that, so it is a warning for as long as it is true.
	 */
	public function test_a_partially_archived_account_warns(): void
	{
		$none = $this->app()->finder('XF:User')->where('user_id', 0);
		$some = $this->app()->finder('XF:User')->where('user_id', '>', 0);

		$this->mockRepository('Hampel\ArchiveSite:ArchiveUsers', function ($mock) use ($none, $some)
		{
			$mock->shouldReceive('protectedUserIds')->andReturn([1]);
			$mock->shouldReceive('activeUsers')->andReturn($none);
			$mock->shouldReceive('archivedUsers')->andReturn($some);
			$mock->shouldReceive('partiallyArchivedUsers')->andReturn($some);
		});

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn] partially archived', $tester->getDisplay());
	}
}
