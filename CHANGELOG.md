# Changelog

All notable changes to Stillgate (published as Deployment Guard up to 0.1.0) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Timed freezes.** `wp stillgate enable --for=30m`, a duration picker on the settings screen and `POST /freeze` with `for` start a freeze that ends by itself, so a deployment that fails halfway cannot leave editing locked. Durations are `30m`, `2h`, `1d` or combinations such as `1h30m`; at least one minute, at most seven days.
- **Extend.** `wp stillgate extend 15m`, the +15/+30/+60 minute buttons and `POST /freeze/extend` push the end of a timed or scheduled freeze back when a deployment is taking longer than planned.
- **REST API for pipelines**, `stillgate/v1`: `/status`, `/freeze`, `/freeze/extend`, `/schedule`, `/scope` and `/receipt`, authenticated with Application Passwords and limited to people who may change the freeze on the settings screen. See `docs/rest-api.md`.
- **Pause comments and forms during a freeze (opt-in).** Under Coverage on the settings screen, or `wp stillgate scope --comments=on --forms=on`, new visitor comments, and submissions of Contact Form 7, WPForms, Ninja Forms, Fluent Forms, Forminator, Formidable and Everest Forms (each checked on a real site), are refused with a clear message while a freeze runs, instead of being saved into a database that is about to be replaced. Both default to off. The check is made when a submission arrives, never when a page is shown, so full-page caches cannot serve a stale state. Recognition is extensible with the `deployment_guard_form_requests` filter; see `docs/forms.md`.
- **Freeze receipt.** Every freeze now leaves a receipt of what changed in the content while it ran: posts saved, created or trashed, estimated deletions, term changes, comments added, and how many visitor submissions were paused. `wp stillgate receipt [--check]`, `wp stillgate drift`, `GET /receipt` and a card on the settings screen show it. It is a detector, not a synchronizer; see `docs/receipt.md` for what it can and cannot see. `receipt --check` passes only for a clean receipt. A scheduled window that began and ended unobserved is recorded as unverified rather than leaving an older receipt standing.
- **Redesigned settings screen.** Tools > Stillgate leads with the current state and the next action, followed by the receipt, Coverage, the schedule and message, commands for pipelines, and the activity log.
- **Admin bar status.** A small pill shows an active freeze and when it ends, or a window starting within the hour, with end and +15 minutes actions for people allowed to use them. It is a dot on a phone, disappears by itself, and is built for logged-in users only.
- **Site Health test.** Reports an active freeze, a manual freeze that has run for six hours or more, a freeze forced on in `wp-config.php`, and a state nobody can end from wp-admin.
- Filters `deployment_guard_submissions_paused`, `deployment_guard_visitor_message`, `deployment_guard_paused_comment_types` and `deployment_guard_form_requests`; the `deployment_guard_state_changed` source can now be `rest`.
- Logo, icon and banner artwork for the plugin directory and the repository.

### Changed

- The administrator notice is now decided by the same shared check as the freeze itself.
- Uninstalling also removes the stored coverage choice and the receipt.

## [0.1.1] - 2026-10-05

The plugin is renamed from Deployment Guard to Stillgate. Stored settings carry over; see "Upgrading from Deployment Guard 0.1.0" in the README for the switch-over sequence.

### Changed

- Renamed to **Stillgate — Editorial Freeze for Deployments**. The WordPress.org slug, text domain, main file (`stillgate.php`), distribution folder and WP-CLI command all become `stillgate`: `wp deployment-guard` is now `wp stillgate` (there is no alias), and the settings screen moves to `tools.php?page=stillgate`.
- Stored identifiers are unchanged so existing sites keep their state: the `Digibold\DeploymentGuard` namespace, `DEPLOYMENT_GUARD_FORCE`, the `deployment_guard_bypass` capability, the `deployment_guard_state` and `deployment_guard_history` options, and every `deployment_guard_*` hook, filter and REST error code.
- The classic admin notice is no longer shown on every admin screen. It appears on the dashboard and on the screens that list or edit protected content, including custom post types and taxonomies (it follows the `deployment_guard_protected_types` filter), explains how the freeze ends, and disappears by itself when the freeze ends. It cannot be dismissed and stores no state. The block-editor notice is unchanged.

### Added

- Stillgate stays passive, and says so on the Plugins screen, when the earlier Deployment Guard copy (or a second Stillgate folder) is already active, instead of registering its hooks twice.
- While Stillgate is running, deleting the earlier Deployment Guard copy no longer erases the shared freeze configuration, activity log or bypass capability grants: Stillgate restores what the old copy's uninstall routine removes, on every site of a network.
- `tests/e2e/migration.sh`, an end-to-end test of the upgrade path using the real 0.1.0 and 0.1.1 archives.

## [0.1.0] - 2026-10-04

Initial release.

### Added

- Manual editorial freeze: turn content protection on or off from Tools > Deployment Guard or WP-CLI.
- Scheduled freeze windows, evaluated against the site timezone and current time on every relevant request rather than relying on WP-Cron.
- Content write protection for classic wp-admin and the block editor: posts, pages, custom post types, revisions, taxonomies and terms, media, navigation menus, widgets, and the Customizer.
- REST API write protection for the same content, leaving read-only requests untouched.
- XML-RPC write protection for the content-changing methods, leaving read-only methods untouched.
- `deployment_guard_bypass` capability, granted to roles with `manage_options` on activation, checked independently of role names.
- Enforcement of the freeze against WP-CLI commands other than Deployment Guard's own, and against WordPress's own scheduled publishing of protected content while a freeze is active.
- Tools > Deployment Guard settings screen: status, manual controls, scheduled window, custom message, protected-content summary, and activity log.
- Admin notice shown during an active freeze, in classic screens and the block editor.
- `wp deployment-guard status|enable|disable|schedule` WP-CLI commands.
- Bounded activity history of freeze state changes and blocked write attempts.
- Multisite-safe per-site operation.
- `DEPLOYMENT_GUARD_FORCE` constant for `wp-config.php` to force the freeze on or off regardless of stored state.

[Unreleased]: https://github.com/digibold/wp-stillgate/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/digibold/wp-stillgate/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/digibold/wp-stillgate/releases/tag/v0.1.0
