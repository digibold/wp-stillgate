<?php
/**
 * Stillgate recipe for Deployer 8.
 *
 * Freezes editing on the live WordPress site while a deploy runs, refuses to
 * publish a release if content changed during the freeze, and ends the freeze
 * right after the new release is live, also when the deploy fails.
 *
 * Usage, in deploy.php, after the recipe that defines deploy:prepare,
 * deploy:publish and deploy:failed (recipe/common.php or recipe/wordpress.php):
 *
 *     import('recipe/wordpress.php');
 *     import(__DIR__ . '/stillgate.php');
 *
 * Every stillgate_* default below is set only when nothing has set it yet, so
 * a set() placed before or after the import both take effect.
 *
 * Full documentation: docs/recipes/deployer.md in the Stillgate repository.
 */

namespace Deployer;

use Symfony\Component\Console\Formatter\OutputFormatter;

foreach (['deploy:lock', 'deploy:unlock', 'deploy:publish', 'deploy:failed'] as $stillgate_required) {
    if (!Deployer::get()->tasks->has($stillgate_required)) {
        throw new \RuntimeException(
            'stillgate.php must be imported after recipe/common.php (or a recipe that loads it), '
            . 'because it hooks deploy:lock, deploy:publish, deploy:unlock and deploy:failed.'
        );
    }
}
unset($stillgate_required);

/*
 * Configuration. Every value can be changed in deploy.php, per host, or with
 * dep deploy -o stillgate_for=1h. A default is set only if the variable does
 * not exist yet, so a set() before the import is not overwritten.
 */
foreach (
    [
        // false: do nothing at all, as if the recipe were not imported. For the
        // one deploy that has to ship a first Stillgate, or to bypass it.
        'stillgate_freeze' => true,

        // WordPress root of the site that is live now. The default is the
        // current release. Set a fixed path when WordPress lives outside the
        // releases, or below them (Bedrock: {{current_path}}/web/wp).
        'stillgate_path' => '{{current_path}}',

        // The WP-CLI binary, as the shell on the host would run it.
        'stillgate_wp' => 'wp',

        // Extra WP-CLI global arguments, written to the command as they are,
        // for example '--allow-root' or '--url=example.com' on a multisite.
        'stillgate_wp_args' => '',

        // How long the freeze lasts if nothing ends it: 30m, 2h, 1h30m. It
        // ends by itself, so a deploy that dies halfway cannot leave editing
        // locked.
        'stillgate_for' => '30m',

        // Message shown to the people who are locked out. Empty keeps the
        // site's own.
        'stillgate_message' => '',

        // How much stillgate:extend pushes the end back.
        'stillgate_extend_by' => '15m',

        // Run stillgate:check before the release is published.
        'stillgate_check' => true,

        // What to do when a freeze is already on at the start of the deploy:
        // 'fail' stops the deploy, 'continue' replaces that freeze with this
        // deploy's timed one and ends it at the end.
        'stillgate_if_frozen' => 'fail',

        // false: a host without WP-CLI, without WordPress or without a
        // Stillgate that has the timed freeze is skipped with a warning. true:
        // it fails the deploy.
        'stillgate_strict' => false,
    ] as $stillgate_name => $stillgate_default
) {
    if (!has($stillgate_name)) {
        set($stillgate_name, $stillgate_default);
    }
}
unset($stillgate_name, $stillgate_default);

/*
 * Helpers.
 *
 * Deployer reads {{name}} as a placeholder in every command, message and
 * value, and does it again each time a string goes through run(), warning()
 * and so on. A message that was already read once and contains a literal {{
 * would be read a second time. Commands are therefore built here from values
 * that get() has already resolved, with every argument quoted, and a literal
 * {{ is escaped as \{{ at the last moment, in stillgate_run(), stillgate_test()
 * and stillgate_text().
 */

/**
 * Protects a literal {{ in text that goes through Deployer's own placeholder
 * parsing, as error() and the output functions do.
 */
function stillgate_text(string $text): string
{
    return str_replace('{{', '\{{', $text);
}

/**
 * The same for text that is printed to the console, which also reads tags such
 * as <info> in it.
 */
function stillgate_console(string $text): string
{
    return stillgate_text(OutputFormatter::escape($text));
}

/**
 * run() for a command that was built from resolved values.
 */
function stillgate_run(string $command): string
{
    return run(str_replace('{{', '\{{', $command));
}

/**
 * test() for a command that was built from resolved values.
 */
function stillgate_test(string $command): bool
{
    return test(str_replace('{{', '\{{', $command));
}

/**
 * Runs a command without throwing, and returns [exit code, output].
 *
 * @return array{0: int, 1: string}
 */
function stillgate_try(string $command): array
{
    $output = stillgate_run($command . ' 2>&1; echo "+stillgate-exit=$?"');

    if (!preg_match('/\+stillgate-exit=(\d+)\s*$/', $output, $match)) {
        return [1, trim($output)];
    }

    return [(int) $match[1], trim(substr($output, 0, -strlen($match[0])))];
}

/**
 * The WP-CLI command line up to the subcommand, for a WordPress root.
 */
function stillgate_wp(string $dir): string
{
    $wp    = trim((string) get('stillgate_wp'));
    $extra = trim((string) get('stillgate_wp_args'));

    return $wp . ' --path=' . quote($dir) . ($extra === '' ? '' : ' ' . $extra);
}

/**
 * The wp stillgate command line for a WordPress root. The arguments must be
 * quoted already.
 */
function stillgate_command(string $dir, string $arguments): string
{
    return stillgate_wp($dir) . ' stillgate ' . $arguments;
}

/**
 * The arguments of the command that starts the timed freeze. Only the value
 * is quoted, so a message that starts with a dash is still a value.
 */
function stillgate_enable_arguments(): string
{
    $arguments = 'enable --for=' . quote((string) get('stillgate_for'));
    $message   = (string) get('stillgate_message');

    if ('' !== $message) {
        $arguments .= ' --message=' . quote($message);
    }

    return $arguments;
}

/**
 * The WordPress root to act on. A freeze that this deploy started stays tied
 * to the directory it was started in, so that ending it later does not depend
 * on where current points by then.
 */
function stillgate_target(): string
{
    $pinned = (string) get('stillgate_frozen_dir', '');

    if ($pinned !== '' && stillgate_test('[ -d ' . quote($pinned) . ' ]')) {
        return $pinned;
    }

    return stillgate_run('cd ' . get('stillgate_path') . ' && pwd -P');
}

/**
 * Reads "active" from wp stillgate status --format=json: true, false, or null
 * when the output is not recognised.
 */
function stillgate_is_active(string $json): ?bool
{
    if (preg_match('/"active"\s*:\s*"?(true|false)"?/', $json, $match)) {
        return 'true' === $match[1];
    }

    return null;
}

/*
 * Tasks.
 */

desc('Freezes editing on the live site with Stillgate');
task('stillgate:freeze', function () {
    set('stillgate_state', 'skipped');
    set('stillgate_frozen_dir', '');

    if (!get('stillgate_freeze')) {
        warning('Stillgate: stillgate_freeze is off, the site is not frozen');

        return;
    }

    $strict = (bool) get('stillgate_strict');

    // Skips with a warning, or fails when strict.
    $skip = function (string $reason) use ($strict): void {
        if ($strict) {
            throw error('Stillgate: ' . stillgate_text($reason) . ' (stillgate_strict is on)');
        }

        warning('Stillgate: freeze skipped, ' . stillgate_console($reason));
    };

    $path = (string) get('stillgate_path');

    if (!stillgate_test('[ -d ' . $path . ' ]')) {
        if (!stillgate_test('[ -d ' . get('current_path') . ' ]')) {
            // The first deploy: nothing is live, so there is no site to
            // freeze, strict or not. This holds when stillgate_path is the
            // release itself and when it is a directory inside it.
            warning(
                'Stillgate: freeze skipped, nothing is live yet at '
                . stillgate_console(get('current_path')) . ' (first deploy)'
            );

            return;
        }

        $skip("$path does not exist");

        return;
    }

    $dir = stillgate_run('cd ' . $path . ' && pwd -P');

    [$code, $output] = stillgate_try(stillgate_command($dir, 'status --format=json'));
    $active          = 0 === $code ? stillgate_is_active($output) : null;

    if (null === $active) {
        $detail = 0 === $code ? 'unexpected output from wp stillgate status' : substr($output, 0, 300);
        $skip("wp stillgate could not run at $dir: $detail");

        return;
    }

    // Only 0.2.0 and later has the timed freeze, and with it "extend". The
    // command is the probe: it does not exist in 0.1.x.
    [$code] = stillgate_try(stillgate_wp($dir) . ' cli has-command ' . quote('stillgate extend'));

    if (0 !== $code) {
        $skip(
            "the Stillgate on the live site at $dir is older than 0.2.0 (wp stillgate extend does not exist), "
            . 'so it has no timed freeze. Update the live plugin first (wp plugin update stillgate), '
            . 'or deploy once with stillgate_freeze disabled (dep deploy -o stillgate_freeze=false)'
        );

        return;
    }

    if ($active) {
        if ('continue' !== get('stillgate_if_frozen')) {
            throw error(
                'Stillgate: a freeze is already on at ' . stillgate_text($dir) . ', so this deploy will not replace it. '
                . 'Set stillgate_if_frozen to continue to replace it with this deploy\'s freeze.'
            );
        }

        warning('Stillgate: a freeze is already on at ' . stillgate_console($dir) . '; replacing it, and ending it with this deploy');
    }

    // Recorded before the command runs: if it fails in a way that leaves the
    // outcome unknown, the failure hook still tries to end the freeze.
    set('stillgate_state', 'frozen');
    set('stillgate_frozen_dir', $dir);

    stillgate_run(stillgate_command($dir, stillgate_enable_arguments()));

    info('Stillgate: editing is frozen at ' . stillgate_console($dir) . ' for ' . stillgate_console((string) get('stillgate_for')) . ' at most');
});

desc('Fails unless the freeze receipt is clean');
task('stillgate:check', function () {
    $dir = stillgate_target();

    // The receipt only covers the time the freeze was on. If the freeze has
    // ended, a clean receipt says nothing about the time after it. The freeze
    // is also gone when a database import replaced the state it lives in.
    $status = stillgate_run(stillgate_command($dir, 'status --format=json'));

    if (true !== stillgate_is_active($status)) {
        throw error(
            'Stillgate: the freeze is no longer on, so the receipt cannot vouch for the whole deploy. '
            . 'Either the timer ran out (raise stillgate_for, or run stillgate:extend while the deploy is running), '
            . 'or a database import replaced the freeze state, which is kept in the database '
            . '(run stillgate:refreeze right after the import).'
        );
    }

    [$code, $output] = stillgate_try(stillgate_command($dir, 'receipt --check'));

    if (0 !== $code) {
        throw error(
            "Stillgate: the receipt is not clean, so content may have changed since the freeze began. Nothing was published.\n"
            . stillgate_text($output) . "\n"
            . 'To list the changed posts: wp stillgate drift'
        );
    }

    info('Stillgate: the receipt is clean');
});

desc('Ends the Stillgate freeze');
task('stillgate:release', function () {
    stillgate_run(stillgate_command(stillgate_target(), 'disable'));

    set('stillgate_state', 'released');
});

desc('Pushes the end of the Stillgate freeze back');
task('stillgate:extend', function () {
    if (in_array(get('stillgate_state', ''), ['skipped', 'released'], true)) {
        info('Stillgate: no freeze to extend');

        return;
    }

    stillgate_run(stillgate_command(stillgate_target(), 'extend ' . quote((string) get('stillgate_extend_by'))));
});

desc('Applies the timed Stillgate freeze again, for example after a database import');
task('stillgate:refreeze', function () {
    if (in_array(get('stillgate_state', ''), ['skipped', 'released'], true)) {
        info('Stillgate: no freeze from this deploy, nothing to apply again');

        return;
    }

    $dir = stillgate_target();

    // Stillgate keeps its state and its receipt in the database, so after an
    // import they are whatever the imported database held: no freeze, an
    // expired one, or a running one with the receipt of the environment the
    // dump came from. An open freeze is ended first, because starting a new
    // one over it would keep that receipt.
    $status = stillgate_run(stillgate_command($dir, 'status --format=json'));

    if (true === stillgate_is_active($status)) {
        stillgate_run(stillgate_command($dir, 'disable'));
    }

    set('stillgate_state', 'frozen');
    set('stillgate_frozen_dir', $dir);

    stillgate_run(stillgate_command($dir, stillgate_enable_arguments()));

    info('Stillgate: editing is frozen again at ' . stillgate_console($dir) . ' for ' . stillgate_console((string) get('stillgate_for')) . ' at most');
});

// The hooks call these two, which act only when this deploy started a freeze.
// A deploy that failed before freezing, or skipped the freeze, never ends
// a freeze that somebody else started.
desc('Runs stillgate:check if this deploy froze the site');
task('stillgate:check-if-frozen', function () {
    if ('frozen' !== get('stillgate_state', '')) {
        info('Stillgate: no freeze from this deploy, receipt not checked');

        return;
    }

    if (!get('stillgate_check')) {
        warning('Stillgate: stillgate_check is off, receipt not checked');

        return;
    }

    invoke('stillgate:check');
})->hidden();

desc('Runs stillgate:release if this deploy froze the site');
task('stillgate:release-if-frozen', function () {
    if ('frozen' !== get('stillgate_state', '')) {
        return;
    }

    invoke('stillgate:release');
})->hidden();

/*
 * Hooks.
 *
 * Freeze right after the deploy lock is taken: a deploy that finds the lock
 * held stops there, before it could touch another deploy's freeze. Check before
 * the new release is published. Release after deploy:symlink has made the new
 * release live and before deploy:unlock gives the lock away, so there is no
 * moment in which the lock is free and the freeze is still on. On failure,
 * release in deploy:failed.
 */
after('deploy:lock', 'stillgate:freeze');
before('deploy:publish', 'stillgate:check-if-frozen');
before('deploy:unlock', 'stillgate:release-if-frozen');
after('deploy:failed', 'stillgate:release-if-frozen');
