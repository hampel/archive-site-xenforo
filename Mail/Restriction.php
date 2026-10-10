<?php namespace Hampel\ArchiveSite\Mail;

use Hampel\ArchiveSite\Config\AllowMail;
use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Entity\User;

/**
 * Who this forum may send mail to, for both of XenForo's recipient setters.
 *
 * The decision lives here rather than in the extension because `XF\Mail\Mail` is declared twice in
 * one file - XenForo 2.3 added return types to both setters and an incompatible signature is a
 * fatal - so anything written in the extension has to be written twice. A rule that has to be
 * edited in two places is a rule that will eventually differ between them.
 *
 * **An attempt to mail a member of an archived forum is an anomaly worth investigating**, so the
 * refusal is deliberately loud: it sets `Mail::$setupError`, and XenForo's own `send()` and
 * `queue()` write that to the server error log, one entry per attempt. That is the point, not a
 * side effect - something tried to mail somebody who should not be receiving mail, and the log is
 * where a forum owner finds out which add-on it was.
 */
class Restriction
{
	/**
	 * Mail to this account is allowed: it is a super admin, it is in the protected list, or the
	 * restriction is switched off for this forum.
	 *
	 * @param User $user
	 *
	 * @return bool
	 */
	public static function allowsUser(User $user)
	{
		return AllowMail::enabled()
			|| $user->is_super_admin
			|| ProtectedUsers::isProtected($user->user_id);
	}

	/**
	 * The accounts at this address that may not be mailed.
	 *
	 * `setTo()` is handed an address rather than a member, which is why this has to look one up:
	 * an add-on composing its own recipient list reaches members that way, and until 2.2.0 that
	 * route was not covered at all.
	 *
	 * **`xf_user.email` is not a unique index**, so an address can belong to more than one
	 * account, and any one of them being unmailable is enough to refuse: the address is where the
	 * mail is going. An address no account holds is nobody's business of ours - a contact form,
	 * an administrator's own entry - and is allowed.
	 *
	 * @param string $email
	 *
	 * @return int[] user ids, empty when the address may be mailed
	 */
	public static function blockedUserIds($email)
	{
		if (AllowMail::enabled())
		{
			return [];
		}

		$email = trim((string) $email);

		// an empty address matches every account that has none; core refuses it on its own
		if ($email === '')
		{
			return [];
		}

		$blocked = [];

		foreach (\XF::app()->finder('XF:User')->with('Admin')->where('email', $email)->fetch() AS $user)
		{
			if (!self::allowsUser($user))
			{
				$blocked[] = $user->user_id;
			}
		}

		return $blocked;
	}

	/**
	 * The exception the refusal records. Its wording is what a forum owner reads in the server
	 * error log, beside the address XenForo's own logSetupError() puts there.
	 *
	 * @param string $who
	 *
	 * @return \Exception
	 */
	public static function error($who)
	{
		return new \Exception("Trying to send email to archived user ($who)");
	}
}
