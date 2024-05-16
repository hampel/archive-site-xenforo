<?php namespace Hampel\ArchiveSite\Cli\Command;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class RestoreUser extends Command
{
	protected function configure()
	{
		$this
			->setName('archive:restore-user')
			->setDescription('Restore a single user')
			->addArgument(
				'user',
                InputArgument::REQUIRED,
				"User_id, username or email address of user to restore"
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Hampel\ArchiveSite\Repository\ArchiveUsers $archiveRepo */
        $archiveRepo = \XF::repository('Hampel\ArchiveSite:ArchiveUsers');
        /** @var \XF\Repository\User $userRepo */
        $userRepo = \XF::repository("XF:User");

        $userArg = $input->getArgument('user');
        if (is_numeric($userArg)) {
            $user = \XF::em()->find('XF:User', $userArg, ['Auth']);
        } else {
            $user = $userRepo->getUserByNameOrEmail($userArg, ['Auth']);
        }

        if (!$user) {
            $output->writeln("<error>" . \XF::phrase('hampel_archivesite_user_x_not_found', ['user' => $userArg]) . "</error>");
            return 1;
        }

        if ($user->is_super_admin || ProtectedUsers::isProtected($user->user_id)) {
            $output->writeln("<error>" . \XF::phrase('hampel_archivesite_user_x_is_protected', ['user' => $user->username]) . "</error>");
            return 1;
        }

        $output->writeln("<info>" . \XF::phrase('hampel_archivesite_restoring_user_x', ['username' => $user->username, 'email' => $user->email]) . "</info>");

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $output->writeln("<question>" . \XF::phrase('hampel_archivesite_are_you_sure_restoring_x', ['user' => $user->username]) . "</question>");
        $question = new Question("<info>" . \XF::phrase('hampel_archivesite_type_yes_to_continue') . ": </info>");
        $continue = $helper->ask($input, $output, $question);
        $output->writeln("");

        if (!in_array($continue, ['yes', 'Yes', 'YES']))
        {
            $output->writeln(\XF::phrase('hampel_archivesite_aborting'));
            return 0;
        }

        if (!$archiveRepo->restoreUser($user)) {
            $output->writeln("<error>" . \XF::phrase('hampel_archivesite_error_restoring_user_x', ['user' => $user->username]) . "</error>");
            return 1;
        }

        $output->writeln(\XF::phrase('hampel_archivesite_done'));
        return 0;
    }
}