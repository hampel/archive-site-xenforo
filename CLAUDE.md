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

## Tests run against the development forum, so they borrow and roll back

```bash
composer install                                      # first time; vendor/ is gitignored
vendor/bin/phpunit                                    # whole suite
vendor/bin/phpunit --filter ArchiveUsersTest          # one class
vendor/bin/phpunit --testsuite Unit                   # one suite
```

Built on `hampel/xenforo-test-framework` v5, isolated to this add-on through `$addonsToLoad` in
`tests/TestCase.php`. **Composer is development-only here** — there is no `require` block, so
nothing Composer installs can reach a release, and `config.platform.php` sits at 8.3 for the test
framework's sake without that raising anything this add-on ships.

**Requires the framework at `^5.2`.** 5.0.0 fixed isolation dropping class extensions registered
on a class name XenForo 2.3 renamed — and `Login`, `PasswordReset` and `Finder\User` are exactly the
renamed names this add-on's 2.2 floor requires, so on an older framework their tests exercise core
instead. 5.1.0 added `callAction()`, which is what lets the archive button's `POST` be tested at
all: it skips the CSRF check, and `preDispatch()` with it, so the super-admin guard on that action
is proved separately with `dispatch()`.

The suite runs against a real forum's database, which is what makes it worth having and what makes
three rules non-negotiable:

- **Anything that writes uses `UsesDatabaseTransactions`**, so archiving a user in a test archives
  nobody. Seven of the test classes write and all seven use it; the rest only read.
- **Borrow users, never create them.** With isolation on, XenForo builds a user `INSERT` from the
  structure it has loaded, so a `NOT NULL` column another installed add-on adds to a user table has
  no value and the save fails. The tests take an existing active user inside the transaction.
- **Anything that renders a template uses `fakesErrors()`.** A template error is written to the
  forum's real `xf_error_log`, and the framework does not suppress it.

**Every test that names a regression was run against that regression and fails.** Keep it that way:
a test that passes with the behaviour it names deleted is counted as coverage and provides none.
One did, and was removed — the finder's own `total()` and `fetch()` agree whether or not super
admins leak, because the discrepancy users saw lived in the controller.


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

**`protectedUserIds()` is the single definition everything else excludes by.** The three other
finders, the archive job's setup data and the ACP's job enqueue all call it, so "protected" cannot
drift between them. It resolves super admins through the `protectedUsers()` finder rather than
testing `Admin.is_super_admin` in each query, and that is not a style choice: the `Admin` relation
is a left join, so an ordinary user has `NULL` there, `NULL <> 1` evaluates to `NULL`, and a
condition that looks like it excludes admins excludes **everyone else** instead.

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
| `activeUsers()` | a real password or no authentication row at all, no archive row, not protected | not yet archived |
| `archivedUsers()` | an archive row, and no password - `XF:NoPassword` or no authentication row | archived by this add-on, restorable |
| `partiallyArchivedUsers()` | `XF:NoPassword`, no archive row | password removed some other way |

**An account can have no `xf_user_authenticate` row at all** — a direct insert, a partial import,
another add-on creating users. `Auth` is therefore a left join in `activeUsers()` and
`archivedUsers()`, and `archiveUser()` and `restoreUser()` skip only the password step for such an
account. Until 2.0.2 every finder inner-joined `Auth`, so the account appeared in no count or list,
while the archive job, which walks every row in `xf_user`, crashed on it part-way and left every
later account active.

**Keep a `User` entity in step with the database when changing its archive row.** XenForo's
queue runner, `Job\Manager::runQueue()`, runs every due job in one request through one entity
manager, so an archive job and a restore job can share cached entities. `archiveUser()` once
created the row without hydrating `$user->ArchivedUser`, and a restore in the same request saw no
row, skipped every account and reported success. Both methods now `hydrateRelation()` after the
write, and the job tests deliberately do not clear the entity cache, so they fail if that returns.

`partiallyArchivedUsers` is the interesting one: it catches accounts that lost their password
before this add-on existed, or by some other route. They cannot be restored, because nothing
recorded what their `user_state` used to be.

## Archiving disables, it does not erase

**An archived account keeps everything it had.** `archiveUser()` clears remember-me records, sets
the auth to `XF:NoPassword`, records the previous `user_state` and sets the state to `disabled`.
Posts, conversations, attachments, date of birth, location and profile fields are all untouched,
and that is deliberate — the add-on exists to stop a forum being *used*, not to scrub it.

Worth stating plainly because the name invites the other reading: **this is not a data-erasure
tool**, and an administrator reaching for it to satisfy a deletion request will not get one.

A longer list of scrubbing and tidying steps was considered when the 2.0 archiving mechanism was
written, and none of it was implemented. It is recorded here rather than lost, since each one is a
decision someone may want to revisit:

- show archived members in the member list again, rather than hiding them as disabled accounts
- delete banned users with no posts
- delete conversations started by archived users
- remove date of birth, and location
- remove bookmarks and profile posts belonging to archived users
- clean up archived users' profiles more generally
- physically remove soft-deleted threads, posts, attachments and photos

**Anything on that list that deletes content needs more thought than it looks.** Restoring a user
puts their `user_state` back from `xf_archivesite_user`, so archiving is reversible by design;
deleting their content would quietly make it one-way, and nothing in the restore path would say
so.

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

  **The "archived" message is only for an account that really is archived** — auth `XF:NoPassword`,
  and neither protected nor a super admin. Every other failure takes core's path, which records the
  attempt. That is a security property, not a wording choice: until 2.0.2 the message went to any
  unprotected member, returned before `recordFailedAttempt()`, and so switched XenForo's
  brute-force limit off for every ordinary member who still had a password. An archived account
  has no password to guess, which is the only reason its failure can skip the count.
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

Twelve of the fourteen are `str_replace` against literal text copied out of core templates, tabs
included. When XF edits one of those templates the find string stops matching, the modification
reports zero replacements and the page renders as stock — a guest sees the log-in prompts and the
birthdays the add-on exists to hide, and nothing raises an error. **The status column still says
`ok`**: that reports whether the modification ran, not whether it matched anything.

**The check is `apply_count`, and it is worth running after every XF upgrade**, because nothing
else will tell you. `xf_template_modification_log` holds one row per template per modification:

```sql
SELECT m.modification_key, SUM(l.apply_count) AS applied
FROM xf_template_modification m
LEFT JOIN xf_template_modification_log l ON (l.modification_id = m.modification_id)
WHERE m.addon_id = 'Hampel/ArchiveSite'
GROUP BY m.modification_id;
```

Any row at 0 is a dead modification. Two were, on XF 2.3, and both are now `preg_replace` for the
reason below.

**The two `preg_replace` ones exist because an exact match cannot span both supported XF
versions.** `hampelArchiveSitePageContainerWhatsNew` broke on 2.3 when the nav group gained a
wrapping `div` and the block moved one tab deeper — matching 2.3's indentation exactly would have
broken 2.2, which matched fine. `hampelArchiveSiteXFMGMediaViewMacros` broke when XF 2.3 replaced
`<xf:macro template="X" name="Y">` with `<xf:macro id="X::Y">`, so its find carries an alternation
for both spellings. Reach for `preg_replace` whenever a find has to tolerate reformatting or a
syntax change, not as a default — an exact match that works is easier to read and to verify.

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

## Upgrades clean up after themselves, which makes a development checkout dangerous

`Setup::postUpgrade()` calls `enqueuePostUpgradeCleanUp()`, queueing `XF\Job\FileCleanUp` to
delete anything the new release's `hashes.json` does not list. It is there because XenForo's
extractor never deletes: 2.0.0 moved the CLI commands out of the `xf:` namespace, and an install
upgraded from 1.x still had the old command classes on disk, still registered, because
`XF\Cli\Runner` discovers commands by scanning `Cli/Command`.

**The call is guarded on `\XF::$versionId >= 2030000` and must stay guarded.** Neither the method
nor the job exists in XenForo 2.2, which is this add-on's declared floor, so an unguarded call is a
fatal during the upgrade rather than a no-op.

**Never apply a release zip on top of a working copy of this add-on now that the job is armed.**
`FileCleanUp` treats the whole add-on directory as deletable and protects only `_output/`,
`hashes.json`, `addon.json`, `build.json`, `_files/` and `_releases/`. Everything else goes,
including `.git`, `.gitattributes`, `TESTING.md` and both Claude files. A plain checkout has no
`hashes.json` so the job completes immediately and the danger never arises — but a zip upgrade
applied onto a checkout writes one, which arms it for the *next* upgrade. Test upgrades on a
disposable instance, never on a checkout.

That this add-on has no Composer dependencies is what makes the job safe to arm at all: the usual
catastrophe is `FileCleanUp` removing `vendor/composer/autoload_files.php`, which the add-on cache
still claims exists and requires unconditionally on every request.

## Releases

Bump `addon.json`'s `version_id` and `version_string` together, add the CHANGELOG entry, then
build. The `xenforo-addon-release` skill carries the ordering; it is not repeated here.

`build.json`'s `exec` block removes the development files from the build output and then moves
every remaining root `*.md` up to the zip root, where a downloader sees them. **The `rm -fv` line
has to stay before the `mv`** — after it, the files it names have already been renamed to
`_build/` and `rm -f` reports nothing while they ship. Adding any new root-level development file
means adding it to that `rm` line and to `.gitattributes`; those are separate mechanisms and
neither implies the other.
