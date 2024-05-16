<?php namespace Hampel\ArchiveSite\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use XF\Cli\Command\Rebuild\AbstractRebuildCommand;

class ArchiveAll extends AbstractRebuildCommand
{
    protected function getRebuildName()
    {
        return 'archive-all-users';
    }

    protected function getRebuildDescription()
    {
        return 'Archive all non-protected users by removing their password';
    }

    protected function getRebuildClass()
    {
        return 'Hampel\ArchiveSite:ArchiveUsers';
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
		$output->writeln("<comment>" . \XF::phrase('hampel_archivesite_archive_users') . "</comment>");
		$output->writeln("");

		$output->writeln("<info>" . \XF::phrase('hampel_archivesite_x_protected_users_found:', ['count' => number_format($protectedCount)]) . "</info>");
		foreach ($protected as $user)
		{
			$output->writeln(" - {$user->username} ({$user->email})");
		}
		$output->writeln("");
		$output->writeln("... " . \XF::phrase('hampel_archivesite_all_other_users_will_be_archived'));
		$output->writeln("");

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$output->writeln("<question>" . \XF::phrase('hampel_archivesite_are_you_sure_archiving') . "</question>");
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