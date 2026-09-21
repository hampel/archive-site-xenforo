<?php

namespace Tests\Unit\Config;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use Tests\TestCase;

/**
 * The protected list is the one thing standing between an archive run and a forum nobody can log
 * in to, and it comes from config.php rather than an option. \XF::config() reads the application
 * config on every call, so setConfig() reaches it directly with nothing to decache.
 */
class ProtectedUsersTest extends TestCase
{
	public function test_an_unset_key_is_an_empty_list(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', null);

		$this->assertSame([], ProtectedUsers::get());
	}

	public function test_an_empty_array_is_an_empty_list(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', []);

		$this->assertSame([], ProtectedUsers::get());
	}

	public function test_a_single_id_is_wrapped_in_a_list(): void
	{
		// config.php is hand-written, so a scalar is a realistic mistake and has to still work
		$this->setConfig('archiveSiteProtectedUsers', 42);

		$this->assertSame([42], ProtectedUsers::get());
	}

	public function test_a_list_is_returned_as_given(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [3, 7, 11]);

		$this->assertSame([3, 7, 11], ProtectedUsers::get());
	}

	public function test_is_protected_matches_only_listed_ids(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [3, 7]);

		$this->assertTrue(ProtectedUsers::isProtected(3));
		$this->assertTrue(ProtectedUsers::isProtected(7));
		$this->assertFalse(ProtectedUsers::isProtected(4));
	}

	public function test_nothing_is_protected_by_an_empty_list(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', []);

		$this->assertFalse(ProtectedUsers::isProtected(1));
	}
}
