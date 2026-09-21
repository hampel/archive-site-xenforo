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

	// ---- the archive button: a POST, so callAction() rather than dispatch() -------------------------

	/**
	 * The form's checkbox is the confirmation. Pressed without it ticked, nothing is queued.
	 */
	public function test_the_archive_button_without_the_confirmation_ticked_is_refused(): void
	{
		$this->fakesJobs();

		$reply = $this->callAction('Hampel\ArchiveSite:Archive', 'archive-users/action', 'admin', [
			'confirm_archive' => '1',
		]);

		$this->assertReplyIsError($reply);
		$this->assertStringContainsString(
			(string) \XF::phrase('hampel_archivesite_you_must_confirm_archive_to_proceed'),
			implode(' ', array_map('strval', (array) $this->replyErrors($reply)))
		);
		$this->assertNoJobsQueued();
	}

	/**
	 * The regression. The confirmation used to be enforced only when the request carried the submit
	 * button's own name, so a POST without it - no fields at all - queued the archive-everyone job.
	 * The box is the confirmation, whatever else the request holds.
	 */
	public function test_the_archive_button_is_refused_whenever_the_box_is_not_ticked(): void
	{
		foreach (['no fields at all' => [], 'the button alone' => ['confirm_archive' => '1']] AS $what => $input)
		{
			$this->fakesJobs();

			$reply = $this->callAction('Hampel\ArchiveSite:Archive', 'archive-users/action', 'admin', $input);

			$this->assertReplyIsError($reply, null, null, "$what was not refused");
			$this->assertNoJobsQueued();
		}
	}

	/**
	 * Confirmed, it queues the archive job - once, under a fixed key so a double submit cannot run
	 * two - carrying the protected list worked out now, and sends the admin to watch it run.
	 */
	public function test_the_confirmed_archive_button_queues_the_job_with_the_protected_users(): void
	{
		$this->fakesJobs();
		$protected = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->protectedUserIds();

		$reply = $this->callAction('Hampel\ArchiveSite:Archive', 'archive-users/action', 'admin', [
			'confirm_archive' => '1',
			'actions' => ['archive' => '1'],
		]);

		$this->assertReplyIsRedirect($reply);
		$this->assertStringContainsString('tools/run-job', $reply->getUrl());
		$this->assertJobQueuedTimes('Hampel\ArchiveSite:ArchiveUsers', 1);
		$this->assertJobQueued('Hampel\ArchiveSite:ArchiveUsers', function ($job) use ($protected)
		{
			return $job['unique_key'] === 'archiveUsers'
				&& $job['execute_data']['protectedUsers'] === $protected;
		});
	}

	public function test_the_archive_action_refuses_a_get(): void
	{
		$this->fakesJobs();

		$reply = $this->callAction('Hampel\ArchiveSite:Archive', 'archive-users/action', 'admin', [], [], 'GET');

		$this->assertReplyIsError($reply, 405);
		$this->assertNoJobsQueued();
	}

	/**
	 * callAction() skips preDispatch(), so the super-admin guard on the one destructive action is
	 * proved here with dispatch() instead.
	 */
	public function test_an_ordinary_admin_cannot_reach_the_archive_action(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['user' => true]);

		$this->assertReplyIsError($this->dispatch('archive/archive-users/action', 'admin'), 403);
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
