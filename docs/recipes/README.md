# Recipes: Stillgate in your deployment pipeline

Each page shows how to freeze a site around a deploy, check the
[receipt](../receipt.md) before anything is overwritten, and end the freeze,
also when the deploy fails. They all need Stillgate 0.2.0 or later on the live
site, and they all use a timed freeze, so a deploy that dies halfway can never
leave editing locked.

| Pipeline | Page | What was run |
| --- | --- | --- |
| GitHub Actions | [github-actions.md](github-actions.md) | The Action's unit tests, an end-to-end script against a real site, and a self-test workflow on a GitHub runner |
| Deployer | [deployer.md](deployer.md) | Real deploys with Deployer 8 on a real site: success, a failed task, a dirty receipt, a database import, a killed deploy |
| Trellis | [trellis.md](trellis.md) | The hook files run through the real Trellis deploy role with Ansible against a real Bedrock site; no VM or remote server |
| Bedrock (Composer, configuration) | [bedrock.md](bedrock.md) | A real Bedrock project: install, activation, the `DEPLOYMENT_GUARD_FORCE` setting, installing as a must-use plugin |
| Anything else | [rest-api.md](../rest-api.md), [wp-cli.md](../wp-cli.md) | A curl call or a WP-CLI command is all a pipeline needs |

## Choosing between WP-CLI and the REST API

- **WP-CLI over SSH** (`wp stillgate enable --for=30m`, `receipt --check`,
  `disable`) is the most direct and works when the REST API is blocked. It
  needs SSH access to the server.
- **The REST API** needs only the site's address and an Application Password,
  which suits hosted pipelines that have no SSH access. Some security plugins
  and firewalls block it.

## The same four rules in every recipe

1. **Use a timer.** If the deploy process is killed, the freeze ends by itself.
2. **Read the receipt while the freeze is on.** It covers only the time the
   freeze was on, so a clean receipt after the timer ran out proves nothing.
3. **A database import replaces the freeze.** Stillgate keeps its state and
   the receipt in the database. Check before the import, and freeze again after
   it if editing must stay locked.
4. **Never end a freeze somebody else started.** The recipes refuse to start
   over an existing freeze or scheduled window unless you tell them to.
