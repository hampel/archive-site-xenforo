<?php namespace Hampel\ArchiveSite\Config;

class ProtectedUsers
{
	const SOURCE_SETTINGS = 'settings';
	const SOURCE_LEGACY = 'legacy';
	const SOURCE_NONE = 'none';

	/**
	 * Which config.php key the list is coming from, which is the first thing to know when an
	 * install protects somebody it did not expect to - or nobody at all. One definition, used by
	 * get() and reported by archive:config, so the precedence cannot be described in two places
	 * and drift.
	 *
	 * @return string one of the SOURCE_ constants
	 */
	public static function source()
	{
		if (Settings::get('protectedUsers') !== null)
		{
			return self::SOURCE_SETTINGS;
		}

		return self::legacyProtectedUsers() === null ? self::SOURCE_NONE : self::SOURCE_LEGACY;
	}

	/**
	 * Every user id an archive run must leave alone, from config.php. Super admins are protected
	 * as well, and are added by Repository\ArchiveUsers::protectedUsers() rather than here.
	 *
	 * @return array
	 */
	public static function get()
	{
		$userids = self::source() == self::SOURCE_LEGACY
			? self::legacyProtectedUsers()
			: Settings::get('protectedUsers');

		if (!$userids) return [];
		if (!is_array($userids)) return array($userids);

		return $userids;
	}

	public static function isProtected($userid)
	{
		return in_array($userid, self::get());
	}

	/**
	 * Ids in the deprecated flat key that the current key is discarding.
	 *
	 * **Both keys set is not an error and the precedence is deliberate** - get() takes the current
	 * one, so an install can add it without editing two places at once. What earns a check is that
	 * the discarding is silent and can be partial: a current key naming fewer ids than the
	 * deprecated one leaves the difference unprotected, and an empty current key leaves every id in
	 * the deprecated key unprotected. Nothing fails in either case, because every super admin stays
	 * protected whatever config.php says - so no count reaches zero, both bulk commands run, and the
	 * next archive run disables accounts somebody had listed.
	 *
	 * **An absent current key is not this case.** Settings::get() reads it with isset(), so
	 * `$config['archiveSite'] = []` falls through to the deprecated key and protects exactly what it
	 * did before. Measured 2026-10-10 rather than reasoned about, because a config.php generated
	 * from a template is the likeliest place for an empty parent to appear.
	 *
	 * @return array ids that are no longer protected; empty when nothing is being discarded
	 */
	public static function ignoredLegacyIds()
	{
		if (self::source() != self::SOURCE_SETTINGS)
		{
			return [];
		}

		return array_values(array_diff(
			array_map('intval', self::legacyIds()),
			array_map('intval', self::get())
		));
	}

	/**
	 * The deprecated key's ids, normalised the way get() normalises the current one, and empty when
	 * the key is not set at all.
	 *
	 * @return array
	 */
	public static function legacyIds()
	{
		$userids = self::legacyProtectedUsers();

		if (!$userids) return [];
		if (!is_array($userids)) return array($userids);

		return $userids;
	}

	/**
	 * The flat $config['archiveSiteProtectedUsers'] that 2.0.2 and earlier read. It still works,
	 * so an install upgrading from one of those versions needs no change to config.php, but
	 * $config['archiveSite']['protectedUsers'] overrides it wherever both are set.
	 *
	 * @deprecated use $config['archiveSite']['protectedUsers']
	 *
	 * @return mixed
	 */
	protected static function legacyProtectedUsers()
	{
		return \XF::config('archiveSiteProtectedUsers');
	}
}
