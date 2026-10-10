<?php

namespace Tests\Fixtures;

/**
 * A stand-in for another add-on's extension of XF\Finder\User - the shape XenForo's own resource
 * standards ask for: narrow the query, and call the parent so that whatever is inside you still
 * applies.
 *
 * It lives outside tests/Unit deliberately. PHPUnit `require_once`s every file it collects while
 * building the suite, which happens before XenForo boots, and a class extension cannot be declared
 * before then - it extends an XFCP_ proxy that only exists once XenForo resolves the extension.
 * Here it is reached through Composer's autoloader instead, on demand, after the boot.
 */
class OuterUserFinder extends XFCP_OuterUserFinder
{
	/**
	 * The marker this test looks for in the finished SQL. Harmless as a condition, and not
	 * something any other code would add.
	 */
	const MARKER_FIELD = 'last_activity';

	public function isValidUser($recentlyActive = false)
	{
		parent::isValidUser($recentlyActive);

		$this->where(self::MARKER_FIELD, '>', 1);

		return $this;
	}
}
