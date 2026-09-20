# Testing Archive Site

What this add-on touches, what breaks quietly, and which checks a machine can settle on its own.
There is no PHPUnit suite yet, so *Needs a human* is longer here than it should be — see the note
at the end of *Automated*.

## Surfaces

### Class extensions

| Extends | Does |
|---|---|
| `XF\Entity\User` | adds the `ArchivedUser` relation; nothing else |
| `XF\Finder\User` | rewrites `isValidUser()` so archived users still count as valid, keeping member counts and statistics honest |
| `XF\Mail\Mail` | `setToUser()` refuses any recipient who is not a super admin or protected, by setting `setupError` rather than throwing |
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

**The protected user list comes from `config.php`, not from an option.**
`$config['archiveSiteProtectedUsers']`, plus every super admin, is the entire set of accounts an
archive run leaves alone. Both `archive-all-users` and `restore-all-users` abort when that list
resolves empty, and that guard is the only thing between a run and a forum nobody can log in to.
Anything that changes how the list is read deserves a deliberate test of the empty case.

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

> There is no PHPUnit suite. With one, several checks below could move up here — a controller's
> permission refusal and template modifications actually applying are both assertable by the test
> framework. Until then they stay manual.

## Needs a human

- **That a logged-in member still sees everything guests do not.** Every guest-hiding
  modification is verified only in the negative above; nothing confirms the positive case.
- **The options render as intended.** That the home page message appears on the forum home; that
  the content end message appears below both the thread list and the thread content; that setting
  a URL makes it a link; that the new-window option opens it in one.
- **`Admin CP > Users > Archive Site > List Protected Users` shows the super admins.** Then add
  `$config['archiveSiteProtectedUsers'] = [<user ids>];` and confirm those users appear too.
- **An archive run does what it says.** From `Archive Users`, confirm the protected and active
  counts look right, tick the confirmation box, run it, then check that the previously active
  users are archived, that **no protected user changed**, and that every archived user in
  `xf_user_authenticate` has the `XF:NoPassword` scheme and no password data.
- **A restore run**, and that a restored user must complete a password reset before logging in.
- **The gallery control is hidden on a forum with XFMG installed.** The modification cannot apply
  at all without it, so an install lacking XFMG proves nothing either way.
- **Appearance and judgement** — whether the end message reads well in place, and whether the
  archived forum looks coherent rather than merely stripped.
