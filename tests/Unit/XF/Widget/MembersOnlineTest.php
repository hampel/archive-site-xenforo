<?php

namespace Tests\Unit\XF\Widget;

use Tests\TestCase;
use XF\Widget\WidgetConfig;

/**
 * The widget is hidden from guests only. A member still sees it, which is what distinguishes this
 * from simply removing the widget.
 */
class MembersOnlineTest extends TestCase
{
	protected function widget(): \XF\Widget\MembersOnline
	{
		// resolve through the extension so the add-on's subclass is the one built
		$class = $this->app()->extension()->extendClass('XF\Widget\MembersOnline');
		$config = new WidgetConfig(0, 'members_online', 'members_online', 'Members online', [], []);

		return new $class($this->app(), $config);
	}

	public function test_it_renders_nothing_for_a_guest(): void
	{
		$this->actingAsGuest();

		$this->assertSame('', $this->widget()->render());
	}

	/**
	 * Core's own render() also returns '' when the visitor cannot view the member list, and a built
	 * visitor has no permissions at all - so without the grant this would pass or fail on core's
	 * check rather than on the add-on's. The grant leaves the add-on's decision as the only thing
	 * that can produce an empty result.
	 */
	public function test_it_still_renders_for_a_member(): void
	{
		// the parent renders for real, so keep anything it logs off the forum's error log
		$this->fakesErrors();
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['viewMemberList' => true]]);

		$this->assertNotSame('', $this->widget()->render());
	}
}
