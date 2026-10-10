# Testing Archive Site

What this add-on touches, what breaks quietly, and which checks a machine can settle on its own.
The PHPUnit suite settles most of the last; *Automated* says what it covers and what it does not.

## Surfaces

### Class extensions

| Extends | Does |
|---|---|
| `XF\Entity\User` | adds the `ArchivedUser` relation; nothing else |
| `XF\Finder\User` | rewrites `isValidUser()` so archived users still count as valid, keeping member counts and statistics honest |
| `XF\Mail\Mail` | `setToUser()` **and** `setTo()` refuse any recipient who is not a super admin or protected, by setting `setupError` rather than throwing - unless `allowMail` is set. The decision lives in `Mail\Restriction`, shared by both version branches |
| `XF\Service\User\Login` | **replaces** `validate()` outright, so a non-protected user gets an "archived" message instead of "incorrect password" |
| `XF\Service\User\PasswordReset` | extends `canTriggerConfirmation()` to refuse a reset for an account this add-on has archived - keyed on the archive row, since core creates passwordless accounts of its own |
| `XF\Widget\MembersOnline` | `render()` returns empty for guests |

All six name the pre-2.3 core class spellings, which is what the declared XenForo 2.2 floor
requires. XenForo 2.3 aliases those names forward; 2.2 has no such mechanism, so adopting the
2.3 spellings would be a floor change.

### Template modifications

Fourteen, all keyed on `$xf.visitor.user_id` except where noted.

| Template | Key suffix | Does |
|---|---|---|
| `PAGE_CONTAINER` | `PageContainerWhatsNew` | hides the What's New nav link from guests — `preg_replace` |
| `forum_overview_wrapper` | `ForumOverviewWrapper` | adds the home page message block |
| `forum_overview_wrapper` | `PageForumOverviewWrapper` | hides the page actions from guests |
| `forum_view` | `ForumViewArchived` | inserts the content end message — **execution order 9** |
| `forum_view` | `ForumView` | neutralises the "log in to post" condition with ` AND false` — **order 10** |
| `member_about` | `Birthday` | hides the birthday from guests |
| `member_about` | `MemberAboutHideFollowers` | hides followers from guests |
| `member_about` | `MemberAboutHideFollowing` | hides following from guests |
| `thread_list_macros` | `ThreadListMacros` | hides the lock symbol from guests |
| `thread_view` | `ThreadView` | removes the "log in or register to post" button |
| `thread_view` | `ThreadViewNotOpen` | hides "not open for further replies" from guests |
| `thread_view` | `ThreadViewSiteArchived` | inserts the content end message |
| `widget_forum_statistics` | `WidgetForumStatistics` | hides the latest member from guests |
| `xfmg_media_view_macros` | `XFMGMediaViewMacros` | hides the gallery BB code control from guests — `preg_replace` |

The last one targets XenForo Media Gallery. Without XFMG installed the template does not exist
and the modification simply never applies, which is correct rather than a failure.

### Other artifacts

- **Four options**, all under `Admin CP > Options > Archive Site`: home page message, content end
  message, its URL, and whether that URL opens in a new window.
- **One table**, `xf_archivesite_user`, holding each archived account's previous `user_state` and
  authentication scheme, with an `ArchivedUser` entity over it.
- **Admin navigation and route** under `Admin CP > Users > Archive Site` — protected, active,
  archived and partially archived user lists, plus the archive action.
- **Six CLI commands** in the `archive:` namespace: four that archive and restore, plus
  `archive:config` and `archive:validate`, which report what the forum is set up to do and prove
  it. Two jobs sit behind the bulk pair.
- **One code event listener**, answering `hampel_admin_api_redaction` to declare that this add-on
  has no secrets, so that an Admin API can show its settings rather than withholding them. It is
  not a dependency: an event no installed add-on defines is never fired.

## Fragile points

**The settings come from `config.php`, not from options.** `$config['archiveSite']` holds
`protectedUsers` and `allowMail`; the flat `$config['archiveSiteProtectedUsers']` of 2.0.2 and
earlier is deprecated, still honoured, and overridden by the nested key wherever both are set. A
hand-written `config.php` can hold anything under that key, so anything reading it has to tolerate
a scalar or a string where an array was meant.

**The protected user list is the dangerous one.** That array, plus every super admin, is the
entire set of accounts an archive run leaves alone. Both `archive-all-users` and
`restore-all-users` abort when that list resolves empty, and that guard is the only thing between
a run and a forum nobody can log in to. Anything that changes how the list is read deserves a
deliberate test of the empty case.

**Template modifications match stock markup, so a XenForo upgrade breaks them silently.** Twelve
use an exact `str_replace` against text copied from core templates, tabs included. When a core
template is reformatted the find stops matching, the page renders as stock, and **the log's
`status` column still says `ok`** — it reports whether the modification ran, not whether it
matched. Two modifications had been dead since the 2.3 upgrade before anyone noticed.

**XenForo counts it as `not_found` in the admin list**, from `status = 'ok'` with a zero apply
count, so `Admin CP > Appearance > Template modifications` does show it to anyone who looks.
`archive:validate` turns that into an exit code instead, which is what a forum nobody is looking
at needs.

**`Login::validate()` is a copy of core, not a call through it.** It has to replace the
authentication failure rather than follow it. Re-read the core method after any XenForo upgrade
and port whatever changed.

**`XF\Mail\Mail` declares its class twice in one file**, behind `\XF::$versionId >= 2030000`,
because `setToUser()` gained a return type in 2.3 and an incompatible signature is a fatal rather
than a warning. Any change to that method has to be made in **both** branches.

**Installing the add-on stops mail to every ordinary member**, not only to archived ones. That is
the intent on an archived forum and a trap anywhere else.

**Archiving and restoring are not inverses.** Restoring resets the password to a random value, so
a restored user cannot log in until they complete a password reset. The original hash is gone the
moment the account is archived, by design. An account that had **no** password keeps none, which
is what the recorded scheme is for - anything that changes how archiving records state deserves a
test of that case, since the account cannot tell you it was wronged.

## Automated

Run from the add-on directory. `cmd.php` resolves the install from its own location.

**The suite is the first thing to run.** It runs against the development forum's real database,
inside transactions that roll back, so it archives and restores accounts without leaving a trace.

```bash
composer install
vendor/bin/phpunit
```

What it covers, by class:

| test | settles |
|---|---|
| `Config\ProtectedUsersTest` | the list is read from either config key, including a scalar and an empty value; the nested key overrides the deprecated flat one, an empty nested list included, and a settings key that is not an array is ignored. Also what a half-done rename discards: a present-but-empty `archiveSite` falls through to the deprecated key and discards nothing, an empty or narrower `protectedUsers` discards the difference, keys that agree in either order discard nothing, and ids are compared as integers |
| `XF\Finder\ExtensionOrderTest` | the two extensions that replace their core method are registered inside a default-order sibling, and the four that call the parent are not |
| `ListenerTest` | the secret declaration names only this add-on, declares no secret options, claims both config keys the code reads, and fails if an option is added whose id reads like a credential |
| `Config\AllowMailTest` | the mail flag is off unless it is set truthy, whatever else the settings key holds |
| `Repository\ArchiveUsersTest` | the finders never include a protected user, and every other account is in exactly one of them - including one with no authentication row, a passwordless member who is not shut (active), a shut passwordless account with no archive row (partially archived) and an archived account that has a password (archived); archiving and restoring change exactly what they should, refuse protected users and super admins, leave an account that never had a password without one, and touch nothing on an account nobody archived |
| `Admin\Controller\ArchiveTest` | each page's total agrees with its finder and its rows are one page of it; the archive button, dispatched as a real `POST` through its guards, refuses without its confirmation, refuses a `GET`, refuses a request carrying no CSRF token, and otherwise queues one archive job carrying the protected list; an ordinary admin reaches none of it |
| `Job\ArchiveUsersTest` | the archive and restore jobs run over every account, one per batch as well as in bulk: every unprotected account is archived, including one with no authentication row, no protected one is touched, and restoring puts each back. A run with nothing protected archives nobody, logs the refusal and completes - where it used to fail on a malformed `NOT IN ()` |
| `TemplateModificationsTest` | every modification applies, read from the forum rather than a list, so a new one is covered automatically |
| `XF\Mail\MailTest` | mail to an ordinary member is refused by either setter, and to a super admin or protected user is not; an address no account holds is allowed; an address shared with an unmailable account is refused; a blocked member is recorded once, not twice; `allowMail` lets the same member through by either route, and `false` does not |
| `XF\Widget\MembersOnlineTest` | the widget is hidden from guests and still shown to members |
| `XF\Service\User\LoginTest` | only an archived account is told the site is archived; every other failure - a member who still has a password, a protected account, and a passwordless account that was never archived - gets core's message and is counted toward the brute-force limit; the core behaviour copied around it still holds |
| `XF\Service\User\PasswordResetTest` | an archived user cannot start a reset; a user with a password, and a passwordless user who was never archived, are both left to core |
| `XF\Finder\UserTest` | an archived user counts as valid only if they were valid before archiving |
| `Cli\ReportCommandsTest` | `archive:config` names the key the protected list came from, reports an unset list as not set, and changes nothing; `archive:validate` fails on an empty protected list and on a modification applying nowhere, warns on the mail switch, a disabled modification, an id that is not a user, unarchived accounts (saying *not started* or *incomplete*) and accounts that cannot be restored, reports a finished forum as complete, warns when the newer `config.php` key names fewer protected ids than the deprecated one and names them, treats the deprecated key on its own as a skip rather than a warning, exits 2 for a warning only under `--strict` and 0 when there is nothing to warn about, and turns a check that throws into one `[fail]` without ending the run; no log call lacks the add-on prefix |
| `Cli\CommandsTest` | all four command classes are ones XenForo can load, since it loads every add-on's to list them; an unknown user, a super admin and a config-protected user are each refused; a run that does not answer the confirmation archives nobody, and one that answers it archives and restores the named account; and both bulk commands abort, log an error and queue nothing when the protected list is empty |
| `TemplateRenderingTest` | the modified templates, rendered: the home message and its empty case, the end message below a thread list and below a thread, its link and new-window options, and three things hidden from guests but shown to members - the latest member, the not-open notice and a birthday |

**An empty protected list cannot be set up through job data.** `setupData()` treats an empty list
as "not supplied yet" and refills it from the repository, so a test that passes
`['protectedUsers' => []]` gets a normal run. A first attempt at the refusal tests did that and
archived all 2011 accounts on the development forum, inside the transaction that rolled them back,
while asserting that nothing had happened. Mock `protectedUserIds()` instead, which is also what
the real case looks like: a forum with no super admin and nothing in `config.php`.

**A render on a forum shared with other add-ons logs errors that are nobody's fault, so the
rendering tests do not assert on all of them.** Template modifications are compiled into the
install's shared template cache, while the suite's isolation deliberately loads no other add-on's
class extensions — so a sibling whose modification reads a column its own entity extension
declares logs a warning on every render here, while the same page is faultless in a browser.
`TemplateRenderingTest` therefore asserts only on errors naming something this add-on inserted: an
`$xf.options.hampelArchiveSite` option, the `ArchivedUser` relation, or one of its own templates.
**The blanket assertion is the stronger check and wants a forum with only this add-on installed**,
which is what a sandbox is for — worth doing when the rendering half changes materially.

**`tests/Isolated/` is not collected by a normal run, and has to be asked for by path:**

```bash
vendor/bin/phpunit tests/Isolated/ExtensionChainTest.php
```

It holds tests that are only valid in a process where nothing has resolved the class they extend.
An extension added during a test is forgotten at teardown, and the next test then resolves that
class with no extension at all - this add-on's own included - so such a test cannot share a run
with anything. Worth running by hand whenever either overriding extension changes.

**Every test named for a regression fails against that regression** — each was run with the
behaviour it names removed. That is the standard to keep when adding one.

**The suite needs the test framework at 5.18 or later** — `composer.json` requires `^5.18`.
Before 5.0 isolation dropped the `Login`, `PasswordReset` and `Finder\User` extensions, so their
tests would silently exercise core; `runJobToCompletion()` for the jobs arrived in 5.3, a rendered
template's `$xf` in 5.4, `runConsoleCommand()` in 5.10, a CSRF token on a non-GET `dispatch()` in
5.15, `assertConsoleCommandsLoad()` in 5.17, and a command's answers in 5.18. Every rendering
test fails on 5.3, because without `$xf` the modifications cannot read the visitor or the options.

The remaining checks are the ones a unit suite cannot reach:

```bash
php ../../../../cmd.php xf-dev:import --addon=Hampel/ArchiveSite
php ../../../../cmd.php xf-dev:unused-phrase-finder --addon Hampel/ArchiveSite --show-unknown
```

The phrase finder's list is trustworthy for this add-on: every lookup is a literal
`\XF::phrase('name')`, which is the only shape its scanner can see. Confirm that still holds
before believing it — this should print nothing:

```bash
grep -rnE "\bphrase(Deferred|dException)?\(" . --include='*.php' \
  | grep -vE "XF::(phrase|phraseDeferred|phrasedException)\(\s*['\"][^'\"[:space:]]+['\"]\s*[),]"
```

**Every template modification still applies, and `archive:validate` is how you find out.** Any
modification applying nowhere is a `[fail]` row naming it, so the check after a XenForo upgrade is
one command:

```bash
php ../../../../cmd.php archive:validate            # 0 clean, 1 something failed
php ../../../../cmd.php archive:validate --strict   # 2 when there are warnings and no failures
```

It also proves the protected list resolves to real accounts, the archive table is present and the
six class extensions apply, and it reports how far the archiving has got: an account that is
neither archived nor protected can still log in, so archiving set up and not finished is a warning
saying *not started* or *incomplete*, and so is an account nothing can restore.
`archive:config` prints the settings and which key each came from, and writes nothing.

The query the command replaced is still worth knowing, since it shows the counts themselves:

```sql
SELECT m.modification_key, COALESCE(SUM(l.apply_count), 0) AS applied
FROM xf_template_modification m
LEFT JOIN xf_template_modification_log l ON (l.modification_id = m.modification_id)
WHERE m.addon_id = 'Hampel/ArchiveSite'
GROUP BY m.modification_id;
```

**Artifacts and metadata agree in both directions** — a file with no `_metadata.json` entry is
never reported as changed, so it goes stale invisibly:

```bash
python3 - <<'EOF'
import json, os, glob
for meta in sorted(glob.glob('_output/*/_metadata.json')):
    d = os.path.dirname(meta)
    keys = set(json.load(open(meta)))
    files = {os.path.relpath(os.path.join(r, f), d)
             for r, _, fs in os.walk(d) for f in fs if f != '_metadata.json'}
    if files != keys:
        print(d, '| file, no entry:', sorted(files - keys), '| entry, no file:', sorted(keys - files))
EOF
```

**Guests do not see what the modifications hide.** Fetch a page as an anonymous visitor and grep
the HTML — but always assert something positive alongside the absence, because an empty response
and a correct one both contain zero matches:

```bash
out=$(curl -sL -H "Host: <your dev host>" "http://127.0.0.1/index.php")
echo "rendered:  $(echo "$out" | grep -c 'p-navgroup')"          # must be non-zero
echo "whatsnew:  $(echo "$out" | grep -c 'p-navgroup-link--whatsnew')"   # must be zero
```

**Class extension targets resolve**, and the find strings match the core templates of *every*
XenForo version the add-on declares support for — the second is what makes the declared floor
evidence rather than assumption. Both are worth re-running whenever the floor moves.

## Needs a human

- **That a logged-in member still sees the rest of what guests do not.** The suite renders the
  latest-member widget, the not-open notice and the birthday for both, but not followers and
  following on a profile, the lock icon in a thread list, the forum home's page actions, or the
  What's New link for a member - the guest half of that one is the curl check above.
- **The gallery control is hidden on a forum with XFMG installed.** The modification cannot apply
  at all without it, so an install lacking XFMG proves nothing either way.
- **That an upgrade removes the files the new version dropped.** `Setup::postUpgrade()` arms
  `XF\Job\FileCleanUp`, and it runs from the job queue *after* the upgrade command returns, so an
  upgrade that reports success proves nothing about it. Run the queue, then check the add-on
  directory. **Do this on a disposable instance only** — the job deletes everything the new
  release's `hashes.json` does not list, which on a working copy includes `.git` and both Claude
  files.
- **Appearance and judgement** — whether the end message reads well in place, and whether the
  archived forum looks coherent rather than merely stripped.
