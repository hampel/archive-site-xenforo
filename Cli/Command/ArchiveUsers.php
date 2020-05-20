<?php namespace Hampel\ArchiveSite\Cli\Command;

use Hampel\ArchiveSite\Repository\Archive;
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
			->setName('xf:archive-users')
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
		/** @var Archive $archiveRepo */
		$archiveRepo = \XF::repository('Hampel\ArchiveSite:Archive');

		$usernameOrEmail = $input->getOption('user');
		if ($usernameOrEmail)
		{
			$user = \XF::repository("XF:User")->getUserByNameOrEmail($usernameOrEmail, ['Auth']);
			if (!$user)
			{
				$output->writeln("<error>User [{$usernameOrEmail}] not found.</error>");
				return 1;
			}

			$output->writeln("<info>Archiving user: {$user->username} ({$user->email})</info>");
			if (!$archiveRepo->archiveUser($user))
			{
				$output->writeln("<error>User archiving user [{$usernameOrEmail}].</error>");
				return 1;
			}

			$output->writeln("Done");
			return 0;
		}

		$finder = $archiveRepo->protectedUsers();
		$protectedCount = $finder->total();
		$protected = $finder->fetch();

		if ($protectedCount == 0)
		{
			$output->writeln("<error>No protected users found, aborting.</error>");
			$output->writeln("This shouldn't happen, because a site should have at least one super-admin and all super admins are protected");
			return 1;
		}

		$output->writeln("");
		$output->writeln("<comment>Archive users</comment>");
		$output->writeln("");

		$output->write("<info>");
		$output->write(number_format($protectedCount));
		$output->write(" protected users found:</info>", true);
		foreach ($protected as $user)
		{
			$output->writeln(" - {$user->username}: {$user->email}");
		}
		$output->writeln("");

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$output->writeln("<question>Are you sure you would like to continue archiving users?.</question>");
		$question = new Question("<info>Type 'yes' to continue, any other key to abort: </info>");
		$continue = $helper->ask($input, $output, $question);
		$output->writeln("");

		if (!in_array($continue, ['yes', 'Yes', 'YES']))
		{
			$output->writeln("Aborting");
			return 0;
		}

		$finder = $archiveRepo->activeUsers();
		$active = $finder->fetch()->filter(function(User $user) {
			return !$user->is_super_admin;
		});

		$total = $active->count();

		if ($total == 0)
		{
			$output->writeln("No active users found.");
			return 0;
		}

		$count = 0;
		foreach ($active as $user)
		{
			$count++;

			if (!$archiveRepo->archiveUser($user))
			{
				$output->writeln("<error>User archiving user [{$user->username}].</error>");
				return 1;
			}

			if ($count % 100 == 0)
			{
				$output->write("Archived ");
				$output->write(number_format($count));
				$output->write(" users", true);
			}
		}

		$output->write("<info>Successfully archived ");
		$output->write(number_format($count));
		$output->write(" users</info>", true);

		return 0;
	}
}