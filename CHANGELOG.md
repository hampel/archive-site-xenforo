CHANGELOG
=========

2.0.1 (2024-07-12)
------------------

* bug fix - check to prevent mail from being sent to archived users is now compatible with both XF v2.2 and v2.3

2.0.0 (2024-07-06)
------------------

* rename CLI commands into the archive namespace - `hg:archive-users` and `hg:restore-users` are
  replaced by `archive:archive-all-users` and `archive:restore-all-users`
* clean up template modifications to make them more reliable
* add new option "ArchiveSiteEndMessageUrlTarget" to choose whether to open end message URL in a new window
* fix index pointing to an invalid template - redirect to an existiing instead
* new archiving mechanism - fully disable users, storing previous user_state in case we want to restore them
* new commands: archive-all-users and restore-all-users
* phrase tweak to emphasise recommended actions
* modify isValidUser finder function to include archived users who are also valid

1.1.1 (2020-05-28)
------------------

* bugfix: hide birthday and thread not open message only for guests
* rename CLI commands out of the xf namespace - `xf:archive-users` and `xf:restore-users` become
  `hg:archive-users` and `hg:restore-users`

1.1.0 (2020-05-28)
------------------

* hide birthday from member about page

1.0.0 (2020-05-27)
------------------

* first release
