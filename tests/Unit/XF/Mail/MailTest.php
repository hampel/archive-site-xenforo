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
 *
 * $config['archiveSite']['allowMail'] switches the whole restriction off, for a development copy
 * of an archived forum. Nothing else does, so the tests for it assert both directions: the same
 * member who is refused by default is accepted with the flag set.
 *
 * **Both recipient setters are covered.** setToUser() is how XenForo addresses mail to a member;
 * setTo() takes an address, which is how an add-on composing its own recipient list reaches one,
 * and until 2.2.0 that route was not blocked at all.
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

	public function test_mail_to_an_ordinary_member_is_allowed_when_the_config_flag_is_set(): void
	{
		$user = $this->anActiveUser();

		$refused = $this->app()->mailer()->newMail()->setToUser($user);
		$this->assertNotNull($this->setupError($refused), 'the member was mailable before the flag was set');

		$this->setConfig('archiveSite', ['allowMail' => true]);
		$allowed = $this->app()->mailer()->newMail()->setToUser($user);

		$this->assertNull($this->setupError($allowed));
	}

	public function test_mail_to_an_ordinary_member_is_still_refused_when_the_flag_is_false(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSite', ['allowMail' => false]);

		$mail = $this->app()->mailer()->newMail()->setToUser($user);

		$this->assertNotNull($this->setupError($mail));
	}

	/**
	 * The flag and the protected list share one settings array, so setting either must not disturb
	 * the other - a mistake the flat key could not make.
	 */
	public function test_the_nested_protected_list_is_honoured_alongside_the_flag(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSite', ['protectedUsers' => [$user->user_id], 'allowMail' => false]);

		$mail = $this->app()->mailer()->newMail()->setToUser($user);

		$this->assertNull($this->setupError($mail));
	}

	// ---- setTo(), the address route -----------------------------------------------------------

	/**
	 * The regression. An add-on that builds its own recipient list - a newsletter, a digest -
	 * addresses mail by email rather than by member, and that reached archived members
	 * untouched while setToUser() was the only thing covered.
	 */
	public function test_mail_addressed_to_an_ordinary_member_s_address_is_refused(): void
	{
		$user = $this->anActiveUser();

		$mail = $this->app()->mailer()->newMail()->setTo($user->email, $user->username);

		$error = $this->setupError($mail);
		$this->assertNotNull($error, 'mail to a member\'s address was not refused');
		$this->assertStringContainsString((string) $user->user_id, $error->getMessage(),
			'the refusal should name the account, for whoever reads the error log');
	}

	public function test_mail_addressed_to_a_super_admin_s_address_is_allowed(): void
	{
		$admin = $this->aSuperAdmin();

		$mail = $this->app()->mailer()->newMail()->setTo($admin->email, $admin->username);

		$this->assertNull($this->setupError($mail));
	}

	/**
	 * An address no account holds is nobody's business of ours: a contact form, a copy to an
	 * administrator's own address, a bounce handler.
	 */
	public function test_mail_to_an_address_no_account_holds_is_allowed(): void
	{
		$mail = $this->app()->mailer()->newMail()->setTo('nobody-has-this-address@example.com');

		$this->assertNull($this->setupError($mail));
	}

	/**
	 * An empty address must not match every account that has no email set - two do on this forum.
	 * Asserted at the decision rather than through the mailer, because core rejects an empty
	 * address itself and, with `debugMode` on, `applySetupError()` rethrows rather than recording
	 * it: the failure would be core's and the test would be about XenForo's debug behaviour.
	 */
	public function test_an_empty_address_blocks_nobody(): void
	{
		$this->assertSame([], \Hampel\ArchiveSite\Mail\Restriction::blockedUserIds(''));
		$this->assertSame([], \Hampel\ArchiveSite\Mail\Restriction::blockedUserIds('   '));
	}

	public function test_the_config_flag_allows_the_address_route_too(): void
	{
		$user = $this->anActiveUser();
		$this->setConfig('archiveSite', ['allowMail' => true]);

		$mail = $this->app()->mailer()->newMail()->setTo($user->email, $user->username);

		$this->assertNull($this->setupError($mail));
	}

	/**
	 * xf_user.email is not a unique index, so an address can belong to more than one account, and
	 * any one of them being unmailable is enough to refuse - the address is where the mail goes.
	 */
	public function test_an_address_shared_with_an_ordinary_member_is_refused(): void
	{
		$admin = $this->aSuperAdmin();
		$member = $this->anActiveUser();

		// raw SQL: the entity validates uniqueness, and this is a state the database allows
		$this->app()->db()->update('xf_user', ['email' => $admin->email], 'user_id = ?', $member->user_id);

		$mail = $this->app()->mailer()->newMail()->setTo($admin->email);

		$error = $this->setupError($mail);
		$this->assertNotNull($error, 'an address shared with an unmailable account was allowed');
		$this->assertStringContainsString((string) $member->user_id, $error->getMessage());
	}

	/**
	 * A blocked member is refused once, not twice: setToUser() returns before the parent, which
	 * would otherwise reach the setTo() override and record a second error for the same message.
	 */
	public function test_a_blocked_member_records_one_error_not_two(): void
	{
		$user = $this->anActiveUser();

		$mail = $this->app()->mailer()->newMail()->setToUser($user);

		$error = $this->setupError($mail);
		$this->assertNotNull($error);
		$this->assertSame(1, preg_match_all('/archived user/', $error->getMessage()),
			'the refusal was recorded more than once');
	}
}
