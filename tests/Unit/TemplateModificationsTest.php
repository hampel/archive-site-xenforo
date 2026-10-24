<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Eleven of this add-on's fourteen template modifications are an exact str_replace against core
 * markup, so a XenForo upgrade that reformats a template breaks them silently: the page renders as
 * stock and the modification's status still reads "ok". Two were dead on XenForo 2.3 before
 * anyone noticed, and a third on early 2.2. The apply count is what tells you, and these read it.
 *
 * Most of them read what the forum has recorded, which is only as current as the last template
 * compile, so an edit to _output/ is invisible until it has been imported. The find-count test
 * reads _output/ and the master template text directly, which is what catches that drift.
 */
class TemplateModificationsTest extends TestCase
{
	/**
	 * Read inside the test rather than from a data provider: a provider is static and runs before
	 * the application boots, so it cannot query the database. Reading it here also means a
	 * modification added later is covered without anyone remembering to list it.
	 */
	public function test_every_modification_applies_to_a_template_that_exists(): void
	{
		$db = $this->app()->db();
		$modifications = $db->fetchAll(
			"SELECT modification_key, template, type
				FROM xf_template_modification
				WHERE addon_id = 'Hampel/ArchiveSite'
				ORDER BY modification_key"
		);

		$this->assertNotEmpty($modifications, 'the add-on has to be installed for this to test anything');

		$checked = 0;
		foreach ($modifications AS $modification)
		{
			$templateExists = $db->fetchOne(
				'SELECT 1 FROM xf_template WHERE title = ? AND type = ? AND style_id = 0',
				[$modification['template'], $modification['type']]
			);

			// a target that does not exist - XFMG absent - can never apply, which is correct
			if (!$templateExists)
			{
				continue;
			}

			$this->assertTemplateModificationApplied($modification['modification_key']);
			$checked++;
		}

		$this->assertGreaterThan(0, $checked, 'no modification had a target template to check');
	}

	/**
	 * Named, because these two are the ones that had stopped applying: XenForo 2.3 wrapped the
	 * nav group in an extra div, moving the block one tab deeper. It is now preg_replace.
	 */
	public function test_the_whats_new_link_modification_applies(): void
	{
		$this->assertTemplateModificationApplied('hampelArchiveSitePageContainerWhatsNew');
	}

	/**
	 * XenForo 2.3 replaced <xf:macro template="X" name="Y"> with <xf:macro id="X::Y">, and the find
	 * string carries an alternation for both. Only meaningful where XFMG is installed.
	 */
	public function test_the_gallery_bb_code_modification_applies_where_xfmg_is_installed(): void
	{
		$hasTemplate = $this->app()->db()->fetchOne(
			"SELECT 1 FROM xf_template WHERE title = 'xfmg_media_view_macros' AND style_id = 0"
		);
		if (!$hasTemplate)
		{
			$this->markTestSkipped('XenForo Media Gallery is not installed on this forum');
		}

		$this->assertTemplateModificationApplied('hampelArchiveSiteXFMGMediaViewMacros');
	}

	/**
	 * Every modification this add-on ships, by key. The framework's assertions gather whatever
	 * the add-on registers, so one added or removed fails nothing on its own - pinning the list
	 * here is what names a modification that arrives without a test or leaves while one remains.
	 */
	protected const MODIFICATIONS = [
		'hampelArchiveSiteBirthday',
		'hampelArchiveSiteForumOverviewWrapper',
		'hampelArchiveSiteForumView',
		'hampelArchiveSiteForumViewArchived',
		'hampelArchiveSiteMemberAboutHideFollowers',
		'hampelArchiveSiteMemberAboutHideFollowing',
		'hampelArchiveSitePageContainerWhatsNew',
		'hampelArchiveSitePageForumOverviewWrapper',
		'hampelArchiveSiteThreadListMacros',
		'hampelArchiveSiteThreadView',
		'hampelArchiveSiteThreadViewNotOpen',
		'hampelArchiveSiteThreadViewSiteArchived',
		'hampelArchiveSiteWidgetForumStatistics',
		'hampelArchiveSiteXFMGMediaViewMacros',
	];

	/**
	 * One per modification, except the gallery one on a forum without XFMG, whose template is
	 * not there to apply to. `absent` is a claim the framework checks: on a forum that has the
	 * template it fails, so the map stays an exact pin wherever the add-on is installed. The
	 * first test in this class covers the same forum from the database side.
	 *
	 * @return array<string, int|string>
	 */
	protected function expectedCounts(): array
	{
		$hasGallery = $this->app()->db()->fetchOne(
			"SELECT 1 FROM xf_template WHERE title = 'xfmg_media_view_macros' AND type = 'public' AND style_id = 0"
		);

		$counts = array_fill_keys(static::MODIFICATIONS, 1);
		if (!$hasGallery)
		{
			$counts['hampelArchiveSiteXFMGMediaViewMacros'] = 'absent';
		}

		return $counts;
	}

	/**
	 * Read from _output/ and the master template rather than the log, so it fails before an import
	 * and tells no match from two. Exactly one matters as much as at least one: a str_replace whose
	 * find matched twice would hide, or insert, its block twice.
	 */
	public function test_every_find_in_the_working_copy_matches_its_template_exactly_once(): void
	{
		$counts = $this->assertAddOnTemplateModificationFindsMatch(null, $this->expectedCounts());

		$this->assertSame(static::MODIFICATIONS, array_keys($counts));
	}

	/**
	 * What the forum actually compiled: once in each template, per style that holds a copy.
	 */
	public function test_every_modification_applies_exactly_once_in_every_template_it_reaches(): void
	{
		$applied = $this->assertAddOnTemplateModificationsApplyTimes($this->expectedCounts());

		$this->assertSame(static::MODIFICATIONS, array_keys($applied));
	}
}
