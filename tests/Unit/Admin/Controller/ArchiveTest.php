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
	 *
	 * The page's total is the finder's, and its rows are one page of that total. Comparing the
	 * total against the row count directly only held while the forum had fewer users than a page,
	 * which is how this read until the development forum grew past twenty active accounts.
	 */
	public function test_the_active_users_page_total_matches_the_finder(): void
	{
		$this->actingAsSuperAdmin();
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');

		$reply = $this->dispatch('archive/active-users', 'admin');

		$this->assertReplyTemplate($reply, 'hampel_archivesite_active_users');
		$total = $this->replyParam($reply, 'total');
		$this->assertSame($repo->activeUsers()->total(), $total);
		$this->assertSame(
			min($total, $this->replyParam($reply, 'perPage')),
			$this->replyParam($reply, 'users')->count(),
			'the rows are not one page of the total'
		);
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

		// assertReplyParam()'s third argument is a failure message, so these asserted only that
		// each parameter existed until the framework started refusing a value there
		$this->assertReplyParamSame($reply, 'active', $repo->activeUsers()->total());
		$this->assertReplyParamSame($reply, 'protected', $repo->protectedUsers()->total());
		$this->assertReplyParamSame($reply, 'archived', $repo->archivedUsers()->total());
		$this->assertReplyParamSame($reply, 'partiallyArchived', $repo->partiallyArchivedUsers()->total());
	}

	// ---- the archive button: dispatched as a POST, with its guards intact ---------------------

	/**
	 * The form's checkbox is the confirmation. Pressed without it ticked, nothing is queued.
	 */
	public function test_the_archive_button_without_the_confirmation_ticked_is_refused(): void
	{
		$this->fakesJobs();

		$this->actingAsSuperAdmin();

		$reply = $this->dispatch('archive/archive-users/action', 'admin', [
			'confirm_archive' => '1',
		], [], 'POST');

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

			$this->actingAsSuperAdmin();

			$reply = $this->dispatch('archive/archive-users/action', 'admin', $input, [], 'POST');

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

		$this->actingAsSuperAdmin();

		$reply = $this->dispatch('archive/archive-users/action', 'admin', [
			'confirm_archive' => '1',
			'actions' => ['archive' => '1'],
		], [], 'POST');

		$this->assertReplyIsRedirect($reply);
		$this->assertStringContainsString('tools/run-job', $reply->getUrl());
		$this->assertJobQueuedTimes('Hampel\ArchiveSite:ArchiveUsers', 1);
		$this->assertJobQueued('Hampel\ArchiveSite:ArchiveUsers', function ($job) use ($protected)
		{
			return $job['unique_key'] === 'archiveUsers'
				&& $job['execute_data']['protectedUsers'] === $protected;
		});
	}

	/**
	 * The CSRF check, which nothing could reach while the suite had to invoke the action directly:
	 * a fully formed, confirmed archive-everyone request is still refused when it carries no
	 * token, and XenForo refuses it before the controller runs at all.
	 */
	public function test_the_archive_action_refuses_a_post_with_no_csrf_token(): void
	{
		$this->fakesJobs();
		$this->actingAsSuperAdmin();

		$reply = $this->dispatchWithoutCsrfToken('archive/archive-users/action', 'admin', [
			'confirm_archive' => '1',
			'actions' => ['archive' => '1'],
		]);

		$this->assertReplyIsError($reply, 400);
		$this->assertNoJobsQueued();
	}

	public function test_the_archive_action_refuses_a_get(): void
	{
		$this->fakesJobs();

		$this->actingAsSuperAdmin();

		$reply = $this->dispatch('archive/archive-users/action', 'admin', [], [], 'GET');

		$this->assertReplyIsError($reply, 405);
		$this->assertNoJobsQueued();
	}

	/**
	 * The guard on the one destructive action, exercised through the same route as the tests above
	 * - which is the point of dispatching them rather than invoking the action: each of those
	 * passes the super-admin check on the way in, so this one failing would be a real difference
	 * and not an artefact of a different entry point.
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
