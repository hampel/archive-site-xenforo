<?php

namespace Tests\Unit;

use Hampel\ArchiveSite\Config\Settings;
use Hampel\ArchiveSite\Listener;
use Tests\TestCase;

/**
 * The secret declaration the Admin API reads, which goes stale silently: an option renamed or
 * added says nothing about the list, and the list says nothing about the options. These are the
 * two assertions that make the suite notice.
 *
 * Nothing here needs the Admin API installed. The declaration is an array of strings, and the
 * event it answers is never fired on a forum that does not define it.
 */
class ListenerTest extends TestCase
{
	/**
	 * Options whose id reads like a credential but is not one. Anything matching the patterns
	 * below has to be either declared a secret or named here, so that a future option cannot
	 * quietly become an undeclared secret.
	 */
	protected const NOT_SECRETS = [];

	protected function declarations(): array
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	protected function optionIds(): array
	{
		$ids = [];

		foreach (glob(dirname(__DIR__, 2) . '/_output/options/*.json') AS $path)
		{
			$name = basename($path, '.json');
			if ($name !== '_metadata')
			{
				$ids[] = $name;
			}
		}

		return $ids;
	}

	/**
	 * An entry for another add-on's id would be a claim about code this add-on's author has not
	 * read, so the declaration names exactly one add-on: this one.
	 */
	public function test_it_declares_this_add_on_and_nothing_else(): void
	{
		$this->assertSame(['Hampel/ArchiveSite'], array_keys($this->declarations()));
	}

	public function test_every_declared_secret_is_an_option_this_add_on_has(): void
	{
		$declarations = $this->declarations()['Hampel/ArchiveSite'];

		foreach ($declarations['options'] ?? [] AS $path)
		{
			$optionId = explode('.', $path)[0];
			$this->assertContains($optionId, $this->optionIds(), "declared secret $path");
		}

		$this->assertSame([], $declarations['options'] ?? [], 'this add-on has no secret options');
	}

	/**
	 * The assertion that catches a new secret. An option called something like a credential is a
	 * decision for somebody, so the suite fails until it is either declared or listed above.
	 */
	public function test_no_option_reads_like_an_undeclared_credential(): void
	{
		$declared = $this->declarations()['Hampel/ArchiveSite']['options'] ?? [];
		$suspicious = [];

		foreach ($this->optionIds() AS $id)
		{
			if (!preg_match('/key|token|secret|password|webhook|credential|licen[cs]e/i', $id))
			{
				continue;
			}

			if (!in_array($id, $declared) && !in_array($id, self::NOT_SECRETS))
			{
				$suspicious[] = $id;
			}
		}

		$this->assertSame([], $suspicious, 'an option reads like a credential and is neither declared nor listed as not one');
	}

	/**
	 * The config half, tied to the constant the code reads rather than to a repeated string, so
	 * renaming the settings key fails here instead of silently withholding it from the API.
	 */
	public function test_it_claims_every_config_key_the_add_on_reads(): void
	{
		$config = $this->declarations()['Hampel/ArchiveSite']['config'] ?? [];

		$this->assertArrayHasKey(Settings::KEY, $config);
		$this->assertArrayHasKey('archiveSiteProtectedUsers', $config, 'the deprecated flat key is still read');
		$this->assertSame([], $config[Settings::KEY], 'nothing under the settings key is a secret');
	}
}
