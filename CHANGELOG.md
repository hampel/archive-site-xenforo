CHANGELOG
=========

2.0.0 (2024-07-06)
------------------

* rename CLI commands to move them out of the xf namespace and into the hg namespace
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

1.1.0 (2020-05-28)
------------------

* hide birthday from member about page

1.0.0 (2020-05-27)
------------------

* first release
