#!/usr/bin/env bash
#
# End-to-end test against a real WordPress install, real WP-CLI and real
# HTTP requests (PHP's built-in web server): proves the distributed plugin
# (no vendor/, no PHPUnit test doubles) actually blocks the WP-CLI commands
# most likely to run during a deployment, that --user=<bypass account> is
# the working escape hatch, that a scheduled post held back by an active
# freeze publishes normally once the freeze ends and WP-Cron gets another
# chance to run it, and that wp-admin, the REST API, XML-RPC,
# ALTERNATE_WP_CRON and uninstall behave the same way when reached through
# a real web server and a real plugin lifecycle rather than a test harness.
#
# Requires: wp-cli, php (including its built-in web server), curl, and a
# reachable MySQL/MariaDB server. Not part of `composer test:*` (those need
# only a database, not a full WordPress install); run manually or wire
# into CI separately from the main suite.
#
# Usage:
#   WP_TEST_DB_HOST=127.0.0.1:3306 bash tests/e2e/cli.sh
#
# Env vars (defaults shown):
#   WP_TEST_DB_HOST=127.0.0.1:3306
#   WP_TEST_DB_NAME=deployment_guard_e2e
#   WP_TEST_DB_USER=root
#   WP_TEST_DB_PASSWORD=root
#   WP_TEST_WEB_PORT=8765
#   WP_VERSION=latest

set -euo pipefail

DB_HOST="${WP_TEST_DB_HOST:-127.0.0.1:3306}"
DB_NAME="${WP_TEST_DB_NAME:-deployment_guard_e2e}"
DB_USER="${WP_TEST_DB_USER:-root}"
DB_PASS="${WP_TEST_DB_PASSWORD:-root}"
WEB_PORT="${WP_TEST_WEB_PORT:-8765}"
WP_VERSION="${WP_VERSION:-latest}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d -t stillgate-e2e.XXXXXX)"
SITE_DIR="$WORK_DIR/site"
SITE_URL="http://127.0.0.1:$WEB_PORT"
SERVER_PID=''

# A table prefix unique to this run: a fresh install every time, even
# against a database a previous run already used, and nothing outside this
# run's own tables is ever touched or removed.
DB_PREFIX="dge2e_$(date +%s)_$$_"

PASS=0
FAIL=0

cleanup() {
	if [ -n "$SERVER_PID" ]; then
		# With PHP_CLI_SERVER_WORKERS the server forks worker processes, which
		# keep listening on the port after their parent exits unless they are
		# stopped first.
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

# expect_exit CODE DESCRIPTION -- command...
expect_exit() {
	local expected="$1" description="$2"
	shift 2
	local actual=0
	"$@" >"$WORK_DIR/last.out" 2>"$WORK_DIR/last.err" || actual=$?

	if [ "$actual" -eq "$expected" ]; then
		echo "PASS ($actual): $description"
		PASS=$((PASS + 1))
	else
		echo "FAIL (expected $expected, got $actual): $description"
		echo '--- stdout ---'; cat "$WORK_DIR/last.out"
		echo '--- stderr ---'; cat "$WORK_DIR/last.err"
		FAIL=$((FAIL + 1))
	fi
}

# expect_eq DESCRIPTION ACTUAL EXPECTED
expect_eq() {
	local description="$1" actual="$2" expected="$3"

	if [ "$actual" = "$expected" ]; then
		echo "PASS: $description"
		PASS=$((PASS + 1))
	else
		echo "FAIL: $description (expected [$expected], got [$actual])"
		FAIL=$((FAIL + 1))
	fi
}

# The HTTP helpers below never abort the script: a request that fails
# outright reports status 000 and is recorded as a failed expectation.

# http_get COOKIE_JAR URL — prints the status code; the body is saved to $WORK_DIR/body.
http_get() {
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' -b "$1" "$2" || true
}

# wp_login USER PASSWORD COOKIE_JAR
wp_login() {
	curl -s -o /dev/null -c "$3" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
		--data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data-urlencode 'testcookie=1' --data-urlencode "redirect_to=$SITE_URL/wp-admin/" \
		"$SITE_URL/wp-login.php" || true
}

# body_contains TEXT — prints "yes" or "no" for the last saved response body.
body_contains() {
	if grep -qF -- "$1" "$WORK_DIR/body" 2>/dev/null; then echo yes; else echo no; fi
}

body_has_php_error() {
	if grep -qiE 'fatal error|critical error|warning</b>|notice</b>' "$WORK_DIR/body" 2>/dev/null; then echo yes; else echo no; fi
}

# rest METHOD ROUTE [curl args...] — as the editor, via an application password.
rest() {
	local method="$1" route="$2"
	shift 2
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' -u "editor:$APP_PASSWORD" -X "$method" "$@" "$SITE_URL/?rest_route=$route" || true
}

json_field() {
	php -r '$d = json_decode( (string) @file_get_contents( $argv[1] ), true ); echo is_array( $d ) && isset( $d[ $argv[2] ] ) ? $d[ $argv[2] ] : "";' "$WORK_DIR/body" "$1"
}

log 'Setting up a throwaway WordPress site'
mkdir -p "$SITE_DIR"
wp core download --version="$WP_VERSION" --force
wp config create \
	--dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" --dbprefix="$DB_PREFIX" --skip-check --force
wp db create 2>/dev/null || true
wp core install \
	--url="$SITE_URL" \
	--title="Stillgate E2E" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test
wp config set WP_ENVIRONMENT_TYPE local
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp config set DISABLE_WP_CRON true --raw

log 'Installing the distributed plugin build (no vendor/, as a real user would get it)'
BUILD_DIR="$SITE_DIR/wp-content/plugins/stillgate"
mkdir -p "$BUILD_DIR"
if git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
	git -C "$REPO_ROOT" archive --format=tar HEAD | tar -x -C "$BUILD_DIR"
else
	# No git history yet (a fresh checkout of the repo before its first
	# commit): approximate `git archive` using the same export-ignore list
	# as .gitattributes.
	rsync -a "$REPO_ROOT"/ "$BUILD_DIR"/ \
		--exclude='.git' --exclude='.github' --exclude='bin' --exclude='docs' \
		--exclude='tests' --exclude='vendor' --exclude='wordpress' \
		--exclude='.gitattributes' --exclude='.gitignore' --exclude='.editorconfig' \
		--exclude='.DS_Store' --exclude='.phpunit.cache' \
		--exclude='composer.json' --exclude='composer.lock' \
		--exclude='phpcs.xml.dist' --exclude='phpunit*.xml.dist' \
		--exclude='CONTRIBUTING.md' --exclude='CODE_OF_CONDUCT.md' --exclude='SECURITY.md' \
		--exclude='README.md'
fi
test ! -e "$BUILD_DIR/vendor"

cp "$REPO_ROOT/tests/Support/fixtures/pixel.png" "$WORK_DIR/pixel.png"
mkdir -p "$SITE_DIR/wp-content/mu-plugins"
cp "$REPO_ROOT/tests/e2e/mu-plugin-probe.php" "$SITE_DIR/wp-content/mu-plugins/dg-e2e-probe.php"

wp plugin activate stillgate

log 'Seeding fixtures (before the freeze starts)'
POST_ID=$(wp post create --post_title="Original" --post_status=publish --porcelain)
TERM_ID=$(wp term create category "Original Category" --porcelain)
wp user create editor editor@example.test --role=editor --user_pass=editor >/dev/null
APP_PASSWORD=$(wp user application-password create editor dg-e2e --porcelain)
# wp_insert_post() only treats a "future" post as genuinely future when
# post_date_gmt is also provided (a bare --post_date, with no --post_date_gmt,
# is otherwise resolved against a post_date_gmt of "now" and publishes
# immediately) — the site here uses UTC, so the two are identical. An hour
# out leaves no doubt it is created as "future" regardless of how long this
# script's own WP-CLI bootstrap takes.
FAR_FUTURE=$(date -u -d '+1 hour' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u -v+1H '+%Y-%m-%d %H:%M:%S')
FUTURE_POST_ID=$(wp post create --post_title="Scheduled" --post_status=future --post_date="$FAR_FUTURE" --post_date_gmt="$FAR_FUTURE" --porcelain)
ALT_POST_ID=$(wp post create --post_title="Scheduled (alternate cron)" --post_status=future --post_date="$FAR_FUTURE" --post_date_gmt="$FAR_FUTURE" --porcelain)
expect_eq 'scheduled post fixture is genuinely "future" at creation' "$(wp post get "$FUTURE_POST_ID" --field=post_status)" 'future'

# Now back-date it directly in the database to make it "due", exactly what
# a genuinely missed cron run looks like — sidestepping wp_insert_post()'s
# own past-date auto-publish correction (which exists precisely to stop a
# *client* from creating a "future" post with a non-future date) rather
# than racing this script's own execution time against a short window.
DUE_AT=$(date -u -d '-30 seconds' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u -v-30S '+%Y-%m-%d %H:%M:%S')
wp eval "global \$wpdb; \$wpdb->update( \$wpdb->posts, array( 'post_date' => '$DUE_AT', 'post_date_gmt' => '$DUE_AT' ), array( 'ID' => $FUTURE_POST_ID ) ); clean_post_cache( $FUTURE_POST_ID );"
expect_eq 'scheduled post fixture is now due' "$(wp post get "$FUTURE_POST_ID" --field=post_status)" 'future'

log 'Enabling the freeze'
expect_exit 0 'wp stillgate enable' wp stillgate enable
ACTIVE=$(wp stillgate status --format=json | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["active"];')
expect_eq 'status reports active' "$ACTIVE" 'true'

log 'Arbitrary WP-CLI commands are blocked (WP-CLI is not exempt)'
expect_exit 1 'wp post update is blocked' wp post update "$POST_ID" --post_title="Changed"
expect_eq 'post title unchanged after blocked update' "$(wp post get "$POST_ID" --field=post_title)" 'Original'

expect_exit 1 'wp post delete (trash) is blocked' wp post delete "$POST_ID"
expect_eq 'post still published after blocked trash' "$(wp post get "$POST_ID" --field=post_status)" 'publish'

expect_exit 1 'wp post delete --force is blocked' wp post delete "$POST_ID" --force
expect_eq 'post still exists after blocked force-delete' "$(wp post get "$POST_ID" --field=post_status)" 'publish'

expect_exit 1 'wp post create is blocked' wp post create --post_title="Should not exist" --post_status=publish

expect_exit 1 'wp term create is blocked' wp term create category "Should not exist"
expect_exit 1 'wp term update is blocked' wp term update category "$TERM_ID" --name="Changed"
expect_eq 'term name unchanged after blocked update' "$(wp term get category "$TERM_ID" --field=name)" 'Original Category'
expect_exit 1 'wp term delete is blocked' wp term delete category "$TERM_ID"

expect_exit 1 'wp media import is blocked' wp media import "$WORK_DIR/pixel.png"
expect_exit 1 'wp media import --skip-copy is blocked' wp media import "$WORK_DIR/pixel.png" --skip-copy

log 'The explicit --user=<bypass account> escape hatch works'
expect_exit 0 'wp post update with --user=admin is allowed' wp post update "$POST_ID" --post_title="Changed by admin" --user=admin
expect_eq 'post title changed by the bypass user' "$(wp post get "$POST_ID" --field=post_title)" 'Changed by admin'

log 'WP-Cron: unrelated events run, protected scheduled publishing is held back'
wp cron event schedule dg_e2e_probe now >/dev/null
wp cron event run --due-now >/dev/null 2>&1 || true
expect_eq 'scheduled post still "future" during an active freeze' "$(wp post get "$FUTURE_POST_ID" --field=post_status)" 'future'
expect_eq 'unrelated cron event still ran during the freeze' "$(wp option get dg_e2e_probe_ran 2>/dev/null || echo 0)" '1'
# The guard's hold() reschedules the held event a few minutes out (or at a
# scheduled freeze's end, if sooner); confirm it is genuinely pending
# again rather than simply abandoned.
wp cron event list --fields=hook --format=csv | grep -q '^publish_future_post$'
expect_eq 'publish_future_post is rescheduled, not abandoned' "$?" '0'

log 'Bypass identity cannot open cron as a write path'
wp option delete dg_e2e_probe_ran >/dev/null 2>&1 || true
wp --user=admin cron event run --due-now >/dev/null 2>&1 || true
expect_eq 'scheduled post still "future" even run as a bypass user' "$(wp post get "$FUTURE_POST_ID" --field=post_status)" 'future'

log 'Real HTTP requests through a web server (PHP built-in server)'
if curl -s -o /dev/null --max-time 2 "$SITE_URL/"; then
	echo "Something is already listening on 127.0.0.1:$WEB_PORT; set WP_TEST_WEB_PORT to a free port." >&2
	exit 1
fi
# OPcache off: this script edits wp-config.php between requests, and a
# cached copy that has not been revalidated yet would silently keep the
# previous constants.
PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable=0 -S "127.0.0.1:$WEB_PORT" -t "$SITE_DIR" >"$WORK_DIR/server.log" 2>&1 &
SERVER_PID=$!
# Wait on a static file: it never boots WordPress, so waiting for the server
# can never spawn WP-Cron (or leave its lock behind) as a side effect.
for _ in $(seq 1 50); do
	if curl -s -o /dev/null "$SITE_URL/readme.html"; then
		break
	fi
	sleep 0.2
done

ADMIN_JAR="$WORK_DIR/admin.cookies"
EDITOR_JAR="$WORK_DIR/editor.cookies"
wp_login admin admin "$ADMIN_JAR"
wp_login editor editor "$EDITOR_JAR"

expect_eq 'administrator: wp-admin dashboard returns 200 during a freeze' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/")" '200'
expect_eq 'administrator: dashboard renders without a PHP error' "$(body_has_php_error)" 'no'
expect_eq 'administrator: dashboard shows the freeze notice' "$(body_contains 'Content changes are temporarily locked')" 'yes'
expect_eq 'administrator: Tools > Stillgate returns 200' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/tools.php?page=stillgate")" '200'
expect_eq 'administrator: settings screen renders without a PHP error' "$(body_has_php_error)" 'no'
expect_eq 'administrator: can still open the editor for a protected post' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/post.php?post=$POST_ID&action=edit")" '200'

expect_eq 'editor: wp-admin dashboard returns 200' "$(http_get "$EDITOR_JAR" "$SITE_URL/wp-admin/")" '200'
expect_eq 'editor: dashboard renders without a PHP error' "$(body_has_php_error)" 'no'
expect_eq 'editor: opening the editor for a protected post returns 423' "$(http_get "$EDITOR_JAR" "$SITE_URL/wp-admin/post.php?post=$POST_ID&action=edit")" '423'
expect_eq 'editor: the 423 page carries the freeze message' "$(body_contains 'Content changes are temporarily locked')" 'yes'
expect_eq 'editor: the posts list stays viewable' "$(http_get "$EDITOR_JAR" "$SITE_URL/wp-admin/edit.php")" '200'

# A restricted user who can manage theme options (the mu-plugin gives the
# site classic Custom Background support): core serves that screen from
# more than one URL, and every one of them must be gated.
wp role create dg_theme_manager 'Theme Manager' --clone=subscriber >/dev/null
wp cap add dg_theme_manager edit_theme_options >/dev/null
wp user create themer themer@example.test --role=dg_theme_manager --user_pass=themer >/dev/null
THEMER_JAR="$WORK_DIR/themer.cookies"
wp_login themer themer "$THEMER_JAR"
expect_eq 'administrator: Custom Background stays available to a bypass holder' "$(http_get "$ADMIN_JAR" "$SITE_URL/wp-admin/themes.php?page=custom-background")" '200'
expect_eq 'theme manager: Custom Background (themes.php) returns 423' "$(http_get "$THEMER_JAR" "$SITE_URL/wp-admin/themes.php?page=custom-background")" '423'
expect_eq 'theme manager: Custom Background reached through admin.php returns 423' "$(http_get "$THEMER_JAR" "$SITE_URL/wp-admin/admin.php?page=custom-background")" '423'

expect_eq 'REST: reading a protected post is allowed' "$(rest GET "/wp/v2/posts/$POST_ID")" '200'
expect_eq 'REST: updating a protected post returns 423' "$(rest POST "/wp/v2/posts/$POST_ID" --data-urlencode 'title=Changed over REST')" '423'
expect_eq 'REST: error code is deployment_guard_active' "$(json_field code)" 'deployment_guard_active'
expect_eq 'REST: a ?_method=DELETE override returns 423' "$(rest POST "/wp/v2/posts/$POST_ID&_method=DELETE")" '423'
expect_eq 'REST: an X-HTTP-Method-Override: DELETE header returns 423' "$(rest POST "/wp/v2/posts/$POST_ID" -H 'X-HTTP-Method-Override: DELETE')" '423'
expect_eq 'post untouched by the REST attempts' "$(wp post get "$POST_ID" --field=post_title)|$(wp post get "$POST_ID" --field=post_status)" 'Changed by admin|publish'

XMLRPC_EDIT="<?xml version=\"1.0\"?><methodCall><methodName>wp.editPost</methodName><params><param><value><int>1</int></value></param><param><value><string>editor</string></value></param><param><value><string>editor</string></value></param><param><value><int>$POST_ID</int></value></param><param><value><struct><member><name>post_title</name><value><string>Changed over XML-RPC</string></value></member></struct></value></param></params></methodCall>"
curl -s -o "$WORK_DIR/body" -H 'Content-Type: text/xml' --data "$XMLRPC_EDIT" "$SITE_URL/xmlrpc.php" >/dev/null || true
expect_eq 'XML-RPC: wp.editPost returns fault code 423' "$(body_contains '<int>423</int>')" 'yes'
expect_eq 'post title unchanged by the XML-RPC attempt' "$(wp post get "$POST_ID" --field=post_title)" 'Changed by admin'

log 'ALTERNATE_WP_CRON: due events run inside a page request, protected scheduled posts are still held'
wp eval "global \$wpdb; \$due = gmdate( 'Y-m-d H:i:s', time() - 30 ); \$wpdb->update( \$wpdb->posts, array( 'post_date' => \$due, 'post_date_gmt' => \$due ), array( 'ID' => $ALT_POST_ID ) ); clean_post_cache( $ALT_POST_ID ); wp_clear_scheduled_hook( 'publish_future_post', array( $ALT_POST_ID ) ); wp_schedule_single_event( time() - 5, 'publish_future_post', array( $ALT_POST_ID ) ); wp_schedule_single_event( time() - 5, 'dg_e2e_probe' ); delete_option( 'dg_e2e_probe_ran' ); delete_transient( 'doing_cron' );"
wp config delete DISABLE_WP_CRON >/dev/null
wp config set ALTERNATE_WP_CRON true --raw >/dev/null
curl -s -o /dev/null "$SITE_URL/" || true
wp config delete ALTERNATE_WP_CRON >/dev/null
wp config set DISABLE_WP_CRON true --raw >/dev/null
expect_eq 'ALTERNATE_WP_CRON really ran due events inside the page request' "$(wp option get dg_e2e_probe_ran 2>/dev/null || echo 0)" '1'
expect_eq 'ALTERNATE_WP_CRON: protected scheduled post still "future"' "$(wp post get "$ALT_POST_ID" --field=post_status)" 'future'

log 'Ending the freeze lets everything through again'
expect_exit 0 'wp stillgate disable' wp stillgate disable
# Run the specific rescheduled event by name rather than --due-now: its
# retry time is usually still a few minutes out, and `cron event run
# <hook>` runs a named event immediately regardless of whether it is due.
expect_exit 0 'wp cron event run publish_future_post' wp cron event run publish_future_post
expect_eq 'scheduled post published once the freeze ended' "$(wp post get "$FUTURE_POST_ID" --field=post_status)" 'publish'

log 'Pausing visitor comments and forms, the receipt and the REST API (real HTTP)'
ADMIN_APP_PASSWORD=$(wp user application-password create admin dg-e2e-admin --porcelain)

# admin_rest METHOD ROUTE [curl args...] — as the administrator, via an application password.
admin_rest() {
	local method="$1" route="$2"
	shift 2
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' -u "admin:$ADMIN_APP_PASSWORD" -X "$method" "$@" "$SITE_URL/?rest_route=$route" || true
}

# visitor_comment — an anonymous comment on the fixture post; prints the status code.
visitor_comment() {
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' \
		--data-urlencode "comment_post_ID=$POST_ID" --data-urlencode 'author=Visitor' \
		--data-urlencode 'email=visitor@example.test' --data-urlencode 'comment=Hello there' \
		--data-urlencode 'comment_parent=0' "$SITE_URL/wp-comments-post.php" || true
}

# receipt_field PATH — a value from `wp stillgate receipt --format=json`, e.g. paused.comments.
receipt_field() {
	wp stillgate receipt --format=json | php -r '$d = json_decode( stream_get_contents( STDIN ), true ); foreach ( explode( ".", $argv[1] ) as $k ) { $d = $d[ $k ] ?? null; } echo is_array( $d ) ? implode( ",", $d ) : ( is_bool( $d ) ? ( $d ? "true" : "false" ) : (string) $d );' "$1"
}

# The earlier steps changed posts a moment ago; a change in the very second a
# freeze begins counts as made during it, so let the clock move on first.
sleep 2
# The scheduled fixtures were published early above, so WordPress stamped them
# with the future date they were scheduled for; that is not a change made
# during the freeze that is about to start.
wp db query "UPDATE ${DB_PREFIX}posts SET post_modified_gmt = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 HOUR ) WHERE post_modified_gmt > UTC_TIMESTAMP()"
expect_exit 0 'wp stillgate scope --comments=on --forms=on' wp stillgate scope --comments=on --forms=on
expect_exit 0 'wp stillgate enable --for=30m' wp stillgate enable --for=30m
COMMENTS_BEFORE=$(wp comment list --post_id="$POST_ID" --format=count)

expect_eq 'a visitor comment is refused with 423 while comments are paused' "$(visitor_comment)" '423'
expect_eq 'the refusal carries the visitor message' "$(body_contains 'temporarily paused')" 'yes'
expect_eq 'no comment was stored' "$(wp comment list --post_id="$POST_ID" --format=count)" "$COMMENTS_BEFORE"

expect_eq 'a Contact Form 7 style post is refused with 423' "$(curl -s -o "$WORK_DIR/body" -w '%{http_code}' --data-urlencode '_wpcf7=1' --data-urlencode 'your-name=Visitor' "$SITE_URL/" || true)" '423'
expect_eq 'the same body sent to a REST-looking URL is refused too' "$(curl -s -o "$WORK_DIR/body" -w '%{http_code}' --data-urlencode '_wpcf7=1' "$SITE_URL/wp-json/anything/at/all" || true)" '423'
expect_eq 'an admin-ajax form submission is refused' "$(curl -s -o "$WORK_DIR/body" -w '%{http_code}' --data-urlencode 'action=wpforms_submit' "$SITE_URL/wp-admin/admin-ajax.php" || true)" '200'
expect_eq 'the admin-ajax refusal carries the visitor message' "$(body_contains 'temporarily paused')" 'yes'
expect_eq 'a Contact Form 7 REST route in any letter case is refused' "$(curl -s -o "$WORK_DIR/body" -w '%{http_code}' -X POST --data-urlencode '_wpcf7_unit_tag=wpcf7-f1-p2-o1' "$SITE_URL/?rest_route=/Contact-Form-7/V1/Contact-Forms/1/FEEDBACK" || true)" '200'
expect_eq '... and is answered as aborted, not processed' "$(body_contains '"status":"aborted"')" 'yes'

expect_eq 'REST API: the status is readable by an administrator' "$(admin_rest GET /stillgate/v1/status)" '200'
expect_eq 'REST API: it reports the freeze as active' "$(json_field active)" '1'
expect_eq 'REST API: an editor is refused' "$(rest GET /stillgate/v1/status)" '403'
expect_eq 'REST API: a freeze with an empty duration is refused' "$(admin_rest POST /stillgate/v1/freeze -d 'for=')" '400'
expect_eq 'REST API: the refusal is invalid_duration' "$(json_field code)" 'invalid_duration'
expect_eq 'REST API: extending a timed freeze succeeds' "$(admin_rest POST /stillgate/v1/freeze/extend -d 'by=15m')" '200'
expect_eq 'REST API: the receipt is readable' "$(admin_rest GET /stillgate/v1/receipt)" '200'

expect_eq 'receipt: one visitor comment was paused' "$(receipt_field paused.comments)" '1'
expect_eq 'receipt: four form submissions were paused' "$(receipt_field paused.forms)" '4'
expect_eq 'receipt: nothing in the content changed' "$(receipt_field clean)" 'true'
expect_exit 0 'wp stillgate receipt --check passes for a clean receipt' wp stillgate receipt --check

wp post update "$POST_ID" --post_title="Changed during the pause" --user=admin >/dev/null
expect_eq 'receipt: a change by a bypass user is listed' "$(receipt_field content.changed_ids)" "$POST_ID"
expect_exit 1 'wp stillgate receipt --check fails once something changed' wp stillgate receipt --check

expect_exit 0 'wp stillgate disable' wp stillgate disable
expect_eq 'a visitor comment is accepted again once the freeze has ended' "$(visitor_comment)" '302'
expect_eq 'the receipt keeps what happened while the freeze was on' "$(receipt_field paused.comments)" '1'
expect_exit 0 'wp stillgate scope --comments=off --forms=off' wp stillgate scope --comments=off --forms=off

log 'wp stillgate schedule and clear'
START=$(date -u '+%Y-%m-%dT%H:%M:%S')
END=$(date -u -d '+1 hour' '+%Y-%m-%dT%H:%M:%S' 2>/dev/null || date -u -v+1H '+%Y-%m-%dT%H:%M:%S')
expect_exit 0 'wp stillgate schedule' wp stillgate schedule --start="$START" --end="$END"
expect_exit 0 'wp stillgate schedule --clear' wp stillgate schedule --clear

log 'Deactivation clears the freeze'
expect_exit 0 'wp stillgate enable (before deactivating)' wp stillgate enable
wp plugin deactivate stillgate
wp plugin activate stillgate
ACTIVE_AFTER_REACTIVATE=$(wp stillgate status --format=json | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["active"];')
expect_eq 'freeze cleared by deactivation, not restored by reactivation' "$ACTIVE_AFTER_REACTIVATE" 'false'

log 'Uninstall (plugin not loaded, as when it is deleted from the Plugins screen)'
wp plugin deactivate stillgate
expect_exit 0 'wp plugin uninstall' wp plugin uninstall stillgate --skip-delete
expect_eq 'freeze state option removed' "$(wp option get deployment_guard_state >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'activity history option removed' "$(wp option get deployment_guard_history >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'coverage option removed' "$(wp option get deployment_guard_scope >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'receipt option removed' "$(wp option get deployment_guard_receipt >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'comment counter option removed' "$(wp option get deployment_guard_paused_comments >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'form counter option removed' "$(wp option get deployment_guard_paused_forms >/dev/null 2>&1 && echo present || echo missing)" 'missing'
expect_eq 'bypass capability removed from administrator' "$(wp cap list administrator | grep -c '^deployment_guard_bypass$' || true)" '0'

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
