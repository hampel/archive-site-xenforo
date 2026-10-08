<?php

namespace Tests\Unit\Config;

use Hampel\ArchiveSite\Config\AllowMail;
use Tests\TestCase;

/**
 * $config['archiveSite']['allowMail'] is read on every send, so what matters most is what it does
 * when it is absent: an install that has never heard of it must keep refusing mail to members.
 * MailTest covers the behaviour the flag buys; these pin down the reading of it.
 */
class AllowMailTest extends TestCase
{
	public function test_mail_is_not_allowed_when_the_settings_key_is_absent(): void
	{
		$this->setConfig('archiveSite', null);

		$this->assertFalse(AllowMail::enabled());
	}

	public function test_mail_is_not_allowed_when_the_flag_is_absent(): void
	{
		$this->setConfig('archiveSite', ['protectedUsers' => [1]]);

		$this->assertFalse(AllowMail::enabled());
	}

	public function test_mail_is_not_allowed_when_the_flag_is_false(): void
	{
		$this->setConfig('archiveSite', ['allowMail' => false]);

		$this->assertFalse(AllowMail::enabled());
	}

	public function test_mail_is_allowed_when_the_flag_is_true(): void
	{
		$this->setConfig('archiveSite', ['allowMail' => true]);

		$this->assertTrue(AllowMail::enabled());
	}

	/**
	 * config.php is hand-written, so the settings key can hold anything at all. Reading it must
	 * not fatal, and nothing short of a truthy flag may switch the restriction off.
	 */
	public function test_a_settings_key_that_is_not_an_array_does_not_allow_mail(): void
	{
		$this->setConfig('archiveSite', 'nonsense');

		$this->assertFalse(AllowMail::enabled());
	}
}
