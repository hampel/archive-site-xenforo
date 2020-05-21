<?php namespace Hampel\ArchiveSite\XF\Widget;

class MembersOnline extends XFCP_MembersOnline
{
	public function render()
	{
		// hide from offline users
		if (!\XF::visitor()->user_id)
		{
			return '';
		}

		return parent::render();
	}
}
