<?php namespace Hampel\ArchiveSite\Factory;

use Hampel\Seeder\Factory\AbstractFactory;

/**
 * An archive row, for a member a seeder has already made. Give it the member's `user_id`.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and
 * must not ship in a release.
 *
 * It exists so that seeder:clean removes the row. Deleting a member leaves their archive row
 * behind, since nothing in this add-on hooks a member's deletion, so a row written any other way
 * would outlive the batch and be counted by archive:validate.
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
