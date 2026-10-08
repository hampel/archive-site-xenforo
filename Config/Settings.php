<?php namespace Hampel\ArchiveSite\Config;

/**
 * This add-on's settings, read out of the install's src/config.php.
 *
 * Everything lives under one key as an array, so that a setting can be added without adding
 * another top-level name to a file shared with XenForo and every other add-on:
 *
 *     $config['archiveSite'] = [
 *         'protectedUsers' => [1, 2],
 *         'allowMail' => true,
 *     ];
 *
 * These are config rather than options deliberately. An archive run rewrites the database an
 * option would live in, and the settings here are ones an administrator should not be able to
 * change by accident from the control panel.
 */
class Settings
{
	/**
	 * The one config key this add-on owns.
	 */
	const KEY = 'archiveSite';

	/**
	 * @param string $key
	 * @param mixed $default returned when the settings array, or this key within it, is absent
	 *
	 * @return mixed
	 */
	public static function get($key, $default = null)
	{
		$settings = \XF::config(self::KEY);

		// config.php is hand-written, so this may be absent or hold anything at all; only an
		// array has keys to read. A key set to null counts as unset, which is what lets
		// ProtectedUsers fall back to the deprecated flat key.
		if (!is_array($settings) || !isset($settings[$key]))
		{
			return $default;
		}

		return $settings[$key];
	}
}
