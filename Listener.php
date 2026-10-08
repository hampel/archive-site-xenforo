<?php namespace Hampel\ArchiveSite;

use Hampel\ArchiveSite\Config\Settings;

class Listener
{
	/**
	 * Declares this add-on's secrets to the Admin API, which answers the
	 * hampel_admin_api_redaction event.
	 *
	 * **This add-on has no secrets**, and the declaration is what says so: until an add-on
	 * declares, the Admin API reports each of its text settings as merely set or not set, which is
	 * no use to anybody asking how a forum is configured. An empty list moves them to shown.
	 *
	 * The four options hold a message, a URL and a switch. The config keys hold user ids and a
	 * boolean. Nothing here lets its holder act as anyone or read anything of ours, so nothing is
	 * withheld - but both config keys are claimed, because claiming a key is what gets its
	 * contents shown rather than withheld as an unknown add-on's.
	 *
	 * It is not a dependency on the Admin API: an event no installed add-on defines is never
	 * fired, so on a forum without it this method is never called and costs nothing. Keep it to
	 * arrays of strings for the same reason.
	 *
	 * @param array $declarations
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/ArchiveSite'] = [
			'options' => [],
			'config' => [
				// the settings array - Config\Settings::KEY, holding protectedUsers and allowMail
				Settings::KEY => [],

				// the flat key 2.0.2 and earlier read, deprecated and still honoured
				'archiveSiteProtectedUsers' => [],
			],
		];
	}
}
