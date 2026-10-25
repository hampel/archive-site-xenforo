<?php namespace Hampel\ArchiveSite\Factory;

use Hampel\Seeder\Factory\AbstractFactory;

/**
 * An archive row, for a member a seeder has already made. Give it the member's `user_id`.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and
 * must not ship in a release.
 *
 * It exists so that the row is recorded in the batch and seeder:clean names it. Deleting the
 * member removes the row as well, from 2.2.4, so the clean usually finds it already gone.
 */
class ArchivedUserFactory extends AbstractFactory
{
	protected $entity = 'Hampel\ArchiveSite:ArchivedUser';

	/**
	 * @return array<string, string>
	 */
	public function definition()
	{
		return [
			'previous_state' => 'valid',
			'previous_scheme_class' => 'XF:Core12',
		];
	}
}
