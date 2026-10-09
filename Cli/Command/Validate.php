<?php namespace Hampel\ArchiveSite\Cli\Command;

use Hampel\ArchiveSite\Cli\RendersReport;
use Hampel\ArchiveSite\Config\AllowMail;
use Hampel\ArchiveSite\Config\ProtectedUsers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum can actually do what archive:config says it is set up to do.
 *
 * The check that earns the command is the template modifications. Twelve of the fourteen match
 * literal text copied out of core templates, so a XenForo upgrade stops one matching and the page
 * renders as stock - a guest sees the log-in prompts and the birthdays the add-on exists to hide,
 * the modification's status column still says `ok`, and nothing anywhere raises an error. The
 * apply count is the only evidence, and until now reading it meant running SQL by hand.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on
 * it. --strict makes a warning exit 2, for a monitor that wants to hear of one, and changes
 * nothing else.
 *
 * **This add-on writes nothing and sends nothing, so --unattended has nothing to skip.** It is
 * accepted anyway, so that a deploy or monitor calling `<addon>:validate --unattended` across
 * every add-on on a forum does not fail here on an unknown option.
 */
class Validate extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('archive:validate')
			->setDescription('Check that the protected list, the mail restriction and the template modifications all work')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Accepted for consistency with other add-ons; this one neither writes nor sends, so it changes nothing')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failures, instead of 0');
	}

	protected function errorLogPrefix(): string
	{
		return 'ArchiveSite: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->checkSection('Environment');
		$this->probe('XenForo version', function ()
		{
			Config::isTestedOnThisXenForo()
				? $this->checkOk('XenForo version', \XF::$version)
				: $this->checkWarn('XenForo version', \XF::$version . ' is outside the tested range, 2.2 and 2.3');
		});
		$this->probe('archive table', function () { $this->archiveTable(); });

		$this->checkSection('Protected users');
		$this->probe('protected list', function () { $this->protectedList(); });
		$this->probe('listed accounts', function () { $this->listedAccountsExist(); });

		$this->checkSection('Mail');
		$this->probe('mail to members', function () { $this->mailRestriction(); });

		$this->checkSection('Class extensions');
		$this->probe('class extensions', function () { $this->classExtensions(); });

		$this->checkSection('Template modifications');
		$this->probe('template modifications', function () { $this->templateModifications(); });

		$this->checkSection('Accounts');
		$this->probe('guest messages', function () { $this->guestMessages(); });
		$this->probe('account counts', function () { $this->accountCounts(); });

		$output->writeln('');

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	/**
	 * The one thing standing between an archive run and a forum nobody can log in to. Both bulk
	 * commands abort when it resolves empty; the admin control panel's own button does not, which
	 * is why this is a failure rather than a warning.
	 */
	protected function protectedList()
	{
		$ids = $this->repo()->protectedUserIds();

		if (!$ids)
		{
			$this->checkFail('protected list', 'empty - an archive run would leave nobody able to log in');

			return;
		}

		$listed = count(ProtectedUsers::get());
		$this->checkOk('protected list', count($ids) . ' account(s): ' . $listed . ' from config.php, the rest super admins');
	}

	/**
	 * An id in config.php that is not a user means somebody meant to protect an account and has
	 * not. Nothing is at risk from the id itself, so it is a warning - but it is always a mistake.
	 */
	protected function listedAccountsExist()
	{
		$listed = ProtectedUsers::get();

		if (!$listed)
		{
			$this->checkSkip('listed accounts', 'nothing listed in config.php');

			return;
		}

		$found = \XF::app()->finder('XF:User')->where('user_id', $listed)->pluckFrom('user_id')->fetch()->toArray();
		$missing = array_diff(array_map('intval', $listed), array_map('intval', array_values($found)));

		$missing
			? $this->checkWarn('listed accounts', 'no such user: ' . implode(', ', $missing))
			: $this->checkOk('listed accounts', count($listed) . ' listed, all exist');
	}

	protected function archiveTable()
	{
		$db = \XF::db();

		if (!$db->fetchOne("SHOW TABLES LIKE 'xf_archivesite_user'"))
		{
			$this->checkFail('archive table', 'xf_archivesite_user is missing - restoring would have nothing to read');

			return;
		}

		$rows = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_archivesite_user');
		$this->checkOk('archive table', $rows . ' archived account(s) recorded');
	}

	/**
	 * Installing this add-on stops mail to every ordinary member, which is the point on an
	 * archived forum. The config switch that turns that off is for a development copy, so finding
	 * it on is worth saying out loud wherever this runs.
	 */
	protected function mailRestriction()
	{
		AllowMail::enabled()
			? $this->checkWarn('mail to members', "allowed - \$config['archiveSite']['allowMail'] is on, so the restriction is off")
			: $this->checkOk('mail to members', 'refused for anyone not protected');
	}

	/**
	 * The extensions name the pre-2.3 core class spellings, so a missing target means either a
	 * broken release or a XenForo that has stopped aliasing them - and either way the add-on's
	 * behaviour is silently core's.
	 */
	protected function classExtensions()
	{
		$rows = \XF::db()->fetchAll(
			'SELECT from_class, to_class, active FROM xf_class_extension WHERE addon_id = ?',
			['Hampel/ArchiveSite']
		);

		if (!$rows)
		{
			$this->checkFail('class extensions', 'none registered - every extension this add-on makes is inactive');

			return;
		}

		$missing = $inactive = [];
		$extension = \XF::app()->extension();

		foreach ($rows AS $row)
		{
			if (!$row['active'])
			{
				$inactive[] = $row['to_class'];
				continue;
			}

			// Resolve it the way XenForo does rather than asking class_exists() about the
			// extension class. An extension class extends an XFCP_ proxy that XenForo declares
			// only while resolving that extension, so autoloading it directly is what throws
			// "Class Hampel\ArchiveSite\XF\...\XFCP_PasswordReset not found" - a fault in the
			// check, not in the add-on. Resolving also proves more: that the extension applies.
			try
			{
				if (!is_a($extension->extendClass($row['from_class']), $row['to_class'], true))
				{
					$missing[] = $row['to_class'] . ' (does not extend ' . $row['from_class'] . ')';
				}
			}
			catch (\Throwable $e)
			{
				$missing[] = $row['to_class'] . ' (' . strtok($e->getMessage(), "\n") . ')';
			}
		}

		if ($missing)
		{
			$this->checkFail('class extensions', 'class not found: ' . implode(', ', $missing));
		}
		else if ($inactive)
		{
			$this->checkWarn('class extensions', 'inactive: ' . implode(', ', $inactive));
		}
		else
		{
			$this->checkOk('class extensions', count($rows) . ' active, all resolve');
		}
	}

	/**
	 * A modification that applies nowhere is dead, and the page it should have changed renders as
	 * stock. One exception is correct rather than a fault: the gallery modification cannot apply
	 * on a forum without XenForo Media Gallery, because the template it targets does not exist.
	 */
	protected function templateModifications()
	{
		$db = \XF::db();
		$mods = $db->fetchAll(
			'SELECT m.modification_key, m.template, m.enabled, COALESCE(SUM(l.apply_count), 0) AS applied
				FROM xf_template_modification m
				LEFT JOIN xf_template_modification_log l ON (l.modification_id = m.modification_id)
				WHERE m.addon_id = ?
				GROUP BY m.modification_id',
			['Hampel/ArchiveSite']
		);

		if (!$mods)
		{
			$this->checkFail('modifications', 'none registered - nothing is being hidden from guests');

			return;
		}

		$dead = $disabled = $absent = [];

		foreach ($mods AS $mod)
		{
			if (!$mod['enabled'])
			{
				$disabled[] = $mod['modification_key'];
			}
			else if (!$mod['applied'])
			{
				$db->fetchOne('SELECT COUNT(*) FROM xf_template WHERE title = ?', [$mod['template']])
					? $dead[] = $mod['modification_key'] . ' (' . $mod['template'] . ')'
					: $absent[] = $mod['modification_key'];
			}
		}

		if ($dead)
		{
			$this->checkFail('modifications', count($dead) . ' applying nowhere: ' . implode(', ', $dead));
		}
		else
		{
			$this->checkOk('modifications', (count($mods) - count($dead) - count($disabled) - count($absent)) . ' of ' . count($mods) . ' applying');
		}

		if ($disabled)
		{
			$this->checkWarn('disabled', implode(', ', $disabled));
		}

		if ($absent)
		{
			$this->checkSkip('template not installed', implode(', ', $absent));
		}
	}

	/**
	 * Everything switched off is a warning, not a pass: a forum hiding its features from guests
	 * with no message explaining why is working exactly as configured and telling nobody anything.
	 */
	protected function guestMessages()
	{
		$options = \XF::options();

		((string) $options->hampelArchiveSiteHomeMessage === '' && (string) $options->hampelArchiveSiteEndMessageHtml === '')
			? $this->checkWarn('guest messages', 'neither message is set, so guests see a stripped forum with no explanation')
			: $this->checkOk('guest messages', 'set');
	}

	/**
	 * Partially archived accounts lost their password by some route that recorded no previous
	 * state, so nothing can restore them. Worth surfacing wherever this runs rather than only in
	 * the control panel.
	 */
	protected function accountCounts()
	{
		$repo = $this->repo();
		$partial = (int) $repo->partiallyArchivedUsers()->total();

		$this->checkOk('counts', sprintf(
			'%d active, %d archived, %d protected',
			$repo->activeUsers()->total(),
			$repo->archivedUsers()->total(),
			count($repo->protectedUserIds())
		));

		if ($partial)
		{
			$this->checkWarn('partially archived', $partial . ' account(s) have no password and no archive row, so cannot be restored');
		}
	}

	/**
	 * @return \Hampel\ArchiveSite\Repository\ArchiveUsers
	 */
	protected function repo()
	{
		return \XF::repository('Hampel\ArchiveSite:ArchiveUsers');
	}
}
