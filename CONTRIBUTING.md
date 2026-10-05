# Contributing to Stillgate

Thanks for considering a contribution. This document covers how to get a
development environment running, the checks a change is expected to pass,
and how to open a pull request.

## Before you start

- For a bug report or feature request, please open an issue first if one
  does not already exist, so it can be discussed before you spend time on a
  patch.
- For a security vulnerability, please follow [SECURITY.md](SECURITY.md)
  instead of opening a public issue.
- By contributing, you agree your contribution is licensed under the
  project's [GPL-2.0-or-later](LICENSE) license, and you agree to abide by
  the [Code of Conduct](CODE_OF_CONDUCT.md).

## Development setup

Requirements: PHP 8.1+, [Composer](https://getcomposer.org/), and a MySQL (or
MariaDB) server for the integration test suite. WP-CLI, `curl`, and a free
local port for PHP's built-in web server are only needed for the optional
end-to-end suite (`composer test:e2e`), which is not run in CI.

```bash
git clone https://github.com/digibold/wp-stillgate.git
cd wp-stillgate
composer install
```

`composer install` pulls in a private copy of WordPress core
(`roots/wordpress-no-content`) for the test suite; it is not part of the
distributed plugin and is not required to install or run Stillgate on
a real site. The plugin itself has no runtime Composer dependency — see
[docs/architecture.md](docs/architecture.md).

Create a test database and point the integration suite at it via
environment variables (defaults shown):

```bash
export WP_TEST_DB_NAME=wordpress_tests
export WP_TEST_DB_USER=root
export WP_TEST_DB_PASSWORD=root
export WP_TEST_DB_HOST=127.0.0.1
```

A throwaway MySQL container is usually the quickest way to get one:

```bash
docker run -d --name stillgate-mysql \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress_tests \
  -p 127.0.0.1:3306:3306 mysql:8.4
```

## Running the checks

```bash
composer lint            # php -l over every plugin/test file
composer phpcs            # WordPress Coding Standards
composer test:unit        # pure-PHP tests, no WordPress
composer test:integration # wp-phpunit against the database above (default, ajax, and xmlrpc groups)
```

The multisite-specific tests are skipped unless the integration suite runs
against a multisite install (`WP_MULTISITE=1 composer test:integration`,
which reinstalls the test database as a network the first time it runs).

All of the above run in CI on every pull request; please make sure they pass
locally first.

## Coding standards

- Follow the existing code style; `composer phpcs` enforces WordPress
  Coding Standards (the `WordPress-Extra` ruleset — see
  [phpcs.xml.dist](phpcs.xml.dist)) with a small number of documented
  exceptions.
- New classes live under `src/`, namespaced `Digibold\DeploymentGuard`, one
  class per file, autoloaded by `src/autoload.php`. The plugin does not load
  Composer's autoloader at runtime, so any new class must resolve through
  that autoloader — `AutoloaderTest` checks this.
- Every user-facing string must be translatable, using the `stillgate`
  text domain.
- Sanitize input, escape output, verify nonces and capabilities, and prefer
  the WordPress APIs already used elsewhere in the codebase over new direct
  `$_GET`/`$_POST`/`$wpdb` access. See [docs/architecture.md](docs/architecture.md)
  for the enforcement layers and why each hook is used the way it is.

## Tests

New behavior should come with tests:

- Pure logic (parsing, validation, bounded-history rules) belongs in
  `tests/Unit`, with no WordPress dependency.
- Anything that touches WordPress (capabilities, hooks, the REST API,
  options) belongs in `tests/Integration`.
- A change to what is blocked or allowed should include a test proving both
  the block and a case that is deliberately still allowed (for example, a
  bypass-capability holder, or an unprotected post type).

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Make your change, with tests, and confirm the checks above pass.
3. Update [CHANGELOG.md](CHANGELOG.md) under an `## [Unreleased]` heading if
   the change is user-facing.
4. Open a pull request describing the change and, for a behavior change,
   why it is safe (which enforcement layer it affects, what it does not
   affect).

Small, focused pull requests are easier to review than large ones. If you
are planning a large change, please open an issue to discuss the approach
first.
