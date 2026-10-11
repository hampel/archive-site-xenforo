<?php

namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @var string $rootDir path to your XenForo root directory, relative to the addon path
	 *
	 * Set $rootDir to '../../../..' if you use a vendor in your addon id (ie <Vendor/AddonId>)
	 * Otherwise, set this to '../../..' for no vendor
	 *
	 * No trailing slash!
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad an array of XenForo addon ids to load
	 *
	 * Specifying an array of addon ids will cause only those addons to be loaded - useful for isolating your addon for
	 * testing purposes
	 *
	 * Leave empty to load all addons
	 */
	protected $addonsToLoad = ['Hampel/ArchiveSite'];

	/**
	 * The host forum's own `config.php` settings for this add-on are cleared before every test.
	 *
	 * They are environment, not fixture: this suite runs against whatever forum it is pointed at,
	 * and both of the keys it reads are things a real forum legitimately sets. A test that does not
	 * set them has to mean "neither key is set", and it cannot mean that while the forum decides.
	 *
	 * **Clearing one is not enough, and that is the trap.** `Config\ProtectedUsers::get()` prefers
	 * `$config['archiveSite']['protectedUsers']` and falls back to the deprecated flat
	 * `$config['archiveSiteProtectedUsers']` only when the nested key is absent - so a test that
	 * sets the flat key alone is overridden by whatever the forum holds in the nested one, with no
	 * error, because both are valid configurations.
	 *
	 * Measured in a sandbox on 2026-10-11, with a nested protected list in `config.php`: nine tests
	 * failed, and two more went on passing while proving nothing they were named for. Removing
	 * these two lines and running the suite on a forum whose `config.php` sets
	 * `$config['archiveSite']['protectedUsers']` is the mutation check - `TESTING.md` says so,
	 * because a development forum that sets neither key cannot show it.
	 *
	 * `setConfig($key, null)` is a removal rather than a null value: every read is guarded by
	 * `isset()`, which is false for null.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('archiveSite', null);
		$this->setConfig('archiveSiteProtectedUsers', null);
	}

	/**
	 * Helper function to load mock data from a file (eg json)
	 * To use, create a "mock" folder relative to the tests folder, eg:
	 * 'src/addons/MyVendor/MyAddon/tests/mock'
	 *
	 * @param $file
	 *
	 * @return false|string
	 */
	protected function getMockData($file)
	{
		return file_get_contents(__DIR__ . '/mock/' . $file);
	}
}
