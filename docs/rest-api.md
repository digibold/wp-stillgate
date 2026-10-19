# REST API for pipelines

Stillgate can be driven over HTTPS by a CI/CD pipeline or deploy tool,
without WP-CLI or SSH access to the server. It uses WordPress's own
authentication and adds no token system of its own.

Base URL: `https://example.com/wp-json/stillgate/v1`

## Authentication and permissions

Use an [Application Password](https://wordpress.org/documentation/article/application-passwords/)
(Users > Profile > Application Passwords) with HTTP Basic authentication.
Application Passwords need HTTPS, or a site with `WP_ENVIRONMENT_TYPE` set
to `local`.

| Operation | Needs |
| --- | --- |
| `GET` routes | `manage_options` |
| `POST` and `DELETE` routes | `manage_options` **and** the `deployment_guard_bypass` capability |

This is the same rule as the settings screen. An account whose own edits are
frozen cannot lift the freeze through the API either. The routes only touch
Stillgate's own state, which a freeze never protects, so they keep working
while a freeze is active.

Create a dedicated user for the pipeline, give it only the role it needs,
and keep its Application Password in your CI secret store.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/status` | Current state |
| `POST` | `/freeze` | Start a freeze. Optional `for` (duration) and `message` |
| `DELETE` | `/freeze` | End the freeze |
| `POST` | `/freeze/extend` | Push the end of a timed or scheduled freeze back. Required `by` |
| `POST` | `/schedule` | Schedule a window. Required `start` and `end`, optional `message` |
| `DELETE` | `/schedule` | Clear the scheduled window |
| `GET` | `/scope` | What a freeze covers beyond editorial content |
| `POST` | `/scope` | Choose it: optional booleans `comments` and `forms` |
| `GET` | `/receipt` | What happened to the content during the latest freeze |

Durations (`for`, `by`) accept `30m`, `2h`, `1d`, combinations such as
`1h30m`, or a bare number of minutes. A timed freeze lasts at least one
minute and at most seven days. A `for` that is supplied but empty or not a
duration is refused with `400 invalid_duration`; only leaving `for` out starts a
freeze without an end.

`start` and `end` accept the same formats as `wp stillgate schedule`: `now`,
a site-time value such as `2026-09-20 22:00`, or an ISO 8601 time with `Z` or
an offset.

## Responses

Every successful call that changes the freeze returns the new state plus a
`result` field naming what happened (`enabled`, `timed`, `extended`,
`disabled`, `scheduled`, `unchanged`, and so on). `POST /scope` returns only
the choice and the result: `{"comments": true, "forms": true, "result":
"scope_saved"}`.

```json
{
  "active": true,
  "forced": null,
  "mode": "scheduled",
  "manual_enabled": false,
  "schedule_status": "active",
  "schedule_start": "2026-09-20T22:00:00+00:00",
  "schedule_end": "2026-09-20T22:30:00+00:00",
  "ends_at": "2026-09-20T22:30:00+00:00",
  "seconds_left": 1740,
  "timezone": "Europe/Riga",
  "schedule_local": { "start": "2026-09-21 01:00", "end": "2026-09-21 01:30" },
  "scope": { "comments": true, "forms": false },
  "message": "Deploying now, back soon.",
  "updated_at": "2026-09-20T22:00:00+00:00",
  "updated_by": 1,
  "result": "timed"
}
```

`forced` is `true` or `false` when `DEPLOYMENT_GUARD_FORCE` is defined as a
boolean in `wp-config.php`, and `null` when it is not set (the normal case).

A timed freeze is stored as a window that starts now, which is why `mode` is
`scheduled` and `schedule_status` is `active`. `mode` is `manual` for a freeze
that lasts until it is ended, and `ends_at` and `seconds_left` are `null`
when there is no end time.

Errors use WordPress's standard REST error shape (`code`, `message`,
`data.status`). Bad input answers `400`, a conflict with the current state
(for example extending a manual freeze, code `not_extendable`) answers `409`,
and missing permission answers `401` or `403`.

## Examples

Start a 30-minute freeze before a deploy, check the receipt afterwards, and
end it:

```bash
SITE=https://example.com/wp-json/stillgate/v1
AUTH="deploy-bot:xxxx xxxx xxxx xxxx xxxx xxxx"

# Start; it ends by itself even if the pipeline dies halfway.
curl -fsS -u "$AUTH" -X POST "$SITE/freeze" \
  -d for=30m -d message="Deploying release 42, back shortly."

# ... deploy ...

# Did anyone change content while the freeze was on?
curl -fsS -u "$AUTH" "$SITE/receipt" | jq -e '.clean'

# Took longer than planned:
curl -fsS -u "$AUTH" -X POST "$SITE/freeze/extend" -d by=15m

# Finished:
curl -fsS -u "$AUTH" -X DELETE "$SITE/freeze"
```

A GitHub Actions step:

```yaml
- name: Freeze editing
  run: |
    curl -fsS -u "${{ secrets.WP_USER }}:${{ secrets.WP_APP_PASSWORD }}" \
      -X POST "${{ vars.SITE_URL }}/wp-json/stillgate/v1/freeze" -d for=45m

- name: Deploy
  run: ./deploy.sh

- name: End freeze
  if: always()
  run: |
    curl -fsS -u "${{ secrets.WP_USER }}:${{ secrets.WP_APP_PASSWORD }}" \
      -X DELETE "${{ vars.SITE_URL }}/wp-json/stillgate/v1/freeze"
```

Prefer a timed freeze (`for=`) over a manual one in pipelines: if the job is
cancelled or the runner dies before the last step, editing unlocks by itself.

`curl -f` makes a failed call fail the step. Ending a freeze that is already
over is not an error: it answers `200` with `"result": "unchanged"`.

## Status in a monitoring check

`GET /status` returns `active`, `ends_at` and `seconds_left`, which is enough
for an uptime or alerting tool that wants to warn when a freeze has been on
longer than expected. For a Site Health style check inside WordPress, see
Tools > Site Health, where Stillgate adds a test of its own.
