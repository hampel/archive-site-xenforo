<?php

namespace Tests\Unit\XF\Entity;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * A deleted member's archive row goes with them. Until 2.2.4 it stayed, and archive:validate
 * counted it as an archived account.
 *
 * Deleting a member removes their avatar and banner files, which no transaction rolls back, so
 * these borrow members who have neither.
 */
class UserTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @return User[]
	 */
	protected function deletableMembers(int $count): array
	{
		$users = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')
			->activeUsers()
			->where('avatar_date', 0)
			->where('gravatar', '')
			->where('Profile.banner_date', 0)
			->fetch($count)
			->toArray();
		if (count($users) < $count)
		{
			$this->markTestSkipped("needs {$count} active, non-protected users with no avatar or banner");
		}

		return array_values($users);
	}

	protected function archive(User $user): void
	{
		$this->assertTrue($this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->archiveUser($user));
	}

	protected function delete(User $user): void
	{
		$user->setOption('enqueue_delete_cleanup', false);
		$this->assertTrue($user->delete(false));
	}

	protected function hasArchiveRow(int $userId): bool
	{
		return (bool) $this->app()->db()->fetchOne(
			'SELECT 1 FROM xf_archivesite_user WHERE user_id = ?', $userId
		);
	}

	public function test_deleting_an_archived_member_deletes_their_archive_row(): void
	{
		[$user] = $this->deletableMembers(1);
		$this->archive($user);
		$this->assertTrue($this->hasArchiveRow($user->user_id), 'archiving wrote no row to delete');

		$this->delete($user);

		$this->assertFalse($this->hasArchiveRow($user->user_id), 'the archive row outlived the member');
	}

	public function test_deleting_a_member_leaves_every_other_archive_row_alone(): void
	{
		[$deleted, $kept] = $this->deletableMembers(2);
		$this->archive($deleted);
		$this->archive($kept);

		$this->delete($deleted);

		$this->assertTrue($this->hasArchiveRow($kept->user_id));
	}
}
