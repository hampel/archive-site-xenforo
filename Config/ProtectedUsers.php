<?php namespace Hampel\ArchiveSite\Config;

class ProtectedUsers
{
	/**
	 * Every user id an archive run must leave alone, from config.php. Super admins are protected
	 * as well, and are added by Repository\ArchiveUsers::protectedUsers() rather than here.
	 *
	 * @return array
	 */
	public static function get()
	{
		$userids = Settings::get('protectedUsers');

		if ($userids === null)
		{
			$userids = self::legacyProtectedUsers();
		}

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
