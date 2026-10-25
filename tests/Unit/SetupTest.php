<?php

namespace Tests\Unit;

use Hampel\ArchiveSite\Setup;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The upgrade step that removes the archive rows deleted members left behind before 2.2.4. It is
 * a delete across every row in the table, so what it keeps matters as much as what it removes.
 */
class SetupTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setup2020470(): void
	{
		$addOn = $this->app()->addOnManager()->getById('Hampel/ArchiveSite');
		$this->assertNotNull($addOn);

		(new Setup($addOn, $this->app()))->upgrade2020470Step1();
	}

	protected function hasArchiveRow(int $userId): bool
	{
		return (bool) $this->app()->db()->fetchOne(
			'SELECT 1 FROM xf_archivesite_user WHERE user_id = ?', $userId
		);
	}

	public function test_the_2_2_4_upgrade_removes_rows_whose_member_is_gone(): void
	{
		$orphan = $this->writeOrphanedArchiveRow();

		$this->setup2020470();

		$this->assertFalse($this->hasArchiveRow($orphan));
	}

	public function test_the_2_2_4_upgrade_keeps_every_row_whose_member_exists(): void
	{
		$user = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne();
		if (!$user)
		{
			$this->markTestSkipped('needs at least one active, non-protected user on the forum');
		}
		$this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->archiveUser($user);
		$this->writeOrphanedArchiveRow();

		$this->setup2020470();

		$this->assertTrue($this->hasArchiveRow($user->user_id));
	}
}
