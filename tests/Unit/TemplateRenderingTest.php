<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * What the template modifications actually do to a page, rendered. TemplateModificationsTest proves
 * each one still matches its template; these prove the markup it produces is right for the visitor
 * and the options. They need framework 5.4, which gives a rendered template the `$xf` every one of
 * these modifications reads - `$xf.visitor` and `$xf.options`.
 *
 * Each visitor-keyed case renders the same template, with the same parameters, for a guest and for a
 * member, and asserts both. The member is the positive control: an absence on its own also passes
 * when nothing rendered, and stock XenForo shows every one of these to guests, so a difference
 * between the two can only come from the add-on.
 *
 * Nothing here writes: entities are changed in memory for the render and never saved. Rendering can
 * log template errors to the forum's real error log, so every test fakes errors.
 */
class TemplateRenderingTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->fakesErrors();
	}

	/**
	 * Why this is not the framework's assertNoTemplateErrors().
	 *
	 * Template modifications are compiled into the install's shared template cache, while
	 * $addonsToLoad deliberately loads no other add-on's class extensions. So a sibling add-on
	 * whose modification reads a column or calls a method its own entity extension declares logs a
	 * warning on every render in this suite, on a forum where nothing is wrong at all - the getter
	 * exists whenever the forum is not being isolated for a test. Hampel/Newsletters' thread_view
	 * modification is the case that forced this: it reads $forum.newsletters_allow.
	 *
	 * None of that is ours to assert on. What is ours is that nothing this add-on inserts into a
	 * template went wrong. See ourTemplateErrors() for how an error is attributed.
	 *
	 * The blanket assertion is the stronger check and belongs in a forum with only this add-on
	 * installed - a sandbox, per TESTING.md.
	 */
	protected function assertNoTemplateErrorsFromThisAddOn(): void
	{
		$this->assertSame(
			[],
			static::ourTemplateErrors($this->app()->templater()->getTemplateErrors()),
			'a template error names something this add-on put there'
		);
	}

	/**
	 * The template errors this add-on is answerable for: one raised inside its own templates, or
	 * one whose message names something its insertions read - an option, or the ArchivedUser
	 * relation. Every insertion reads $xf.visitor, a hampelArchiveSite option or that relation.
	 *
	 * **The class an error is about does not count, and that is the point of asking this way.**
	 * While this add-on is loaded, every XF:User resolves to its User extension, so when a sibling's
	 * modification calls a method its own extension adds, XenForo's message names
	 * Hampel\ArchiveSite\XF\Entity\User as the object. Matching "archivesite" anywhere in the
	 * error blamed this add-on for that, from the 2.2.3 release until 2026-10-23.
	 *
	 * @param array<mixed> $errors as Templater::getTemplateErrors() returns them
	 *
	 * @return string[] each attributable error, JSON-encoded for the failure message
	 */
	protected static function ourTemplateErrors(array $errors): array
	{
		$ours = [];

		foreach ($errors AS $error)
		{
			$template = is_array($error) ? (string) ($error['template'] ?? '') : '';
			$message = is_array($error) ? (string) ($error['error'] ?? '') : (string) $error;

			if (preg_match('/^\w+:hampel_archivesite_/', $template)
				|| preg_match('/hampelArchiveSite|ArchivedUser/i', $message)
			)
			{
				$ours[] = is_array($error) ? json_encode($error, JSON_THROW_ON_ERROR) : $message;
			}
		}

		return $ours;
	}

	public function test_a_template_error_is_attributed_by_where_it_is_and_what_it_names(): void
	{
		$sibling = 'public:sibling_macros';
		$ourClass = 'Hampel\ArchiveSite\XF\Entity\User';

		$errors = [
			'a sibling calling its own method on a user, which names our class as the object' => [
				'template' => $sibling, 'type' => E_USER_WARNING,
				'error' => "[E_USER_WARNING] Method canUseSomething is not callable on the given object ($ourClass)",
			],
			'a sibling reading its own column' => [
				'template' => 'public:thread_view', 'type' => E_USER_WARNING,
				'error' => "[E_USER_WARNING] Accessed unknown getter 'sibling_allow' on XF:Forum[2]",
			],
			'an error inside one of our templates' => [
				'template' => 'admin:hampel_archivesite_archived_users', 'type' => E_USER_WARNING,
				'error' => "[E_USER_WARNING] Accessed unknown getter 'anything' on XF:User[1]",
			],
			'one of our options, from a core template we modify' => [
				'template' => 'public:forum_view', 'type' => E_USER_WARNING,
				'error' => "[E_USER_WARNING] Template public:forum_view: hampelArchiveSiteEndMessage is not an array",
			],
			'the relation our insertions read' => [
				'template' => 'public:thread_view', 'type' => E_USER_WARNING,
				'error' => "[E_USER_WARNING] Accessed unknown getter 'ArchivedUser' on XF:User[3]",
			],
		];

		$attributed = array_keys(array_filter(
			$errors,
			fn($error) => static::ourTemplateErrors([$error]) !== []
		));

		$this->assertSame([
			'an error inside one of our templates',
			'one of our options, from a core template we modify',
			'the relation our insertions read',
		], $attributed);
	}

	/**
	 * @param array<string, array<string, bool>> $permissions merged over the view permissions every test needs
	 */
	protected function asMember(array $permissions = []): void
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, array_replace_recursive([
			'general' => ['view' => true, 'viewProfile' => true, 'viewMemberList' => true],
			'forum' => ['viewThreads' => true, 'viewContent' => true],
		], $permissions));
	}

	protected function aThread(): \XF\Entity\Thread
	{
		$thread = $this->app()->finder('XF:Thread')->with('Forum')->fetchOne();
		if (!$thread)
		{
			$this->markTestSkipped('needs a thread on the forum');
		}

		return $thread;
	}

	protected function threadView(\XF\Entity\Thread $thread): string
	{
		return $this->renderTemplate('public:thread_view', [
			'thread' => $thread,
			'forum' => $thread->Forum,
			'posts' => [],
			'page' => 1,
			'perPage' => 20,
			'totalPosts' => 0,
		]);
	}

	// ---- options --------------------------------------------------------------------------------

	public function test_the_home_page_message_is_shown(): void
	{
		$this->setOption('hampelArchiveSiteHomeMessage', 'This forum was archived in 2020.');
		$this->actingAsGuest();

		$html = $this->renderTemplate('public:forum_overview_wrapper');

		$this->assertSee($html, 'This forum was archived in 2020.');
		$this->assertNoTemplateErrorsFromThisAddOn();
	}

	/**
	 * The block is inside a content check, so an empty option leaves no empty box on the page. It is
	 * measured as one block row fewer, since there is no text to look for when there is no message.
	 */
	public function test_an_empty_home_page_message_leaves_no_empty_block(): void
	{
		$this->actingAsGuest();
		$this->setOption('hampelArchiveSiteHomeMessage', 'A message');
		$with = substr_count($this->renderTemplate('public:forum_overview_wrapper'), 'block-row');

		$this->setOption('hampelArchiveSiteHomeMessage', '');
		$without = substr_count($this->renderTemplate('public:forum_overview_wrapper'), 'block-row');

		$this->assertSame($with - 1, $without);
	}

	public function test_the_end_message_is_shown_below_a_thread(): void
	{
		$this->setOption('hampelArchiveSiteEndMessageHtml', 'No new posts - this forum is archived.');
		$this->actingAsGuest();

		$html = $this->threadView($this->aThread());

		$this->assertSee($html, 'No new posts - this forum is archived.');
		$this->assertNoTemplateErrorsFromThisAddOn();
	}

	/**
	 * forum_view carries two modifications whose order matters: the one at execution order 9 inserts
	 * this message ahead of a core condition, and the one at 10 then neutralises that condition. If
	 * the order were swapped the second would no longer match, so the message rendering here is also
	 * a check that the pair still composes.
	 */
	public function test_the_end_message_is_shown_below_a_thread_list(): void
	{
		$this->setOption('hampelArchiveSiteEndMessageHtml', 'No new threads - this forum is archived.');
		$this->actingAsGuest();
		$forum = $this->app()->finder('XF:Forum')->with('Node')->fetchOne();
		if (!$forum)
		{
			$this->markTestSkipped('needs a forum');
		}

		$html = $this->renderTemplate('public:forum_view', [
			'forum' => $forum,
			'threads' => [],
			'stickyThreads' => [],
			'page' => 1,
			'perPage' => 20,
			'total' => 0,
			'canonicalFilters' => [],
			'filters' => [],
		]);

		$this->assertSee($html, 'No new threads - this forum is archived.');
		$this->assertNoTemplateErrorsFromThisAddOn();
	}

	/**
	 * Three options interact: the message, its URL, and whether the URL opens a new window. Without
	 * a URL the message is not a link at all.
	 */
	public function test_the_end_message_is_a_link_only_with_a_url_and_opens_a_new_window_only_when_asked(): void
	{
		$this->setOption('hampelArchiveSiteEndMessageHtml', 'Read why');
		$this->actingAsGuest();
		$thread = $this->aThread();

		$this->setOption('hampelArchiveSiteEndMessageUrl', '');
		$noUrl = $this->threadView($thread);

		$this->setOption('hampelArchiveSiteEndMessageUrl', 'https://example.com/why-archived');
		$this->setOption('hampelArchiveSiteEndMessageUrlTarget', false);
		$sameWindow = $this->threadView($thread);

		$this->setOption('hampelArchiveSiteEndMessageUrlTarget', true);
		$newWindow = $this->threadView($thread);

		$this->assertSee($noUrl, 'Read why');
		$this->assertDontSee($noUrl, 'https://example.com/why-archived');

		$this->assertSee($sameWindow, 'href="https://example.com/why-archived"', false);
		$this->assertSame(substr_count($noUrl, '_blank'), substr_count($sameWindow, '_blank'));

		$this->assertSee($newWindow, 'href="https://example.com/why-archived"', false);
		$this->assertSame(substr_count($sameWindow, '_blank') + 1, substr_count($newWindow, '_blank'));
	}

	// ---- hidden from guests, shown to members ---------------------------------------------------

	public function test_the_latest_member_is_hidden_from_guests_but_shown_to_members(): void
	{
		$latest = $this->app()->finder('XF:User')->fetchOne();
		$params = ['forumStatistics' => [
			'threads' => 1, 'messages' => 1, 'users' => 1, 'latestUser' => $latest,
		]];

		$this->actingAsGuest();
		$guest = $this->renderTemplate('public:widget_forum_statistics', $params);
		$this->asMember();
		$member = $this->renderTemplate('public:widget_forum_statistics', $params);

		$this->assertDontSee($guest, $latest->username);
		$this->assertSee($member, $latest->username);
	}

	public function test_the_not_open_notice_is_hidden_from_guests_but_shown_to_members(): void
	{
		$thread = $this->aThread();
		$thread->discussion_open = false; // in memory, for the render only
		$notOpen = (string) \XF::phrase('not_open_for_further_replies');

		$this->actingAsGuest();
		$guest = $this->threadView($thread);
		$this->asMember();
		$member = $this->threadView($thread);

		$this->assertDontSee($guest, $notOpen);
		$this->assertSee($member, $notOpen);
		$this->assertNoTemplateErrorsFromThisAddOn();
	}

	public function test_a_birthday_is_hidden_from_guests_but_shown_to_members(): void
	{
		$user = $this->app()->finder('XF:User')->with(['Profile', 'Option', 'Privacy'])->fetchOne();
		// in memory, for the render only
		$user->Profile->dob_day = 5;
		$user->Profile->dob_month = 6;
		$user->Profile->dob_year = 1990;
		$user->Option->show_dob_date = true;
		$user->Option->show_dob_year = true;
		$birthday = (string) \XF::phrase('birthday');

		$this->actingAsGuest();
		$guest = $this->renderTemplate('public:member_about', ['user' => $user]);
		$this->asMember();
		$member = $this->renderTemplate('public:member_about', ['user' => $user]);

		$this->assertDontSee($guest, $birthday);
		$this->assertSee($member, $birthday);
		$this->assertNoTemplateErrorsFromThisAddOn();
	}
}
