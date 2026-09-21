<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Twelve of this add-on's fourteen template modifications are an exact str_replace against core
 * markup, so a XenForo upgrade that reformats a template breaks them silently: the page renders as
 * stock and the modification's status still reads "ok". Two were dead on XenForo 2.3 before
 * anyone noticed. The apply count is the only thing that tells you, and these read it.
 *
 * They read what the forum has recorded, which is only as current as the last template compile.
 * An edit to _output/ is invisible here until it has been imported.
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
}
