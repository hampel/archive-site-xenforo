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
