# Changelog

All notable changes to Deployment Guard are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-14

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

[Unreleased]: https://github.com/digibold/wp-deployment-guard/compare/0.1.0...HEAD
[0.1.0]: https://github.com/digibold/wp-deployment-guard/releases/tag/0.1.0
