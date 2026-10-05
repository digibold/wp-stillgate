#!/usr/bin/env bash
#
# End-to-end test of the upgrade path from the 0.1.0 release (published as
# "Deployment Guard") to Stillgate, using the two real distribution ZIPs
# (built with `git archive`, exactly as releases are), real WP-CLI and real
# HTTP requests against a throwaway WordPress site.
#
# Stillgate keeps the earlier release's namespace, options and capability,
# so both copies share one set of stored state. This script proves:
#
#  A. The documented sequence works and nothing is lost: install Stillgate,
#     deactivate Deployment Guard, activate Stillgate, then delete the old
#     copy. The freeze configuration, the activity log and every role's
#     bypass grant (including a custom role that re-installing would never
#     grant) survive the old copy's real uninstall.php, whichever way it is
#     removed: the Plugins-screen Delete, delete_plugins(), `wp plugin
#     delete` or `wp plugin uninstall`.
#  B. Control: deleting the old copy while Stillgate is NOT active really
#     does erase that shared state (which is why the sequence matters).
#  C. Activating Stillgate while the old copy is active does not fatal: it
#     stays passive and explains itself on the Plugins screen.
#  D. With the load order reversed (Stillgate first) nothing registers
#     twice and nothing fatals.
#
# Requires: wp-cli, php (including its built-in web server), curl, git with
# the release tag available, and a reachable MySQL/MariaDB server. Run
# manually; not part of `composer test:*`.
#
# Usage:
#   WP_TEST_DB_HOST=127.0.0.1:3306 bash tests/e2e/migration.sh
#
# Env vars (defaults shown):
#   WP_TEST_DB_HOST=127.0.0.1:3306
#   WP_TEST_DB_NAME=stillgate_e2e
#   WP_TEST_DB_USER=root
#   WP_TEST_DB_PASSWORD=root
#   WP_TEST_WEB_PORT=8766
#   WP_VERSION=latest
#   LEGACY_REF=v0.1.0   (git ref of the earlier release)

set -euo pipefail

DB_HOST="${WP_TEST_DB_HOST:-127.0.0.1:3306}"
DB_NAME="${WP_TEST_DB_NAME:-stillgate_e2e}"
DB_USER="${WP_TEST_DB_USER:-root}"
DB_PASS="${WP_TEST_DB_PASSWORD:-root}"
WEB_PORT="${WP_TEST_WEB_PORT:-8766}"
WP_VERSION="${WP_VERSION:-latest}"
LEGACY_REF="${LEGACY_REF:-v0.1.0}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d -t stillgate-migration.XXXXXX)"
SITE_DIR="$WORK_DIR/site"
SITE_URL="http://127.0.0.1:$WEB_PORT"
PLUGINS_DIR="$SITE_DIR/wp-content/plugins"
SERVER_PID=''

# A table prefix unique to this run; nothing outside this run's own tables
# is ever touched or removed.
DB_PREFIX="sgmig_$(date +%s)_$$_"

PASS=0
FAIL=0

cleanup() {
	if [ -n "$SERVER_PID" ]; then
		pkill -P "$SERVER_PID" 2>/dev/null || true
		kill "$SERVER_PID" 2>/dev/null || true
	fi

	if [ -f "$SITE_DIR/wp-config.php" ]; then
		wp db clean --yes >/dev/null 2>&1 || true
	fi

	rm -rf "$WORK_DIR"
}
trap cleanup EXIT

WP_BIN="$(command -v wp)"

wp() {
	php -d memory_limit=512M "$WP_BIN" --path="$SITE_DIR" --allow-root "$@"
}

log() {
	printf '\n\033[1m== %s ==\033[0m\n' "$1"
}

record() {
	local ok="$1" description="$2" detail="${3:-}"

	if [ "$ok" = yes ]; then
		echo "PASS: $description"
		PASS=$((PASS + 1))
	else
		echo "FAIL: $description"
		[ -n "$detail" ] && printf '%s\n' "$detail"
		FAIL=$((FAIL + 1))
	fi
}

# expect_exit CODE DESCRIPTION -- command...
expect_exit() {
	local expected="$1" description="$2"
	shift 2
	local actual=0
	"$@" >"$WORK_DIR/last.out" 2>"$WORK_DIR/last.err" || actual=$?

	if [ "$actual" -eq "$expected" ]; then
		record yes "$description (exit $actual)"
	else
		record no "$description (expected exit $expected, got $actual)" "$(printf -- '--- stdout ---\n%s\n--- stderr ---\n%s' "$(cat "$WORK_DIR/last.out")" "$(cat "$WORK_DIR/last.err")")"
	fi
}

# expect_clean DESCRIPTION -- command...: exit 0 and no PHP fatal/warning/notice.
expect_clean() {
	local description="$1"
	shift 2
	local actual=0
	"$@" >"$WORK_DIR/last.out" 2>"$WORK_DIR/last.err" || actual=$?

	if [ "$actual" -eq 0 ] && ! grep -qiE 'PHP (Fatal|Warning|Notice|Deprecated|Parse)|Fatal error|Cannot redeclare' "$WORK_DIR/last.out" "$WORK_DIR/last.err"; then
		record yes "$description (exit 0, no PHP error output)"
	else
		record no "$description (exit $actual)" "$(printf -- '--- stdout ---\n%s\n--- stderr ---\n%s' "$(cat "$WORK_DIR/last.out")" "$(cat "$WORK_DIR/last.err")")"
	fi
}

# expect_eq DESCRIPTION ACTUAL EXPECTED
expect_eq() {
	if [ "$2" = "$3" ]; then
		record yes "$1"
	else
		record no "$1" "expected [$3], got [$2]"
	fi
}

http_get() {
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' -b "$1" "$2" || true
}

wp_login() {
	curl -s -o /dev/null -c "$3" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
		--data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data-urlencode 'testcookie=1' --data-urlencode "redirect_to=$SITE_URL/wp-admin/" \
		"$SITE_URL/wp-login.php" || true
}

body_contains() {
	if grep -qF -- "$1" "$WORK_DIR/body" 2>/dev/null; then echo yes; else echo no; fi
}

body_has_php_error() {
	if grep -qiE 'fatal error|critical error|warning</b>|notice</b>|cannot redeclare' "$WORK_DIR/body" 2>/dev/null; then echo yes; else echo no; fi
}

json_get() {
	php -r '$d = json_decode( (string) stream_get_contents( STDIN ), true ); echo is_array( $d ) && isset( $d[ $argv[1] ] ) ? ( is_bool( $d[ $argv[1] ] ) ? ( $d[ $argv[1] ] ? "true" : "false" ) : $d[ $argv[1] ] ) : "";' "$1"
}

# The roles that currently hold the bypass capability, as a sorted list.
roles_with_bypass() {
	local role out=''
	for role in $(wp role list --field=role); do
		if wp cap list "$role" 2>/dev/null | grep -qx 'deployment_guard_bypass'; then
			out="$out $role"
		fi
	done
	printf '%s' "$out" | tr ' ' '\n' | sort | tr '\n' ' '
}

# Stored state as one comparable string.
stored_state() {
	printf '%s|%s' "$(wp option get deployment_guard_state --format=json 2>/dev/null || echo MISSING)" "$(wp option get deployment_guard_history --format=json 2>/dev/null || echo MISSING)"
}

# Fresh site content and plugins for each scenario (same files, empty tables).
reset_site() {
	wp plugin deactivate --all >/dev/null 2>&1 || true
	rm -rf "${PLUGINS_DIR:?}/deployment-guard" "${PLUGINS_DIR:?}/zz-deployment-guard" "${PLUGINS_DIR:?}/stillgate"
	wp db clean --yes >/dev/null
	wp core install --url="$SITE_URL" --title="Stillgate migration E2E" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
	wp config set DISABLE_WP_CRON true --raw >/dev/null
}

install_legacy() {
	wp plugin install "$WORK_DIR/legacy.zip" >/dev/null
	test -f "$PLUGINS_DIR/deployment-guard/deployment-guard.php"
}

# Seed the earlier release with real state: log entries, a message, a
# custom role granted the bypass capability and a bypass user.
seed_legacy_state() {
	wp role create dg_release_editor 'Release Editor' --clone=editor >/dev/null
	wp cap add dg_release_editor deployment_guard_bypass >/dev/null
	wp user create releaser releaser@example.test --role=dg_release_editor --user_pass=releaser >/dev/null
	wp user create editor editor@example.test --role=editor --user_pass=editor >/dev/null
	POST_ID=$(wp post create --post_title='Original' --post_status=publish --porcelain)
	wp deployment-guard enable --message='Migrating the site.' >/dev/null
	wp post update "$POST_ID" --post_title='Blocked attempt' >/dev/null 2>&1 || true
	wp deployment-guard disable >/dev/null
	wp deployment-guard schedule --start="$(date -u -d '+2 days' '+%Y-%m-%d %H:%M' 2>/dev/null || date -u -v+2d '+%Y-%m-%d %H:%M')" \
		--end="$(date -u -d '+2 days +1 hour' '+%Y-%m-%d %H:%M' 2>/dev/null || date -u -v+2d -v+1H '+%Y-%m-%d %H:%M')" >/dev/null
}

# What must still work once Stillgate alone runs on the migrated state.
verify_working_stillgate() {
	local label="$1"
	expect_exit 0 "$label: wp stillgate status works" wp stillgate status
	expect_clean "$label: enabling a freeze through Stillgate" -- wp stillgate enable
	expect_exit 1 "$label: a plain editor is blocked during the freeze" wp post update "$POST_ID" --post_title='Editor change' --user=editor
	expect_exit 0 "$label: the custom bypass role still works (its capability survived)" wp post update "$POST_ID" --post_title='Release editor change' --user=releaser
	expect_exit 0 "$label: disabling the freeze" wp stillgate disable
}

log 'Building the two real distribution archives'
git -C "$REPO_ROOT" rev-parse --verify --quiet "$LEGACY_REF^{commit}" >/dev/null || { echo "Git ref $LEGACY_REF not found." >&2; exit 1; }
git -C "$REPO_ROOT" archive --format=zip --prefix=deployment-guard/ "$LEGACY_REF" -o "$WORK_DIR/legacy.zip"
git -C "$REPO_ROOT" archive --format=zip --prefix=stillgate/ HEAD -o "$WORK_DIR/stillgate.zip"
echo "earlier release ($LEGACY_REF): $(shasum -a 256 "$WORK_DIR/legacy.zip" | cut -d' ' -f1)"
echo "this build (HEAD):           $(shasum -a 256 "$WORK_DIR/stillgate.zip" | cut -d' ' -f1)"

log 'Setting up a throwaway WordPress site'
mkdir -p "$SITE_DIR"
wp core download --version="$WP_VERSION" --force >/dev/null
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" --dbprefix="$DB_PREFIX" --skip-check --force >/dev/null
wp db create 2>/dev/null || true
# wp config set loads WordPress, so the site has to be installed first.
wp core install --url="$SITE_URL" --title="Stillgate migration E2E" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
wp config set WP_ENVIRONMENT_TYPE local >/dev/null
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw >/dev/null
wp config set FS_METHOD direct >/dev/null
reset_site

if curl -s -o /dev/null --max-time 2 "$SITE_URL/"; then
	echo "Something is already listening on 127.0.0.1:$WEB_PORT; set WP_TEST_WEB_PORT to a free port." >&2
	exit 1
fi
PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable=0 -S "127.0.0.1:$WEB_PORT" -t "$SITE_DIR" >"$WORK_DIR/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
	curl -s -o /dev/null "$SITE_URL/readme.html" && break
	sleep 0.2
done
ADMIN_JAR="$WORK_DIR/admin.cookies"

# -------------------------------------------------------------------------
log 'A. The documented sequence, for each way of removing the old copy'
# Routes: how the old copy is finally removed.
for ROUTE in plugins-screen delete-plugins wp-plugin-delete wp-plugin-uninstall; do
	log "A ($ROUTE)"
	reset_site
	install_legacy
	wp plugin activate deployment-guard >/dev/null
	seed_legacy_state

	# 1. Install Stillgate (not activated yet).
	expect_exit 0 "[$ROUTE] 1. install the Stillgate ZIP (left inactive)" wp plugin install "$WORK_DIR/stillgate.zip"
	# 2. Deactivate Deployment Guard (this ends any active freeze).
	expect_exit 0 "[$ROUTE] 2. deactivate Deployment Guard" wp plugin deactivate deployment-guard
	# 3. Activate Stillgate.
	expect_clean "[$ROUTE] 3. activate Stillgate" -- wp plugin activate stillgate
	verify_working_stillgate "[$ROUTE] after switching"

	BEFORE_STATE="$(stored_state)"
	BEFORE_ROLES="$(roles_with_bypass)"
	expect_eq "[$ROUTE] the custom role holds the bypass capability before the removal" "$(printf '%s' "$BEFORE_ROLES" | grep -c 'dg_release_editor')" '1'

	# 4. Delete the old copy while Stillgate is active.
	case "$ROUTE" in
		plugins-screen)
			wp_login admin admin "$ADMIN_JAR"
			http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/plugins.php" >/dev/null
			NONCE="$(grep -o '"ajax_nonce":"[a-f0-9]*"' "$WORK_DIR/body" | head -1 | sed 's/.*"ajax_nonce":"\([a-f0-9]*\)".*/\1/')"
			RESPONSE="$(curl -s -b "$ADMIN_JAR" \
				--data-urlencode 'action=delete-plugin' \
				--data-urlencode 'plugin=deployment-guard/deployment-guard.php' \
				--data-urlencode 'slug=deployment-guard' \
				--data-urlencode "_ajax_nonce=$NONCE" \
				"$SITE_URL/wp-admin/admin-ajax.php" || true)"
			expect_eq "[$ROUTE] 4. the Plugins-screen Delete (admin-ajax delete-plugin) succeeds" "$(printf '%s' "$RESPONSE" | grep -c '"success":true')" '1'
			;;
		delete-plugins)
			expect_exit 0 "[$ROUTE] 4. delete_plugins() as the Plugins screen calls it" \
				wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $r = delete_plugins( array( "deployment-guard/deployment-guard.php" ) ); exit( true === $r ? 0 : 1 );'
			;;
		wp-plugin-delete)
			expect_exit 0 "[$ROUTE] 4. wp plugin delete deployment-guard" wp plugin delete deployment-guard
			;;
		wp-plugin-uninstall)
			expect_exit 0 "[$ROUTE] 4. wp plugin uninstall deployment-guard" wp plugin uninstall deployment-guard
			;;
	esac

	expect_eq "[$ROUTE] the old copy's files are gone" "$([ -e "$PLUGINS_DIR/deployment-guard" ] && echo present || echo gone)" 'gone'
	expect_eq "[$ROUTE] Stillgate is still active" "$(wp plugin get stillgate --field=status)" 'active'
	expect_eq "[$ROUTE] freeze configuration and activity log are exactly as before" "$(stored_state)" "$BEFORE_STATE"
	expect_eq "[$ROUTE] every role keeps its bypass capability" "$(roles_with_bypass)" "$BEFORE_ROLES"
	verify_working_stillgate "[$ROUTE] after removing the old copy"
done

# -------------------------------------------------------------------------
log 'B. Control: removing the old copy while Stillgate is NOT active erases the shared state'
reset_site
install_legacy
wp plugin activate deployment-guard >/dev/null
seed_legacy_state
wp plugin install "$WORK_DIR/stillgate.zip" >/dev/null
wp plugin deactivate deployment-guard >/dev/null
CONTROL_BEFORE="$(roles_with_bypass)"
expect_exit 0 'wp plugin uninstall deployment-guard (Stillgate inactive)' wp plugin uninstall deployment-guard
expect_eq 'control: the freeze state option is gone' "$(wp option get deployment_guard_state >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'control: the activity log is gone' "$(wp option get deployment_guard_history >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'control: the custom role lost its bypass capability' "$(roles_with_bypass | grep -c 'dg_release_editor')" '0'
echo "(roles holding the capability before: $CONTROL_BEFORE)"

# -------------------------------------------------------------------------
log 'C. Activating Stillgate while the old copy is active'
reset_site
install_legacy
wp plugin activate deployment-guard >/dev/null
seed_legacy_state
wp plugin install "$WORK_DIR/stillgate.zip" >/dev/null
BEFORE_STATE="$(stored_state)"
expect_clean 'activating Stillgate with the old copy active (no fatal)' -- wp plugin activate stillgate
expect_eq 'both plugins are active' "$(wp plugin list --status=active --field=name | grep -cE '^(stillgate|deployment-guard)$')" '2'
expect_exit 0 'the old copy keeps working (wp deployment-guard status)' wp deployment-guard status
expect_exit 1 'Stillgate stayed passive (wp stillgate is not registered yet)' wp stillgate status
expect_eq 'stored state untouched by the attempt' "$(stored_state)" "$BEFORE_STATE"

wp_login admin admin "$ADMIN_JAR"
expect_eq 'Plugins screen renders (200)' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/plugins.php")" '200'
expect_eq 'Plugins screen has no PHP error' "$(body_has_php_error)" 'no'
expect_eq 'Plugins screen explains that Stillgate has not started' "$(body_contains 'Stillgate has not started')" 'yes'
expect_eq 'dashboard renders (200)' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/")" '200'
expect_eq 'dashboard has no PHP error' "$(body_has_php_error)" 'no'
expect_eq 'the notice is limited to the Plugins screen' "$(body_contains 'Stillgate has not started')" 'no'

expect_exit 0 'deactivating the old copy' wp plugin deactivate deployment-guard
expect_exit 0 'Stillgate takes over with the same settings (wp stillgate status)' wp stillgate status
expect_eq 'Plugins screen no longer shows the notice' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/plugins.php" >/dev/null; body_contains 'has not started')" 'no'

# -------------------------------------------------------------------------
log 'D. Load order reversed: Stillgate loads first, the old copy second'
reset_site
install_legacy
mv "$PLUGINS_DIR/deployment-guard" "$PLUGINS_DIR/zz-deployment-guard"
wp plugin install "$WORK_DIR/stillgate.zip" >/dev/null
expect_clean 'activate Stillgate' -- wp plugin activate stillgate
expect_clean 'activate the old copy (loads after Stillgate, no fatal)' -- wp plugin activate zz-deployment-guard
expect_exit 0 'wp stillgate status works' wp stillgate status
HOOKS="$(wp eval 'global $wp_filter; $n = 0; foreach ( $wp_filter["map_meta_cap"]->callbacks as $callbacks ) { foreach ( $callbacks as $cb ) { if ( is_array( $cb["function"] ) && $cb["function"][0] instanceof \Digibold\DeploymentGuard\Enforcement\CapabilityFilter ) { $n++; } } } echo $n;')"
expect_eq 'enforcement hooks are registered exactly once' "$HOOKS" '1'

wp_login admin admin "$ADMIN_JAR"
expect_eq 'Plugins screen renders (200)' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/plugins.php")" '200'
expect_eq 'Plugins screen has no PHP error' "$(body_has_php_error)" 'no'
expect_eq 'Plugins screen says the old copy is still active alongside Stillgate' "$(body_contains 'still active alongside Stillgate')" 'yes'
expect_eq 'Tools > Stillgate renders (200)' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/tools.php?page=stillgate")" '200'
expect_eq 'Tools > Stillgate has no PHP error' "$(body_has_php_error)" 'no'

if [ "$FAIL" -gt 0 ] && [ -s "$WORK_DIR/server.log" ]; then
	echo
	echo '--- web server log (last 40 lines) ---'
	tail -n 40 "$WORK_DIR/server.log"
fi

echo
echo "================================"
echo "Results: $PASS passed, $FAIL failed"
echo "================================"

[ "$FAIL" -eq 0 ]
