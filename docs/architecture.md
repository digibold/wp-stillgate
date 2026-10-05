# Architecture

This document describes how Stillgate enforces a freeze, and why
each mechanism is used. It assumes familiarity with WordPress plugin
development; for what the plugin does and how to use it, see
[README.md](../README.md).

## Overview

```
Plugin (bootstrap)
 ├─ FreezeService ── owns deployment_guard_state / deployment_guard_history
 │    └─ History     (bounded, deduplicated, throttled log)
 ├─ Guard ── restriction policy: who is affected, and by what
 ├─ Suspension ── narrow internal guard for Stillgate's own CLI commands
 └─ Enforcement layers, overlapping on purpose:
      ├─ CapabilityFilter  (map_meta_cap — every context)
      ├─ AdminGate         (classic wp-admin screens — web)
      ├─ AjaxGate          (admin-ajax.php — web)
      ├─ RestGate          (REST API — web, and REST calls made from CLI/cron)
      ├─ XmlRpcGate        (XML-RPC — web)
      ├─ DataLayerGuard    (core write functions themselves — web/cli/cron)
      └─ ScheduledPublishGuard (core's own scheduled-post publisher — cron)
```

`FreezeConfig`, `DateInput`, and `History::append()` are pure value
objects/functions with no WordPress dependency, which is what lets them run
under plain PHPUnit (`tests/Unit`) without a database.

## Actor contexts

Every enforcement decision starts from `Guard::context()`, one of three
values:

- **`web`** — a browser request: classic wp-admin, the block editor, REST
  calls the editor itself makes, XML-RPC.
- **`cli`** — WP-CLI, running any command other than Stillgate's own
  (see [Suspension](#suspension)).
- **`cron`** — a WP-Cron event actually running, including `wp cron event
  run`, which core marks the same way (`DOING_CRON`) a web-triggered
  `wp-cron.php` request would be.

`cron` takes priority over `cli`, because `wp cron event run` is both at
once in the same process. This matters: **cron is always restricted, with
no bypass capability and no filter.** A scheduled task has no identity to
check a capability against, and letting it inherit whichever user happens
to be "current" (typically none, but a badly written cron callback could
call `wp_set_current_user()`) would turn cron into an unrestricted write
path — which is exactly what the freeze exists to prevent. `web` and `cli`
restriction is capability-based (`deployment_guard_bypass`), checked
against whichever role holds it, never against a role's name.

## What "applies" means

`Guard::applies_to_post()` (and the equivalent for a taxonomy, a design
surface, or XML-RPC) is `true` only when all of the following hold:

1. The current actor is restricted (see above).
2. The object's type is in the protected set (`deployment_guard_protected_types`).
   A revision or autosave is resolved to its parent post first, so it is
   protected exactly when the parent is.
3. **For `web` only**, the actor natively holds the matching primitive
   capability (`edit_posts` for the object's post type, `upload_files` for
   attachments, `edit_terms`/`assign_terms` for a taxonomy,
   `edit_theme_options` for a design surface). A subscriber, or a logged-in
   customer placing an order, is never blocked or logged: they could never
   have made this change anyway.

`cli` and `cron` skip step 3 entirely — a scheduled task or a `wp` command
carries no editorial capability to test in the first place.

## Enforcement layers

The layers overlap on purpose. A request-level gate answers at the door a
request came through, with a clear 423 response and the freeze message;
the capability and data layers underneath catch paths no gate sees. No
single layer covers everything, and some writes are outside what any of
them can see — see [Known limitations](#known-limitations).

| Layer | Contexts | Mechanism |
| --- | --- | --- |
| `CapabilityFilter` | all | `map_meta_cap`, denies `edit_post`/`delete_post`/`publish_post`/`edit_term`/`delete_term`/`assign_term` (and their nested meta caps) for a protected object |
| `AdminGate` | web | `load-{$pagenow}` (and, for Custom Header and Custom Background, each screen's own `load-{$page_hook}`) on every classic admin screen that can change protected content |
| `AjaxGate` | web | `admin_init`, before admin-ajax.php dispatches, for the specific `action` values that write protected content |
| `RestGate` | web (+ REST calls made from CLI/cron) | `rest_request_before_callbacks`, matching the route's controller by instance identity |
| `XmlRpcGate` | web | `xmlrpc_call`, a denylist of content-changing methods |
| `DataLayerGuard` | web/cli/cron | short-circuits on the underlying core functions (`wp_trash_post`, `wp_delete_post`, `wp_insert_term`, upload prefilters, `wp_insert_post`) themselves |
| `ScheduledPublishGuard` | cron | wraps core's own `publish_future_post` cron callback |

`CapabilityFilter` catches any code — core's or a plugin's — that asks
WordPress whether the current user may change a protected object
(`current_user_can( 'edit_post', ... )` and every meta capability derived
from it). It cannot see code that writes without asking: a plugin calling
`wp_insert_post()` with no capability check of its own, or direct SQL. For
WP-CLI and WP-Cron, `DataLayerGuard` short-circuits the core write
functions themselves; on the web, such third-party code is a known
limitation.

### REST target resolution

`RestGate` resolves a matched route's target by the **identity** of the
controller instance WordPress itself registered the route with
(`WP_Post_Type::get_rest_controller()`, `WP_Taxonomy::get_rest_controller()`,
and the revisions/autosave controller equivalents), not by guessing from
the URL. A method override (`?_method=DELETE` or the
`X-HTTP-Method-Override` header) is already applied by the time this runs,
so it cannot be used to disguise a write as a safe method. `/batch/v1`
sub-requests are matched and gated individually, the same way, as they are
dispatched.

Creating a term of a non-hierarchical taxonomy through its collection route
is checked against the taxonomy's *assign* capability, because that is what
core itself requires for it; every other term write is checked against the
taxonomy's edit capability.

A route a plugin registers with a controller of its own — neither a
registered post type's or taxonomy's controller, nor an instance of a core
content controller class — is not recognized. The
[`deployment_guard_rest_is_protected`](hooks.md) filter can protect it.

### Data-layer backstops and why some are CLI-only

`pre_trash_post`, `pre_untrash_post`, `pre_delete_post`,
`pre_delete_attachment`, and `pre_insert_term` are all **filters** that can
cleanly short-circuit the corresponding core function with a false/error
return, in any context. Because a revision is resolved to its parent,
deleting a revision of protected content is blocked the same way.

`wp_update_term()` and `wp_delete_term()` have no such filter — only the
`edit_terms` and `pre_delete_term` **action** hooks, fired before either
makes any change, with no way to return a value that aborts them. In `cli`
context, `DataLayerGuard` uses these to call `WP_CLI::error()` directly,
which ends the process. This is not available in `web` or `cron` context:
`AdminGate`/`AjaxGate`/`RestGate` already cover every core path to updating
or deleting a term on the web, and aborting a cron *event* outright (rather
than just this one write failing) would stop unrelated events in the same
run — so a term update/delete reached this way during cron is a documented
limitation, not a gap in a layer that could otherwise close it.

`wp_insert_post_empty_content` (which can force any `wp_insert_post()` —
including through `wp_update_post()` — to fail) is scoped to `cli`/`cron`
only. On the web, `AdminGate`, `AjaxGate`, `RestGate`, and
`CapabilityFilter` already cover every core path into `wp_insert_post()`;
applying this filter there too risks a false positive against core's own
internal empty-content inserts (auto-drafts, revisions). In `cli`/`cron` it
is the only backstop available at all, since neither WP-CLI's own commands
nor a cron callback go through any admin/AJAX/REST gate. The one exception
is the `wp_global_styles` and `wp_navigation` posts WordPress *creates*
automatically while rendering; updating one that already exists is blocked
like any other protected write.

### Scheduled publishing

`ScheduledPublishGuard` replaces core's own `check_and_publish_future_post`
callback on the `publish_future_post` cron hook — but only if that callback
is still registered at its original priority; if another plugin has
already replaced it, this class steps aside rather than risk two
publishers running. The swap happens at the very end of `init`: after any
plugin that replaces core's publisher has had its chance, and before
`wp_loaded`, where `wp_cron()` runs due events inside the page request
itself when `ALTERNATE_WP_CRON` is enabled.

When a due, protected `future` post would otherwise publish while a freeze
is active, it is left as `future` and rescheduled a few minutes out (or
exactly at a scheduled freeze's end, if that is sooner); every other due
event in the same cron run, and every other post, runs through core's own
function completely unmodified. This check is independent of any user,
capability, or the `deployment_guard_is_restricted` filter — a cron event
has no identity, and nothing can bypass it.

## WP-CLI is not exempt

Every WP-CLI command other than `wp stillgate` itself is treated as
`cli` context: `wp post update`, `wp post delete`, `wp term create`, `wp
media import`, and the equivalent commands from third-party plugins are
all subject to `CapabilityFilter` and `DataLayerGuard` exactly like any
other write. `--user=<account>` is the explicit, intentional way to act as
a specific person — including a bypass holder — from the command line,
exactly as it would be on the web. Commands that write post meta, term
assignments, or options directly are not blocked; see
[Known limitations](#known-limitations).

## Suspension

Stillgate's own `status`/`enable`/`disable`/`schedule` commands only
read and write Stillgate's own two options, which no enforcement
layer protects, so they keep working during a freeze without anything
being relaxed for them. They still run inside `Suspension::run()`, a
narrowly scoped internal guard, built so that the most it could ever
exempt is the command's own code — never anything else:

- Only `Cli\Command`'s own method bodies ever call `Suspension::run()`.
- There is no hook, option, or constant that opens a suspension from
  anywhere else.
- It exists only for the duration of that one call (`try`/`finally`, so it
  closes even if the command throws), and is checked nowhere except
  `Guard::is_restricted()`.
- It applies only at the exact hook nesting depth `run()` was entered at.
  Anything WordPress dispatches through an action or filter while a command
  runs — a plugin's `updated_option` or `shutdown` callback, a
  `deployment_guard_state_changed` listener — runs deeper, and is enforced
  exactly as it would be outside the command. Every enforcement layer is
  itself a hook callback, so in practice the suspension never relaxes
  enforcement for anything.
- `WP_CLI::error()` exits the process, which skips `finally`; the commands
  therefore always call it outside the suspension.

## Data model

Two options, both created on activation and **not autoloaded** (an
autoloaded option is rewritten as part of WordPress's combined
`alloptions` blob on every write to *any* autoloaded option; with a
persistent object cache, that read-modify-write race could put back a
stale freeze state):

- `deployment_guard_state` — `enabled` (bool), `schedule_start`/
  `schedule_end` (UTC Unix timestamps or `null`), `message`, `updated_at`,
  `updated_by`.
- `deployment_guard_history` — see [Activity history](#activity-history).

`FreezeService` caches both in memory for the lifetime of the request, and
invalidates that cache on the corresponding `add_option_*`/
`update_option_*`/`delete_option_*` hooks — not only its own writes, but
any write to the same option from anywhere, including a *different*
`FreezeService` instance in the same process (which matters for WP-CLI
sessions and the test suite, where the plugin's own bootstrap instance and
a script's own instance can otherwise disagree) — and on `switch_blog`, so
after `switch_to_blog()` both are read again from the site that is now
current.

`Guard` separately memoizes `is_restricted()` per `blog_id|user_id|context`
for the lifetime of the request, invalidated on `set_current_user`,
`switch_blog`, and the same state-option hooks.

### Activity history

A single bounded, append-only log, capped at 100 entries total (of which at
most 60 may be "blocked write" entries — state changes always get priority
over the older blocked-write history). A blocked-write entry is dropped
outright, rather than recorded, if an identical one (same user, vector, and
target) was already logged within the last 10 minutes, or if the global
(30 per 10 minutes) or per-user (10 per 10 minutes) throttle has been
reached — so a determined or automated stream of blocked requests cannot
grow the option without bound. Nothing but a short, allowlisted context
(vector, target, source, and similar) is ever stored: no request payload,
IP address, or message body.

## Multisite

Stillgate operates per site. Activation installs only the site being
activated; a Multisite network's other sites install themselves lazily
(`Installer::maybe_install()`, cheap enough to run on every `admin_init`
and before every state-changing call) the first time an administrator
visits wp-admin or a WP-CLI command targets that site. Network deactivation
likewise clears the freeze on the current (main) site only: a freeze still
enabled on another site takes effect again if the plugin is reactivated
there.

A Super Admin is treated exactly like any other bypass-capability check:
`map_meta_cap` itself denies them only via an explicit `do_not_allow`, and
WordPress core short-circuits everything else for a Super Admin before
Stillgate's filter even has a chance to run — so a Super Admin is
effectively always a bypass holder, on every site. Code that uses
`switch_to_blog()` is enforced against the freeze state of the site it
switched to. Uninstalling pages through every site on the network (100 at a
time) to remove both options and the bypass capability everywhere.

## Known limitations

- **Bypass holders' changes can still be lost.** The freeze prevents
  everyone *else* from changing protected content; it cannot prevent a
  bypass holder (or WP-CLI with `--user=<bypass account>`) from making a
  change that a concurrent database sync then overwrites.
- **Freeze state lives in the database.** A database import during a
  migration can lift or copy the freeze along with everything else. The
  `DEPLOYMENT_GUARD_FORCE` constant in `wp-config.php` is the way around
  this for a deployment script that needs a guarantee independent of the
  database. It must be the boolean `true` or `false`; any other value
  (`1`, `'true'`) is ignored.
- **Caches and long-running processes.** A persistent object cache not
  shared between WP-CLI and web workers can mean a CLI-issued state change
  is not immediately visible to web requests; `wp cache flush` after a CLI
  change addresses this. A long-running PHP process (a queue worker, `wp
  shell`) that read the state before a manual freeze was turned on keeps
  its copy until it restarts. Scheduled windows are evaluated against the
  current time and are not affected.
- **Code that writes without asking WordPress first.** Third-party code
  that changes protected content on the web without a capability check
  (a front-end submission form calling `wp_insert_post()` directly, for
  instance), admin screens added by plugins that create content
  themselves (importers, for instance), a plugin's REST route with a
  controller of its own (see
  [REST target resolution](#rest-target-resolution)), direct SQL, and a
  direct call to `wp_publish_post()` are not blocked.
- **WP-CLI and WP-Cron writes below the post and term functions.** Post
  meta and term assignments to existing terms (`wp post meta update`, `wp
  post term add`, `wp media regenerate`), and widgets, menu locations,
  theme mods and other options (`wp widget add`, `wp menu location assign`,
  `wp theme mod set`) are not blocked there; those design surfaces are
  protected at their wp-admin, AJAX, and REST entry points only.
  `wp_update_term()`/`wp_delete_term()` writes made during a cron event are
  not blocked either (see
  [Data-layer backstops](#data-layer-backstops-and-why-some-are-cli-only)).
- **Automated writes to protected content fail during a freeze.** Cron
  events and WP-CLI commands run as nobody in particular, so their writes
  to protected content are blocked for the duration: emptying the trash,
  deleting old auto-drafts, importers, and scheduled jobs that update
  products fail (quietly, in cron). A plugin whose activation routine
  creates protected content fails under `wp plugin activate`; activate it
  before the freeze, or with `--user=<bypass account>`.
- **WP-Cron keeps running.** Unrelated events, and reads, are never
  affected; only publishing protected scheduled content is held back (see
  above). A plugin that replaces core's scheduled-post publisher is left
  alone, and posts it publishes are not held.
- **Restricted users lose some reads as well.** Previewing a draft and
  `context=edit` REST reads of protected content are unavailable to them
  during a freeze.
- **Comments, Notes (WordPress 6.9+), users, plugins, themes, and general
  site options are out of scope.**
- **REST batch requests and XML-RPC's `system.multicall` are not atomic.**
  A blocked sub-request does not undo ones that already succeeded earlier
  in the same batch.
