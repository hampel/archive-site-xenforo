<?php namespace Hampel\ArchiveSite\Cli\Command;

use Hampel\ArchiveSite\Cli\RendersReport;
use Hampel\ArchiveSite\Config\AllowMail;
use Hampel\ArchiveSite\Config\ProtectedUsers;
use Hampel\ArchiveSite\Config\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What this forum is configured to archive and to hide, and where each setting comes from.
 *
 * It exists because the settings that matter most here are not on the options page at all: the
 * protected user list and the mail switch live in config.php, so an administrator looking at the
 * control panel cannot see either. It reads and prints, writes nothing, and sends nothing -
 * archive:validate is the command that finds out whether any of it works.
 *
 * Nothing here is a credential, so nothing is redacted. The protected list is printed as counts
 * rather than names because the names are of no use in a ticket and the counts are.
 */
class Config extends Command
{
	use RendersReport;

	const FROM_CONFIG = ' <fg=cyan>(config.php)</>';

	const FROM_OPTIONS = ' <fg=cyan>(options)</>';

	protected function configure()
	{
		$this
			->setName('archive:config')
			->setDescription('Show what this forum is configured to archive and hide, and where each setting comes from');
	}

	protected function errorLogPrefix(): string
	{
		return 'ArchiveSite: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$app = \XF::app();

		$this->heading('Archive Site');
		$addOn = $app->addOnManager()->getById('Hampel/ArchiveSite');
		$this->detail('add-on', $addOn ? (string) $addOn->getJson()['version_string'] : '');
		$this->detail('XenForo', \XF::$version . (self::isTestedOnThisXenForo() ? '' : ' - outside the tested range, 2.2 and 2.3'));

		$this->heading('Protected users');
		$source = $this->sourceDescription();
		$this->detail('source', $source === '' ? '' : $source . self::FROM_CONFIG);
		$this->detail('listed in config.php', (string) count(ProtectedUsers::get()));
		$this->detail('deprecated key', $this->legacyKeyState());
		$this->detail('super admins', (string) $this->superAdminCount());
		$this->detail('protected in total', (string) count($this->repo()->protectedUserIds()));

		$this->heading('Mail');
		$this->detail(
			'mail to members',
			(AllowMail::enabled() ? 'allowed - the restriction is off' : 'refused') . self::FROM_CONFIG
		);

		$this->heading('Hidden from guests');
		$options = $app->options();
		$this->detail('home page message', $this->textState($options->hampelArchiveSiteHomeMessage) . self::FROM_OPTIONS);
		$this->detail('content end message', $this->textState($options->hampelArchiveSiteEndMessageHtml) . self::FROM_OPTIONS);
		$this->detail('end message URL', ((string) $options->hampelArchiveSiteEndMessageUrl) . ($options->hampelArchiveSiteEndMessageUrl ? self::FROM_OPTIONS : ''));
		$this->detail('opens a new window', ($options->hampelArchiveSiteEndMessageUrlTarget ? 'yes' : 'no') . self::FROM_OPTIONS);

		$output->writeln('');

		return 0;
	}

	/**
	 * The add-on's class extensions name the pre-2.3 core class spellings, which is what a 2.2
	 * floor requires and what keeps working on 2.3 through XenForo's aliases. 2.4 is untested.
	 *
	 * @return bool
	 */
	public static function isTestedOnThisXenForo()
	{
		return \XF::$versionId >= 2020070 && \XF::$versionId < 2040000;
	}

	protected function sourceDescription(): string
	{
		switch (ProtectedUsers::source())
		{
			case ProtectedUsers::SOURCE_SETTINGS:
				return "\$config['" . Settings::KEY . "']['protectedUsers']";

			case ProtectedUsers::SOURCE_LEGACY:
				return "\$config['archiveSiteProtectedUsers'] - deprecated, use \$config['" . Settings::KEY . "']['protectedUsers']";

			default:
				return '';
		}
	}

	/**
	 * What the deprecated flat key is doing on this forum, which is the question an upgrade raises:
	 * an install from before 2.2.0 still reads it, and one part-way through the rename has both set.
	 * Where both are set and the current one names fewer ids, the difference is no longer protected
	 * - archive:validate warns about that, and this says the count.
	 */
	protected function legacyKeyState(): string
	{
		if (ProtectedUsers::source() == ProtectedUsers::SOURCE_LEGACY)
		{
			return 'in use - this forum reads it' . self::FROM_CONFIG;
		}

		if (!ProtectedUsers::legacyIds())
		{
			return '';
		}

		$ignored = ProtectedUsers::ignoredLegacyIds();

		return ($ignored
			? 'also set, ' . count($ignored) . ' id(s) ignored - see archive:validate'
			: 'also set, nothing ignored') . self::FROM_CONFIG;
	}

	/**
	 * Its length, not its text: the messages are HTML and belong in nobody's terminal, but whether
	 * one is set and roughly how long it is answers the question people ask.
	 */
	protected function textState($value): string
	{
		$value = (string) $value;

		return $value === '' ? '' : 'set, ' . mb_strlen($value) . ' characters';
	}

	protected function superAdminCount(): int
	{
		return (int) \XF::app()->finder('XF:User')
			->with('Admin', true)
			->where('Admin.is_super_admin', 1)
			->total();
	}

	/**
	 * @return \Hampel\ArchiveSite\Repository\ArchiveUsers
	 */
	protected function repo()
	{
		return \XF::repository('Hampel\ArchiveSite:ArchiveUsers');
	}
}
