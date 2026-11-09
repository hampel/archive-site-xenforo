<?php

namespace Tests\Unit\Admin\Controller;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Dispatched rather than invoked, because every action but the index is guarded by
 * assertSuperAdmin() in preDispatchController(), and calling an action directly never runs it.
 *
 * The routes only read, but the list-page test plants archived and partially archived accounts so
 * that the sets differ, and the transaction is what makes that archive nobody.
 */
class ArchiveTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function actingAsSuperAdmin(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, [], ['is_super_admin' => true]);
	}

	/**
	 * @return int[]
	 */
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

		$reply = $this->dispatch('archive-site/active-users', 'admin');

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

		$reply = $this->dispatch('archive-site/active-users', 'admin');

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

		$reply = $this->dispatch('archive-site/archive-users', 'admin');

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

		$reply = $this->dispatch('archive-site/archive-users/action', 'admin', [
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

			$reply = $this->dispatch('archive-site/archive-users/action', 'admin', $input, [], 'POST');

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

		$reply = $this->dispatch('archive-site/archive-users/action', 'admin', [
			'confirm_archive' => '1',
			'actions' => ['archive' => '1'],
		], [], 'POST');

		$this->assertReplyIsRedirect($reply);
		$this->assertStringContainsString('tools/run-job', $reply->getUrl());
		$this->assertJobQueuedTimes('Hampel\ArchiveSite:ArchiveUsers', 1);
		$this->assertJobQueued('Hampel\ArchiveSite:ArchiveUsers', function ($job) use ($protected)
		{
			return $job['unique_key'] === 'hampelArchiveSiteArchiveUsers'
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

		$reply = $this->dispatchWithoutCsrfToken('archive-site/archive-users/action', 'admin', [
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

		$reply = $this->dispatch('archive-site/archive-users/action', 'admin', [], [], 'GET');

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

		$this->assertReplyIsError($this->dispatch('archive-site/archive-users/action', 'admin'), 403);
	}

	/**
	 * Admin permissions are not enough: every action but the index needs a super admin, because
	 * an archive run can lock the whole forum out.
	 */
	public function test_an_ordinary_admin_is_refused(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['user' => true]);

		$reply = $this->dispatch('archive-site/active-users', 'admin');

		$this->assertReplyIsError($reply, 403);
	}

	// ---- every page, not only the two the regressions were on ---------------------------------

	/**
	 * Each list page and the finder it is the view of. The rows are one page of that finder, so
	 * the total is what tells a page reading the wrong set from one reading the right set.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function listPages(): array
	{
		return [
			'protected' => ['archive-site/protected-users', 'hampel_archivesite_protected_users', 'protectedUsers'],
			'archived' => ['archive-site/archived-users', 'hampel_archivesite_archived_users', 'archivedUsers'],
			'partially archived' => ['archive-site/partially-archived-users', 'hampel_archivesite_partially_archived_users', 'partiallyArchivedUsers'],
			'active' => ['archive-site/active-users', 'hampel_archivesite_active_users', 'activeUsers'],
		];
	}

	/**
	 * A development forum holds no archived and no partially archived accounts, so both of those
	 * totals are 0 and a page reading the wrong one of them passes. Inside the transaction members
	 * are made to look archived with no record, and others archived, one at a time until every set
	 * has its own size. A fixed count is not enough: the protected set is the forum's super admins,
	 * whose number the forum decides - one, here, which is what a single planted account matched.
	 */
	protected function plantDistinguishableSets(\Hampel\ArchiveSite\Repository\ArchiveUsers $repo): void
	{
		$totals = function () use ($repo)
		{
			$this->app()->em()->clearEntityCache();

			return array_map(function ($finder) use ($repo) { return $repo->$finder()->total(); },
				array_column(static::listPages(), 2));
		};
		$distinct = function (array $values) { return count(array_unique($values)) === count($values); };

		$users = array_values($repo->activeUsers()->with('Auth')->fetch(12)->toArray());

		// listPages() order: protected, archived, partially archived, active
		do
		{
			$user = array_shift($users);
			if (!$user)
			{
				$this->markTestSkipped('needs more active, non-protected users on the forum');
			}
			if ($user->Auth)
			{
				$user->Auth->setNoPassword();
				$user->Auth->save();
			}
			$user->user_state = 'disabled';
			$user->save();
			$now = $totals();
		}
		while ($now[2] === $now[0] || $now[2] === $now[3]);

		while (!$distinct($now))
		{
			$user = array_shift($users);
			if (!$user)
			{
				$this->markTestSkipped('needs more active, non-protected users on the forum');
			}
			$repo->archiveUser($user);
			$now = $totals();
		}
	}

	/**
	 * Rendered as well as dispatched, because the template is this add-on's and nothing else
	 * renders it: a template error there is logged and the page shows a blank where the list was.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('listPages')]
	public function test_each_list_page_shows_its_own_set_and_renders_cleanly(string $route, string $template, string $finder): void
	{
		$this->fakesErrors();
		$repo = $this->app()->repository('Hampel\ArchiveSite:ArchiveUsers');
		$this->plantDistinguishableSets($repo);
		$this->actingAsSuperAdmin();

		$reply = $this->dispatch($route, 'admin');

		$this->assertReplyTemplate($reply, $template);
		$total = $this->replyParam($reply, 'total');
		$this->assertSame($repo->$finder()->total(), $total);
		$this->assertSame(
			min($total, $this->replyParam($reply, 'perPage')),
			$this->replyParam($reply, 'users')->count(),
			'the rows are not one page of the total'
		);

		$this->renderReply($reply);
		$ours = array_filter(
			$this->app()->templater()->getTemplateErrors(),
			function ($error) { return preg_match('/^\w+:hampel_archivesite_/', (string) ($error['template'] ?? '')) === 1; }
		);
		$this->assertSame([], array_values($ours), "the $template template raised an error");
	}

	/**
	 * Every action but the index is a super admin's, and the guard is a default branch rather than
	 * a list - so a page added to the index's case, or a new action given its own, is what this
	 * catches.
	 *
	 * @return array<string, array{string}>
	 */
	public static function superAdminRoutes(): array
	{
		return [
			'protected' => ['archive-site/protected-users'],
			'archived' => ['archive-site/archived-users'],
			'partially archived' => ['archive-site/partially-archived-users'],
			'active' => ['archive-site/active-users'],
			'archive confirmation' => ['archive-site/archive-users'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('superAdminRoutes')]
	public function test_an_ordinary_admin_is_refused_every_page_but_the_index(string $route): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['user' => true]);

		$this->assertReplyIsError($this->dispatch($route, 'admin'), 403);
	}

	/**
	 * The index is the one route without the guard, and all it does is point at the protected
	 * list - which then asks for a super admin like every other page.
	 */
	public function test_the_index_sends_an_admin_to_the_protected_users_page(): void
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['user' => true]);

		$reply = $this->dispatch('archive-site', 'admin');

		$this->assertReplyIsRedirect($reply);
		$this->assertStringContainsString('archive-site/protected-users', $reply->getUrl());
	}
}
