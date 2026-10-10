CHANGELOG
=========

2.2.2 (2026-10-10)
------------------

* `archive:validate` now warns when `$config['archiveSite']['protectedUsers']` names fewer accounts
  than the deprecated `$config['archiveSiteProtectedUsers']` it replaced, listing the ids that are
  no longer protected. Nothing else reports this: every super administrator stays protected either
  way, so no count reaches zero and an archive run proceeds
* `archive:config` now says whether the deprecated setting is in use, overridden, or discarding ids
* the what's new template modification described itself as hiding a link on mobile devices. It
  hides the link in the page header on every device, which is what it has always done - only the
  description in `Admin CP > Appearance > Template modifications` was wrong
* `README.md` carried a second, older copy of its configuration section, which still said that mail
  addressed to an email address was not blocked. It has been blocked since 2.2.0

2.2.1 (2026-10-10)
------------------

* bug fix - on XenForo 2.2, `archive:archive-user` and `archive:restore-user` archived or restored
  the account and then failed while printing their final message, and the other two commands failed
  the same way when a confirmation was declined. XenForo 2.3 was unaffected
* `archive:validate` now reports how far the archiving has got, which it previously printed as a
  plain count. An account that is neither archived nor protected can still log in, so a forum that
  set archiving up and did not finish is a warning that says which it is - not started, or
  incomplete. An account with no password and no archive row warns for as long as it exists, since
  nothing recorded what its state was and nothing can restore it

2.2.0 (2026-10-10)
------------------

* bug fix - mail addressed to a member's email address is now refused as well as mail addressed to
  the member. Only the second was covered before, so an add-on that builds its own recipient list,
  as a newsletter or a digest does, still reached archived members
* bug fix - restoring an account that never had a password no longer gives it one. XenForo creates
  passwordless accounts for registrations through a connected account, and those came back
  reachable by lost-password where before they were not. The authentication scheme an account had
  is now recorded when it is archived
* bug fix - the member lists and counts no longer describe a passwordless member as partially
  archived, and an archived account that still has a password now appears in the archived list
  instead of in none of them. All three lists are keyed on whether this add-on archived the
  account, with "partially archived" meaning an account that is shut, has no password, and has no
  record here
* bug fix - restoring an account this add-on never archived leaves it alone. Naming the wrong
  account to `archive:restore-user` used to reset a real member's password
* bug fix - an archive run with nothing protected is refused and logged, instead of failing with a
  database error. Archiving every account including every administrator is what such a run would
  otherwise have done
* change - this add-on's extensions of `XF\Finder\User` and `XF\Service\User\Login` now run
  inside another add-on's extensions of the same classes rather than outside them, so another
  add-on's changes to those methods are no longer discarded

2.1.0 (2026-10-09)
------------------

* new setting `$config['archiveSite']['allowMail']` re-enables mail to members, for a development
  or staging copy of an archived forum. Installing this add-on otherwise stops mail to anyone who
  is not a super administrator or protected
* all settings now live under one `$config['archiveSite']` array, holding `protectedUsers` and
  `allowMail`. The old `$config['archiveSiteProtectedUsers']` still works and needs no change on
  upgrade; the new key overrides it where both are set
* new command `archive:config` prints every setting and which `config.php` key or option it came
  from, which is the only way to see the protected list without the admin control panel
* new command `archive:validate` checks that the forum can do what it is configured to do: the
  protected list resolves to real accounts, the archive table is present, the class extensions
  apply, and **every template modification still matches the template it targets** - which
  otherwise fails silently when XenForo changes a core template. It exits 0, or 1 on a failure,
  with `--strict` for 2 on warnings alone
* security - a member whose account has no password but was never archived is no longer told the
  site is archived, and their failed logins now count towards XenForo's brute-force limit. This
  affects accounts registered through a connected account, which XenForo creates without a
  password
* bug fix - such a member can request a password by email again, which is how XenForo intends
  them to move off a passwordless account
* bug fix - restoring an account that was awaiting approval no longer fails when it is archived
  and restored in the same background job run
* this add-on now tells a monitoring API which of its settings are secrets - none of them are

2.0.2 (2026-09-22)
------------------

* security - a failed login by a member who still has a password now counts towards XenForo's
  brute-force limit, and they are told the password is incorrect; previously they were told the
  site was archived and the attempt was not counted. Only an archived account is now told the site
  is archived
* now requires XenForo 2.2.0 or higher
* bug fix - two template modifications had stopped applying on XenForo 2.3, which let guests see
  the What's New link and the gallery BB code control; both are hidden from guests again
* bug fix - archiving all users no longer stops part-way on an account that has no authentication
  record, and such accounts are now included in the active and archived user counts
* bug fix - restoring users in the same background job run as archiving them no longer skips them
* bug fix - the Archive Users action now always requires its confirmation box to be ticked
* bug fix - the active user count no longer includes super administrators
* bug fix - removed a "Clean Users" admin menu item that led nowhere
* upgrading now removes files that earlier versions left behind, including two command classes
  from 1.x that were still registered as `hg:archive-users` and `hg:restore-users`

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
