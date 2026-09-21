<?php

namespace Tests\Unit\XF\Mail;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;
use XF\Mail\Mail;

/**
 * Installing this add-on stops mail to every ordinary member, not only to archived ones - the
 * intent on an archived forum and a trap anywhere else, so it is worth pinning down.
 *
 * setToUser() refuses by setting Mail::$setupError rather than throwing, and send() then logs that
 * error to the forum's real error log. So these read the property instead of sending: it is
 * exactly what the extension changes, and it touches nothing outside the object.
 */
class MailTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setupError(Mail $mail): ?\Throwable
	{
		$property = new \ReflectionProperty(Mail::class, 'setupError');
		$property->setAccessible(true);

		return $property->getValue($mail);
	}

	/**
	 * Core also refuses a user with no email address, which would make a refusal here pass for the
	 * wrong reason. So only users who have one are used.
	 */
	protected function withEmail(?User $user, string $what): User
	{
		if (!$user || !$user->email)
		{
			$this->markTestSkipped("needs $what with an email address on the forum");
		}

		return $user;
	}

	protected function anActiveUser(): User
	{
		return $this->withEmail(
			$this->app()->repository('Hampel\ArchiveSite:ArchiveUsers')->activeUsers()->fetchOne(),
			'an active, non-protected user'
		);
	}

	protected function aSuperAdmin(): User
	{
		return $this->withEmail(
			$this->app()->finder('XF:User')
				->with('Admin', true)
				->where('Admin.is_super_admin', 1)
				->fetchOne(),
			'a super admin'
		);
	}

	public function test_mail_to_an_ordinary_member_is_refused(): void
	{
		$user = $this->anActiveUser();

		$mail = $this->app()->mailer()->newMail()->setToUser($user);

		$error = $this->setupError($mail);
		$this->assertNotNull($error, 'mail to an ordinary member was not refused');
		$this->assertStringContainsString('archived user', $error->getMessage());
	}

	public function test_mail_to_a_super_admin_is_allowed(): void
	{
		$admin = $this->aSuperAdmin();

		$mail = $this->app()->mailer()->newMail()->setToUser($admin);

		$this->assertNull($this->setupError($mail));
	}

	public function test_mail_to_a_config_protected_user_is_allowed(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);

		$mail = $this->app()->mailer()->newMail()->setToUser($user);

		$this->assertNull($this->setupError($mail));
	}
}
