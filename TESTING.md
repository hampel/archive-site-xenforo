# Testing Archive Site

What this add-on touches, what breaks quietly, and which checks a machine can settle on its own.
The PHPUnit suite settles most of the last; *Automated* says what it covers and what it does not.

## Surfaces

### Class extensions

| Extends | Does |
|---|---|
| `XF\Entity\User` | adds the `ArchivedUser` relation; nothing else |
| `XF\Finder\User` | rewrites `isValidUser()` so archived users still count as valid, keeping member counts and statistics honest |
| `XF\Mail\Mail` | `setToUser()` refuses any recipient who is not a super admin or protected, by setting `setupError` rather than throwing - unless `allowMail` is set |
| `XF\Service\User\Login` | **replaces** `validate()` outright, so a non-protected user gets an "archived" message instead of "incorrect password" |
| `XF\Service\User\PasswordReset` | extends `canTriggerConfirmation()` to refuse users whose auth is `XF:NoPassword` |
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
- **One table**, `xf_archivesite_user`, holding each archived user's previous `user_state`, with
  an `ArchivedUser` entity over it.
- **Admin navigation and route** under `Admin CP > Users > Archive Site` — protected, active,
  archived and partially archived user lists, plus the archive action.
- **Four CLI commands** in the `archive:` namespace, and two jobs behind them.

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
template is reformatted the find stops matching, the page renders as stock, and **the status
column still says `ok`** — it reports whether the modification ran, not whether it matched. Two
modifications had been dead since the 2.3 upgrade before anyone noticed. The apply-count query
under *Automated* is the only thing that reveals this.

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
moment the account is archived, by design.

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
| `Config\ProtectedUsersTest` | the list is read from either config key, including a scalar and an empty value; the nested key overrides the deprecated flat one, an empty nested list included, and a settings key that is not an array is ignored |
| `Config\AllowMailTest` | the mail flag is off unless it is set truthy, whatever else the settings key holds |
| `Repository\ArchiveUsersTest` | the finders never include a protected user, and every other account is in exactly one of them - including one with no authentication row; archiving and restoring change exactly what they should, and refuse protected users and super admins |
| `Admin\Controller\ArchiveTest` | each page's total agrees with its finder and its rows are one page of it; the archive button, dispatched as a real `POST` through its guards, refuses without its confirmation, refuses a `GET`, refuses a request carrying no CSRF token, and otherwise queues one archive job carrying the protected list; an ordinary admin reaches none of it |
| `Job\ArchiveUsersTest` | the archive and restore jobs run over every account, one per batch as well as in bulk: every unprotected account is archived, including one with no authentication row, no protected one is touched, and restoring puts each back |
| `TemplateModificationsTest` | every modification applies, read from the forum rather than a list, so a new one is covered automatically |
| `XF\Mail\MailTest` | mail to an ordinary member is refused, and to a super admin or protected user is not; `allowMail` lets the same member through, and `false` does not |
| `XF\Widget\MembersOnlineTest` | the widget is hidden from guests and still shown to members |
| `XF\Service\User\LoginTest` | only an archived account is told the site is archived; every other failure - a member who still has a password, a protected account, with or without a password - gets core's message and is counted toward the brute-force limit; the core behaviour copied around it still holds |
| `XF\Service\User\PasswordResetTest` | an archived user cannot start a reset; a user with a password is left to core |
| `XF\Finder\UserTest` | an archived user counts as valid only if they were valid before archiving |
| `Cli\CommandsTest` | all four command classes are ones XenForo can load, since it loads every add-on's to list them; an unknown user, a super admin and a config-protected user are each refused; a run that does not answer the confirmation archives nobody; and both bulk commands abort, log an error and queue nothing when the protected list is empty |
| `TemplateRenderingTest` | the modified templates, rendered: the home message and its empty case, the end message below a thread list and below a thread, its link and new-window options, and three things hidden from guests but shown to members - the latest member, the not-open notice and a birthday |

**A render on a forum shared with other add-ons logs errors that are nobody's fault, so the
rendering tests do not assert on all of them.** Template modifications are compiled into the
install's shared template cache, while the suite's isolation deliberately loads no other add-on's
class extensions — so a sibling whose modification reads a column its own entity extension
declares logs a warning on every render here, while the same page is faultless in a browser.
`TemplateRenderingTest` therefore asserts only on errors naming something this add-on inserted: an
`$xf.options.hampelArchiveSite` option, the `ArchivedUser` relation, or one of its own templates.
**The blanket assertion is the stronger check and wants a forum with only this add-on installed**,
which is what a sandbox is for — worth doing when the rendering half changes materially.

**Every test named for a regression fails against that regression** — each was run with the
behaviour it names removed. That is the standard to keep when adding one.

**The suite needs the test framework at 5.17 or later** — `composer.json` requires `^5.17`.
Before 5.0 isolation dropped the `Login`, `PasswordReset` and `Finder\User` extensions, so their
tests would silently exercise core; `runJobToCompletion()` for the jobs arrived in 5.3, a rendered
template's `$xf` in 5.4, `runConsoleCommand()` in 5.10, a CSRF token on a non-GET `dispatch()` in
5.15, and `assertConsoleCommandsLoad()` in 5.17. Every rendering test fails on 5.3, because without
`$xf` the modifications cannot read the visitor or the options.

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

**Every template modification still applies.** Any row at 0 is a dead modification, and nothing
else reports it. Run this after every XenForo upgrade:

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
