<?php namespace Hampel\ArchiveSite\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * RELATIONS
 * @property-read \Hampel\ArchiveSite\Entity\ArchivedUser|null $ArchivedUser
 */
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

	/**
	 * A deleted member's archive row goes with them. Left behind, it was counted by
	 * archive:validate as an archived account, and would be read as one by any later account
	 * given that id.
	 *
	 * @return void
	 */
	protected function _postDelete()
	{
		parent::_postDelete();

		$this->db()->delete('xf_archivesite_user', 'user_id = ?', $this->user_id);
	}
}