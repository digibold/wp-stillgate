# Stillgate in Trellis deploys

[Trellis](https://roots.io/trellis/) deploys a [Bedrock](https://roots.io/bedrock/)
site with Ansible. A deploy builds a new release in its own directory, then
switches the `current` symlink to it. Trellis lets you run your own task files
at fixed points of that process ("deploy hooks"). This page uses two of them to
freeze editing while a release is built and to end the freeze once the new
release is live.

Stillgate runs through WP-CLI over the SSH connection Trellis already uses, so
no Application Password or REST call is needed. It must be installed and
active on the live site before the first freeze: see [bedrock.md](bedrock.md).
Use Stillgate 0.2.0 or newer (`--for`, `extend` and the receipt do not exist in
0.1.1). The hooks check for it and skip the freeze on a live site that has an
older release: see
[moving a live site from an older Stillgate](#moving-a-live-site-from-an-older-stillgate).

A stock Trellis deploy replaces code, not the database, but it does write to
it: the finalize step runs `wp option delete` and, when the stored theme
paths point at a release, `wp option set`, then `wp core update-db`,
`wp rewrite flush` and `wp cache flush`. The only schema work is
`wp core update-db`. The freeze keeps editors from changing content while the
release is built and switched. The receipt matters mainly when you add a step
of your own that replaces the live database; see
[the receipt before a database is replaced](#the-receipt-before-a-database-is-replaced).

## Where the freeze fits

The deploy role runs these steps in order. Each step has a `before` and an
`after` hook variable; `deploy_before` and `deploy_after` surround all of them.

| Order | Step | What Trellis does |
| --- | --- | --- |
| 1 | `deploy_before` | |
| 2 | initialize | creates the directory layout and the `deploy_helper` facts |
| 3 | update | `git clone` of the repository into `shared/source` |
| 4 | **`deploy_prepare_before`** | **freeze here** |
| 5 | prepare | creates the new release directory with `git archive` |
| 6 | build | writes `.env`, copies `vendor`, runs `composer install` |
| 7 | share | links the shared folders (uploads) |
| 8 | finalize | checks the install, moves the `current` symlink, then runs `wp option delete` (and `wp option set` for theme paths that point at a release), `wp core update-db`, `wp rewrite flush`, `wp cache flush` and reloads php-fpm |
| 9 | **`deploy_after`** | **end the freeze here** |

Why these two:

- By step 4 the repository was fetched, so a bad branch or SSH problem fails
  the deploy at step 3 and never starts a freeze. The new release has not been
  created, and nothing that builds it or touches the database has run.
- By step 9 the new code is live and Trellis has finished its own `wp` commands
  and the php-fpm reload, so editors come back to the new release.
- Neither variable has a default list in the deploy role, so setting it does
  not remove a hook that Trellis runs for you. Four of the fourteen hook
  variables do have defaults in `roles/deploy/defaults/main.yml`:
  `deploy_build_before` (`deploy-hooks/build-before.yml`, the placeholder in
  your Trellis directory for building theme assets), `deploy_build_after`
  (the role's `build-after.yml`, which runs `composer install`, then
  `deploy-hooks/build-after.yml`), `deploy_finalize_before` (the install
  check) and `deploy_finalize_after` (the theme paths, update-db, rewrite and
  cache flush and the php-fpm reload). The other ten have none. If you add a
  file to one of the four, keep the default entries first in your list, or
  those steps stop running: with an empty `deploy_finalize_before` the deploy
  failed in the finalize step because `wp_installed` was undefined.
- To freeze later and shorten the freeze, put the same file on
  `deploy_finalize_before` after the role's own file (editors are then free
  while the release is built). To freeze before anything, use `deploy_before`
  and write the path as `{{ project_root }}/{{ project_current_path }}`,
  because `deploy_helper` does not exist until the initialize step has run.

Trellis runs a hook file only if it exists on the machine that runs Ansible
(the role checks each path with `stat`, delegated to localhost, and skips a
missing file without an error). A misspelled file name means no freeze and no
warning, so look for the "Freeze editing" task in the output of your first deploy.

## Hook files

Create `deploy-hooks/stillgate-freeze.yml` in your Trellis directory (the folder
Trellis' documentation suggests for hooks):

```yaml
---
# Freeze editing on the live release. Runs on the server, as web_user.
# Stops the deploy if Stillgate is already frozen or has a scheduled window,
# unless stillgate_take_over is true. Records stillgate_started_freeze, so
# that the release hook ends only a freeze that this deploy started.
- name: Look for the live release
  stat:
    path: "{{ deploy_helper.current_path }}"
  register: stillgate_live

- name: Check that the live release has a Stillgate with timed freezes
  command:
    argv: [wp, cli, has-command, stillgate extend]
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_present
  changed_when: false
  failed_when: false
  when: stillgate_live.stat.exists

- name: Read the freeze state of the live site
  command: wp stillgate status --format=json
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_status
  changed_when: false
  when: stillgate_live.stat.exists and stillgate_present.rc == 0

- name: Parse the freeze state
  set_fact:
    stillgate_state: "{{ stillgate_status.stdout | from_json }}"
  when: stillgate_live.stat.exists and stillgate_present.rc == 0

- name: Stop if a freeze or a scheduled window is already there
  fail:
    msg: >-
      Stillgate on the live site is already frozen or has a scheduled window
      (mode {{ stillgate_state.mode }}, window {{ stillgate_state.schedule_status }}).
      The deploy leaves it alone and stops. End it, or wait for it to end,
      and deploy again. To let this deploy replace it with its own timed
      freeze, and end that freeze afterwards, set stillgate_take_over to true.
  when:
    - stillgate_live.stat.exists and stillgate_present.rc == 0
    - stillgate_state.active | string | lower == 'true'
      or stillgate_state.schedule_status in ['upcoming', 'active']
    - not (stillgate_take_over | default(false) | bool)

- name: Freeze editing
  command:
    argv: >-
      {{ ['wp', 'stillgate', 'enable', '--for=' ~ (stillgate_freeze_for | default('30m'))]
         + (['--message=' ~ stillgate_freeze_message] if stillgate_freeze_message is defined else []) }}
  args:
    chdir: "{{ deploy_helper.current_path }}"
  when: stillgate_live.stat.exists and stillgate_present.rc == 0

- name: Record that this deploy started the freeze
  set_fact:
    stillgate_started_freeze: true
  when: stillgate_live.stat.exists and stillgate_present.rc == 0

- name: Report that there is no live release to freeze
  debug:
    msg: "There is no live release yet, so no freeze was started."
  when: not stillgate_live.stat.exists

- name: Report that no freeze was started
  debug:
    msg: >-
      The live release has no Stillgate 0.2.0 or newer (the command
      wp stillgate extend is missing), so no freeze was started and this
      deploy runs unfrozen. {{ stillgate_present.stderr | default('') }}
  when: stillgate_live.stat.exists and stillgate_present.rc != 0
```

And `deploy-hooks/stillgate-release.yml`:

```yaml
---
# End the freeze once the new release is live. Runs on the server, as web_user.
# Ends only a freeze that the freeze hook of this deploy started.
- name: Check that the new release has a Stillgate with timed freezes
  command:
    argv: [wp, cli, has-command, stillgate extend]
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_present
  changed_when: false
  failed_when: false
  when: stillgate_started_freeze | default(false) | bool

- name: End the freeze
  command: wp stillgate disable
  args:
    chdir: "{{ deploy_helper.current_path }}"
  when: stillgate_started_freeze | default(false) | bool and stillgate_present.rc == 0

- name: Report that the freeze was left to end by itself
  debug:
    msg: >-
      Stillgate is not available on the new release, so the freeze is left to
      end at its timer. {{ stillgate_present.stderr | default('') }}
  when: stillgate_started_freeze | default(false) | bool and stillgate_present.rc != 0

- name: Report that no freeze was ended
  debug:
    msg: "This deploy did not start a freeze, so none was ended."
  when: not (stillgate_started_freeze | default(false) | bool)
```

Register them in `group_vars/all/deploy-hooks.yml` (or in
`group_vars/production/` to freeze only production):

```yaml
deploy_prepare_before:
  - "{{ playbook_dir }}/deploy-hooks/stillgate-freeze.yml"

deploy_after:
  - "{{ playbook_dir }}/deploy-hooks/stillgate-release.yml"

# Optional. How long the freeze may last if the deploy never reaches the end.
stillgate_freeze_for: 30m

# Optional. Leave it out to keep the message that is stored on the site.
# stillgate_freeze_message: "Deploying now, back shortly."
```

Trellis hook files are included into one play, so `stillgate_started_freeze`,
which the freeze file sets with `set_fact`, is still there when the release
file runs at the end. Run as `ansible-playbook deploy.yml`, the release file
ended the freeze that the freeze file had started.

Behaviour, as run:

- **No live release, or no Stillgate 0.2.0 on it.** The first deploy of a
  site has no `current` release, and the deploy that first adds Stillgate has
  a live release without it. A live release with Stillgate older than 0.2.0
  or with the plugin deactivated is the same case. The freeze file prints a
  message, starts nothing and the deploy goes on unfrozen; the release file
  then ends nothing. The next deploy freezes. If you would rather fail than
  go on unfrozen, replace the last two tasks of the freeze file with a
  `fail` task.
- **A freeze or a scheduled window is already there.** The deploy stops at
  the freeze file, before a release is built, and changes nothing: not the
  freeze, not the window, not the stored message. It stops for a manual
  freeze, a running scheduled window, an upcoming scheduled window, and a
  freeze that an earlier failed deploy left on until its timer ends (see
  [If the deploy fails](#if-the-deploy-fails)). To let one deploy replace it,
  pass `-e stillgate_take_over=true`, for example
  `ansible-playbook deploy.yml -e env=production -e site=example.com -e stillgate_take_over=true`.
  The deploy then starts its own timed freeze and the release file ends it,
  so the earlier manual freeze or window is gone afterwards, and an upcoming
  window is removed and not restored. Replacing a freeze that is open keeps
  its receipt, which opened when that freeze began: a post changed during the
  earlier freeze makes the receipt check fail.
- **The release file ends only a freeze that this deploy started.** If the
  freeze file did not start one (it was skipped, or the file is not registered),
  `stillgate_started_freeze` is not set and the release file leaves the site
  alone. A manual freeze set under Stillgate 0.1.1 stayed on after a deploy
  that skipped the freeze. When the deploy did start a freeze, the release
  file ends whatever freeze is on at that point, so if someone replaced this
  deploy's freeze with their own in the meantime, theirs is ended too.
- **The message.** `--message` is passed only when `stillgate_freeze_message`
  is set. Without it the stored message is left as it is, and editors who are
  refused during the deploy see the message stored by the last freeze. Set
  `stillgate_freeze_message` if they should see a deploy message instead.
  Stillgate keeps the message in the site's settings and `disable` does not
  clear it, so a message set by a deploy is still stored when the freeze has
  ended. Ansible's `command` module expands environment variables in its
  arguments: a message containing `$HOME` was stored with the deploy user's
  home directory in its place. Avoid `$` followed by a name in the message. A leading `~` is safe, because the
  argument always begins with `--message=`.
  Quotes, semicolons and backticks arrived unchanged.
- **The duration.** A `stillgate_freeze_for` that is not a duration
  (`banana`, `0`, or an empty value) makes `wp stillgate enable` exit `1`, and
  the deploy stops before anything is built. A duration is `30m`, `2h`, `1d` or
  `1h30m`: at least one minute, at most seven days.
- **The state.** The files read `wp stillgate status --format=json`. In
  0.2.0 that prints every value as a string (`"active":"true"`), so they
  compare `active | string | lower` with `'true'`.
- **The check.** It uses `wp cli has-command "stillgate extend"`, not
  `wp plugin is-active` and not `wp cli has-command stillgate`. A Stillgate
  installed as a must-use plugin is running though `wp plugin is-active
  stillgate` exits `1` for it (see [bedrock.md](bedrock.md#as-a-must-use-plugin)),
  and under 0.1.1 `has-command stillgate` exits `0` while
  `wp stillgate enable --for=30m` fails with `unknown --for parameter`.
  The `extend` command exists from 0.2.0 on: with 0.1.1 live
  `has-command "stillgate extend"` exited `1`, and with 0.2.0 it exited `0`,
  as a normal plugin and as a must-use plugin, and `1` when the plugin was
  deactivated.
- On Multisite each site has its own freeze, and these tasks act on the site
  WP-CLI resolves from `.env`. Add `--url=` for the other sites as needed;
  that was not run.

## Moving a live site from an older Stillgate

The freeze file looks at the plugin on the live release, which is the code
from before the deploy that is running. A deploy that ships Stillgate 0.2.0 to
a site that runs 0.1.1 therefore cannot be frozen by these hooks, and without
the check it would fail: `wp stillgate enable --for=30m` is an error under
0.1.1, and it would stop the deploy that was meant to install 0.2.0.

1. Require 0.2.0 in the Bedrock project (see [bedrock.md](bedrock.md)) and
   deploy. With the hooks registered the freeze file prints that the live
   release has no Stillgate 0.2.0, the deploy runs unfrozen and ships the new
   plugin. With 0.1.1 live, the deploy that shipped 0.2.0 ended with 0.2.0 on
   the live release.
2. Check that the plugin is active on the server, as described in
   [bedrock.md](bedrock.md#activate-it).
3. From the next deploy on, the live release has `stillgate extend` and the
   freeze runs.

If that first deploy has to be frozen as well, deploy the plugin update on its
own before you register the hooks. The receipt check below fails instead of
skipping when the live release lacks Stillgate 0.2.0, because it exists to stop
a deploy.

## Running `wp stillgate` from a hook

- **Path.** `deploy_helper.current_path` is the `current` symlink, which is
  `<www_root>/<site>/current` (`www_root` is `/srv/www` unless you changed it).
  It is set by the deploy role's `deploy_helper` task and is available from
  `deploy_initialize_after` onward. Trellis' own finalize hooks run their `wp`
  commands the same way: `command: wp ...` with `chdir` set to it. The
  `chdir` matters because WP-CLI finds Bedrock's `wp-cli.yml` (`path: web/wp`)
  from the current directory. Before the cutover `current` is the old
  release, after it the new one, so the freeze file runs on the old code and
  the release file on the new.
- **User.** The deploy play sets `remote_user: "{{ web_user }}"`, which is
  `web` by default, and hook files are included into that play. They have no
  `become`, so their tasks run as `web_user`, the same user that owns the
  release files and runs Trellis' other `wp` commands. Only the `stat` that
  looks for the hook file runs on your own machine.
- **Output.** At the default verbosity the freeze and release tasks print only
  `changed`; the WP-CLI output, including the end time of the freeze, appears
  with `ansible-playbook -v`. The receipt check prints the receipt itself
  (a debug task), so the deploy log always shows what it found.

Commands Stillgate does not block during a freeze, as run on a Bedrock site
with a freeze on: `wp core is-installed`, `wp option get`, `wp option set`,
`wp option delete`, `wp core update-db` (when the database is already
current), `wp rewrite flush` and `wp cache flush`, which are the commands of
Trellis' own finalize steps. A command that writes posts is blocked: `wp post
create` exited `1` with a `Stillgate:` message. A hook that has to create or
update content while the freeze is on needs `--user=<an account with the
bypass capability>`, or has to run before the freeze. A real pending database
upgrade under a freeze was not tried.

## If the deploy fails

Trellis has no failure hook. The deploy role is the list of steps above and
`deploy.yml` has no rescue or cleanup section, so when a task fails the play
stops and nothing of yours runs afterwards. The rollback playbook
(`rollback.yml`) runs no deploy hooks either. The timed freeze is the safety
net: it ends by itself at the time given by `stillgate_freeze_for`, even if the deploy dies,
the connection drops, or nobody notices.

- Choose `stillgate_freeze_for` to be longer than your slowest normal deploy.
  A freeze that expires before the deploy ends does not protect the end of it.
- A freeze left on by a failed deploy makes the next deploy stop at the freeze
  file (see above). Wait for the timer, end it with `wp stillgate disable`
  (below), or deploy with `-e stillgate_take_over=true`.
- A slow step can extend it from a hook: `command: wp stillgate extend 15m`
  with the same `chdir`. The total cannot exceed seven days.
- To end a freeze left by a failed deploy before its timer, log in as the web
  user and run it from the live release:

```bash
cd /srv/www/example.com/current
wp stillgate disable
```

  `wp stillgate status` shows whether a freeze is on and when it ends.

## The receipt before a database is replaced

The receipt says whether anything changed in the content while the freeze was
on; see [receipt.md](../receipt.md) for what it records and what it cannot see.
In a deploy that replaces the live database (an import of a dump in a hook of
yours, or a separate playbook), the receipt tells you, before the import,
whether content changed since the freeze began. If the dump was taken after the
freeze began, a clean receipt means the dump is still complete as far as the
receipt can see.

`deploy-hooks/stillgate-receipt-check.yml` stops the deploy unless a freeze is
still on and its receipt is clean:

```yaml
---
# Stop the deploy unless the freeze is still on and nothing changed while it
# was on. Place it before any step that replaces the live database.
- name: Check that the live release has a Stillgate with timed freezes
  command:
    argv: [wp, cli, has-command, stillgate extend]
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_present
  changed_when: false
  failed_when: false

- name: Stop if Stillgate 0.2.0 or newer is missing
  fail:
    msg: "Stillgate 0.2.0 or newer is not available, so the receipt cannot be checked. {{ stillgate_present.stderr }}"
  when: stillgate_present.rc != 0

- name: Read the freeze state
  command: wp stillgate status --format=json
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_status_now
  changed_when: false

- name: Stop if the freeze is no longer on
  fail:
    msg: >-
      No freeze is on. The freeze hook did not start one, the timer ran out,
      or someone ended it, so the receipt does not cover the time since then
      and a clean receipt proves nothing. If the timer ran out, raise
      stillgate_freeze_for or run wp stillgate extend 15m before the deploy
      gets here.
  when: (stillgate_status_now.stdout | from_json).active | string | lower != 'true'

- name: Read the freeze receipt
  command: wp stillgate receipt --check
  args:
    chdir: "{{ deploy_helper.current_path }}"
  register: stillgate_receipt
  changed_when: false
  failed_when: false

- name: Show the receipt
  debug:
    var: stillgate_receipt.stdout_lines

- name: Stop unless the receipt is clean
  fail:
    msg: "The receipt is not clean, or nothing can be promised. Read it above before going on."
  when: stillgate_receipt.rc != 0
```

Include it ahead of your own database step, and, if you use a hook that
Trellis already fills, after the role's file:

```yaml
deploy_finalize_before:
  - "{{ playbook_dir }}/roles/deploy/hooks/finalize-before.yml"
  - "{{ playbook_dir }}/deploy-hooks/stillgate-receipt-check.yml"
  - "{{ playbook_dir }}/deploy-hooks/your-database-step.yml"
```

The file fails the deploy in three cases: the live release has no Stillgate
0.2.0 (there is nothing to check, so it does not skip), no freeze is on, and
the receipt is not clean. `wp stillgate receipt --check` exits `1` unless the
receipt is clean: content changed, a scheduled window nobody observed, or no
freeze has run. In a run with `stillgate_freeze_for: 1m`, a wait of 70
seconds and an edit of a post after the freeze had ended, the receipt said
`closed` and `clean: true`; the version of this file that only ran
`receipt --check` let the deploy go on to the database step, and this version
stopped it with "No freeze is on". The first deploy of a site, which has no live
release, and a deploy on which the freeze file skipped itself fail it too.

These rules from [receipt.md](../receipt.md) decide whether the check means
anything:

- **The freeze must be on, or have been on for the whole job.** The receipt
  covers only the time between its start and its end. The file checks that a
  freeze is on when it runs. It cannot tell whether the freeze was on for the
  whole time before that, so keep `stillgate_freeze_for` longer than the work
  or extend the freeze.
- **Take the dump after the freeze began.** The receipt then covers exactly the
  time since the copy was made. A freeze that starts in `deploy_prepare_before`
  covers a dump taken later in the same deploy, not one taken yesterday.
- **Read it before the import.** Stillgate keeps the receipt in the database, so
  importing another database replaces the receipt with the one in the dump.
- It is a detector. It does not merge anything, and the `drift` command lists
  the changed post IDs for a person to deal with.

After a normal deploy the closed receipt stays until the next freeze, so
`wp stillgate receipt` on the server shows afterwards whether content changed
while the deploy ran.

## Verified against

Checked on 2026-10-19:

- Trellis: `roles/deploy/tasks/*.yml`, `roles/deploy/defaults/main.yml`,
  `roles/deploy/hooks/*.yml`, `roles/deploy/vars/main.yml`, `deploy.yml`,
  `rollback.yml`, `roles/rollback/tasks/*.yml`, `ansible.cfg`,
  `group_vars/all/main.yml` and `group_vars/all/users.yml` on `master`
  (commit `2bce3ad`); the deploy-related files were identical at the latest
  release tag, v1.31.1. The list of fourteen hook variables, and the four that
  have defaults, were read from `roles/deploy/tasks/*.yml` and
  `roles/deploy/defaults/main.yml`. The Trellis deploys documentation page for
  the hook list, how to define hooks, the warning about overriding hooks that
  have defaults, and rollback. That page does not document the variables
  available in hooks or the user tasks run as; those come from the source above
  and from the `deploy_helper` module source in the `community.general`
  collection (`current_path` is `<path>/<current_path>`).
- The five YAML blocks on this page, extracted from it and parsed with PyYAML,
  checked with `ansible-playbook --syntax-check`, and run unchanged under
  Trellis `master` `deploy.yml` (variable check, connection test and the deploy
  role) on localhost with ansible-core 2.15.13 and `community.general` 9.5.13,
  against a Bedrock project (WordPress 7.1.3, WP-CLI 2.12.0, PHP 8.4, a
  throwaway MySQL) that Trellis cloned, built with `composer install` and
  switched with `deploy_helper`. Only the `sudo service php-fpm reload` task of
  the role was replaced by a message. The runs:
  - a normal deploy with Stillgate 0.2.0 (the freeze was on during the build, an
    edit by WP-CLI was refused, the release file ended it, `stillgate_started_freeze`
    reached the release file), including a plugin installed as must-use;
  - Stillgate 0.1.1 from WordPress.org on the live release: the freeze was
    skipped, the deploy went on and ended with 0.2.0 live, the next deploy
    froze; the receipt-check file failed; a manual freeze made under 0.1.1 was
    left on; `wp cli has-command stillgate` exited `0` and
    `wp cli has-command "stillgate extend"` exited `1`;
  - the plugin deactivated on the live release, a new release without the plugin
    (the freeze was left to its timer), and a site with no `current` release;
  - a manual freeze with a stored message, a running scheduled window and an
    upcoming scheduled window: the deploy stopped before a release was built and
    changed nothing, and with `stillgate_take_over=true` it replaced them and
    ended its own freeze; a freeze left by a failed deploy stopped the next one;
  - `stillgate_freeze_message` with quotes, a semicolon, backticks and `$HOME`;
  - `stillgate_freeze_for` set to an empty value, `0`, `banana`, `8d` and `30s`
    (each failed the freeze task and started nothing);
  - the receipt check passing on a clean receipt, failing after an edit by a
    bypass account, and failing with a 1 minute freeze, a wait of 70 seconds and
    a late edit; the earlier version of the file, which only ran
    `receipt --check`, passed in that last run; `wp stillgate extend 15m` from a
    hook kept the freeze on past the first minute; a take over that kept the
    earlier receipt and failed the check;
  - an empty `deploy_finalize_before` failed the deploy with `wp_installed`
    undefined.
- The WP-CLI commands and exit codes against the plugin's `docs/wp-cli.md`,
  `docs/receipt.md` and the code, and by running them.

Also run: the freeze file registered after the role's `finalize-before.yml`,
and on `deploy_before` with the path changed to
`{{ project_root }}/{{ project_current_path }}`: each froze, a mid-deploy edit
was refused, and the release hook ended the freeze. A freeze forced with
`DEPLOYMENT_GUARD_FORCE=true` cannot be taken over: the freeze file refuses it,
and `stillgate_take_over` does not lift it, so remove the constant first.

Not run: a remote host over
SSH, the `web` user (the runs used the current
user), the `sudo` php-fpm reload, Vagrant, trellis-cli, Ansible versions other
than 2.15.13, Multisite, PHP notices on
stdout ahead of the JSON of `status --format=json`, a persistent object cache
shared or not shared with php-fpm (see
[architecture.md](../architecture.md#known-limitations)), a pending
`wp core update-db` upgrade during a freeze, and trellis-cli passing
`-e stillgate_take_over=true`.
