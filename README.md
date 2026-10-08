# Archive Site for XenForo 2.x

This XenForo 2.x addon lets you put a site into archive mode and hide unnecessary features from guests

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

* [Addon: Archive Site](https://xenforo.com/community/resources/archive-site.7824/)
* [Discussion and support: Archive Site](https://xenforo.com/community/threads/archive-site.180884/)

## Requirements

Requires XenForo 2.2.0 or higher. Tested on XenForo 2.2 and 2.3.

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
