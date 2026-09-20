# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on — `Hampel/ArchiveSite`, "Archive Site". It sits at
`src/addons/Hampel/ArchiveSite/` inside a XenForo install and is its own git repository; the
install around it is not.

Where the install has an `AGENTS.md` at its root, read it first — it carries the XF conventions,
the `_output`/`_data` boundary, the `cmd.php` command signatures and the per-add-on git layout.
This file covers only what is specific to Archive Site.

## Commands

Run from this directory — it is the git repo, so `git` acts on the right thing. `cmd.php` resolves
the install from its own location rather than the working directory, so the relative path below
works unchanged.

```bash
php ../../../../cmd.php xf-dev:import --addon=Hampel/ArchiveSite    # _output/ -> database
php ../../../../cmd.php xf-addon:export Hampel/ArchiveSite          # database -> _output/
php ../../../../cmd.php xf-addon:build-release Hampel/ArchiveSite   # zip into _releases/
```

The add-on's own commands, once it is installed:

```bash
php ../../../../cmd.php archive:archive-all-users     # queues the rebuild job, interactive confirm
php ../../../../cmd.php archive:restore-all-users     # the inverse job
php ../../../../cmd.php archive:archive-user <user>   # user_id, username or email
php ../../../../cmd.php archive:restore-user <user>
```

There is no `composer.json` and no PHPUnit suite. `TESTING.md` is the test plan, and it is manual.

## Two unrelated features share one add-on

Nothing connects these beyond both being needed when a forum stops taking new content, and a
change to one almost never touches the other:

- **Hiding things from guests** — options, template modifications and the `MembersOnline` widget
  extension. Nothing here reads the archive tables; it keys off `$xf.visitor.user_id` and the four
  `hampelArchiveSite*` options.
- **Archiving user accounts** — the repository, the two jobs, the four CLI commands, the admin
  controller and `xf_archivesite_user`. Nothing here reads the options.

## Protected users come from `config.php`, not from an option

`Config\ProtectedUsers::get()` reads `$config['archiveSiteProtectedUsers']` out of the install's
`src/config.php`, and that array plus every super admin is the entire set of accounts an archive
run will leave alone. It is deliberately not an ACP option: an option lives in a database an
archive run is rewriting, and a config file does not.

Two consequences worth holding on to:

- **The protected list is invisible to a fresh checkout.** `Admin CP > Users > Archive Site >
  List Protected Users` is the only way to see what a given install considers protected, and both
  `archive-all-users` and `restore-all-users` abort with an error when the list comes back empty.
  That guard is the one thing standing between a run and an install nobody can log in to.
- `ProtectedUsers::isProtected()` is checked again inside `Repository\ArchiveUsers::archiveUser()`
  and `restoreUser()`, below the job's own filtering. Keep that second check when editing either —
  the CLI single-user commands rely on it, not on the job's ID query.

## Archiving and restoring are not inverses

`archiveUser()` clears the user's remember-me records, sets their auth to `XF:NoPassword`, records
their `user_state` in `xf_archivesite_user`, then sets the state to `disabled`. `restoreUser()`
puts the recorded state back and deletes the row — but it calls `Auth::resetPassword()`, which
generates a random password rather than returning the old one. **A restored user cannot log in
until they complete a password reset**, and that is the design, not an oversight: the original
hash is gone the moment the account is archived.

This is why the repository exposes four finders rather than two, and why the ACP has a page for
each:

| finder | the user has | means |
|---|---|---|
| `protectedUsers()` | super admin, or an ID in `config.php` | never touched by a run |
| `activeUsers()` | a real password, no archive row | not yet archived |
| `archivedUsers()` | `XF:NoPassword` and an archive row | archived by this add-on, restorable |
| `partiallyArchivedUsers()` | `XF:NoPassword`, no archive row | password removed some other way |

`partiallyArchivedUsers` is the interesting one: it catches accounts that lost their password
before this add-on existed, or by some other route. They cannot be restored, because nothing
recorded what their `user_state` used to be.

## Installing this add-on stops mail to ordinary users

`XF\Mail\Mail::setToUser()` is extended to refuse any recipient who is not a super admin and not
in the protected list. It does not check whether the user is archived — the moment the add-on is
active, every normal member is unmailable, which is the point on an archived site and a
foot-gun anywhere else.

The refusal sets `$this->setupError` rather than throwing, so the caller sees the send fail the
way XF's own validation failures do instead of a fatal.

## `Finder\User::isValidUser()` is overridden so statistics stay honest

Archived users are `disabled`, so stock XF drops them out of member counts and the members-online
list, and an archived forum's statistics collapse overnight. The override widens the condition to
`user_state = 'valid' OR ArchivedUser.previous_state = 'valid'`, which is the only reason
`XF\Entity\User` carries the `ArchivedUser` relation at all.

## Two extensions are copies of core, and go stale silently

- **`XF\Service\User\Login::validate()`** is a full reimplementation of the parent, not a call
  through it, because the custom "this site is archived" error has to replace the authentication
  failure rather than follow it. Re-read the core method after any XF upgrade and port whatever
  changed.
- **`XF\Mail\Mail`** declares the class twice in one file, inside an `if (\XF::$versionId >=
  2030000)` branch, because `setToUser()` gained a `: \XF\Mail\Mail` return type in XF 2.3 and an
  incompatible signature is a fatal, not a warning. Any further change to that method has to be
  made in both branches.

The supported range is **XF 2.2 and 2.3**, and all three places that state it agree: the
`require.XF` floor, the README, and the branch above, which knows about exactly those two. The
class extensions all name the pre-2.3 core classes — `XF\Service\User\Login`, not
`LoginService` — which is what a 2.2 floor requires and what keeps working on 2.3, since 2.3
aliases the old names forward and 2.2 has no such mechanism. **Adopting the 2.3 spellings would
be a floor change**, whether or not `addon.json` said so.

## Template modifications match stock markup exactly, so an XF upgrade breaks them quietly

All fourteen are `str_replace` against literal text copied out of core templates, tabs included.
When XF edits one of those templates the find string stops matching, the modification reports
zero replacements in the ACP and the page renders as stock — a guest sees the log-in prompts and
the birthdays the add-on exists to hide, and nothing raises an error.

Two patterns recur and are worth recognising before editing one:

- Wrapping the matched block in `<xf:if is="$xf.visitor.user_id">` and re-emitting it as `$0`.
  This is how everything guest-hiding works.
- Appending ` AND false` to a core condition, in `hampelArchiveSiteForumView`, to neutralise a
  block without deleting markup another modification matches against.

Ordering is load-bearing on `forum_view`: `hampelArchiveSiteForumViewArchived` runs at execution
order 9 and inserts the end-message block ahead of the original condition, then
`hampelArchiveSiteForumView` runs at 10 and neutralises that condition. Swap them and the second
find string no longer matches.

`TESTING.md` lists every modification and what to look at. It is the whole verification story for
this half of the add-on — work through it after any XF upgrade, not just after a change here.

## Releases

Bump `addon.json`'s `version_id` and `version_string` together, add the CHANGELOG entry, then
build. The `xenforo-addon-release` skill carries the ordering; it is not repeated here.

`build.json`'s `exec` block removes the development files from the build output and then moves
every remaining root `*.md` up to the zip root, where a downloader sees them. **The `rm -fv` line
has to stay before the `mv`** — after it, the files it names have already been renamed to
`_build/` and `rm -f` reports nothing while they ship. Adding any new root-level development file
means adding it to that `rm` line and to `.gitattributes`; those are separate mechanisms and
neither implies the other.
