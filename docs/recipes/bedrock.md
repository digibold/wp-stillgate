# Stillgate in a Bedrock project

[Bedrock](https://roots.io/bedrock/) manages WordPress with Composer: plugins
are Composer packages, configuration is PHP files in `config/`, and settings
come from a `.env` file. This page covers installing Stillgate in such a
project, activating it, and setting `DEPLOYMENT_GUARD_FORCE` per environment.
To drive freezes from a Trellis deploy, see [trellis.md](trellis.md).

Use Stillgate 0.2.0 or newer. Earlier releases have no timed freeze, no
`extend`, no REST API and no receipt.

## Install with Composer

Check which package repository your `composer.json` lists under
`repositories`.

**Bedrock 1.31.7 and a project created from its current `master`** list
[WP Packages](https://wp-packages.org) (`https://repo.wp-packages.org`),
whose plugin names start with `wp-plugin/`:

```bash
composer require wp-plugin/stillgate:^0.2
```

Stillgate is available there as `wp-plugin/stillgate`. For a `0.x` release
`^0.2` allows 0.2.x only, so a later minor release does not arrive without you
asking for it.

**A project that still uses WPackagist** (`https://wpackagist.org`) needs the
`wpackagist-plugin/` name:

```bash
composer require wpackagist-plugin/stillgate:^0.2
```

A package index can lag behind WordPress.org, and the versions it lists change.
Before you rely on either index, check what it offers:

```bash
composer show --available wp-plugin/stillgate
composer show --available wpackagist-plugin/stillgate
```

If the version you need is not listed, use the GitHub release below.

**Neither index, or you want the GitHub release**: add the release zip as a
Composer `package` repository. Use a package name of your own so it cannot
clash with an index:

```json
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "digibold/stillgate",
      "version": "0.2.0",
      "type": "wordpress-plugin",
      "dist": {
        "url": "https://github.com/digibold/wp-stillgate/releases/download/v0.2.0/stillgate.zip",
        "type": "zip"
      },
      "require": { "composer/installers": "^2.0" }
    }
  }
]
```

```bash
composer require digibold/stillgate:0.2.0
```

You change `version` and the URL yourself for each release. Without a Composer
step at all, unzip the plugin into `web/app/plugins/stillgate`; Bedrock's
`.gitignore` ignores everything in `web/app/plugins/`, so you would have to
add an exception for it (`!web/app/plugins/stillgate`).

In all three cases the package has the type `wordpress-plugin`, and Bedrock's
`installer-paths` put it in `web/app/plugins/stillgate/`.

## Activate it

Composer installs the files; it does not activate anything. Activation is a
database setting, so it is per environment:

```bash
wp plugin activate stillgate
```

Run it from the project root: Bedrock's `wp-cli.yml` sets `path: web/wp`, which
WP-CLI finds from the current directory. Activation also gives the
`deployment_guard_bypass` capability to every role that has `manage_options`,
which the REST API and the settings screen need.

Because the setting lives in the database, importing a database from an
environment where the plugin is inactive deactivates it on the target.
Activate it again after such an import, or use the must-use option below.

Bedrock's `config/application.php` sets `DISALLOW_FILE_MODS` to `true` (the
development environment file sets it back to `false`), so plugins are
installed and updated with Composer, not from wp-admin.

## As a must-use plugin

Bedrock ships `web/app/mu-plugins/bedrock-autoloader.php`, which loads every
plugin found in a subfolder of `web/app/mu-plugins/` on each request. Such a
plugin has no active or inactive setting: it does not use the `active_plugins`
option, so a database import cannot deactivate it (the import itself was not
tried).

To install Stillgate there, add its package name to the mu-plugin entry of
`installer-paths` in `composer.json`, ahead of the `plugins` entry:

```json
"installer-paths": {
  "web/app/mu-plugins/{$name}/": ["type:wordpress-muplugin", "wp-plugin/stillgate"],
  "web/app/plugins/{$name}/": ["type:wordpress-plugin"],
  "web/app/themes/{$name}/": ["type:wordpress-theme"]
}
```

Then `composer require wp-plugin/stillgate:^0.2` puts it in
`web/app/mu-plugins/stillgate/`. Use the package name you installed with
(`digibold/stillgate`, for the zip).

If Stillgate is already installed, Composer does not move it when
`installer-paths` changes. `composer reinstall wp-plugin/stillgate` answers
that the pattern "does not match any currently installed packages", because
Composer no longer finds the package at its new install path. Run
`composer remove wp-plugin/stillgate` and then the `composer require` above.
`composer install` also installs it at the new path, but warns that the lock
file is not up to date with `composer.json`. Neither command deletes the old
copy: delete `web/app/plugins/stillgate` yourself.

What was checked, on a Bedrock site created with Composer:

- Stillgate loads, and its stored state and the `deployment_guard_bypass`
  capability are created the first time the autoloader sees it, so no separate
  activation step is needed. The autoloader runs the plugin's activation hook
  itself for a plugin it has not seen before; Stillgate also creates its state
  on its own when it is missing (`Installer::maybe_install()`).
- `wp stillgate enable --for=5m`, `wp stillgate receipt --check` and `wp
  stillgate disable` work.
- `wp plugin list` does not list it (only `bedrock-autoloader` appears as
  must-use), so `wp plugin is-active stillgate` fails even though it is
  running. To test for it in a script, use `wp cli has-command "stillgate extend"`,
  which exits `0` when the command exists and `1` when it does not. Use the
  `extend` form rather than `stillgate` alone: `extend` exists only from
  Stillgate 0.2.0, which timed freezes need, while Stillgate 0.1.1 also answers
  to `stillgate`. It gave `0` with the plugin installed as must-use and `1`
  after `composer remove`; with the plugin in `web/app/plugins/` it gave `0`
  when active and `1` when deactivated.
- There is nothing to deactivate. `composer remove` deletes the code and leaves
  the `deployment_guard_*` options, the capability and any stored freeze in the
  database, because the plugin's deactivation and uninstall routines never run.
  With the code gone nothing enforces a leftover freeze. If you want a clean
  database, delete the options and the capability yourself.

Not checked: how the plugin appears in the Plugins screen (the autoloader's
source marks autoloaded plugins with an asterisk in the Must-Use tab), a
Multisite network, and a must-use file that is not in a subfolder.

## Setting DEPLOYMENT_GUARD_FORCE per environment

`DEPLOYMENT_GUARD_FORCE` forces the freeze on or off whatever the stored state
says (see [wp-cli.md](../wp-cli.md#recovering-when-the-settings-screen-is-unavailable)).
It must be the PHP boolean `true` or `false`; any other value is ignored. In a
Bedrock project, read it from the environment and define it only when it is
set. Put this in `config/application.php`, before `Config::apply();` (the
file's "Custom Settings" block is a good place):

```php
/**
 * Stillgate: force the editorial freeze on or off for this environment.
 * Defined only when DEPLOYMENT_GUARD_FORCE is set to true or false.
 */
$stillgate_force = env('DEPLOYMENT_GUARD_FORCE');

if (is_bool($stillgate_force)) {
    Config::define('DEPLOYMENT_GUARD_FORCE', $stillgate_force);
}
```

`env()` is the helper Bedrock imports with `use function Env\env;`. Bedrock's
`config/application.php` turns on its `CONVERT_BOOL` option, so the strings
`true` and `false` come back as real booleans, which is why the `is_bool()`
test works. Results on a Bedrock site, with the value in `.env`:

| Value of `DEPLOYMENT_GUARD_FORCE` | Constant |
| --- | --- |
| `true`, `TRUE`, `"true"` | defined as `true` |
| `false`, `False`, `'false'` | defined as `false` |
| not set, empty, `null`, `1`, `0`, `yes` | not defined |

A variable that is already in the process environment wins over the same name
in `.env`, because Bedrock loads `.env` without overwriting existing variables
(checked by running WP-CLI with the variable set in the shell; a PHP-FPM
pool's `env[]` was not tried). To set it per environment, put the line in each
environment's `.env`, or set it in the server environment.

```bash
# .env on the environment that should be frozen
DEPLOYMENT_GUARD_FORCE=true
```

To check what the site sees:

```bash
wp eval 'var_dump( defined( "DEPLOYMENT_GUARD_FORCE" ) ? DEPLOYMENT_GUARD_FORCE : "not defined" );'
wp stillgate status        # the "forced" row
```

Things to know:

- A forced `true` cannot be lifted with Stillgate's own commands. With the
  stored state off, `wp stillgate disable` answered "The freeze was already
  off" while the site stayed frozen. Remove the variable to lift it.
- Define the constant in one place. `Config::define()` throws if the constant
  was already defined (for example by a `define()` in another config file),
  according to the `roots/wp-config` source; that case was not run.
- To pin a value for one environment in code instead, put
  `Config::define('DEPLOYMENT_GUARD_FORCE', true);` in that environment's
  `config/environments/<name>.php`, the way Bedrock's own `staging.php`
  suggests for overrides. Not run.

## Verified against

Checked on 2026-10-19:

- Bedrock: `composer.json`, `config/application.php`, `config/environments/`,
  `.env.example`, `wp-cli.yml` and `.gitignore` on `master` (commit
  `cb125a7`), `composer.json` and `config/application.php` at release 1.31.7;
  the Bedrock documentation pages on Composer, the MU Plugin Autoloader and
  environment variables; the source of `roots/bedrock-autoloader` 1.1.0,
  `roots/wp-config` 1.0.0 and `oscarotero/env` as installed.
- A real Bedrock project made with `composer create-project roots/bedrock`
  (WordPress 7.1.3, PHP 8.4, WP-CLI 2.12.0) against a throwaway MySQL:
  `composer require wp-plugin/stillgate:^0.2` installed 0.2.0 into
  `web/app/plugins/stillgate`, identical file for file to the 0.2.0 plugin
  zip; activation with WP-CLI; the `DEPLOYMENT_GUARD_FORCE` snippet with each
  value in the table; the must-use install, load, removal and
  `wp cli has-command` results above; the `package` repository example
  installing the GitHub release zip (identical to the plugin zip).
- WP Packages and WPackagist, with Composer 2.8.9 in a scratch project that
  lists both: `composer show --available` for `wp-plugin/stillgate` and for
  `wpackagist-plugin/stillgate` listed 0.2.0, and `composer require` of
  `wp-plugin/stillgate:^0.2` and of `wpackagist-plugin/stillgate:^0.2`
  installed 0.2.0 identical file for file to the plugin zip (the WPackagist
  install was not made in a Bedrock project). The WP Packages index file
  `https://repo.wp-packages.org/p2/wp-plugin/stillgate.json` has the type
  `wordpress-plugin`. Bedrock `master` (commit `cb125a7`) and release 1.31.7
  still list `repo.wp-packages.org`, not WPackagist.
- Moving the plugin to the must-use path: in a Bedrock project with Stillgate
  installed under `web/app/plugins`, `installer-paths` was edited as shown.
  `composer reinstall wp-plugin/stillgate` answered that no installed package
  matched. `composer install` installed it in `web/app/mu-plugins/stillgate`
  (identical to the zip) with the lock file warning, and
  `composer remove` followed by `composer require wp-plugin/stillgate:^0.2`
  did the same with a current lock file. Both left the old directory in
  `web/app/plugins`.
- Stillgate: `src/FreezeService.php` (the constant is used only when it is a
  boolean), `src/Plugin.php`, `src/Installer.php`, the 0.2.0 changelog.

Not run: a Bedrock project that uses WPackagist as its index, a PHP-FPM
or Trellis-provisioned server, Multisite, the Plugins screen in a browser, and
whether a plugin that is still active in the database and present in both
`web/app/plugins` and `web/app/mu-plugins` causes trouble. Index contents
change; check them with `composer show --available` before you rely on them.
