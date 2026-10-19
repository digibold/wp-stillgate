# Stillgate in GitHub Actions

The Stillgate Action freezes a WordPress site for the length of a job, reads
the [receipt](../receipt.md) at the end, and ends the freeze, also when the job
failed. It is a single step in your workflow.

It needs Stillgate 0.2.0 or later on the site. It uses the
[REST API](../rest-api.md), so nothing has to be installed on the runner and no
SSH access is needed for the freeze itself.

## Set up

1. On the live site, make a user for the pipeline and give it only what it
   needs: the `manage_options` and `deployment_guard_bypass` capabilities (an
   administrator has both).
2. Create an [Application Password](https://wordpress.org/documentation/article/application-passwords/)
   for that user (Users > Profile). The site must be served over https;
   Application Passwords are not offered over plain http except in a local
   environment.
3. In the repository settings, add the secrets `WP_USER` and `WP_APP_PASSWORD`,
   and the variable `SITE_URL` (for example `https://example.com`).

## Use it

Put the step right before the step that touches the live site, so the freeze
is as short as it can be. The Action ends the freeze after the last step of the
job.

```yaml
name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Build
        run: ./build.sh

      - name: Freeze editing
        uses: digibold/wp-stillgate@action-v1
        with:
          site-url: ${{ vars.SITE_URL }}
          username: ${{ secrets.WP_USER }}
          app-password: ${{ secrets.WP_APP_PASSWORD }}
          duration: 45m
          message: Deploying ${{ github.sha }}. Editing is back shortly.

      - name: Deploy
        run: ./deploy.sh
```

`action-v1` follows the Action's 1.x releases. To pin it exactly, use the full
commit SHA of a version you have read, as GitHub recommends for any third-party
action.

If the job is cancelled or a step fails, the Action still ends the freeze. If
the whole runner dies before it can, the freeze ends by itself when `duration`
runs out. That is the reason to always use a timer.

## What happens at the end of the job

The last part of the Action, which runs after your last step:

1. Reads the receipt while the freeze is still on and writes a short summary to
   the job page: the counts, the IDs of the posts that changed (at most 50), how
   many comments and form submissions were paused, and the
   `wp export --post__in=...` command for carrying changed posts over.
2. Ends the freeze (unless `release` is `false`).
3. Fails the job when `fail-on-changes` is `true` and the receipt is not clean,
   or cannot confirm that nothing changed. The freeze has already been ended by
   then, so a failed check never leaves the site locked.

A receipt cannot confirm that nothing changed when the freeze ended before the
job did (the timer ran out, so editors were free for the rest of the job), when
the receipt could not be read, and when the freeze on the site is no longer the
one this job started. In the last case the Action does not end it: a freeze that
somebody else started is never ended by this job, and the warning names the
command that ends it.

## Inputs

| Input | Default | Meaning |
| --- | --- | --- |
| `site-url` | required | Address of the site. https, except for `localhost` and `127.0.0.1`. A path prefix (WordPress in a subdirectory) works. |
| `username` | required | The WordPress user that owns the Application Password. |
| `app-password` | required | The Application Password. It is masked in the log. |
| `duration` | `30m` | How long the freeze lasts if nothing ends it: `30m`, `2h`, `1h30m`; 1 minute to 7 days. Make it longer than the job. |
| `message` | empty | Shown to the people whose editing is frozen. It is always sent: an empty value clears the message an earlier freeze left, and the site shows its default text. |
| `if-already-frozen` | `fail` | `fail`: stop when a freeze is active or a window is scheduled. `keep`: leave an active freeze alone, do nothing, and do not end it afterwards (it still stops for a window that has not begun, because nothing is frozen and the job would go on unfrozen). `replace`: take it over; the freeze this job starts takes its place. |
| `fail-on-changes` | `true` | Fail the job, after releasing the freeze, when the receipt is not clean or cannot confirm that nothing changed. `false` only reports it. |
| `release` | `true` | End the freeze at the end of the job. `false` leaves it on until it times out. |

Outputs, readable by later steps of the same job: `frozen` (`true` when this
Action started a freeze) and `ends-at`. The verdict of the receipt is not an
output: the step that reads it runs after the last step of the job, and the
runner does not pass on what such a step writes (checked on a GitHub runner:
the job output stays empty). It is in the job summary and in the result of the
job.

## Things to know

- **Replacing keeps the old receipt.** With `if-already-frozen: replace` over an
  active freeze, the receipt of that earlier freeze continues. Changes made
  during it count, so the job can fail for edits made before it started.
  Replacing a scheduled window removes the window.
- **A database import replaces the freeze.** Stillgate keeps its state and the
  receipt in the database. If a step of your job imports a database, the freeze
  that was on is replaced by whatever the dump holds, and the Action cannot
  confirm anything at the end. Read the receipt before the import yourself
  (`GET /wp-json/stillgate/v1/receipt`, or `wp stillgate receipt --check` over
  SSH) and set `fail-on-changes` to `false` for the Action's own check. See
  [the receipt](../receipt.md) and, for a worked example, the database steps in
  the [Deployer recipe](deployer.md#database-steps).
- **The message is always sent.** A message stored in the site's settings is
  replaced by the Action's `message`, or cleared when it is empty.
- **Clock skew.** When the request that starts the freeze gets no usable answer,
  the Action does not know whether a freeze began. It checks at the end and
  ends one that began within five minutes of the request. If the runner's clock
  and the site's differ by more than that, it does not end it (the freeze then
  ends by itself) and fails the job.
- **Retries.** Only reads and the final release are retried. The request that
  starts the freeze is sent once.
- **Redirects are not followed.** If `site-url` redirects, give the final
  address, so the credentials never go anywhere you did not name.

## When it fails

| Message | Likely cause |
| --- | --- |
| 401 | Wrong user or Application Password, or the site does not offer Application Passwords (not https, or disabled by a plugin). |
| 403 | The user lacks `manage_options` or `deployment_guard_bypass`. |
| 404 on both addresses | The REST API is disabled by a security plugin, or Stillgate is not active or older than 0.2.0. |
| An answer that is not JSON | A firewall or CDN challenge page in front of the REST API. Allow the runner, or use WP-CLI over SSH. |
| 400 `invalid_duration` | `duration` is not a duration, or is outside 1 minute to 7 days. |

If the REST API cannot be reached from GitHub, use the commands instead, over
SSH, as in [the WP-CLI reference](../wp-cli.md#deploy-script-recipe):

```yaml
      - name: Freeze editing
        run: ssh deploy@example.com 'cd /var/www/html && wp stillgate enable --for=45m'
      - name: Deploy
        run: ./deploy.sh
      - name: Check and release
        if: always()
        run: |
          ssh deploy@example.com 'cd /var/www/html && wp stillgate receipt --check; status=$?; wp stillgate disable; exit $status'
```

## How it is tested

`tests/action` holds unit tests that run the Action's two steps as the runner
does, against a mock site. `tests/e2e/action.sh` runs them against a real
WordPress site with the plugin installed, including the failure cases, and
`.github/workflows/action-selftest.yml` runs the Action on a GitHub runner
against a throwaway site in this repository's CI: it freezes the site, checks
that the freeze is on and that a restricted action is refused, and a second job
confirms that the post step ran and finished successfully.
