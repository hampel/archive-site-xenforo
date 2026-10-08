<?php namespace Hampel\ArchiveSite\Config;

/**
 * $config['archiveSite']['allowMail'] switches the mail restriction off, so that XenForo mails
 * every member as it normally would.
 *
 * Installing this add-on otherwise stops mail to anyone who is not a super admin and not
 * protected, whether or not they are archived. That is the point on an archived forum and a
 * nuisance on a development or staging copy of one, which is what this exists for.
 *
 * It is not an option, because re-enabling mail to every member of an archived forum is not
 * something an administrator should be able to do by ticking a box.
 */
class AllowMail
{
	/**
	 * @return bool
	 */
	public static function enabled()
	{
		return (bool) Settings::get('allowMail', false);
	}
}
