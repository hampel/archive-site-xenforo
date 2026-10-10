<?php

namespace Tests\Isolated;

use Tests\Fixtures\OuterUserFinder;
use Tests\TestCase;

/**
 * Tests that are only valid in a process where nothing has resolved the class they extend.
 *
 * **This directory is deliberately outside both testsuites in `phpunit.xml`, so a normal run does
 * not collect it.** Run it by path:
 *
 *     vendor/bin/phpunit tests/Isolated/ExtensionChainTest.php
 *
 * The reason is not tidiness. Adding a class extension during a test is forgotten at teardown, and
 * what the next test then resolves is the class **unextended** - without this add-on's own
 * extension either. Left in the normal suite, this test passed and took 47 later tests down with
 * it, because everything after it got a core XF\Finder\User. The LogicException the framework
 * throws for an already-resolved class cannot save it: that fires in the other case, when this
 * test runs late.
 */
class ExtensionChainTest extends TestCase
{
	/**
	 * The property that order buys, exercised rather than asserted about: a sibling extension that
	 * calls the parent keeps its own narrowing **and** this add-on's widening.
	 *
	 * The framework appends an extension added during a test, so the stand-in is outermost
	 * whatever the database says - which is the right shape here, because what is being tested is
	 * that being wrapped works, not which of the two is wrapped.
	 *
	 * **It can only run in a process where nothing has resolved XF\Finder\User yet**, since the
	 * XFCP proxy XenForo aliases cannot be declared twice, and almost every test in this suite
	 * resolves that class. Run this class on its own:
	 *
	 *     vendor/bin/phpunit tests/Unit/XF/Finder/ExtensionOrderTest.php
	 */
	public function test_a_sibling_that_calls_the_parent_keeps_both_narrowings(): void
	{
		try
		{
			$this->app()->extension()->addClassExtension('XF\Finder\User', OuterUserFinder::class);
		}
		catch (\LogicException $e)
		{
			$this->markTestSkipped('XF\Finder\User is already resolved in this process - run this class alone');
		}

		$finder = $this->app()->finder('XF:User');
		$this->assertInstanceOf(OuterUserFinder::class, $finder, 'the stand-in should be outermost');
		$this->assertArrayHasKey('Hampel\ArchiveSite\XF\Finder\User', class_parents($finder),
			'this add-on should be inside it');

		$sql = $finder->isValidUser()->getQuery();

		$this->assertStringContainsString('previous_state', $sql,
			"this add-on's widening was discarded by the sibling's call");
		$this->assertStringContainsString(OuterUserFinder::MARKER_FIELD, $sql,
			"the sibling's own condition is missing");
	}
}
