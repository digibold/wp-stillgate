<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/img/logo-dark.svg">
    <img src="docs/img/logo.svg" alt="Stillgate" width="340">
  </picture>
</p>

# Stillgate — Editorial Freeze for Deployments

Stillgate protects production WordPress installations during
migrations, deployments, and maintenance windows. It freezes editorial
changes for a set time, can also pause visitor comments and form
submissions, ends by itself when the time is up, and tells you afterwards
whether anything changed while it was on.

## The problem

Deploying a WordPress site — syncing a database, pushing files, running a
migration — usually needs a window where the database will not change
underneath you. In practice, editors keep working: a post saved mid-sync
can be silently overwritten or lost, a media upload can land in a database
that is about to be replaced, a menu edit can vanish. The frontend, though,
should generally stay up the whole time — visitors, customers checking out,
and anyone submitting a form still need the site to work.

Stillgate puts the *backend* into a controlled editorial freeze —
locking content changes — while leaving the public frontend completely
untouched.

## How it works

While a freeze is active, a logged-in user without the
`deployment_guard_bypass` capability is blocked from creating, editing,
deleting, or restoring protected content through classic wp-admin, the
block editor, the REST API, and XML-RPC. WP-CLI commands and WP-Cron events
are blocked from making the same changes. The check is capability-based,
never role-based — grant `deployment_guard_bypass` to any role (or directly
to a user) and it is honored the same way Administrator already is by
default.

Enforcement works through WordPress's own entry points, capability checks,
and content functions. Code that goes around all of them — direct database
queries, or a plugin that writes content without asking WordPress whether
the user may — is outside what it can see; see
[Known limitations](#known-limitations).

A freeze can be turned on immediately, started for a set time (for
example 30 minutes, after which it ends by itself and can be extended if the
deployment runs long), or configured as a scheduled window (start and end, in
the site's own timezone). Whether a freeze is active is computed from the
current time and stored configuration on **every** relevant request — it
never depends on a WP-Cron event having fired on time.

For the full enforcement design — which hooks are used where, and why —
see [docs/architecture.md](docs/architecture.md).

## What is protected

By default: posts, pages, every public custom post type, attachments,
revisions, public taxonomies, navigation menus, widgets, the Customizer,
and the Site Editor (templates, template parts, global styles, navigation,
synced patterns, fonts). The exact effective list — which depends on what
is registered on your site — is shown on the settings screen and in `wp
stillgate status`, and can be narrowed with the
[`deployment_guard_protected_types`](docs/hooks.md) filter.

**Scheduled posts of a protected type do not publish while a freeze is
active.** They stay `future` and publish normally, through WordPress's own
mechanism, once the freeze ends (see
[docs/architecture.md](docs/architecture.md#scheduled-publishing)).

**Not protected:** users, plugins and themes, and general site
settings/options. The public, logged-out frontend is never affected — a
customer checking out is unaffected regardless of whether a freeze is
active. Visitor comments and form submissions also keep working unless you
choose to pause them; see [Pausing comments and forms](#pausing-comments-and-forms-opt-in).

## Bypass behavior

Administrators receive the `deployment_guard_bypass` capability
automatically on activation (granted to every role that already has
`manage_options`, not hardcoded to the Administrator role by name, so a
custom role with equivalent responsibility is covered too). A user with
this capability can continue working during a freeze — but a change they
make can still be lost if a deployment or migration proceeds concurrently;
the freeze protects everyone *else's* content, not a bypass holder's own
edits. Super Admins on Multisite are always treated as bypass holders.

**WP-CLI is not exempt.** `wp post update`, `wp term create`, `wp media
import`, and equivalent commands from other plugins are blocked the same
way while a freeze is active. `--user=<account with bypass>` is the
explicit, intentional way to make a change from the command line during a
freeze — the same identity-based bypass as on the web. Stillgate's
own `wp stillgate` commands keep working during a freeze because
they only change Stillgate's own state; nothing is exempted for
them (see [docs/architecture.md](docs/architecture.md#suspension)).

## Installation

1. Upload the plugin to `/wp-content/plugins/stillgate`, or install
   the zip through Plugins > Add New > Upload Plugin.
2. Activate it. Roles with `manage_options` (Administrator, by default)
   receive the `deployment_guard_bypass` capability automatically.
3. Configure it under **Tools > Stillgate**, or with WP-CLI (see
   below).

Requires WordPress 6.6+ and PHP 8.1+. No Composer step is needed to run the
plugin — see [Development](#development).

## Upgrading from Deployment Guard 0.1.0

Stillgate is the new name of Deployment Guard. Version 0.1.1 renames the
plugin, its folder, text domain and WP-CLI command, but keeps every stored
identifier — the `deployment_guard_state` and `deployment_guard_history`
options, the `deployment_guard_bypass` capability, the
`DEPLOYMENT_GUARD_FORCE` constant and the `deployment_guard_*` hooks — so
your configuration, activity log and bypass roles carry over. Because both
versions read and write the same stored data, only one of them can run at a
time. Switch in this order:

1. Install `stillgate.zip` under Plugins > Add New > Upload Plugin, but do
   not activate it yet.
2. Deactivate **Deployment Guard**. Deactivating it ends a freeze that is
   currently active, as deactivation always does, so switch outside a
   deployment.
3. Activate **Stillgate** and check **Tools > Stillgate**.
4. Delete the old **Deployment Guard** plugin, with Stillgate active.

With WP-CLI:

```bash
wp plugin install stillgate.zip
wp plugin deactivate deployment-guard
wp plugin activate stillgate
wp plugin delete deployment-guard
```

Things to know:

- Delete the old copy only while Stillgate is active. The old release's
  uninstall routine removes the shared options and the bypass capability from
  every role; Stillgate puts back what it removes, but only when it is
  running at that moment. Deleting the old copy while Stillgate is inactive
  erases the freeze configuration, the activity log and the bypass grants.
- If you activate Stillgate while Deployment Guard is still active, Stillgate
  does not start and says so on the Plugins screen; the old copy keeps
  working until you deactivate it, and Stillgate takes over on the next page
  load with the same settings.
- `wp deployment-guard …` is now `wp stillgate …` (there is no alias), and
  the settings screen is **Tools > Stillgate** (`tools.php?page=stillgate`).
  Update any deploy scripts.

## Usage

Everything is on one screen, **Tools > Stillgate**. It leads with the
current state (open, frozen, or a window scheduled) and the one thing to do
next: start a freeze for 15 minutes to 4 hours or until you end it, end it, or
extend a timed one. Below it are the receipt of the latest freeze, Coverage
(what a freeze covers), the schedule, the message shown to editors, the commands
for a pipeline, access, the protected content, and the activity log. Starting a freeze requires an
explicit confirmation checkbox. While a freeze runs, a small status in the
admin bar shows when it ends (a dot on a phone), with end and extend actions
for the people allowed to use them, and Tools > Site Health reports a freeze
that has been left on.

A timed freeze is the safest way to run a deployment: if the job fails or the
runner dies, editing unlocks by itself.

### Scheduled freeze example

To lock content for a maintenance window this weekend, in the site's own
timezone:

- Start: `2026-09-20 22:00`
- End: `2026-09-21 02:00`

The freeze becomes active and ends automatically at those times, computed
fresh on each request — no cron dependency. A manual freeze and a scheduled
window can coexist; the effective rule is "active if either says so".

### WP-CLI

```bash
wp stillgate status
wp stillgate enable --for=30m --message="Deploying now, back in 30 minutes."
wp stillgate extend 15m
wp stillgate scope --comments=on --forms=on
wp stillgate receipt --check
wp stillgate schedule --start="2026-09-20 22:00" --end="2026-09-21 02:00"
wp stillgate disable
```

Full reference, including a deploy script recipe and Multisite usage, in
[docs/wp-cli.md](docs/wp-cli.md).

### Pausing comments and forms (opt-in)

Anything a visitor saves while a deployment runs can be lost when the
database is replaced, the same as an editor's change. Under **Coverage** on
the settings screen, or with `wp stillgate scope`, you can include visitor
comments and form submissions in a freeze. Both default to off, so nothing
changes unless you choose it.

- **Comments**: new comments, reviews, pingbacks and trackbacks are refused
  with a clear message, through the comment form, the REST API and XML-RPC.
  Internal comment types such as WooCommerce order notes keep working.
- **Forms**: submissions of the common form plugins are refused and the
  visitor is told the form is paused. Which plugins were checked, and how, is
  in [docs/forms.md](docs/forms.md); other forms can be added with a filter.
- People who can bypass the freeze can still comment and submit. The check is
  made when a submission arrives, never when a page is shown, so page caches
  cannot serve a stale state.
- Orders and checkout are never paused.

### The freeze receipt

Every freeze leaves a receipt of what happened to the content while it ran:
posts saved, created or trashed, estimated deletions, term changes, comments
added, and how many visitor submissions were paused. A clean receipt tells a
deploy that nothing the receipt can see changed while the freeze was on, so the
database it copied at the start is still complete. A dirty one lists exactly
which posts changed, so you can look at them before overwriting anything. It
only covers the time the freeze was on: run the check while the freeze is still
running, or make the freeze last as long as the work.

```bash
wp stillgate receipt --check || echo "content changed, or nothing can be promised"
wp export --post__in="$(wp stillgate drift)" --dir=/backups
```

The receipt is a detector. It does not synchronize databases, and
[docs/receipt.md](docs/receipt.md) says plainly what it cannot see.

### For pipelines (REST API)

A CI/CD job can start, extend and end a freeze over HTTPS with an Application
Password, no SSH needed:

```bash
curl -fsS -u "$WP_USER:$WP_APP_PASSWORD" -X POST "$SITE/wp-json/stillgate/v1/freeze" -d for=30m
```

See [docs/rest-api.md](docs/rest-api.md) for every route, permissions and a
GitHub Actions example.

### Integrations

Ready-made recipes for freezing a site around a deploy, checking the receipt and
releasing the freeze even when the deploy fails, each run against real tooling:

- **GitHub Actions**: a one-step Action, `digibold/wp-stillgate@action-v1`
  ([docs/recipes/github-actions.md](docs/recipes/github-actions.md))
- **Deployer**: a recipe with a tested database-import sequence
  ([docs/recipes/deployer.md](docs/recipes/deployer.md))
- **Trellis** and **Bedrock**: deploy hooks, Composer install and configuration
  ([docs/recipes/trellis.md](docs/recipes/trellis.md),
  [docs/recipes/bedrock.md](docs/recipes/bedrock.md))

An overview, and how to choose between WP-CLI and the REST API, is in
[docs/recipes](docs/recipes/README.md).

## REST API and XML-RPC behavior

A blocked REST write returns **HTTP 423 Locked** with a machine-readable
WordPress REST error:

```json
{
  "code": "deployment_guard_active",
  "message": "Content changes are temporarily locked while a deployment is in progress.",
  "data": {
    "status": 423,
    "deployment_guard": { "ends_at": "2026-09-21T02:00:00+00:00" }
  }
}
```

`ends_at` is `null` when the freeze has no predictable end (a manual
freeze, active until an operator disables it). Read-only requests (`GET`,
`HEAD`, `OPTIONS`) are never blocked by this check; a method override
header or query argument cannot be used to disguise a write as a safe
method.

XML-RPC's content-changing methods (`wp.newPost`, `wp.editPost`,
`wp.deletePost`, `wp.newTerm`, and similar) return an `IXR_Error` with
fault code `423` and the same message. Read-only methods, comment methods,
and `pingback.ping` are unaffected.

## Multisite behavior

Stillgate operates per site in this version — there is no network-wide
freeze screen. Each site's freeze is configured, and enforced,
independently; a Super Admin is always treated as a bypass holder, on every
site. See [docs/architecture.md](docs/architecture.md#multisite) for
activation, deactivation, and uninstall behavior across a network.

## Known limitations

The complete list, with the reasoning behind each, is in
[docs/architecture.md](docs/architecture.md#known-limitations). In short:

- A bypass holder's own changes can still be overwritten by a concurrent
  sync.
- Freeze state lives in the database, so a database import can lift or
  copy it. The `DEPLOYMENT_GUARD_FORCE` constant (the boolean `true` or
  `false`) in `wp-config.php` overrides the stored state.
- A persistent object cache not shared between WP-CLI and web servers, or
  a long-running PHP process, can keep seeing an old state until the cache
  is flushed or the process restarts.
- Not blocked: direct database queries; third-party code that writes
  protected content on the web without a capability check; plugin REST
  routes with controllers of their own; direct `wp_publish_post()` calls;
  and, in WP-CLI and WP-Cron, post meta, term assignments to existing
  terms, widgets, menu locations, theme mods and other options, plus term
  updates and deletions made during a cron event.
- Automated writes to protected content — cron jobs such as emptying the
  trash or product updates, and plugin activation routines run through
  WP-CLI — fail for the duration of a freeze.
- Restricted users cannot preview drafts of protected content during a
  freeze.

## Security philosophy

- Layered enforcement: every entry point that can change protected content
  (classic admin, AJAX, REST, XML-RPC, WP-CLI, WP-Cron) is gated where the
  request enters, with a capability-layer backstop (`map_meta_cap`) and
  data-layer short-circuits underneath for paths the gates do not see —
  see [docs/architecture.md](docs/architecture.md).
- Capability-based throughout, never role-based by name.
- Nonces and capabilities are verified on every state-changing request to
  the settings screen; input is sanitized and output is escaped. The only
  direct SQL is the freeze receipt's read-only, prepared queries; nothing
  writes to WordPress's own tables.
- No custom database table; four options (freeze state, activity log,
  coverage choice, receipt), removed on uninstall.
- The activity log never stores request payloads, message bodies, or IP
  addresses — only a timestamp, event type, user ID, and a short,
  allowlisted context.

If you find a security issue, please see [SECURITY.md](SECURITY.md) rather
than opening a public issue.

## Privacy and telemetry

Stillgate makes **no outbound HTTP requests** and sends **no data to
any external service**. It requires no external account, license key, or
cloud service. Its activity log is a bounded local option, described to
site owners through WordPress's own Privacy Policy content mechanism, and
is deleted entirely on uninstall. The freeze receipt keeps counts,
timestamps and post IDs only, never content or personal data, and is deleted
on uninstall too.

## Development

Stillgate has **no runtime Composer dependency** — a
`git archive`/zip of the repository runs on its own, with its own tiny
PSR-4 autoloader (`src/autoload.php`), because the distributed plugin never
requires site owners to run Composer. Composer is only used for
development: coding-standards tooling and the test suite's private copy of
WordPress core.

```bash
composer install
composer lint             # php -l
composer phpcs             # WordPress Coding Standards
composer test:unit         # no WordPress required
composer test:integration  # requires a MySQL database — see below
composer test:e2e          # real WP-CLI and a real web server — see below
```

## Testing

Pure logic (schedule math, datetime parsing, the bounded history's
dedup/throttle rules) is unit-tested with no WordPress dependency in
`tests/Unit`. Everything that touches WordPress — capability checks, every
enforcement layer, the settings screen, WP-CLI commands, Multisite — is
integration-tested against a real WordPress + MySQL environment
(`wp-phpunit`) in `tests/Integration`. `tests/e2e/cli.sh` installs the
distributed build on a throwaway site and exercises it through real WP-CLI
commands and real HTTP requests (wp-admin, REST, XML-RPC,
`ALTERNATE_WP_CRON`, uninstall). See
[CONTRIBUTING.md](CONTRIBUTING.md#running-the-checks) for how to set up a
test database and run the suites, including the AJAX, XML-RPC, and
Multisite groups.

## Contributing

Bug reports, feature discussion, and pull requests are welcome — see
[CONTRIBUTING.md](CONTRIBUTING.md). Please review the
[Code of Conduct](CODE_OF_CONDUCT.md) before participating, and see
[SECURITY.md](SECURITY.md) for reporting a vulnerability privately.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (c) 2026 [Digibold SIA](https://createit.lv).
