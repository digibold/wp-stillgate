# Stillgate in Deployer deploys

[Deployer](https://deployer.org) deploys a project as numbered releases and
switches a `current` symlink to the new one. This page covers a recipe,
[`deployer/stillgate.php`](deployer/stillgate.php), that you copy next to your
`deploy.php`. It:

1. freezes editing on the site that is live now, early in the deploy;
2. stops the deploy before the new release is published if anything changed
   while the freeze was on;
3. ends the freeze as soon as the new release is live, before Deployer's lock
   is released, and also when the deploy fails.

It runs `wp stillgate` on the host, over SSH or locally, so the host needs
WP-CLI. A pipeline that has no shell on the server can use the
[REST API](../rest-api.md) instead.

It needs Stillgate 0.2.0 or newer on the live site: earlier releases have no
timed freeze, no `extend` and no receipt. A live site that still runs 0.1.x is
detected and skipped (see [Upgrading a live site from 0.1.x](#upgrading-a-live-site-from-01x)).
It is written for Deployer 8 and was run with 8.0.5. Deployer 7 and earlier
were not tried.

## Add it to a project

Copy `stillgate.php` next to `deploy.php` and import it **after** the recipe
that defines the deploy tasks:

```php
<?php
namespace Deployer;

import('recipe/wordpress.php');   // or recipe/common.php

// ... hosts, shared_files, your own tasks ...

import(__DIR__ . '/stillgate.php');

// Hooks that must run before the receipt check go below the import.
before('deploy:publish', 'assets:build');
```

Imported before `recipe/common.php`, the file stops with an error that says
so, because it hooks `deploy:lock`, `deploy:unlock`, `deploy:publish` and
`deploy:failed`.

The recipe's hooks are on `deploy:lock` (freeze), `deploy:publish` (check),
`deploy:unlock` (release) and `deploy:failed` (release).

Hooks on one task run in the order Deployer 8 keeps them (read in its 8.0.5
source and seen in the runs): `after` hooks in the order they were registered,
`before` hooks in the opposite order, so the one registered last runs first. This matters for one thing. A task of yours on
`before('deploy:publish', ...)`, such as an asset build, runs before the check
if you register it **after** the import, and after the check if you register it
before the import. Register it after the import, so that the check is the last
thing before the switch.

The freeze ends **right after `deploy:symlink`**, before `deploy:unlock` (see
[Why this order](#why-this-order)). A task that has to finish while editing is
still locked, such as a PHP-FPM reload or a cache flush, belongs on
`deploy:symlink`. It runs before the release whether it is registered before or
after the import:

```php
after('deploy:symlink', 'php-fpm:reload');
```

A task on `after('deploy:publish', ...)` runs after the freeze has ended.

Then point the recipe at the live site. If the release itself is the WordPress
root, the default is right. If WordPress lives outside the releases, point at
it:

```php
host('example.com')
    ->set('deploy_path', '/var/www/example.com');

set('stillgate_path', '/var/www/example.com/wordpress');
```

Run a normal deploy. Add `--plan` to see where the tasks sit without running
anything: `dep deploy --plan`.

### Bedrock

On [Bedrock](https://roots.io/bedrock/) the project root is not the WordPress
root: WordPress is in `web/wp`. Point `stillgate_path` at the directory that
holds WordPress:

```php
set('stillgate_path', '{{current_path}}/web/wp');
```

With the default, `{{current_path}}`, WP-CLI answers "No WordPress installation
found" at the project root. The freeze is then skipped with a warning, or, with
`stillgate_strict`, the deploy fails. The first deploy is skipped without error
also when `stillgate_path` is a subdirectory of `current`, because nothing is
live yet. This was run against a project created with
`composer create-project roots/bedrock`; see [Verified against](#verified-against).
For installing Stillgate in Bedrock and for Trellis, see
[bedrock.md](bedrock.md) and [trellis.md](trellis.md).

## Configuration

All values are Deployer config: set them with `set()`, per host, or on the
command line with `dep deploy -o stillgate_for=1h`.

**A `set()` before the import and a `set()` after it both work.** The recipe
sets a default only for a variable that does not exist yet, so a value you set
earlier is kept and a value you set later replaces the default. A value set on
a host (`host(...)->set()`) or with `-o` wins over both. This was run for
`stillgate_path`, `stillgate_strict`, `stillgate_for`, `stillgate_if_frozen`
and `stillgate_check`, each set before the import, after it, on the host and
with `-o`. The other variables use the same code path and were not each tried
in every position.

| Variable | Default | Meaning |
| --- | --- | --- |
| `stillgate_freeze` | `true` | `false` turns the whole recipe off for the deploy: no freeze, no check, no release, `stillgate:refreeze` does nothing. A warning says so. Inside a deploy only: a task run alone (`dep stillgate:refreeze`, `dep stillgate:release`) acts whatever this is set to. For the one deploy that cannot be frozen, for example `dep deploy -o stillgate_freeze=false`. |
| `stillgate_path` | `{{current_path}}` | WordPress root of the site that is live now. The default is the current release. Set a fixed path when WordPress lives outside the releases or in a subdirectory of them (`{{current_path}}/web/wp` for Bedrock). It is used the way Deployer uses its own paths, unquoted, so `~` works. |
| `stillgate_wp` | `wp` | The WP-CLI command, as the shell on the host runs it. |
| `stillgate_wp_args` | empty | Extra WP-CLI global arguments, added to every call as written, for example `--allow-root`, or `--url=example.com` to pick one site of a multisite network. |
| `stillgate_for` | `30m` | How long the freeze lasts if nothing ends it: `30m`, `2h`, `1h30m`. At least one minute and at most seven days. It must cover the whole deploy up to the check. |
| `stillgate_message` | empty | The message shown to people who are locked out. Empty keeps the message the site already has. See below. |
| `stillgate_extend_by` | `15m` | How far `stillgate:extend` pushes the end back. |
| `stillgate_check` | `true` | `false` skips the receipt check, with a warning. |
| `stillgate_if_frozen` | `fail` | What to do when a freeze is already on at the start. `fail` stops the deploy. `continue` replaces that freeze with this deploy's timed one and ends it at the end. |
| `stillgate_strict` | `false` | `false`: a host where `wp stillgate` cannot run, or where the live Stillgate is older than 0.2.0, is skipped with a warning. `true`: it fails the deploy. |

The booleans take `true` and `false`. On the command line, `-o name=false` is
turned into a boolean by Deployer; in `deploy.php` write `false`, not `'false'`.

### The message

The message is passed to WP-CLI as one quoted argument, built in PHP, and not
parsed by the shell. These characters in `stillgate_message` reach the plugin
unchanged, checked against giving the same text to WP-CLI directly: single and
double quotes, `$`, `${HOME}`, backticks, `;`, `&`, `&&`, `|`, `>`, a leading
`-` or `--`, newlines, tabs, backslashes (also at the end), `%`, `*`, `~`, `!`,
non-ASCII text (including an emoji), and a 400 character string. Text such as
`; touch file` or `$(touch file)` was not executed. The plugin itself strips
HTML tags from the message, as it does for the same text given to WP-CLI.

Deployer reads `{{name}}` in a config value as a placeholder, in the value you
set. So `{{current_path}}` in the message is expanded, and a name that does not
exist is an error in Deployer (run: the freeze task fails with `Config option "name" does not exist`). To show a literal `{{`, write `\{{` in the value:

```php
set('stillgate_message', 'Back in a moment \{{ soon }}');
```

The same works on the command line (`-o 'stillgate_message=Back \{{ soon }}'`).
The recipe does not read the message a second time, so the text shows as
`Back in a moment {{ soon }}`.

Two limits that come from Deployer and not from the recipe: Deployer
normalises line endings in a value, so a carriage return and line feed pair
arrives as a line feed, and `-o name=value` stops the value at a second `=`,
so `-o stillgate_message=a=b` sets `a`. Set a message that has an `=` with
`set()`. The quoting uses the `$'...'` form of Deployer's `quote()`, which
needs a bash-compatible shell on the host (Deployer's default, `bash -s`);
other shells and SSH were not tried.

## What runs when

For `dep deploy` with `recipe/wordpress.php`, the order is, as `--plan` prints
it:

```
deploy:info
deploy:setup
deploy:lock
stillgate:freeze            <- after deploy:lock
deploy:release
deploy:update_code
deploy:env, deploy:shared, deploy:writable
stillgate:check-if-frozen   <- before deploy:publish
deploy:symlink              <- the new release goes live here
stillgate:release-if-frozen <- before deploy:unlock
deploy:unlock
deploy:cleanup
deploy:success
```

If a task fails, Deployer runs `deploy:failed`, and the recipe adds
`stillgate:release-if-frozen` after it.

### Tasks

| Task | Does | Hooked |
| --- | --- | --- |
| `stillgate:freeze` | `wp stillgate enable --for=<stillgate_for>`, with `--message` if one is set. Skips with a message when nothing is live yet, when Stillgate cannot run, or when the live Stillgate is older than 0.2.0 (see `stillgate_strict`). | after `deploy:lock` |
| `stillgate:check` | Fails unless the freeze is still on and `wp stillgate receipt --check` passes. The failure message includes the receipt. | through `stillgate:check-if-frozen`, before `deploy:publish` |
| `stillgate:release` | `wp stillgate disable`. | through `stillgate:release-if-frozen`, before `deploy:unlock` and after `deploy:failed` |
| `stillgate:extend` | `wp stillgate extend <stillgate_extend_by>`. Skips when this deploy has no freeze. | not hooked |
| `stillgate:refreeze` | Starts the timed freeze again, whatever state the database is in. If the database holds a running freeze, it is ended first so that the new freeze opens a new receipt. Ignores `stillgate_if_frozen`. Skips when this deploy has no freeze. For after a database import; see [Database steps](#database-steps). | not hooked |

`stillgate:check-if-frozen` and `stillgate:release-if-frozen` are hidden
wrappers. They call the task only if this deploy started a freeze, so a deploy
that failed before freezing, or skipped the freeze, never ends a freeze that
somebody else started. The public tasks can also be run alone, for example
`dep stillgate:release production` to end a freeze that a dead deploy left
behind, or `dep stillgate:extend production`. Run alone, they always act.

To push the end back from inside a deploy, hook `stillgate:extend` before a
slow task:

```php
before('assets:build', 'stillgate:extend');
```

## Why this order

**Freeze after `deploy:lock`.** `deploy:lock` stops a second deploy while one
is running. A deploy that finds the lock held stops there and runs no failure
hook, so it cannot touch the freeze of the deploy that holds the lock. The
freeze is still early: it comes before the release directory is created, the
code is fetched and any build step runs.

**Freeze before any database copy.** The receipt covers the time between the
start of the freeze and its end. A dump taken after the freeze began is
covered; one taken before it is not. If you add a step that exports the
database, put it after `stillgate:freeze`.

**Check before `deploy:publish`, and before any step that writes to the live
database.** The check answers one question: did content change since the
freeze began. It is only useful before something relies on the answer. The
recipe checks before the symlink switch. The check also fails if the freeze is
no longer on, because the receipt says nothing about the time after the
freeze ended: a clean receipt from a freeze whose timer ran out half way
through the deploy would otherwise pass. See [receipt.md](../receipt.md).

**Release between `deploy:symlink` and `deploy:unlock`.** Editing resumes when
the new code is live, and the freeze ends before Deployer's lock is released.
The recipe hooks the release on `deploy:unlock`, so it runs after every hook
on `deploy:symlink` whatever the order of registration. In the runs that read
the state at that point, the first task after `deploy:unlock` found the freeze
already off and the lock free. By that order a second deploy finds either the
lock held or the freeze already ended; it cannot find the lock free and the
freeze still on. (A second deploy started in those moments was not run.) The
cost is that editing resumes a few moments before `deploy:cleanup`,
`deploy:success` and anything you hooked on `after('deploy:publish', ...)`. If
the release fails, the lock stays held too, because `deploy:unlock` does not
run; see Limits. A failure while publishing also ends the freeze, through the
failed hook.

**The timer is the safety net.** `--for` makes the freeze end by itself. If the
whole process dies (the CI runner is killed, the SSH connection drops, the
machine reboots), no hook runs, and editing unlocks when the timer runs out.
Choose `stillgate_for` as the longest the deploy should take, not the typical
time, and use `stillgate:extend` for the rare long deploy.

**The freeze is applied to the live site, not the release being built.** The
site that visitors and editors use is the one behind `stillgate_path`. With the
default, the recipe resolves `current` to its real directory when it freezes
and keeps using that directory for the rest of the deploy, so ending the freeze
does not depend on where `current` points by then. The release normally runs
before `deploy:cleanup`, so that directory still exists. If a task of yours
removes it earlier (for example `deploy:cleanup` right after `deploy:symlink`
with `keep_releases` set to 1), the recipe falls back to `stillgate_path`.

## Database steps

Stillgate keeps the freeze state and the receipt in the database. A step that
replaces the database, such as importing a dump, therefore replaces both:
after it, the site holds whatever freeze the imported database had. A dump
taken before the freeze began holds no freeze at all, so the site is no longer
frozen, the receipt of the live database is gone, and the check before
`deploy:publish` finds the freeze off.

The sequence that works:

```php
// after import(__DIR__ . '/stillgate.php');
before('db:import', 'stillgate:check-if-frozen');   // 1
after('db:import', 'stillgate:refreeze');           // 3
```

1. **Check before the import.** The receipt of the live database is the proof
   that nothing was lost: it says whether anyone changed content since the
   freeze began, and it is about to be replaced. If it is not clean, the
   deploy stops before the import runs.
2. **Import.** `wp db import` is not a content write and runs while the site
   is frozen.
3. **Refreeze right after the import**, if editing must stay locked until the
   release is published. `stillgate:refreeze` applies the timed freeze
   (`stillgate_for`, `stillgate_message`) again, whatever state the imported
   database holds: none, an expired freeze, or a running one with the receipt
   of the environment the dump came from. A running freeze is ended first,
   because starting a freeze over it would keep the old receipt.
4. **The check before `deploy:publish` is unchanged**, and now refers to the
   new freeze. Its receipt covers the time since the refreeze, not the time
   before the import; that was step 1.

Keep the refreeze directly after the import. Between the end of the import and
the refreeze, a dump without a freeze leaves the site unfrozen, and anything
an editor saves in that moment lands in the new database. The hooks above run
back to back.

If you leave out step 3, the check before `deploy:publish` fails with:

> the freeze is no longer on, so the receipt cannot vouch for the whole
> deploy. Either the timer ran out (raise stillgate_for, or run
> stillgate:extend while the deploy is running), or a database import
> replaced the freeze state, which is kept in the database (run
> stillgate:refreeze right after the import).

The recipe cannot tell the two causes apart, so the message names both.

If the imported database comes from an environment where Stillgate is not
active, `wp stillgate` does not exist after the import, so `stillgate:refreeze`
fails, and so does the release in the failure hook: the deploy fails, the lock
stays held and the site is not frozen. Activate the plugin in that dump (or
import one that has it active) before relying on this sequence.

Run with a real `wp db import` of a dump taken before the freeze. The freeze
state after each phase:

| Phase | Without `stillgate:refreeze` | With the sequence above |
| --- | --- | --- |
| after `stillgate:freeze` | on | on |
| before the import | on | on, receipt clean (check passed) |
| right after the import | off (the imported database has no freeze) | off |
| after `stillgate:refreeze` | not run | on, new receipt open and clean |
| check before `deploy:publish` | fails with the message above, nothing published | passes |
| after the release step | off, `deploy:failed` ran, lock held | off, release published, lock free |

A dump taken during a freeze (running, with a receipt that showed a change)
arrived as a running freeze with that receipt. `stillgate:refreeze` replaced it
with a new clean receipt and the deploy was published. If the freeze was
skipped (`stillgate_freeze=false`, or no Stillgate on the live site),
`stillgate:refreeze` does nothing and the import still runs.

The recipe cannot know which of your tasks write to the live database in other
ways. Steps that write only options or tables outside content are not covered
by the receipt either; see Limits.

## Upgrading a live site from 0.1.x

The deploy that ships Stillgate 0.2.0 would be blocked by the freeze step if
the live site still ran 0.1.x, because 0.1.x has no timed freeze: `wp stillgate
enable --for=...` fails there. The recipe asks the live site first, with
`wp cli has-command "stillgate extend"`. The `extend` command exists in 0.2.0
and later; on the real 0.1.1 the command exits 1, on 0.2.0 it exits 0.

- **Default (`stillgate_strict` off):** the freeze is skipped with a warning
  that says to update the live plugin first (`wp plugin update stillgate`), or
  to deploy once with `stillgate_freeze` disabled. The deploy goes on
  unfrozen, ships 0.2.0, and the next deploy freezes. The check and release
  steps do nothing for the deploy that was not frozen.
- **`stillgate_strict` on:** the deploy fails with the same message. Either
  update the plugin on the live site first, or run that one deploy with
  `dep deploy -o stillgate_freeze=false`.

The deploy that ships 0.2.0 runs without a freeze. Nothing guards the content
during that one deploy.

## Deploy steps that use WP-CLI while the freeze is on

`wp` commands in your own tasks follow the same rules as any other writer:
a command that creates, updates or deletes posts, pages, media or terms is
refused while the freeze is on, unless it runs as a user who has the bypass
capability (`--user=admin`). See [architecture.md](../architecture.md#wp-cli-is-not-exempt).
A bypass user's edits during the deploy are recorded in the receipt, so they
make the check fail.

## What happens when

Each row was run against the test setup below, with the recipe as it is on this
page.

| Situation | Result |
| --- | --- |
| Normal deploy | Site frozen for the duration: `wp post update` from a task failed with the Stillgate message. Check passed, release published, freeze ended before `deploy:unlock`. Receipt closed and clean. |
| A task fails halfway | `deploy:failed` ran the release. Site unfrozen afterwards, `current` unchanged, Deployer's lock still held. |
| Same, with `after('deploy:failed', 'deploy:unlock')` registered after the import, and also before it | Site unfrozen and the lock released. |
| A user with the bypass capability edits a post during the freeze | `stillgate:check` failed with the receipt in the message (`clean false`, the post ID). Nothing was published, the freeze was released, and the receipt stayed available for `wp stillgate receipt` and `wp stillgate drift`. |
| First deploy, nothing at `current` | Skipped with a warning, deploy succeeded. Also skipped with `stillgate_strict` on, whether `stillgate_path` is `current` itself or a directory below it. |
| `stillgate_path` is a fixed directory that exists, first deploy | The live site was frozen, although there is no `current` yet. |
| `stillgate_path` does not exist and `current` does | Skipped with a warning; with `stillgate_strict` on, the deploy failed. |
| Stillgate deactivated, `wp` not found, or the path is not WordPress | Skipped with a warning that carries the WP-CLI error (up to 300 characters). With `stillgate_strict` on, the deploy failed with the same text. |
| Live Stillgate 0.1.1 | Skipped with the update message; with `stillgate_strict` on, the deploy failed with it; with `stillgate_freeze` off, deployed. |
| Deploy lock held by an earlier failed deploy | The deploy stopped at `deploy:lock`. A freeze somebody else had started was still on afterwards. |
| A freeze is already on | Default: the deploy failed at `stillgate:freeze` and the other freeze stayed on. With `stillgate_if_frozen=continue`: it was replaced and ended with the deploy. |
| Database import, no `stillgate:refreeze` | The check before `deploy:publish` failed with the message in [Database steps](#database-steps). Nothing was published. |
| Database import with the documented sequence | Check, import, refreeze, check, publish, release; the site was frozen from the refreeze to the symlink. |
| Receipt not clean before the import | The check before the import failed; the import did not run. |
| Freeze timer shorter than the deploy (`stillgate_for=1m`, 75 second task) | `stillgate:check` failed because the freeze was no longer on. Nothing was published. |
| Same, with `stillgate:extend` called mid-deploy | The freeze was extended, the check passed, the release was published. |
| `stillgate_for` empty or not a duration | The deploy failed at `stillgate:freeze` with the WP-CLI message; no untimed freeze was started. |
| `stillgate:release` fails | The new release was already live. The deploy exited with an error, the failed hook tried once more and failed again, the freeze stayed on until its timer, and Deployer's lock stayed held. |
| `deploy:cleanup` removes the live release before the freeze is released (`keep_releases` 1) | The release fell back to `stillgate_path` and ended the freeze. |
| A task fails after `deploy:cleanup` | The freeze had ended before; the deploy exited with an error and the lock was free. |
| `dep` killed with `kill -9` during the deploy | The freeze stayed on after the process was gone and ended by itself when the timer ran out (`stillgate_for=1m`). Deployer's lock stayed held. |

## Limits

- **The timer is a promise to editors, not to the deploy.** A deploy that
  outlasts the freeze fails at the check, or, if you turned the check off,
  carries on with editors back at work.
- **A failed or killed deploy leaves Deployer's own lock in place.** The next
  deploy stops at `deploy:lock` with "Deploy locked", before anything of
  Stillgate's runs. Clear it with `dep deploy:unlock`, or have Deployer do it
  after a failure with the hook its own TYPO3 recipe uses, registered after the
  import:

  ```php
  after('deploy:failed', 'deploy:unlock');
  ```

  It composes with this recipe: on a failed deploy the freeze ended and the
  lock was released, whether the line was before or after the import. Do not
  use `fail('deploy', 'deploy:unlock')`: calling `fail('deploy', ...)` again
  replaces the `deploy:failed` hook that this recipe depends on. A killed
  deploy runs no hooks, so it still leaves the lock behind.
- **If ending the freeze fails, the lock stays held as well.** The release runs
  before `deploy:unlock`, so when `wp stillgate disable` fails, neither the
  lock nor the freeze is released, also with the hook above (the first failing
  hook stops the rest). The new release is already live. Run
  `dep stillgate:release` and then `dep deploy:unlock`, or wait for the timer
  and run `dep deploy:unlock`.
- **Replacing a freeze keeps its receipt.** With `stillgate_if_frozen=continue`,
  the receipt of the freeze you replaced carries on, so a change made before the
  deploy started also makes the check fail. This is `wp stillgate enable`
  behaviour, checked by hand. `stillgate:refreeze` avoids it by ending a
  running freeze first.
- **The receipt has its own limits**: post meta alone, options, users and custom
  tables are not inspected. Read [receipt.md](../receipt.md) before relying
  on it. The receipt works at one-second resolution: a post saved in the same
  second the freeze begins counts as changed.
- **`active` is read from `wp stillgate status --format=json`.** In 0.2.0 that
  command prints every value as a string; the recipe accepts both strings and
  booleans.
- **A failure of `wp stillgate status` for any reason**, including a database
  outage, is treated as "cannot run" and skips the freeze when `stillgate_strict`
  is off.
- **On a first deploy with a `stillgate_path` outside `current`** that is
  misspelled, the freeze is skipped without error, because nothing is live yet.
  With `stillgate_strict` the second deploy fails.
- **Multisite** is reachable through `stillgate_wp_args=--url=...`, one site
  per deploy. Not tried.
- **Not tried**: remote hosts over SSH, more than one host in a deploy,
  `dep rollback`, `--no-hooks`, running `wp` as another user with
  `become`, a persistent object cache, a database import while Stillgate is a
  must-use plugin, and Deployer 7.

## Verified against

Everything above marked as run was run on 2026-10-19 on one machine, with
Deployer on a `localhost()` host.

| Component | Version |
| --- | --- |
| Deployer | 8.0.5 (installed with Composer; the latest stable tag on Packagist that day) |
| PHP | 8.4.10 |
| WP-CLI | 2.12.0 |
| WordPress | 7.1.3, with `WP_ENVIRONMENT_TYPE` set to `local` |
| Stillgate | 0.2.0; the rows with a live 0.1.1 used the `tags/0.1.1` directory of the WordPress.org plugin repository (checked out with Subversion earlier the same day) |
| MySQL | 8.4.11 |
| git | 2.37.2 |

Three setups were used. In the first, the repository held only a theme and
`stillgate_path` pointed at a separate WordPress install. In the second, the
repository was a whole WordPress tree deployed with `recipe/wordpress.php`
and the default `stillgate_path`, so the freeze followed `current`. In the
third, the repository was a project created with `composer create-project
roots/bedrock` (WordPress 7.1.3 from Composer), deployed with `recipe/common.php`
and `stillgate_path` set to `{{current_path}}/web/wp`; the Bedrock files came
from Composer's local cache, so that install ran offline. A task added only for
the tests probed the site from inside the deploy.

The Deployer hooks, `fail`, `invoke`, `has` and the `| quote` filter were
checked in the Deployer 8.0.5 source, and the ordering of `deploy:publish`
(`deploy:symlink`, `deploy:unlock`, `deploy:cleanup`, `deploy:success`) in its
`recipe/common.php`. The Deployer documentation pages for hooks could not be
retrieved on the day, so nothing here relies on them.

Not run: remote hosts over SSH (so the quoting over SSH is unverified);
`wp plugin update stillgate` itself, which needs the network (the upgrade
deploy was simulated with a task that installs the 0.2.0 zip over 0.1.1); a
second deploy that starts in the moments around `deploy:unlock` (the ordering
is shown by the task order and by the state found at `deploy:unlock`); Deployer
7; `dep rollback`.
