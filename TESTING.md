
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
          	* replace with `Admin CP > Options > Archive Site > Content End Message` /
              `Admin CP > Options > Archive Site > Content End Message Url`
	* `PAGE_CONTAINER`
		* Remove mobile what's new link for guests
	* `thread_list_macros`
		* Remove lock symbol from threads in thread list
	* `thread_view`
		* Remove log in or register to post from thread view pages
          	* replace with `Admin CP > Options > Archive Site > Content End Message` / 
              `Admin CP > Options > Archive Site > Content End Message Url` 
		* Remove not open for further replies from thread view pages
	* `widget_forum_statistics`
		* Remove latest member from forum statistics widget
	* `xfmg_media_view_macros`
		* Hide gallery link from media pages

* Fragile points:

		
* Testing 
	* Check that `Admin CP > Options > Archive Site > Home page message` is shown on forum home page 
	* Check that `Admin CP > Options > Archive Site > Content End Message` is shown below thread list and below thread 
      content 
    * Set a URL in `Admin CP > Options > Archive Site > Content End Message Url` and check that the Content End Message 
      becomes linked
    * Check `Admin CP > Options > Archive Site > Open link in a new window` and verify that link opens in new window
    * Go to `Admin CP > Users > Archive Site > List Protected Users` and verify that the super users are listed
        * Add `$config['archiveSiteProtectedUsers'] = [<user ids>];` with the IDs of some users and then verify that these 
          users become listed in Protected Users.
    * Go to `Admin CP > Users > Archive Site > Archive Users` and confirm protected and active users are as expected
        * check the `Confirm X active users will be archived` box and then click "Archive" 
            * check that the previously active users are now archived, but none of the protected users have been changed
            * check the `xf_user_authenticate` table and verify that all archived users have the `XF:NoPassword` scheme and no password data