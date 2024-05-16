<?php namespace Hampel\ArchiveSite\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ArchivedUser extends Entity
{
	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_archivesite_user';
		$structure->shortName = 'Hampel\ArchiveSite:ArchivedUser';
		$structure->primaryKey = 'user_id';
		$structure->columns = [
			'user_id' => ['type' => self::UINT, 'required' => true],
			'previous_state' => ['type' => self::STR, 'maxLength' => 32, 'required' => true],
		];

		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			]
		];

		return $structure;
	}
}
