<?php

namespace Tests\Unit\XF\Finder;

use Tests\TestCase;

/**
 * Where this add-on sits in a chain of extensions on the same core class.
 *
 * `Finder\User::isValidUser()` and `Service\User\Login::validate()` **replace** their core method
 * instead of calling the parent, because neither can compose: core ANDs `user_state = 'valid'`
 * where the first needs an OR, and the second has to replace an authentication failure rather than
 * follow it. That is a deliberate trade-off, and it has a consequence for everybody else - an
 * extension inside ours is discarded without a word.
 *
 * XenForo applies extensions in `execute_order` then `to_class` order, and each one wraps the
 * previous, so **the last applied is outermost and decides whether anything below it runs**. At
 * the default order of 10 the tie breaks alphabetically, which put this add-on outside any sibling
 * whose namespace sorts earlier - `Hampel\ApiUsers`, `Hampel\AdminApi`, `Hampel\ApprovalQueuePlus`
 * - and silently dropped theirs.
 *
 * So these two extensions are registered at order 1, to sit **innermost**: a sibling at the
 * default order now wraps this add-on, and if it calls the parent as it should, both apply.
 * `tests/Isolated/ExtensionChainTest.php` exercises that composing for real; it cannot live in
 * this suite, and says why.
 */
class ExtensionOrderTest extends TestCase
{
	/**
	 * The two that replace their method are below XenForo's default, and the four that call the
	 * parent are left at it - there is nothing to gain from moving those, and an add-on that
	 * quietly demanded to be innermost everywhere would be its own kind of rude.
	 */
	public function test_the_two_overriding_extensions_sit_innermost(): void
	{
		$orders = [];

		foreach ($this->app()->finder('XF:ClassExtension')->where('addon_id', 'Hampel/ArchiveSite')->fetch() AS $extension)
		{
			$orders[$extension->from_class] = $extension->execute_order;
		}

		$this->assertLessThan(10, $orders['XF\Finder\User'] ?? 10,
			'Finder\User replaces isValidUser(), so it must sit inside a sibling that calls the parent');
		$this->assertLessThan(10, $orders['XF\Service\User\Login'] ?? 10,
			'Login replaces validate(), so it must sit inside a sibling that calls the parent');

		$this->assertSame(10, $orders['XF\Entity\User'] ?? null);
		$this->assertSame(10, $orders['XF\Mail\Mail'] ?? null);
		$this->assertSame(10, $orders['XF\Service\User\PasswordReset'] ?? null);
		$this->assertSame(10, $orders['XF\Widget\MembersOnline'] ?? null);
	}
}
