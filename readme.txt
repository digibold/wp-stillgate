=== Stillgate — Editorial Freeze for Deployments ===
Contributors: twelvell
Tags: deployment, migration, maintenance, freeze, editorial
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Freezes editing during deployments and migrations, optionally pauses comments and forms, ends on a timer, and reports what changed.

== Description ==

Deploying a WordPress site — syncing a database, pushing files, running a
migration — usually needs a window where the database will not change
underneath you. In practice, editors keep working, and a post, upload, or
menu change made mid-sync can be silently lost. Stillgate puts the
*backend* into a controlled editorial freeze during that window, while the
public frontend keeps working normally the whole time.

**What a freeze does**

While active, a logged-in user without the `deployment_guard_bypass`
capability is blocked from creating, editing, deleting, or restoring
protected content — posts, pages, custom post types, media, revisions,
taxonomies and terms, navigation menus, widgets, or the Customizer —
through classic wp-admin, the block editor, the REST API, and XML-RPC;
WP-CLI commands and WP-Cron events are blocked from the same content
changes. Scheduled posts of a protected type do not publish while the
freeze is active; they remain scheduled and publish normally, through
WordPress's own mechanism, once it ends.

A freeze can be turned on immediately, started for a set time (for example
30 minutes, after which it ends by itself and can be extended if the
deployment runs long), or configured as a scheduled window in the site's own
timezone. Whether it is active is computed from the current time and stored
configuration on every relevant request — it never depends on a WP-Cron event
firing on time.

**Pause comments and forms (optional)**

Anything a visitor saves while a deployment runs can be lost when the database
is replaced, the same as an editor's change. Under Coverage you can include
visitor comments and form submissions in a freeze. Both are off by default.
Visitors are told clearly that the form is paused; people who can bypass the
freeze can still submit; WooCommerce orders and checkout are never paused.
Contact Form 7, WPForms, Ninja Forms, Fluent Forms, Forminator, Formidable and
Everest Forms were each checked on a real site; other forms can be added with a
filter. The check happens when a submission arrives, never when a page is
shown, so page caches cannot serve a stale state.

**Freeze receipt**

Every freeze leaves a receipt of what changed in the content while it ran:
posts saved, created or trashed, estimated deletions, term changes, comments
added, and how many visitor submissions were paused. A clean receipt tells your
deploy that nothing it can see changed while the freeze was on, so the database
it copied at the start is still complete; otherwise it lists exactly which posts
to look at before anything is overwritten. It covers only the time the freeze
was on. It is a detector, not a synchronizer: it does not merge databases, and it says so in
the documentation.

**For pipelines**

`wp stillgate enable --for=30m`, `wp stillgate extend 15m`, `wp stillgate
receipt --check`, and a REST API (`/wp-json/stillgate/v1`, authenticated with
Application Passwords) let a CI job freeze, extend and release a site without
SSH. A timed freeze unlocks by itself even if the job dies.

**Bypass is capability-based, not role-based**

Roles with `manage_options` (Administrator, by default) receive the
`deployment_guard_bypass` capability automatically on activation — the
check is against the capability, never hardcoded to a role's name, so a
custom role can be granted it too. A bypass holder can keep working during
a freeze, though their own changes can still be lost if a deployment
proceeds concurrently; the freeze protects everyone *else's* content.

**WP-CLI is not exempt.** `wp post update`, `wp term create`, `wp media
import`, and equivalent commands from other plugins are blocked the same
way during a freeze; `--user=<account with bypass>` is the explicit way to
act as a specific person from the command line. Stillgate's own `wp
stillgate` commands keep working, because they only change Stillgate's own
state.

**Security and privacy**

Stillgate makes no outbound HTTP requests, sends no data to any
external service, requires no account or license key, and collects no
telemetry. It uses no custom database table — four options, all removed on
uninstall — and its small internal activity log never stores request
payloads, message bodies, or IP addresses. The freeze receipt keeps counts,
timestamps and post IDs only, never content or personal data.

**Known limitations, stated plainly**

* A bypass holder's own changes are not protected from being overwritten.
* Freeze state lives in the database; a database import during a migration
  can lift or copy it. The `DEPLOYMENT_GUARD_FORCE` constant in
  `wp-config.php` (the boolean `true` or `false`; any other value is
  ignored) forces the freeze on or off independently of the stored state,
  for exactly this situation.
* With a persistent object cache not shared between WP-CLI and web
  workers, a CLI-issued change may need `wp cache flush` to take effect
  immediately for web requests; a long-running PHP process keeps the state
  it read until it restarts.
* Changes that go around WordPress's own content functions and capability
  checks are not blocked: direct database queries, third-party code that
  writes content without checking the user's capabilities, plugin REST
  routes with controllers of their own, and direct `wp_publish_post()`
  calls.
* In WP-CLI and WP-Cron, post meta, term assignments to existing terms,
  widgets, menu locations, theme mods and other options are not blocked,
  nor are term updates and deletions made during a cron event.
* Automated writes to protected content fail while a freeze is active:
  cron jobs such as emptying the trash or updating products, and plugin
  activation routines run through WP-CLI.
* Restricted users cannot preview drafts of protected content during a
  freeze.
* WP-Cron keeps running; unrelated events are unaffected, and only
  publishing protected scheduled content is held back specifically.
* Users, plugins, themes, and general site settings are out of scope.
  Visitor comments and form submissions are paused only if you choose to, and
  only for the form plugins listed above; comments inserted directly with
  `wp_insert_comment()` (importers, `wp comment create`) are not paused.
* The receipt only sees content saved through WordPress; a change written
  straight to the database that does not touch the post's modified time is
  invisible to it, and orders kept outside the posts table are not listed.
* Operates per site on Multisite in this version; there is no
  network-wide freeze screen yet, and network activation or deactivation
  affects the current site's state only.

Full architecture notes, the complete enforcement matrix, and every hook
this plugin exposes are documented in the [GitHub repository](https://github.com/digibold/wp-stillgate).

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/stillgate`, or
   install it through Plugins > Add New > Upload Plugin.
2. Activate the plugin. Roles with `manage_options` receive the
   `deployment_guard_bypass` capability automatically.
3. Go to Tools > Stillgate to configure a freeze, or use WP-CLI:
   `wp stillgate enable`.

== Frequently Asked Questions ==

= Will this stop the public site from working? =

No. Stillgate only restricts editorial *writes* from logged-in
users without the bypass capability. Anonymous visitors, logged-in
customers, and every other frontend read or write that is not editorial
content stay unaffected. The only exception is one you choose: pausing
visitor comments and form submissions for the length of a freeze, which is off
by default.

= Does this protect against database changes made outside WordPress? =

No. Stillgate enforces its freeze through WordPress's own request
handling (admin screens, REST, XML-RPC, WP-CLI, WP-Cron), capability
checks, and content functions. A direct database edit, a raw SQL script,
or a completely separate application writing to the same database is
outside anything a WordPress plugin can see or prevent.

= Does this sync databases between environments? =

No, and it deliberately does not try. Merging two databases that both changed
needs knowledge of every plugin's tables and of which side wins each conflict;
a plugin that guessed would eventually overwrite the wrong thing. What
Stillgate does is make the risky moment safe: it keeps the database still while
you copy it, and the receipt tells you afterwards whether anything changed, with
the exact post IDs if it did. See the documentation in the repository.

= Does this work with my form plugin? =

Contact Form 7, WPForms, Ninja Forms, Fluent Forms, Forminator, Formidable and
Everest Forms were each checked on a real site. Gravity Forms and Elementor
Pro forms are recognized by their known request shapes but were not tested,
because they are paid plugins. Any other form can be added with the
`deployment_guard_form_requests` filter, which the repository documents.

= Does this work with WooCommerce? =

Checkout, orders and order notes keep working during a freeze. Pausing comments
also pauses product reviews from visitors. Stock, sales counts and ratings
change by themselves while a shop sells, so on a shop the receipt will list
products as changed; the `deployment_guard_receipt_post_types` filter can leave
them out.

= Does WP-CLI bypass the freeze? =

No. Commands that create, update, or delete protected posts, pages, media,
and terms are blocked and exit with an error. Lower-level commands that
write post meta, term assignments, widgets, menu locations, theme mods, or
other options directly are not blocked. Stillgate's own `wp
stillgate` commands keep working because they only change the
freeze state itself.

= What happens to scheduled (future-dated) posts during a freeze? =

A protected post scheduled to publish during an active freeze stays
scheduled instead. It publishes normally, through WordPress's own
mechanism, once the freeze ends.

= Does this work on Multisite? =

Yes, per site. Each site's freeze is configured and enforced
independently; there is no network-wide screen in this version. A Super
Admin is always treated as a bypass holder.

= Does this send any data anywhere? =

No. Stillgate makes no outbound HTTP requests and has no telemetry.
Everything it stores stays in your own site's database.

= I installed 0.1.0 as "Deployment Guard". How do I switch to Stillgate? =

Stillgate keeps the earlier release's stored settings, so nothing needs to be
reconfigured, but both copies share them and only one can run. Install the new
ZIP without activating it, deactivate Deployment Guard (this ends a freeze that
is active), activate Stillgate, and then delete the old copy while Stillgate is
active: Stillgate keeps the freeze configuration, the activity log and every
role's bypass capability through that deletion. Do not delete the old copy while
Stillgate is inactive, which erases them. If you activate Stillgate first, it
stays idle and says so on the Plugins screen until you deactivate the old copy.
With WP-CLI: `wp plugin install stillgate.zip`, `wp plugin deactivate
deployment-guard`, `wp plugin activate stillgate`, `wp plugin delete
deployment-guard`.

= I removed my own bypass capability by mistake. How do I recover? =

WP-CLI (`wp stillgate disable`) and the `DEPLOYMENT_GUARD_FORCE`
constant in `wp-config.php` both work independently of the
`deployment_guard_bypass` capability.

== Screenshots ==

1. The control room: start a freeze for a set time, or until you end it, with an optional message for editors.
2. A running timed freeze with its end time, buttons to extend it, and the receipt so far.
3. The freeze receipt lists the posts that changed while the freeze was on, and how many comments were paused.
4. What an editor sees: the status in the admin bar and a notice that explains the freeze.

== Changelog ==

= 0.1.1 =
Renamed from Deployment Guard to Stillgate. The stored settings, activity log
and bypass capability carry over. The freeze notice now appears only on the
screens where editing is affected and explains how the freeze ends. See the
full changelog at
https://github.com/digibold/wp-stillgate/blob/main/CHANGELOG.md

= 0.1.0 =
Initial release. See the full changelog at
https://github.com/digibold/wp-stillgate/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 0.1.1 =
Renamed from Deployment Guard. If you installed 0.1.0 from GitHub, install this
version, deactivate Deployment Guard, activate Stillgate, and only then delete
the old copy; the FAQ has the exact steps. `wp deployment-guard` is now `wp stillgate`.

= 0.1.0 =
Initial release.
