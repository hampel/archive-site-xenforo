<?php

namespace Tests\Unit\Config;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use Tests\TestCase;

/**
 * The protected list is the one thing standing between an archive run and a forum nobody can log
 * in to, and it comes from config.php rather than an option. \XF::config() reads the application
 * config on every call, so setConfig() reaches it directly with nothing to decache.
 *
 * Two keys hold it: $config['archiveSite']['protectedUsers'], and the flat
 * $config['archiveSiteProtectedUsers'] that 2.0.2 and earlier read, now deprecated and still
 * honoured. The tests below that set the flat key are what keeps the deprecated path working.
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

	// ---- the nested key, and its precedence over the deprecated flat one ---------------------

	public function test_the_nested_key_is_read(): void
	{
		$this->setConfig('archiveSite', ['protectedUsers' => [3, 7, 11]]);

		$this->assertSame([3, 7, 11], ProtectedUsers::get());
	}

	public function test_a_single_nested_id_is_wrapped_in_a_list(): void
	{
		$this->setConfig('archiveSite', ['protectedUsers' => 42]);

		$this->assertSame([42], ProtectedUsers::get());
	}

	/**
	 * The whole point of the nested key: an install can move to it without first removing the old
	 * one, and what it says wins.
	 */
	public function test_the_nested_key_overrides_the_deprecated_flat_key(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [1, 2]);
		$this->setConfig('archiveSite', ['protectedUsers' => [3]]);

		$this->assertSame([3], ProtectedUsers::get());
	}

	/**
	 * Deliberately empty is not the same as absent: it means nothing is protected, which is what
	 * both CLI commands abort on. Falling back to the old key here would hide that.
	 */
	public function test_an_empty_nested_list_overrides_the_deprecated_flat_key(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [1, 2]);
		$this->setConfig('archiveSite', ['protectedUsers' => []]);

		$this->assertSame([], ProtectedUsers::get());
	}

	public function test_the_deprecated_flat_key_is_used_when_the_nested_one_is_absent(): void
	{
		$this->setConfig('archiveSite', ['allowMail' => true]);
		$this->setConfig('archiveSiteProtectedUsers', [5]);

		$this->assertSame([5], ProtectedUsers::get());
	}

	/**
	 * config.php is hand-written, so the settings key can hold anything. Whatever it holds, the
	 * add-on must not fatal reading it.
	 */
	public function test_a_settings_key_that_is_not_an_array_is_ignored(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [5]);
		$this->setConfig('archiveSite', 'nonsense');

		$this->assertSame([5], ProtectedUsers::get());
	}

	// ---- what a half-done migration discards -------------------------------------------------

	/**
	 * `$config['archiveSite'] = []` is a present parent holding no protectedUsers, which is what a
	 * config.php generated from a template produces when the list it renders from is empty.
	 * Settings::get() reads the key with isset(), so this falls through to the deprecated key and
	 * protects exactly what it did before, rather than overriding it with nothing.
	 *
	 * Measured on 2026-10-10 for the session that generates one of these files, which asked rather
	 * than inferring it. Asserted here because a template now depends on the answer.
	 */
	public function test_a_present_but_empty_settings_array_falls_through_to_the_deprecated_key(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [5, 293]);
		$this->setConfig('archiveSite', []);

		$this->assertSame(ProtectedUsers::SOURCE_LEGACY, ProtectedUsers::source());
		$this->assertSame([5, 293], ProtectedUsers::get());
		$this->assertSame([], ProtectedUsers::ignoredLegacyIds());
	}

	/**
	 * The hazard the check exists for, in its worst form: the current key is present and empty, so
	 * every id in the deprecated key is unprotected. Nothing fails, because super admins are
	 * protected whatever config.php says.
	 */
	public function test_an_empty_current_key_discards_every_deprecated_id(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [5, 293]);
		$this->setConfig('archiveSite', ['protectedUsers' => []]);

		$this->assertSame([5, 293], ProtectedUsers::ignoredLegacyIds());
	}

	public function test_a_narrower_current_key_discards_the_difference(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [5, 293, 1041]);
		$this->setConfig('archiveSite', ['protectedUsers' => [5]]);

		$this->assertSame([293, 1041], ProtectedUsers::ignoredLegacyIds());
	}

	/**
	 * Both keys set is the normal state of an install part-way through the rename, and it is not a
	 * fault: where they name the same accounts there is nothing to report.
	 */
	public function test_nothing_is_discarded_when_the_keys_agree_in_any_order(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', [5, 293]);
		$this->setConfig('archiveSite', ['protectedUsers' => [293, 5]]);

		$this->assertSame([], ProtectedUsers::ignoredLegacyIds());
	}

	public function test_nothing_is_discarded_when_only_one_key_is_set(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', null);
		$this->setConfig('archiveSite', ['protectedUsers' => [5]]);

		$this->assertSame([], ProtectedUsers::ignoredLegacyIds());

		$this->setConfig('archiveSite', null);
		$this->setConfig('archiveSiteProtectedUsers', [5]);

		$this->assertSame([], ProtectedUsers::ignoredLegacyIds());
	}

	/**
	 * config.php is hand-written and a generated one may quote its ids, so both sides are compared
	 * as integers - otherwise a quoted id would read as discarded and the warning would be noise.
	 */
	public function test_ids_are_compared_as_integers(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', ['5', 293]);
		$this->setConfig('archiveSite', ['protectedUsers' => [5]]);

		$this->assertSame([293], ProtectedUsers::ignoredLegacyIds());
	}

	public function test_a_scalar_in_the_deprecated_key_is_still_compared(): void
	{
		$this->setConfig('archiveSiteProtectedUsers', 42);
		$this->setConfig('archiveSite', ['protectedUsers' => []]);

		$this->assertSame([42], ProtectedUsers::ignoredLegacyIds());
	}
}
