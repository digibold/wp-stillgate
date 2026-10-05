# WP-CLI reference

```
wp stillgate status  [--format=<table|json|yaml>]
wp stillgate enable  [--message=<message>]
wp stillgate disable
wp stillgate schedule (--start=<datetime> --end=<datetime> | --clear) [--message=<message>]
```

Releases up to 0.1.0 registered `wp deployment-guard`; that command no longer
exists and has no alias.

These are the only commands Stillgate treats specially. Every other
WP-CLI command — including from other plugins — is subject to the same
enforcement as any other actor while a freeze is active: see
[architecture.md](architecture.md#wp-cli-is-not-exempt). A command that
creates, updates, or deletes protected posts, pages, media, or terms exits
with status `1` and a `Stillgate:` message, before anything is
written. Lower-level commands that write post meta, term assignments,
widgets, menu locations, theme mods, or other options directly are not
blocked — see [architecture.md](architecture.md#known-limitations).

## `status`

Prints the current freeze state: whether it is active, why (`manual`,
`scheduled`, or `inactive`), the schedule window (in both the site timezone
and UTC), the effective protected post types and taxonomies, which roles
hold the bypass capability, and whether a persistent object cache is in
use.

```bash
wp stillgate status
wp stillgate status --format=json
```

## `enable`

Turns the freeze on immediately, regardless of any schedule. Idempotent:
running it again while already enabled succeeds with no change.

```bash
wp stillgate enable
wp stillgate enable --message="Deploying now, back in 10 minutes."
```

## `disable`

Turns the freeze off. If a scheduled window is currently running, it is
cleared too, so disabling never leaves the freeze active a moment later. A
window that has not started yet is left in place and a warning is printed
— run `wp stillgate schedule --clear` as well if you want to remove
it entirely.

```bash
wp stillgate disable
```

## `schedule`

Sets or clears the scheduled freeze window. `--start` and `--end` accept:

- `now`
- a local datetime in the site's own timezone: `2026-09-20 22:00` or
  `2026-09-20T22:00:00`
- a datetime with an explicit UTC or offset designator:
  `2026-09-20T22:00:00Z` or `2026-09-20T22:00:00+02:00`

Relative phrases (`tomorrow`, `+2 hours`) and bare Unix timestamps are
rejected deliberately, to keep deployment scripts unambiguous.

```bash
wp stillgate schedule --start="2026-09-20 22:00" --end="2026-09-21 02:00"
wp stillgate schedule --start=now --end="2026-09-20T04:00:00Z"
wp stillgate schedule --clear
```

A scheduled window can coexist with the manual switch; the effective rule is
"active if either says so". The maximum window is 7 days and a start cannot
be more than 365 days out — for anything longer, use the manual switch
around your own longer-lived process instead.

## Deploy script recipe

```bash
wp stillgate enable
# sync files and database
wp cache flush
wp stillgate status --format=json   # confirm the state after the import
wp stillgate disable
```

`wp cache flush` matters specifically when a persistent object cache is in
use and web servers do not share it with the process running WP-CLI: see
[architecture.md](architecture.md#known-limitations). `wp stillgate
enable`/`disable` warn when this applies.

For a freeze that should self-expire without a second command, prefer
`schedule` over `enable`/`disable`. `--end` does not accept a relative
offset like `+1 hour` (see above); compute the actual timestamp instead:

```bash
wp stillgate schedule --start=now --end="$(date -u -d '+1 hour' '+%Y-%m-%dT%H:%M:%SZ')"
```

## Multisite

Each site's freeze is independent. Target a specific site with `--url`:

```bash
wp stillgate status --url=example.com
wp stillgate enable --url=site2.example.com
```

To act across every site in the network:

```bash
wp site list --field=url | xargs -I% wp stillgate status --url=%
```

## Exit codes

`status` and a successful `enable`/`disable`/`schedule` (including a no-op,
such as `enable` while already enabled) exit `0`. A usage error (missing
`--end`, `--clear` combined with `--start`/`--end`, an unparseable
datetime, an invalid schedule such as an end before the start) exits `1`
with a message on stderr, and changes nothing.

## Recovering when the settings screen is unavailable

Changing the freeze from Tools > Stillgate requires both
`manage_options` and the `deployment_guard_bypass` capability. If an
administrator's bypass capability has been removed, these WP-CLI commands
remain available (they only change Stillgate's own state, which the
freeze never locks — see [architecture.md](architecture.md#suspension)), as
does the `DEPLOYMENT_GUARD_FORCE` constant in `wp-config.php`:

```php
// Force the freeze on regardless of stored state:
define( 'DEPLOYMENT_GUARD_FORCE', true );
// Force it off regardless of stored state:
define( 'DEPLOYMENT_GUARD_FORCE', false );
```

The constant must be the boolean `true` or `false`. Any other value (`1`,
`'true'`) is ignored, and the stored state applies.
