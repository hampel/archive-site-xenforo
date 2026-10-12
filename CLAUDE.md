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

**Requires the framework at `^5.18`.** 5.0.0 fixed isolation dropping class extensions registered
on a class name XenForo 2.3 renamed — and `Login`, `PasswordReset` and `Finder\User` are exactly the
renamed names this add-on's 2.2 floor requires, so on an older framework their tests exercise core
instead. 5.3.0 added `runJobToCompletion()`, which the job tests use to run the archive and restore
jobs as XenForo's job manager would. 5.4.0 gave a rendered template the `$xf` every template
modification here reads, which is what lets `TemplateRenderingTest` check what a guest and a member
actually see. 5.10.0 added `runConsoleCommand()`, 5.17.0 `assertConsoleCommandsLoad()` and 5.18.0
the `inputs` that answer a command's confirmation - between them, all that `Cli\CommandsTest` and
`Cli\ReportCommandsTest` are.

**5.15.0 is the release that changed how an existing test is written.** A non-GET `dispatch()` now
carries a CSRF token, so the archive button is dispatched as a real `POST` with `preDispatch()` and
its super-admin guard intact, where it used to need `callAction()` to get past the check — and
`dispatchWithoutCsrfToken()` proves the check still refuses. **`assertReplyParam()`'s third
argument is a failure message, not an expected value**, and the framework refuses a value there
since 5.14.0: that is how the archive page's count test was found to have been asserting only that
four parameters existed. `assertReplyParamSame()` is what it meant.

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


## The forum's own `config.php` must not reach the suite, and clearing one key is not enough

**`Tests\TestCase::setUp()` clears both `$config['archiveSite']` and the deprecated flat
`$config['archiveSiteProtectedUsers']` before every test.** The suite runs against whatever forum
it is pointed at, and both keys are things a real forum legitimately sets — so a test that does not
set them has to mean *neither key is set*, and it cannot mean that while the forum decides.

**Clearing one is not enough, and that asymmetry is the whole trap.** `ProtectedUsers::get()`
prefers the nested key and reads the flat one only when the nested key is absent, so a test that
sets the flat key alone is overridden by whatever the forum holds in the nested one. Nothing fails:
both are valid configurations, and the override is the documented behaviour working as designed.

**A development forum that sets neither key cannot show this**, which is why it survived four
release cycles. The mutation check needs a forum whose `config.php` sets
`$config['archiveSite']['protectedUsers']` — `TESTING.md` carries the command.

Measured on 2026-10-11, in a sandbox seeded with 56 members and two config-protected ids:

| the forum's `config.php` | the suite |
|---|---|
| sets the nested protected list | **9 failures**, every one a config-protected-user test |
| sets neither key | 160 pass |
| sets the nested list, with the `setUp()` clearing in place | 160 pass |

**And `config.php` is only half of it: the forum's population is the other half.** On a forum
holding real `XF:NoPassword` members, `Job\ArchiveUsersTest`'s restore test failed with *user 32
still has no password* - because it asserted, for every restored account, that a password had come
back. An account that was passwordless before archiving correctly returns passwordless, which is
what `previous_scheme_class` is for, so the add-on was right and the test was wrong. It now
asserts both populations separately, and the passwordless half is the stronger assertion: it fails
if the 2.2.0 fix is reverted, which the job tests never checked before - only the repository tests
did, against a fixture. Measured 2026-10-12.

**Two tests went on passing while proving nothing they were named for**, which is the half worth
fearing. `test_nothing_is_protected_by_an_empty_list` asserted `isProtected(1)` is false while the
forum's own list — not the empty one it set — was in effect, and would have *failed* on a forum
whose list happens to contain user 1. And `LoginTest`'s protected-account-without-a-password test
passed with its protection inert, because an unprotected passwordless account with no archive row
takes core's path too: the behaviour it asserts is produced by the archive-row keying rather than
by the protection it set up. **That one is still weaker than its name suggests** even with the
config leak closed, and is the next thing to tighten there.

## Two unrelated features share one add-on

Nothing connects these beyond both being needed when a forum stops taking new content, and a
change to one almost never touches the other:

- **Hiding things from guests** — options, template modifications and the `MembersOnline` widget
  extension. Nothing here reads the archive tables; it keys off `$xf.visitor.user_id` and the four
  `hampelArchiveSite*` options.
- **Archiving user accounts** — the repository, the two jobs, the four CLI commands, the admin
  controller and `xf_archivesite_user`. Nothing here reads the options.

## Protected users come from `config.php`, not from an option

`Config\ProtectedUsers::get()` reads `$config['archiveSite']['protectedUsers']` out of the install's
`src/config.php`, and that array plus every super admin is the entire set of accounts an archive
run will leave alone. It is deliberately not an ACP option: an option lives in a database an
archive run is rewriting, and a config file does not.

**Every setting lives under the one `archiveSite` key, read through `Config\Settings::get()`.** The
flat `$config['archiveSiteProtectedUsers']` that 2.0.2 and earlier read is deprecated and still
honoured, so an upgrading install needs no change to `config.php` — `ProtectedUsers` falls back to
it only when the nested key is absent, and a nested key set to an empty array therefore *overrides*
rather than falls through. That asymmetry is deliberate: an empty list is what the CLI commands
abort on, and falling back would hide a deliberate one. `Settings::get()` also has to survive a
hand-written `config.php` holding anything at all under that key, so it checks `is_array()` before
reading.

**A present-but-empty parent is absent, and that was measured rather than reasoned about.**
`$config['archiveSite'] = []` holds no `protectedUsers`, and `Settings::get()` reads the key with
`isset()`, so the deprecated key is read exactly as before. It matters because a `config.php`
generated from a template is where an empty parent appears, and the two empty forms behave
differently: `['protectedUsers' => []]` overrides and protects nobody from `config.php`, while `[]`
falls through. Checked against the real classes on 2026-10-10 and asserted in
`ProtectedUsersTest`, because a generated config now depends on the answer.

**Where both keys are set, the newer one is the whole list, and the loss can be partial and
silent.** An id named only in the deprecated key is no longer protected, and nothing fails: every
super admin is protected whatever `config.php` says, so no count reaches zero, both bulk commands
run, and the next archive run disables an account somebody had listed.
`ProtectedUsers::ignoredLegacyIds()` is that decision in one place and `archive:validate` warns
with the ids. **Note what this means for the empty-list guards above** — they fire on the *total*
being empty, so on any forum that has a super admin they do not fire at all, and this check is the
only thing that reports a half-done rename.

Two consequences worth holding on to:

- **The protected list is invisible to a fresh checkout.** `Admin CP > Users > Archive Site >
  List Protected Users` is the only way to see what a given install considers protected, and both
  `archive-all-users` and `restore-all-users` abort with an error when the list comes back empty.
  That guard is the one thing standing between a run and an install nobody can log in to.
- `ProtectedUsers::isProtected()` is checked again inside `Repository\ArchiveUsers::archiveUser()`
  and `restoreUser()`, below the job's own filtering. Keep that second check when editing either —
  the CLI single-user commands rely on it, not on the job's ID query.

**An unprotected run is refused by the job itself, not only by the CLI.** `Job\ArchiveUsers::
getNextIds()` returns no ids when the protected list is empty, logs the same error the CLI prints,
and lets the job complete having touched nobody — because archiving every account, administrators
included, is the one outcome this add-on must never produce by accident. Both bulk CLI commands
abort for that reason, and the ACP button cannot reach the state at all, since only a super admin
can press it and a super admin is always in the list it enqueues. What the guard covers is
everything else: `xf:rebuild`, or any code enqueuing the job directly, on a forum with no super
admin and nothing in `config.php`.

**It also kept a malformed query out of the database.** `$db->quote([])` returns an empty string,
so an empty list built `AND user_id NOT IN ()` — a syntax error on the first batch, not a no-op.
The `?: [0]` still in that query is defence in depth for the same reason: a query that cannot be
malformed is worth two characters.

**`protectedUserIds()` is the single definition everything else excludes by.** The three other
finders, the archive job's setup data and the ACP's job enqueue all call it, so "protected" cannot
drift between them. It resolves super admins through the `protectedUsers()` finder rather than
testing `Admin.is_super_admin` in each query, and that is not a style choice: the `Admin` relation
is a left join, so an ordinary user has `NULL` there, `NULL <> 1` evaluates to `NULL`, and a
condition that looks like it excludes admins excludes **everyone else** instead.

## The add-on declares that it has no secrets

`Listener::adminApiRedaction()` answers the `hampel_admin_api_redaction` event with an empty
secret list for `Hampel/ArchiveSite`, claiming both `config.php` keys the add-on reads. **The
declaration is what gets the settings shown**: an Admin API reporting a forum's configuration
reports an undeclared add-on's text settings as merely set or not set, since nothing has said
which of them are credentials.

There is nothing to withhold here — the options hold a message, a URL and a switch, and the
config keys hold user ids and a boolean. **It is not a dependency on the Admin API either**: an
event no installed add-on defines is never fired, so the method is never called on a forum
without it. Keep it to arrays of strings, and add any new option to the declaration in the same
commit — `ListenerTest` fails when an option's id reads like a credential and is neither
declared nor listed as deliberately not one.

## Archiving and restoring are not inverses

`archiveUser()` clears every credential the account has — remember-me records, passkeys and
two-step — sets their auth to `XF:NoPassword`, records their `user_state` **and their
authentication scheme** in `xf_archivesite_user`, then sets the state to `disabled`.
`restoreUser()` puts the recorded state back and deletes the row — and where the account had a
password, it calls `Auth::resetPassword()`, which generates a random one rather than returning the
old. **A restored user cannot log in until they complete a password reset**, and that is the
design, not an oversight: the original hash is gone the moment the account is archived.

**An account that never had a password is left without one.** That is what
`previous_scheme_class` is for, and the question is asked through XenForo's own
`hasPassword()` so another add-on's passwordless scheme is covered as well as core's. Until 2.2.0
a connected-account member came back with a real password they never had, reachable by
lost-password where before they were not. An empty recorded scheme means it is not known — a row
written before 2.2.0 — and keeps the old behaviour, since an upgrade cannot find out
retroactively.

**And `restoreUser()` touches the authentication row only when there is an archive row to restore
from.** It is reachable from `archive:restore-user` with any account at all, so until 2.2.0 a
mistyped name reset a real member's password and changed nothing else.

This is why the repository exposes four finders rather than two, and why the ACP has a page for
each:

| finder | the account has | means |
|---|---|---|
| `protectedUsers()` | super admin, or an ID in `config.php` | never touched by a run |
| `activeUsers()` | no archive row, and is not both shut and passwordless | not archived by this add-on |
| `archivedUsers()` | an archive row | archived by this add-on, restorable |
| `partiallyArchivedUsers()` | no archive row, `user_state = 'disabled'`, no usable password | looks archived, and nothing recorded what it was |

**The archive row is what decides, and the authentication scheme is only a secondary check.**
Until 2.2.0 all three keyed on `XF:NoPassword`, which is not a synonym for archived: XenForo
creates passwordless accounts for registrations through a connected account, so every one of those
members was reported as *partially archived* and left out of the active count, while an archived
account whose scheme was anything else — an administrator set a password on it, or another
add-on owns its authentication — appeared in **none** of the four sets at all, archive row and
all. The three unprotected sets now partition the forum, which
`test_every_unprotected_account_is_in_exactly_one_set` asserts against the real data rather than
against the SQL.

**`user_state = 'disabled'` is what keeps ordinary members out of `partiallyArchivedUsers()`**, so
the set means what its name says: an account that looks archived — shut, with no way to log in
— and that this add-on has no record of, so nothing can restore it. A member who merely has no
password is active.

**An account can have no `xf_user_authenticate` row at all** — a direct insert, a partial import,
another add-on creating users. `Auth` is a left join everywhere for that reason, and
`archiveUser()` and `restoreUser()` skip only the password step for such an account. Until 2.0.2
every finder inner-joined `Auth`, so the account appeared in no count or list, while the archive
job, which walks every row in `xf_user`, crashed on it part-way and left every later account
active.

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

**An archived account keeps everything it wrote, and nothing it logged in with.** Posts,
conversations, attachments, date of birth, location and profile fields are all untouched, and that
is deliberate — the add-on exists to stop a forum being *used*, not to scrub it. The credentials
go, which is the section below.

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

**Anything on that list that deletes content needs more thought than it looks, and the credential
clearing below is not the precedent for it.** Archiving is one way by intention and restoring is
the exceptional case (Simon, 2026-10-12), so "it would make archiving irreversible" is no longer
the objection it was. The line that remains is what the row is *for*: a credential has no value to
an archived forum and cannot be put back whether or not anyone tries, whereas the content is the
thing the forum is being kept for, and `restoreUser()` quietly promises to return an account to
what it was. Deleting a member's conversations or bookmarks breaks that promise in a way nothing in
the restore path would say.

## Credentials are the exception, and clearing them is one way

**`archiveUser()` deletes every way into the account: remember-me records, passkeys, two-step
provider rows and trusted devices, on top of replacing the password with `XF:NoPassword`.** Nothing
about them is recorded and nothing restores them. That is the asymmetry with `previous_state` and
`previous_scheme_class`, and it is deliberate on two grounds — an archived forum should stop
carrying the rows that let somebody into it, and there is nothing restorable in a credential
anyway: the secret behind a two-step provider or a passkey cannot be written back any more than a
password hash can. A restored owner sets both up again, which is the position they are already in
over their password.

`Repository\ArchiveUsers::clearCredentials()` is the whole of it, and it is three decisions:

| | |
|---|---|
| passkeys | deleted one entity at a time, so a sibling add-on with data hanging off a passkey is told it has gone. Core offers no bulk method |
| two-step | `XF:Tfa`'s own `disableTfaForUser()` — the method the account security page calls. Provider rows, trusted devices and the `use_tfa` flag, in one place |
| `xf_tfa_attempt` | left alone. It is rate limiting rather than anything the account owns, and XenForo's cron deletes every row over a day old |

**The order is load-bearing and `user_option.use_tfa` is why.** XenForo maintains a `passkey`
two-step provider row beside the passkeys themselves, so the passkeys go first and the entity's own
`_postDelete()` retires that row; `disableTfaForUser()` then clears what is left **and puts
`use_tfa` back to 0**. A `use_tfa` flag with no provider rows behind it asks for a code nothing can
issue, which would lock a restored owner out of the account they had just been given back — so
every one of these tests asserts the flag as well as the rows, and a mutation that deletes the rows
with raw SQL instead fails all three.

**Passkeys are the half that closes a real gap rather than saving space.** A passkey authenticates
through `LoginController::actionPasskey()`, which goes to `Passkey\ManagerService` and
`LoginPlugin::completeLogin()` and **never reaches the `Login::validate()` this add-on replaces**.
Access was still refused — XenForo turns a disabled account away at
`XF\Pub\Controller\AbstractController:221` — but with core's generic disabled page instead of
this add-on's *site is archived* message, and the credential stayed in the database for as long as
the forum did. Two of the add-on's own admin phrases already promised that an archived account
cannot log in "using any authentication mechanism (passwords, connected accounts, passkeys, etc)",
which the code did not do until this was added.

**A forum archived by an earlier version still holds all of it, and the fix is to run the command
again rather than an upgrade step.** `Job\ArchiveUsers::getNextIds()` walks every row in `xf_user`
rather than only the unarchived ones, so `archive:archive-all-users` reaches an account that is
already archived, and `archiveUser()` on one of those clears the credentials while creating no
second archive row. **That last part is what makes the advice safe**: `previous_state` is recorded
only when there is no row, so a re-run cannot overwrite a `valid` with the `disabled` the account
now is, which would turn every later restore into a no-op.
`test_archiving_an_already_archived_account_clears_credentials_and_keeps_its_record` is that
assertion, with the state set explicitly because a borrowed account can be `disabled` to begin with.

**Connected accounts are the one credential left in place**, and that is open rather than settled:
`xf_user_connected_account` survives an archive run, so a Google or Facebook login still
authenticates and meets the same generic page. Removing them is a larger decision than it looks,
because such an account is usually passwordless, so a restore that has nothing to reset leaves an
owner with no route back in at all.

**A development forum is likely to hold no two-step rows and no passkeys at all**, and this one
holds none — all four tables at 0, measured on 2026-10-12. So the tests build their fixtures
through XenForo's own repositories inside the transaction, and the same warning applies as to the
config keys above: a development forum cannot show you what it does not have. A seeded sandbox can
— Seeder's `withTwoStep()` and `withPasskey()` make both.

**The passkey half is guarded on `\XF::$versionId >= 2030000` and must stay guarded.** The table,
the entity and the repository are all new in XF 2.3, and this add-on's floor is 2.2, where
resolving any of them is a fatal part-way through an archive run rather than an empty result. The
two-step half needs no guard: `disableTfaForUser()` has the same name and body in both versions.

## A bulk run that stops stays in the job queue, and the Admin CP offers to finish it

**Both bulk commands enqueue through `enqueueUnique()`, which defaults to `manual_execute = 1`**,
so a run that stops leaves a row in `xf_job` that nothing automatic touches — not cron, not the
job runner on a web request, and not `xf:run-jobs`, all of which select `manual_execute = 0`.
Three things do reach it: `--resume`, `xf:run-jobs --manual-only`, and XenForo's own notice on the
Admin CP index and on `Rebuild Caches`, whose *continue running them* link is `tools/run-job` with
no `only` parameter and therefore **runs every pending manual job**, with no per-job confirmation
and nothing naming what they are. So a run abandoned because it went wrong is restarted by an
administrator clearing what reads as a stale cache rebuild.

**The Admin CP and the CLI use different unique keys for the same job**, so neither can resume the
other's run and both rows can exist at once. `AbstractRebuildCommand` builds
`xfRebuildJob-archive-all-users`; `Admin\Controller\Archive` enqueues `archiveUsers`. There are
three keys rather than four because the Admin CP has no bulk restore.

**Whether the row carries a protected list is what decides where a resume gets its list from.**
`AbstractRebuildJob::run()` ends in `resume()`, which carries the data `setupData()` resolved —
the list included — and the manager writes that to `execute_data`. So any run that got through one
pass holds a snapshot, and since `setupData()` fills the list only when it is empty, a resume
archives by that snapshot rather than by the current `config.php`. **Re-running the command is the
clean path**: `Manager::_enqueue()` is an upsert with `execute_data = VALUES(execute_data)`, so it
discards the stored list and the stored position together.

Measured on 2026-10-11 by constructing the job both ways without running it:

```text
as freshly enqueued by the CLI     protectedUsers=[1]      start=0
as stored after one resume pass    protectedUsers=[1,999]  start=42
```

**A run that fails on its first pass stores nothing**, which is the case a small forum produces
and a real one never does: the `manual_execute` branch of `Manager::runJobInternal()` updates only
`trigger_date` and `last_run_date` before rethrowing, so `execute_data` is still what the command
enqueued and a resume recomputes. Both forms exist in the wild and they behave differently, which
is why `archive:validate`'s `pending run` row prints the stored count.

**The snapshot is deliberate rather than a defect** — within one run the list should be stable,
and `--resume` means resume *this* run — so nothing tries to correct it. What was missing is that
a stopped run was invisible: the Admin CP notice names neither the add-on nor the job, and
`validate` said nothing. Paired with the `archiving` row it is now the whole diagnosis —
incomplete **with** a pending run means resume it, incomplete **without** one means start again.

## Installing this add-on stops mail to ordinary users

**Both recipient setters are extended** — `setToUser()`, which is how XenForo addresses mail to a
member, and `setTo()`, which takes an address and is how an add-on composing its own recipient
list reaches one. Until 2.2.0 only the first was covered, so a newsletter or digest built that way
reached archived members untouched. Neither checks whether the user is archived: the moment the
add-on is active, every normal member is unmailable, which is the point on an archived site and a
foot-gun anywhere else.

`Mail\Restriction` holds the decision because the extension class is declared twice (below), and a
rule written in both bodies is a rule that will eventually differ between them. It resolves an
address through the `XF:User` finder, and **`xf_user.email` is not a unique index**, so one
unmailable account among several at that address refuses the send.

**The refusal sets `$this->setupError` directly, and the server error log entry it produces is the
point of the design, not a side effect.** XenForo's `send()` and `queue()` write that error to the
log, one entry per attempt, naming the account — and on an archived forum an attempt to mail a
member is an anomaly worth investigating, because nothing should be doing it. Do not route it
through core's `applySetupError()`, which **throws** when `debugMode` is on: the refusal would then
behave differently on a development install from a production one, and a blocked newsletter would
become a fatal rather than a log entry.

**`$config['archiveSite']['allowMail']` switches the restriction off entirely**, for a development
or staging copy of an archived forum that still has to send mail. It is config rather than an
option for the same reason the protected list is, and more so: re-enabling mail to every member of
an archived forum is not something that should be a checkbox. `Config\AllowMail::enabled()` is
checked first in both version branches of the extension, so a truthy flag means the method is
stock XF.

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

  **The "archived" message is only for an account that really is archived** — which means **an
  archive row**, plus no usable password, and neither protected nor a super admin. Every other
  failure takes core's path, which records the attempt. That is a security property, not a wording
  choice: until 2.0.2 the message went to any unprotected member, returned before
  `recordFailedAttempt()`, and so switched XenForo's brute-force limit off for every ordinary
  member who still had a password. An archived account has no password to guess, which is the only
  reason its failure can skip the count.

  **`XF:NoPassword` is not a synonym for archived, and testing for it was the same bug a second
  time.** XenForo creates passwordless accounts itself — `RegisterController` calls
  `setNoPassword()` for a registration through a connected account — and another add-on can have
  its own passwordless scheme. Until 2.1.0 both this extension and `PasswordReset` keyed on the
  scheme, so those members were told the site was archived and were exempt from the throttle, and
  could not request the password that core's own `AbstractAuth::hasPassword()` docblock says is
  how they move off that scheme. Both now key on `$user->ArchivedUser`, with
  `getAuthenticationHandler()->hasPassword()` as the "no password to guess" half, which covers
  another add-on's scheme as well as core's.
- **`XF\Mail\Mail`** declares the class twice in one file, inside an `if (\XF::$versionId >=
  2030000)` branch, because `setToUser()` gained a `: \XF\Mail\Mail` return type in XF 2.3 and an
  incompatible signature is a fatal, not a warning. Any further change to that method has to be
  made in both branches.

**Those two extensions are registered at `execute_order` 1, and that is load-bearing.** XenForo
applies extensions in `execute_order` then `to_class` order and each one wraps the previous, so the
last applied is outermost and decides whether anything below it runs — proven on this install by
resolving a two-extension chain and reading `class_parents()`. At the default order of 10 the tie
breaks alphabetically, which put this add-on **outside** any sibling whose namespace sorts earlier
and silently discarded its version of the method. Innermost is the honest place for an extension
that cannot call its parent: a sibling at the default order now wraps this add-on, and if it calls
the parent as it should, both apply. The other four extensions call the parent and stay at 10.

`tests/Isolated/ExtensionChainTest.php` proves the composing with a stand-in sibling. **It is
outside both testsuites in `phpunit.xml` on purpose** — an extension added during a test is
forgotten at teardown, and the next test then resolves that class with *no* extension at all,
including this add-on's own. Left in the normal suite it passed and took 47 later tests down with
it.

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
birthdays the add-on exists to hide, and nothing raises an error. **The log's `status` column still
says `ok`**: it reports whether the modification ran, not whether it matched anything.

**XenForo does surface it, though, and this file said otherwise until 2.2.1.**
`XF\Entity\TemplateModification::getLogSummary()` counts a row with `status === 'ok'` and
`apply_count === 0` as `not_found`, and the core `template_modification_list` admin template
renders that count — so a dead modification shows as *not found* in
`Admin CP > Appearance > Template modifications`. What `archive:validate` adds is a `[fail]` and an
exit code, for a forum nobody is looking at; it is not the only evidence there is.

The query behind both is worth knowing, since `xf_template_modification_log` holds one row per
template per modification:

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

## `archive:config` and `archive:validate` say what the forum is set up to do, and prove it

The two self-describing commands, to the convention the `xenforo-addon-validate` skill carries.
They exist here because **the settings that matter most are not on the options page**: the
protected list and the mail switch live in `config.php`, so the control panel cannot show either,
and the template modifications fail silently by design.

- **`archive:config` reads and prints.** Which `config.php` key supplied the protected list — the
  nested one or the deprecated flat one — how many accounts it protects, whether mail to members
  is allowed, and the four options. It writes nothing and sends nothing, and a test asserts that.
- **`archive:validate` exercises it.** An empty protected list is a `[fail]`, because an archive
  run would then leave nobody able to log in and the ACP's own button does not guard against it.
  `allowMail` being on is a `[warn]`.
- **A half-done `config.php` rename is a `[warn]`; using the deprecated key is a skip.** The
  distinction is the design, not an accident of implementation: a forum reading the flat key is
  configured correctly and always will be, so warning about it would make `--strict` exit 2 on
  every forum that has not renamed it — which is precisely where a monitor still needs the flag
  to mean something. A skip is reported for whoever is looking and never counted. Both directions
  have a test that fails if they swap.
- **It also reports how far the archiving has got, which is state rather than configuration.** An
  account that is neither archived nor protected can still log in, so archiving set up and not
  finished is a `[warn]` — *not started* where nothing has been archived, *incomplete* where some
  has, because those want different actions. A partially archived account warns for as long as it
  exists, since nothing can restore it. **A forum running only the guest-hiding half warns
  permanently, and that is correct**: the archiving half is switched on and doing nothing. It stays
  exit 0 without `--strict`, so it blocks no gate.
- **A bulk run left half-finished in the job queue is a `[warn]` too**, naming the entry point and
  whether the row stored a protected list — which is what decides resume against re-run. XenForo
  does say a manual job is pending, on the Admin CP index, but it names neither the add-on nor the
  job, and its link runs every pending manual job at once. See *A bulk run that stops stays in the
  job queue* for the mechanism.
- **Most of what `validate` reports is also somewhere in the control panel**, and that is not an
  argument against it: the point is an exit code for a forum nobody is looking at. Do not describe
  a check here as the only evidence of something without reading what core already shows — the
  modification check was documented that way and it was wrong.
- **The exit contract is 0/1, with `--strict` for 2** on warnings only, and `Cli\RendersReport`'s
  `checkExitCode()` is the whole implementation. `--unattended` is accepted and does nothing, since
  neither command writes nor sends; it exists so one command line works across add-ons.
- **`Cli\RendersReport` is a copy of `Hampel/Monolog`'s**, with three typed properties and the
  `void` return types removed because this add-on declares XF 2.2 and therefore PHP 7.0, where
  Monolog declares 7.4. **Fix the original and take it again**; do not improve the copy here.
- **Every `\XF::logError()` in the add-on begins with `ArchiveSite: `**, and a test fails if one
  does not. A log mirror skips its own rows by that prefix, and a row without one is alerted on
  through the handler that just failed.
- **Resolve a class extension with `extension()->extendClass($fromClass)`, never
  `class_exists($toClass)`.** An extension class extends an `XFCP_` proxy that XenForo declares
  only while resolving that extension, so autoloading it directly throws `Class ... XFCP_...
  not found` — which is a fault in the check, not in the add-on. That cost a false `[fail]` here.

## Uninstalling drops the table, and that is deliberate

`Setup::uninstall()` drops `xf_archivesite_user`, so every recorded `previous_state` goes with it
while the accounts stay `disabled` and passwordless. There is no guard and no warning, which reads
like an oversight and is not one: **an archived site generally stays archived.** Restoring users is
an extraordinary step rather than an expected one, so the normal end state of this add-on is the
one uninstalling leaves behind, and a warning about losing the ability to reverse something nobody
is planning to reverse would be noise.

If a forum is going to restore its users, it does that **before** uninstalling —
`archive:restore-all-users`, or the ACP page, while the table still holds what it needs.

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
