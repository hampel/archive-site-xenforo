<?php namespace Hampel\ArchiveSite\Cli\Command;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use XF\Entity\User;

class ArchiveUsers extends Command
{
	protected function configure()
	{
		$this
			->setName('archive:archive-users')
			->setDescription('Archive users by removing their password')
			->addOption(
				'user',
				'u',
				InputOption::VALUE_REQUIRED,
				"Archive a single user by username or email address"
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var \Hampel\ArchiveSite\Repository\ArchiveUsers $archiveRepo */
		$archiveRepo = \XF::repository('Hampel\ArchiveSite:ArchiveUsers');

		$usernameOrEmail = $input->getOption('user');
		if ($usernameOrEmail)
		{
			/** @var User $user */
			$user = \XF::repository("XF:User")->getUserByNameOrEmail($usernameOrEmail, ['Auth']);
			if (!$user)
			{
				$output->writeln("<error>" . \XF::phrase('hampel_archivesite_user_x_not_found', ['user' => $usernameOrEmail]) . "</error>");
				return 1;
			}

			if ($user->is_super_admin || ProtectedUsers::isProtected($user->user_id))
			{
				$output->writeln("<error>" . \XF::phrase('hampel_archivesite_user_x_is_protected', ['user' => $usernameOrEmail]) . "</error>");
				return 1;
			}

			$output->writeln("<info>" . \XF::phrase('hampel_archivesite_archiving_user_x', ['username' => $user->username, 'email' => $user->email]) . "</info>");
			if (!$archiveRepo->archiveUser($user))
			{
				$output->writeln("<error>" . \XF::phrase('hampel_archivesite_error_archiving_user_x', ['user' => $usernameOrEmail]) . "</error>");
				return 1;
			}

			$output->writeln(\XF::phrase('hampel_archivesite_done'));
			return 0;
		}

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

        $active = $archiveRepo->activeUsers()->fetch();
		$total = $active->count();

		if ($total == 0)
		{
			$output->writeln(\XF::phrase('hampel_archivesite_no_active_users'));
		}
        else
        {
            $count = 0;
            foreach ($active as $user)
            {
                $count++;

                if (!$archiveRepo->archiveUser($user))
                {
                    $output->writeln("<error>" . \XF::phrase('hampel_archivesite_error_archiving_user_x', ['user' => $user->username]) . "</error>");
                    return 1;
                }

                if ($count % 100 == 0)
                {
                    $output->writeln(\XF::phrase('hampel_archivesite_archived_x_users', ['count' => number_format($count)]));
                }
            }

            $output->writeln("<info>" . \XF::phrase('hampel_archivesite_successfully_archived_x_users', ['count' => number_format($count)]) . "</info>");
        }

        $partiallyArchived = $archiveRepo->partiallyArchivedUsers()->fetch();
        $total = $partiallyArchived->count();

        if ($total > 0)
        {
            $count = 0;
            foreach ($partiallyArchived as $user)
            {
                $count++;

                if (!$archiveRepo->archiveUser($user))
                {
                    $output->writeln("<error>" . \XF::phrase('hampel_archivesite_error_archiving_user_x', ['user' => $user->username]) . "</error>");
                    return 1;
                }

                if ($count % 100 == 0)
                {
                    $output->writeln(\XF::phrase('hampel_archivesite_archived_x_users', ['count' => number_format($count)]));
                }
            }

            $output->writeln("<info>" . \XF::phrase('hampel_archivesite_successfully_archived_x_users', ['count' => number_format($count)]) . "</info>");
        }

		return 0;
	}
}