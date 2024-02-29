
* Class extensions
	* `XF\Mail\Mail`
		* intercept `setToUser` and throw an exception if trying to send email to a non-protected user
	* `XF\Service\User\Login`
		* replace `validate` so we can set a custom error message for people not in our allowed logins list
	* `XF\Service\User\Login`
		* extend `canTriggerConfirmation` to stop people with no passwords from resetting them
	* `XF\Widget\MembersOnline`
		* intercept `render` to hide widget from offline users

* template modifications
	* `member_about`
		* Remove birthday from member about page
		* Hide followers from member about
		* Hide following from member about
	* `forum_overview_wrapper`
		* Add message below page title on forum home page
		* Remove new posts button for guests
	* `forum_view`
		* Remove log in or register to post from forum view pages
	* `PAGE_CONTAINER`
		* Remove mobile what's new link for guests
	* `thread_list_macros`
		* Remove lock symbol from threads in thread list
	* `thread_view`
		* Remove log in or register to post from thread view pages
		* Remove not open for further replies from thread view pages
	* `widget_forum_statistics`
		* Remove latest member from forum statistics widget
	* `xfmg_media_view_macros`
		* Hide gallery link from media pages

* Fragile points:

		
* Testing:
	