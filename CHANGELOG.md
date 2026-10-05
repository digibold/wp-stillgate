# Changelog

All notable changes to Stillgate (published as Deployment Guard up to 0.1.0) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
