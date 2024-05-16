<?php namespace Hampel\ArchiveSite\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use XF\Cli\Command\Rebuild\AbstractRebuildCommand;

class RestoreAll extends AbstractRebuildCommand
{
    protected function getRebuildName()
    {
        return 'restore-all-users';
    }

    protected function getRebuildDescription()
    {
        return 'Restore archived users so they can log in';
    }

    protected function getRebuildClass()
    {
        return 'Hampel\ArchiveSite:RestoreUsers';
    }

    protected function configure()
    {
        parent::configure();

        $this->setName('archive:' . $this->getRebuildName());
    }

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var \Hampel\ArchiveSite\Repository\ArchiveUsers $archiveRepo */
		$archiveRepo = \XF::repository('Hampel\ArchiveSite:ArchiveUsers');

		$finder = $archiveRepo->protectedUsers();
		$protectedCount = $finder->total();
		$protected = $finder->fetch();

		if ($protectedCount == 0)
		{
			$output->writeln("<error>" . \XF::phrase('hampel_archivesite_no_protected_users_found') . "</error>");
			\XF::logError(\XF::phrase('hampel_archivesite_no_protected_users_found_error'));
			return 1;
		}

		$output->writeln("");
		$output->writeln("<comment>" . \XF::phrase('hampel_archivesite_restore_users') . "</comment>");
		$output->writeln("");

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$output->writeln("<question>" . \XF::phrase('hampel_archivesite_are_you_sure_restoring') . "</question>");
		$question = new Question("<info>" . \XF::phrase('hampel_archivesite_type_yes_to_continue') . ": </info>");
		$continue = $helper->ask($input, $output, $question);
		$output->writeln("");

		if (!in_array($continue, ['yes', 'Yes', 'YES']))
		{
			$output->writeln(\XF::phrase('hampel_archivesite_aborting'));
			return 0;
		}

        return parent::execute($input, $output);
	}
}