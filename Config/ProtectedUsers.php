<?php namespace Hampel\ArchiveSite\Config;

class ProtectedUsers
{
	public static function get()
	{
		$userids = \XF::config('archiveSiteProtectedUsers');

		if (!$userids) return [];
		if (!is_array($userids)) return array($userids);

		return $userids;
	}

	public static function isProtected($userid)
	{
		return in_array($userid, self::get());
	}
}
