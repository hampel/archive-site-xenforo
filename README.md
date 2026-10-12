# Archive Site for XenForo 2.x

This XenForo 2.x addon lets you put a site into archive mode and hide unnecessary features from guests

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

* [Addon: Archive Site](https://xenforo.com/community/resources/archive-site.7824/)
* [Discussion and support: Archive Site](https://xenforo.com/community/threads/archive-site.180884/)

## Requirements

Requires XenForo 2.2.0 or higher. Tested on XenForo 2.2 and 2.3.

## Commands

```bash
php cmd.php archive:config                 # what this forum is set up to archive and hide
php cmd.php archive:validate               # whether it can actually do it
php cmd.php archive:archive-all-users      # archive every unprotected account, after confirming
php cmd.php archive:restore-all-users      # the inverse
php cmd.php archive:archive-user <user>    # one account, by id, name or email
php cmd.php archive:restore-user <user>
```

`archive:config` prints every setting and which `config.php` key or option it came from, and
changes nothing. `archive:validate` proves the forum can do it: the protected list resolves to real
accounts, the archive table is there, the class extensions apply, and every template modification
still matches the template it targets — which is what breaks quietly when XenForo changes a core
template.

**It also reports how far the archiving has got**, because that is the add-on's own state rather
than its configuration: an account that is neither archived nor protected can still log in, so a
forum that set archiving up and never ran it, or whose run stopped part-way, gets a warning saying
which of those it is. So does an account with no password and no archive row, which cannot be
restored because nothing recorded what its state was.

**And it reports a bulk run left half-finished**, which is what tells you which of those to do.
A stopped `archive-all-users` or `restore-all-users` stays in XenForo's job queue as a manual job:
nothing runs it automatically, but the Admin CP offers to continue it along with any other pending
rebuild. **Resuming it archives by the protected list it recorded when it started**, while
re-running the command reads `config.php` afresh — so the warning says which of the two a resume
would use, and how many accounts that list holds.

Its exit code is a contract:

| | all fine | warnings only | any failure |
|---|---|---|---|
| `archive:validate` | 0 | 0 | 1 |
| `archive:validate --strict` | 0 | 2 | 1 |

So `archive:validate && next-step` carries on past a warning, and `--strict` is for a monitor that
wants to hear about one. **1 always means failed** — this is not the monitoring-plugin numbering,
where 1 is a warning. `--unattended` is accepted and does nothing, since neither command writes or
sends anything; it is there so that one command line works across add-ons.

## Archiving an account

Archiving disables the account and removes every way of logging in to it: the password, the "stay
logged in" records, any passkeys, and two-step verification along with its trusted devices. Posts,
conversations, attachments, profile fields and personal details are all left alone — this stops a
forum being used, and is not a data-erasure tool.

**Restoring an account is possible but it is not an undo.** The previous user state is recorded and
put back, and an account that had a password is given a new random one, so its owner has to go
through a password reset to get in. Nothing records the credentials themselves, because nothing
could put them back, so **an owner whose account is restored sets up two-step and any passkeys
again from scratch**. Archiving is meant to be one way; restoring is for the exceptional case.

## Configuration

Two settings live in the install's `src/config.php` rather than in the admin control panel, because
an archive run rewrites the database an option would be stored in:

```php
$config['archiveSite'] = [
    'protectedUsers' => [1, 2],
    'allowMail' => false,
];
```

- **`protectedUsers`** — user ids an archive run must leave alone, on top of every super
  administrator, who is always protected. `Admin CP > Users > Archive Site > List Protected Users`
  shows what an install resolves. Both `archive-all-users` and `restore-all-users` abort when the
  list comes back empty, which is the one thing standing between a run and a forum nobody can log
  in to.
- **`allowMail`** — set it to `true` to let XenForo mail members as it normally would. Installing
  this add-on otherwise stops mail to anyone who is not a super administrator and not protected,
  whether or not they are archived. That is the intent on an archived forum, and a nuisance on a
  development or staging copy of one, which is what the setting is for.

### Mail to members, and the error log

**Both of XenForo's recipient setters are covered**: mail addressed to a member, and mail
addressed to an email address that belongs to one. An address no account holds — a contact form,
an administrator's own entry — is not affected. Where an address belongs to more than one account,
which XenForo permits, one unmailable account is enough to refuse it.

**Each refusal is recorded in the server error log, one entry per attempt, and that is
deliberate.** On an archived forum nothing should be mailing members at all, so an attempt is an
anomaly worth investigating rather than noise to be suppressed: the entry names the account and
the add-on that tried, which is what makes it possible to find out what is still running. If a
forum legitimately needs to mail members, `allowMail` is the setting for it, and the log goes
quiet because nothing is being refused.

## The deprecated protected-users key

**The flat `$config['archiveSiteProtectedUsers']` that 2.0.2 and earlier read still works**, so an
install upgrading from one of those versions needs no change to `config.php` at all. It is
deprecated: `$config['archiveSite']['protectedUsers']` replaces it, and overrides it wherever both
are set.

**Where both are set, the newer key is the whole list.** An id named only in the deprecated one is
no longer protected, and nothing fails when that happens — every super administrator stays
protected whatever `config.php` says, so no count reaches zero and an archive run proceeds. That is
what `archive:validate` warns about, naming the ids it found:

```text
[warn] config keys              1 id(s) in archiveSiteProtectedUsers are not in archiveSite.protectedUsers, so they are no longer protected: 293
```

**An absent newer key is not that case.** `$config['archiveSite'] = []` holds no `protectedUsers`,
so the deprecated key is read exactly as it was before — which makes it safe for a `config.php`
generated from a template to declare the parent and leave the list out. Using the deprecated key on
its own is reported by both commands and is not a warning, so `archive:validate --strict` stays at 0
on a forum that simply has not renamed it.
