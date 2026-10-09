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
changes nothing. `archive:validate` proves the forum can do it: the protected list resolves to
real accounts, the archive table is there, the class extensions apply, and **every template
modification still matches the template it targets** — which is the one thing that breaks silently
when XenForo changes a core template, since a modification that matches nothing still reports as
`ok`.

Its exit code is a contract:

| | all fine | warnings only | any failure |
|---|---|---|---|
| `archive:validate` | 0 | 0 | 1 |
| `archive:validate --strict` | 0 | 2 | 1 |

So `archive:validate && next-step` carries on past a warning, and `--strict` is for a monitor that
wants to hear about one. **1 always means failed** — this is not the monitoring-plugin numbering,
where 1 is a warning. `--unattended` is accepted and does nothing, since neither command writes or
sends anything; it is there so that one command line works across add-ons.

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

`$config['archiveSiteProtectedUsers']`, the flat key used by 2.0.2 and earlier, still works and
needs no change on upgrade. It is deprecated: `$config['archiveSite']['protectedUsers']` overrides
it wherever both are set.
