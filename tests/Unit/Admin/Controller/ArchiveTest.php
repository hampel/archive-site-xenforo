<?php

namespace Tests\Unit\Admin\Controller;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Dispatched rather than invoked, because every action but the index is guarded by
 * assertSuperAdmin() in preDispatchController(), and calling an action directly never runs it.
 *
 * Read-only routes, but wrapped in a transaction anyway so nothing an admin dispatch happens to
 * write can outlive the test.
 */
class ArchiveTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function actingAsSuperAdmin(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, [], ['is_super_admin' => true]);
	}

	protected function superAdminIds(): array
	{
		return array_map('intval', array_values($this->app()->finder('XF:User')
			->with('Admin', true)
			->where('Admin.is_super_admin', 1)
			->pluckFrom('user_id')->fetch()->toArray()));
	}

	/**
	 * The user-visible half of the super-admin leak. The page took its total from the finder and
	 * its rows from a PHP filter applied afterwards, so the two disagreed by the number of super
	 * admins - the footer counter and the pagination both read the wrong total.
	 */
	public function test_the_active_users_page_total_matches_its_rows(): void
	{
		$this->actingAsSuperAdmin();

		$reply = $this->dispatch('archive/active-users', 'admin');

		$this->assertReplyTemplate($reply, 'hampel_archivesite_active_users');
		$users = $this->replyParam($reply, 'users');
		$this->assertSame($this->replyParam($reply, 'total'), $users->count());
	}

	public function test_the_active_users_page_never_lists_a_super_admin(): void
	{
		$this->actingAsSuperAdmin();

		$reply = $this->dispatch('archive/active-users', 'admin');

		$listed = array_map('intval', $this->replyParam($reply, 'users')->keys());
		$this->assertSame([], array_values(array_intersect($this->superAdminIds(), $listed)));
	}

	/**
	 * Each count on the confirmation page used to be computed differently - the active one by
	 * fetching every active user as an entity and counting after a PHP filter.
	 */
	public function test_the_archive_page_counts_agree_with_the_finders(): void
	{
		$this->actingAsSuperAdmin();
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');

		$reply = $this->dispatch('archive/archive-users', 'admin');

		$this->assertReplyParam($reply, 'active', $repo->activeUsers()->total());
		$this->assertReplyParam($reply, 'protected', $repo->protectedUsers()->total());
		$this->assertReplyParam($reply, 'archived', $repo->archivedUsers()->total());
		$this->assertReplyParam($reply, 'partiallyArchived', $repo->partiallyArchivedUsers()->total());
	}

	/**
	 * Admin permissions are not enough: every action but the index needs a super admin, because
	 * an archive run can lock the whole forum out.
	 */
	public function test_an_ordinary_admin_is_refused(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['user' => true]);

		$reply = $this->dispatch('archive/active-users', 'admin');

		$this->assertReplyIsError($reply, 403);
	}
}
