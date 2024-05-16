<?php namespace Hampel\ArchiveSite\XF\Entity;

use XF\Mvc\Entity\Structure;

class User extends XFCP_User
{
	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);

		$structure->relations['ArchivedUser'] = [
			'entity' => 'Hampel\ArchiveSite:ArchivedUser',
			'type' => self::TO_ONE,
			'conditions' => 'user_id',
			'primary' => true
		];

		return $structure;
	}
}