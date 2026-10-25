<?php namespace Hampel\ArchiveSite\Seed;

use Hampel\ArchiveSite\Factory\ArchivedUserFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Seed\AbstractSeeder;
use XF\Entity\User;

/**
 * Members in the archive states that archive:archive-all-users cannot produce, because each is
 * what an earlier version or an interrupted run left behind.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and
 * must not ship in a release. Run it in a sandbox, never on a forum anybody uses.
 *
 *     php cmd.php seeder:run Hampel/ArchiveSite:ArchiveStates -p archived=8 -p partial=3
 *
 * Parameters, each a number of members:
 *
 *     archived     archived as this version leaves them: a recorded state and scheme, no
 *                  password, no credentials. Half of them never had a password (8)
 *     legacy       archived before 2.2.0, which recorded no scheme, so a restore resets a
 *                  password whatever the account had (3)
 *     credentials  archived before 2.2.3, which left their "stay logged in" key in place and
 *                  either two-step or a passkey - what a re-run of archive-all-users has to
 *                  clear. Half get a passkey, which comes with backup codes as XenForo adds them
 *                  beside any provider, so no member is given both (4)
 *     partial      shut and passwordless with no archive row, so nothing can restore them (3)
 *
 * The state each archived member is recorded as having had cycles through valid, valid,
 * moderated and email_confirm, so a restore puts some back in the approval queue. Seed members
 * before listing any in config.php: a seeded id that is protected is archived all the same,
 * which no run of this add-on would do.
 */
class ArchiveStatesSeeder extends AbstractSeeder
{
	protected const PREVIOUS = ['valid', 'valid', 'moderated', 'email_confirm'];

	/**
	 * @return void
	 */
	public function run()
	{
		$archived = $this->count('archived', 8);
		$legacy = $this->count('legacy', 3);
		$credentials = $this->count('credentials', 4);
		$partial = $this->count('partial', 3);

		$this->members($archived - intdiv($archived, 2), true)->create();
		$this->members(intdiv($archived, 2), false)->create();

		$this->members($legacy, true, function (User $user)
		{
			$this->archive($user, '');
		})->create();

		$this->members($credentials - intdiv($credentials, 2), true)
			->withTwoStep()
			->withRememberKey()
			->create();
		$this->members(intdiv($credentials, 2), true)
			->withPasskey()
			->withRememberKey()
			->create();

		UserFactory::new()
			->count($partial)
			->withoutPassword()
			->afterCreating(function (User $user)
			{
				$this->shut($user);
			})
			->create();
	}

	/**
	 * Members to be archived once saved - by default as this version would, recording the
	 * scheme they had.
	 *
	 * @param bool          $password whether they had one
	 * @param \Closure|null $archive  what to do with each instead
	 */
	protected function members(int $count, bool $password, ?\Closure $archive = null): UserFactory
	{
		$archive = $archive ?: function (User $user)
		{
			$this->archive($user, $user->Auth ? $user->Auth->scheme_class : '');
		};

		$factory = UserFactory::new()
			->count($count)
			->sequence(...array_map(function ($state)
			{
				return ['user_state' => $state];
			}, self::PREVIOUS))
			->afterCreating($archive);

		return $password ? $factory : $factory->withoutPassword();
	}

	/**
	 * What archiveUser() writes, short of clearing credentials: the archive row, then the
	 * password and the state.
	 */
	protected function archive(User $user, string $scheme): void
	{
		$row = ArchivedUserFactory::new()->create([
			'user_id' => $user->user_id,
			'previous_state' => $user->user_state,
			'previous_scheme_class' => $scheme,
		]);

		$user->hydrateRelation('ArchivedUser', $row);
		$this->shut($user);
	}

	/**
	 * No password and disabled, saved through the entities as archiving saves them.
	 */
	protected function shut(User $user): void
	{
		UserFactory::silence($user);

		if ($user->Auth)
		{
			$user->Auth->setNoPassword();
			$user->Auth->save();
		}

		$user->user_state = 'disabled';
		$user->save();
	}
}
