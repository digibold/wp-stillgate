#!/usr/bin/env bash
#
# End-to-end test of the GitHub Action (action/main.js and action/post.js)
# against a real WordPress install with the distributed plugin build, real
# HTTP requests (PHP's built-in web server) and real WP-CLI. The two scripts
# are started exactly as the Actions runner starts them: the INPUT_*
# variables (the runner upper-cases the input id and keeps its hyphens, as in
# INPUT_SITE-URL), GITHUB_OUTPUT, GITHUB_STATE and GITHUB_STEP_SUMMARY
# pointing at files, and the saved state handed to the post step as STATE_*
# variables.
#
# Requires: wp-cli, php (including its built-in web server), node, curl, and
# a reachable MySQL/MariaDB server. Not part of `composer test:*`; run
# manually or wire into CI separately from the main suite.
#
# Usage:
#   WP_TEST_DB_HOST=127.0.0.1:3306 bash tests/e2e/action.sh
#
# Env vars (defaults shown):
#   WP_TEST_DB_HOST=127.0.0.1:3306
#   WP_TEST_DB_NAME=deployment_guard_e2e_action
#   WP_TEST_DB_USER=root
#   WP_TEST_DB_PASSWORD=root
#   WP_TEST_WEB_PORT=8766
#   WP_VERSION=latest
#   WP_CORE_DIR=      a WordPress directory to copy instead of downloading
#   STILLGATE_ZIP=    a plugin zip (top folder stillgate/) to install instead
#                     of `git archive HEAD`

set -euo pipefail

DB_HOST="${WP_TEST_DB_HOST:-127.0.0.1:3306}"
DB_NAME="${WP_TEST_DB_NAME:-deployment_guard_e2e_action}"
DB_USER="${WP_TEST_DB_USER:-root}"
DB_PASS="${WP_TEST_DB_PASSWORD:-root}"
WEB_PORT="${WP_TEST_WEB_PORT:-8766}"
WP_VERSION="${WP_VERSION:-latest}"
WP_CORE_DIR="${WP_CORE_DIR:-}"
STILLGATE_ZIP="${STILLGATE_ZIP:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d -t stillgate-e2e-action.XXXXXX)"
SITE_DIR="$WORK_DIR/site"
SITE_URL="http://127.0.0.1:$WEB_PORT"
SERVER_PID=''
CREATED_DB=0
JOB_DIR=''

DB_PREFIX="dgact_$(date +%s)_$$_"

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
		if [ "$CREATED_DB" -eq 1 ]; then
			wp db drop --yes >/dev/null 2>&1 || true
		else
			wp db clean --yes >/dev/null 2>&1 || true
		fi
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

# expect_match DESCRIPTION TEXT PATTERN (extended regular expression)
expect_match() {
	if printf '%s' "$2" | grep -Eq -- "$3"; then
		echo "PASS: $1"
		PASS=$((PASS + 1))
	else
		echo "FAIL: $1 (no match for [$3] in [$2])"
		FAIL=$((FAIL + 1))
	fi
}

# expect_contains DESCRIPTION TEXT FRAGMENT (a fixed string, not a pattern)
expect_contains() {
	if printf '%s' "$2" | grep -Fq -- "$3"; then
		echo "PASS: $1"
		PASS=$((PASS + 1))
	else
		echo "FAIL: $1 (no [$3] in [$2])"
		FAIL=$((FAIL + 1))
	fi
}

# expect_between DESCRIPTION VALUE MIN MAX
expect_between() {
	if [ "$2" -ge "$3" ] && [ "$2" -le "$4" ]; then
		echo "PASS: $1 ($2)"
		PASS=$((PASS + 1))
	else
		echo "FAIL: $1 (expected $3..$4, got [$2])"
		FAIL=$((FAIL + 1))
	fi
}

# --- Acting as the Actions runner ------------------------------------------

# settle — the receipt compares modification times at one-second resolution,
# so a post saved in the same second a freeze begins is counted as changed
# during it (it errs towards "changed"). Scenarios that expect a clean receipt
# wait out the second after the last write they or the setup made.
settle() {
	sleep 2
}

# new_job NAME — a fresh set of runner files, the way each step gets its own.
new_job() {
	JOB_DIR="$WORK_DIR/job-$1"
	mkdir -p "$JOB_DIR"
	: >"$JOB_DIR/state"
	: >"$JOB_DIR/output"
	: >"$JOB_DIR/summary"
}

# kv_file FILE [NAME] — read a GITHUB_OUTPUT/GITHUB_STATE file written with
# the delimiter protocol. With a NAME, print that value; without, print
# NAME=value lines for single-line values (all the action writes).
kv_file() {
	node -e '
		const lines = require("fs").readFileSync(process.argv[1], "utf8").split("\n");
		const result = {};
		for (let i = 0; i < lines.length; i += 1) {
			const m = /^([^<=]+)<<(.+)$/.exec(lines[i]);
			if (!m) continue;
			const value = [];
			for (i += 1; i < lines.length && lines[i] !== m[2]; i += 1) value.push(lines[i]);
			result[m[1]] = value.join("\n");
		}
		const name = process.argv[2];
		if (name) { process.stdout.write(result[name] === undefined ? "" : result[name]); }
		else { for (const k of Object.keys(result)) console.log(k + "=" + result[k]); }
	' "$1" "${2:-}"
}

job_output() { kv_file "$JOB_DIR/output" "$1"; }
job_state()  { kv_file "$JOB_DIR/state" "$1"; }

# act main|post [input=value ...] — run a step with the runner's environment.
# Inputs not given take the defaults declared in action.yml. The step's
# combined stdout and stderr go to $JOB_DIR/log, its exit code is returned.
act() {
	local step="$1"
	shift
	local args=(
		"INPUT_SITE-URL=$SITE_URL" "INPUT_USERNAME=admin" "INPUT_APP-PASSWORD=$ADMIN_APP_PASSWORD"
		"INPUT_DURATION=30m" "INPUT_MESSAGE=" "INPUT_IF-ALREADY-FROZEN=fail"
		"INPUT_FAIL-ON-CHANGES=true" "INPUT_RELEASE=true"
	)
	local pair name
	for pair in "$@"; do
		name="$(printf '%s' "${pair%%=*}" | tr '[:lower:]' '[:upper:]')"
		args+=( "INPUT_$name=${pair#*=}" )
	done

	if [ "$step" = post ]; then
		# The runner hands the state saved by the main step to the post step
		# as STATE_<name>, and gives the step new output and summary files.
		local line
		while IFS= read -r line; do
			[ -n "$line" ] && args+=( "STATE_$line" )
		done < <(kv_file "$JOB_DIR/state")
		: >"$JOB_DIR/output"
		: >"$JOB_DIR/summary"
	fi

	local code=0
	env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
		"GITHUB_OUTPUT=$JOB_DIR/output" "GITHUB_STATE=$JOB_DIR/state" "GITHUB_STEP_SUMMARY=$JOB_DIR/summary" \
		"${args[@]}" \
		node "$REPO_ROOT/action/$step.js" >"$JOB_DIR/log" 2>&1 || code=$?
	return "$code"
}

# act_code STEP [input=value ...] — print the exit code instead of returning it.
act_code() {
	local code=0
	act "$@" || code=$?
	echo "$code"
}

# The REST API as the administrator (independent of the action), for checking.
admin_rest() {
	curl -s -u "admin:$ADMIN_APP_PASSWORD" "$@" || true
}

status_field() {
	admin_rest "$SITE_URL/?rest_route=/stillgate/v1/status" |
		node -e 'let d="";process.stdin.on("data",c=>d+=c).on("end",()=>{const j=JSON.parse(d);const v=j[process.argv[1]];process.stdout.write(v===null||v===undefined?"":String(v));})' "$1"
}

# Seconds from now until an ISO 8601 time.
seconds_until() {
	node -e 'process.stdout.write(String(Math.round((Date.parse(process.argv[1]) - Date.now()) / 1000)))' "$1"
}

http_code() {
	curl -s -o "$WORK_DIR/body" -w '%{http_code}' "$@" || true
}

# --- Set up --------------------------------------------------------------

log 'Setting up a throwaway WordPress site'
if curl -s -o /dev/null --max-time 2 "$SITE_URL/"; then
	echo "Something is already listening on 127.0.0.1:$WEB_PORT; set WP_TEST_WEB_PORT to a free port." >&2
	exit 1
fi

mkdir -p "$SITE_DIR"
if [ -n "$WP_CORE_DIR" ]; then
	cp -R "$WP_CORE_DIR"/. "$SITE_DIR"/
	rm -f "$SITE_DIR/wp-config.php"
else
	wp core download --version="$WP_VERSION" --force
fi
wp config create \
	--dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" --dbprefix="$DB_PREFIX" --skip-check --force
if wp db create 2>/dev/null; then CREATED_DB=1; fi
wp core install \
	--url="$SITE_URL" \
	--title="Stillgate Action E2E" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test
wp config set WP_ENVIRONMENT_TYPE local
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp config set DISABLE_WP_CRON true --raw

log 'Installing the distributed plugin build (no vendor/, as a real user would get it)'
BUILD_DIR="$SITE_DIR/wp-content/plugins/stillgate"
if [ -n "$STILLGATE_ZIP" ]; then
	unzip -q "$STILLGATE_ZIP" -d "$SITE_DIR/wp-content/plugins"
else
	mkdir -p "$BUILD_DIR"
	git -C "$REPO_ROOT" archive --format=tar HEAD | tar -x -C "$BUILD_DIR"
fi
test ! -e "$BUILD_DIR/vendor"
test ! -e "$BUILD_DIR/action"
test ! -e "$BUILD_DIR/action.yml"
wp plugin activate stillgate

log 'Seeding fixtures (before any freeze)'
POST_ID=$(wp post create --post_title="Original" --post_status=publish --porcelain)
wp user create editor editor@example.test --role=editor --user_pass=editor >/dev/null
EDITOR_APP_PASSWORD=$(wp user application-password create editor action-e2e --porcelain)
ADMIN_APP_PASSWORD=$(wp user application-password create admin action-e2e --porcelain)
wp rewrite structure '/%postname%/'
wp rewrite flush >/dev/null

PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable=0 -S "127.0.0.1:$WEB_PORT" -t "$SITE_DIR" >"$WORK_DIR/server.log" 2>&1 &
SERVER_PID=$!
# Warm the front page before any freeze: a block theme creates its fallback
# Navigation menu on the first render, and that is a write the receipt would
# (rightly) report if it happened after the freeze began. The first request
# is slow on a cold server, so it gets a long time limit.
for _ in $(seq 1 50); do
	curl -s -o /dev/null --max-time 120 "$SITE_URL/" && break
	sleep 0.2
done
curl -s -o /dev/null --max-time 120 "$SITE_URL/"
expect_eq 'the fallback navigation menu exists before the first freeze' \
	"$(wp post list --post_type=wp_navigation --format=count)" '1'

expect_eq 'pretty permalinks: /wp-json/stillgate/v1/status answers 200 for the administrator' \
	"$(http_code -u "admin:$ADMIN_APP_PASSWORD" "$SITE_URL/wp-json/stillgate/v1/status")" '200'

# --- Pretty permalinks ---------------------------------------------------

log 'Freeze for the length of a job (pretty permalinks, /wp-json/)'
settle
new_job one
expect_eq 'main: exit code 0' "$(act_code main duration=10m message='E2E deploy in progress')" '0'
expect_eq 'main: output frozen=true' "$(job_output frozen)" 'true'
ENDS_AT="$(job_output ends-at)"
expect_match 'main: ends-at is an ISO 8601 time' "$ENDS_AT" '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}(Z|[+-][0-9:]{5})$'
REMAINING=$(seconds_until "$ENDS_AT")
expect_between 'main: ends-at is about 10 minutes after the start' "$REMAINING" 570 600
expect_eq 'main: ends-at equals the end the site reports' "$(status_field ends_at)" "$ENDS_AT"
expect_eq 'main: the site is frozen' "$(status_field active)" 'true'
expect_eq 'main: the site shows the message' "$(status_field message)" 'E2E deploy in progress'
expect_eq 'main: state started=true' "$(job_state started)" 'true'
expect_eq 'main: state windowstart is the start the site reports' "$(job_state windowstart)" "$(status_field schedule_start)"
expect_match 'main: state startedat is the runner time in milliseconds' "$(job_state startedat)" '^[0-9]{13}$'
expect_eq 'main: state endsat is the end the site reports' "$(job_state endsat)" "$ENDS_AT"
expect_eq 'main: state route=pretty' "$(job_state route)" 'pretty'
expect_eq 'main: the app password reaches the log only in an add-mask command' \
	"$(grep -v '^::add-mask::' "$JOB_DIR/log" | grep -cF -- "$ADMIN_APP_PASSWORD" || true)" '0'
expect_match 'main: the log starts with the mask' "$(head -n1 "$JOB_DIR/log")" '^::add-mask::'

log 'The freeze really blocks restricted actions while the job runs'
expect_exit 1 'wp post update (no user) is blocked' wp post update "$POST_ID" --post_title="Blocked"
expect_eq 'post title unchanged' "$(wp post get "$POST_ID" --field=post_title)" 'Original'
expect_eq 'editor REST write answers 423' "$(http_code -u "editor:$EDITOR_APP_PASSWORD" -X POST --data-urlencode 'title=Over REST' "$SITE_URL/wp-json/wp/v2/posts/$POST_ID")" '423'

log 'if-already-frozen: fail refuses while a freeze is active'
new_job two
expect_eq 'main (fail): exit code 1' "$(act_code main)" '1'
expect_match 'main (fail): says a freeze is already active' "$(cat "$JOB_DIR/log")" '^::error::A freeze is already active'
expect_eq 'main (fail): output frozen=false' "$(job_output frozen)" 'false'
expect_eq 'main (fail): no state saved' "$(job_state started)" ''
expect_eq 'main (fail): the first freeze is untouched' "$(status_field ends_at)" "$ENDS_AT"
expect_eq 'post after a refused start: exit code 0 (nothing to do)' "$(act_code post)" '0'
expect_eq 'post after a refused start: the first freeze is still on' "$(status_field active)" 'true'

log 'if-already-frozen: keep continues without owning the freeze'
new_job three
expect_eq 'main (keep): exit code 0' "$(act_code main if-already-frozen=keep)" '0'
expect_eq 'main (keep): output frozen=false' "$(job_output frozen)" 'false'
expect_eq 'main (keep): ends-at is the existing freeze end' "$(job_output ends-at)" "$ENDS_AT"
expect_eq 'post (keep): exit code 0' "$(act_code post if-already-frozen=keep)" '0'
expect_eq 'post (keep): the freeze it did not start is still on' "$(status_field active)" 'true'
expect_eq 'post (keep): the first freeze end is unchanged' "$(status_field ends_at)" "$ENDS_AT"

log 'Post step: clean receipt, release'
JOB_DIR="$WORK_DIR/job-one"
expect_eq 'post: exit code 0' "$(act_code post)" '0'
expect_eq 'post: receipt-clean=true' "$(job_output receipt-clean)" 'true'
expect_eq 'post: changed-ids is empty' "$(job_output changed-ids)" ''
expect_match 'post: the job summary holds the receipt table' "$(cat "$JOB_DIR/summary")" 'Posts, pages and media changed \| 0 \|'
expect_match 'post: the job summary says the freeze was ended' "$(cat "$JOB_DIR/summary")" 'The freeze was ended by this job'
expect_eq 'post: the freeze has been released' "$(status_field active)" 'false'
expect_exit 0 'wp post update works again after the release' wp post update "$POST_ID" --post_title="After the first job"

log 'A bypass user changes content during the freeze: the post step fails after releasing'
new_job four
expect_eq 'main: exit code 0' "$(act_code main duration=10m)" '0'
expect_eq 'main: the site is frozen' "$(status_field active)" 'true'
expect_exit 0 'wp post update --user=admin (the bypass account) works' wp post update "$POST_ID" --post_title="Edited during the freeze" --user=admin
expect_eq 'post: exit code 1' "$(act_code post)" '1'
expect_match 'post: reports the change as an error annotation' "$(cat "$JOB_DIR/log")" "^::error::Content changed while the site was frozen \(post IDs .*$POST_ID"
expect_eq 'post: receipt-clean=false' "$(job_output receipt-clean)" 'false'
expect_eq 'post: changed-ids names the post' "$(job_output changed-ids)" "$POST_ID"
expect_match 'post: the summary names the post and the export command' "$(cat "$JOB_DIR/summary")" "wp export --post__in=$POST_ID "
expect_eq 'post: the freeze was released before failing' "$(status_field active)" 'false'

log 'fail-on-changes false: reported, not failed'
new_job five
act main duration=10m || true
wp post update "$POST_ID" --post_title="Edited again" --user=admin >/dev/null
expect_eq 'post (fail-on-changes=false): exit code 0' "$(act_code post fail-on-changes=false)" '0'
expect_eq 'post (fail-on-changes=false): receipt-clean=false' "$(job_output receipt-clean)" 'false'
expect_eq 'post (fail-on-changes=false): released' "$(status_field active)" 'false'

log 'release false leaves the freeze on'
settle
new_job six
act main duration=10m || true
expect_eq 'post (release=false): exit code 0' "$(act_code post release=false)" '0'
expect_eq 'post (release=false): receipt-clean=true' "$(job_output receipt-clean)" 'true'
expect_eq 'post (release=false): the freeze is still on' "$(status_field active)" 'true'
wp stillgate disable >/dev/null

log 'A freeze that ended before the post step cannot be vouched for'
settle
new_job seven
act main duration=10m || true
wp stillgate disable >/dev/null
expect_eq 'post (already ended): exit code 1' "$(act_code post)" '1'
expect_match 'post (already ended): warns that the freeze had ended' "$(cat "$JOB_DIR/log")" '^::warning::The freeze had already ended'
expect_match 'post (already ended): the error says nothing can be promised' "$(cat "$JOB_DIR/log")" '^::error::The freeze had already ended.*nothing can be promised'
expect_eq 'post (already ended): receipt-clean=unknown' "$(job_output receipt-clean)" 'unknown'
expect_match 'post (already ended): the summary cannot confirm' "$(cat "$JOB_DIR/summary")" 'Cannot be confirmed'
expect_eq 'post (already ended): no freeze was started again' "$(status_field active)" 'false'
expect_eq 'post (already ended, fail-on-changes=false): exit code 0' "$(act_code post fail-on-changes=false)" '0'
expect_eq 'post (already ended, fail-on-changes=false): receipt-clean=unknown' "$(job_output receipt-clean)" 'unknown'

log 'An extended freeze is still this job'"'"'s freeze (only the end moves)'
settle
new_job extended
act main duration=10m || true
START_BEFORE="$(status_field schedule_start)"
wp stillgate extend 5m >/dev/null
expect_eq 'extend: the start of the window is unchanged' "$(status_field schedule_start)" "$START_BEFORE"
expect_eq 'extend: it matches the start the action saved' "$(job_state windowstart)" "$START_BEFORE"
expect_eq 'post (extended): exit code 0' "$(act_code post)" '0'
expect_eq 'post (extended): receipt-clean=true' "$(job_output receipt-clean)" 'true'
expect_eq 'post (extended): released' "$(status_field active)" 'false'

log 'The post step never ends a freeze that changed hands'
settle
new_job foreign
act main duration=10m || true
sleep 2
wp stillgate disable >/dev/null
wp stillgate enable --for=20m >/dev/null
OTHER_ENDS_AT="$(status_field ends_at)"
expect_eq 'foreign: exit code 1' "$(act_code post)" '1'
expect_match 'foreign: warns loudly and names the command' "$(cat "$JOB_DIR/log")" '^::warning::The freeze now active on the site is not the one this job started.*wp stillgate disable'
expect_match 'foreign: fails the job' "$(cat "$JOB_DIR/log")" '^::error::The freeze on the site changed hands'
expect_eq 'foreign: the other freeze is still on' "$(status_field active)" 'true'
expect_eq 'foreign: its end is unchanged' "$(status_field ends_at)" "$OTHER_ENDS_AT"
expect_eq 'foreign: receipt-clean=unknown' "$(job_output receipt-clean)" 'unknown'
expect_eq 'foreign (fail-on-changes=false): exit code 0' "$(act_code post fail-on-changes=false)" '0'
expect_eq 'foreign (fail-on-changes=false): the other freeze is still on' "$(status_field active)" 'true'
wp stillgate disable >/dev/null

new_job manual
settle
act main duration=10m || true
wp stillgate enable >/dev/null
expect_eq 'manual freeze turned on during the job: exit code 1' "$(act_code post)" '1'
expect_match 'manual: says it was turned on by hand' "$(cat "$JOB_DIR/log")" 'turned on by hand'
expect_eq 'manual: the manual freeze is still on' "$(status_field manual_enabled)" 'true'
wp stillgate disable >/dev/null

log 'A request whose answer was lost (started=maybe)'
settle
new_job maybe_ours
act main duration=10m || true
STARTED_AT="$(job_state startedat)"
printf 'started<<EOF\nmaybe\nEOF\nstartedat<<EOF\n%s\nEOF\nroute<<EOF\npretty\nEOF\n' "$STARTED_AT" >"$JOB_DIR/state"
expect_eq 'post (maybe, ours): exit code 0' "$(act_code post)" '0'
expect_match 'post (maybe, ours): warns that it is not known whether a freeze began' "$(cat "$JOB_DIR/log")" '^::warning::The request that starts the freeze got no usable answer'
expect_eq 'post (maybe, ours): the freeze was ended' "$(status_field active)" 'false'
expect_eq 'post (maybe, ours): receipt-clean=unknown' "$(job_output receipt-clean)" 'unknown'

new_job maybe_foreign
wp stillgate enable --for=20m >/dev/null
OTHER_ENDS_AT="$(status_field ends_at)"
LONG_AGO="$(node -e 'process.stdout.write(String(Date.now() - 3600000))')"
printf 'started<<EOF\nmaybe\nEOF\nstartedat<<EOF\n%s\nEOF\nroute<<EOF\npretty\nEOF\n' "$LONG_AGO" >"$JOB_DIR/state"
expect_eq 'post (maybe, started an hour ago by the clock): exit code 1' "$(act_code post)" '1'
expect_eq 'post (maybe, foreign): the other freeze is still on' "$(status_field ends_at)" "$OTHER_ENDS_AT"
wp stillgate disable >/dev/null
new_job maybe_nothing
printf 'started<<EOF\nmaybe\nEOF\nstartedat<<EOF\n%s\nEOF\nroute<<EOF\npretty\nEOF\n' "$STARTED_AT" >"$JOB_DIR/state"
expect_eq 'post (maybe, nothing active): exit code 0' "$(act_code post)" '0'
expect_match 'post (maybe, nothing active): nothing to end' "$(cat "$JOB_DIR/log")" 'nothing to end'

log 'A scheduled window that has not begun is not overwritten'
WINDOW_START="$(node -e 'process.stdout.write(new Date(Date.now() + 3 * 3600e3).toISOString().replace(/\.\d+Z$/, "Z"))')"
WINDOW_END="$(node -e 'process.stdout.write(new Date(Date.now() + 5 * 3600e3).toISOString().replace(/\.\d+Z$/, "Z"))')"
wp stillgate schedule --start="$WINDOW_START" --end="$WINDOW_END" >/dev/null
expect_eq 'window: status says upcoming' "$(status_field schedule_status)" 'upcoming'
expect_eq 'window: nothing is frozen yet' "$(status_field active)" 'false'
WINDOW_REPORTED_START="$(status_field schedule_start)"
WINDOW_REPORTED_END="$(status_field schedule_end)"

new_job window_fail
expect_eq 'window, fail: exit code 1' "$(act_code main)" '1'
expect_contains 'window, fail: names the start of the window' "$(cat "$JOB_DIR/log")" "$WINDOW_REPORTED_START"
expect_contains 'window, fail: names the end of the window' "$(cat "$JOB_DIR/log")" "$WINDOW_REPORTED_END"
expect_match 'window, fail: points to replace' "$(cat "$JOB_DIR/log")" 'if-already-frozen to replace'
expect_eq 'window, fail: the window is untouched' "$(status_field schedule_start)" "$WINDOW_REPORTED_START"
expect_eq 'window, fail: output frozen=false' "$(job_output frozen)" 'false'
expect_eq 'window, fail: no state saved' "$(job_state started)" ''

new_job window_keep
expect_eq 'window, keep: exit code 1' "$(act_code main if-already-frozen=keep)" '1'
expect_match 'window, keep: explains that nothing would be frozen' "$(cat "$JOB_DIR/log")" 'without a freeze'
expect_eq 'window, keep: the window is untouched' "$(status_field schedule_start)" "$WINDOW_REPORTED_START"

settle
new_job window_replace
expect_eq 'window, replace: main exit code 0' "$(act_code main if-already-frozen=replace)" '0'
expect_match 'window, replace: says what is replaced' "$(cat "$JOB_DIR/log")" '^::notice::A scheduled freeze window is set'
expect_eq 'window, replace: the site is frozen' "$(status_field active)" 'true'
expect_eq 'window, replace: the window is the job'"'"'s freeze' "$(status_field schedule_start)" "$(job_state windowstart)"
expect_eq 'window, replace: post exit code 0' "$(act_code post if-already-frozen=replace)" '0'
expect_eq 'window, replace: receipt-clean=true' "$(job_output receipt-clean)" 'true'
expect_eq 'window, replace: released' "$(status_field active)" 'false'
expect_eq 'window, replace: the replaced window is gone' "$(status_field schedule_status)" 'none'

log 'if-already-frozen replace takes over an active freeze'
wp stillgate enable >/dev/null
expect_eq 'manual freeze: status active' "$(status_field active)" 'true'
new_job takeover
expect_eq 'takeover (fail): exit code 1' "$(act_code main)" '1'
expect_eq 'takeover (fail): the manual freeze is untouched' "$(status_field manual_enabled)" 'true'
settle
expect_eq 'takeover (replace): main exit code 0' "$(act_code main if-already-frozen=replace)" '0'
expect_eq 'takeover (replace): the manual freeze became the job'"'"'s timed freeze' "$(status_field manual_enabled)" 'false'
expect_eq 'takeover (replace): the site is still frozen' "$(status_field active)" 'true'
expect_eq 'takeover (replace): post exit code 0' "$(act_code post if-already-frozen=replace)" '0'
expect_eq 'takeover (replace): released for good' "$(status_field active)" 'false'

log 'The message is always sent: an earlier message is cleared'
settle
new_job message_one
act main message='Release 41' || true
expect_eq 'message: the site shows it' "$(status_field message)" 'Release 41'
act post || true
new_job message_two
expect_eq 'message: next run without a message, exit code 0' "$(act_code main)" '0'
expect_eq 'message: the earlier message is gone' "$(status_field message)" ''
act post || true

log 'Failures against the real site'
new_job eight
expect_eq 'wrong password: exit code 1' "$(act_code main app-password='wxyz wxyz wxyz wxyz wxyz wxyz')" '1'
expect_match 'wrong password: explains the credentials' "$(cat "$JOB_DIR/log")" '^::error::HTTP 401'
new_job nine
expect_eq 'editor (no manage_options): exit code 1' "$(act_code main username=editor app-password="$EDITOR_APP_PASSWORD")" '1'
expect_match 'editor: names the capabilities' "$(cat "$JOB_DIR/log")" '^::error::HTTP 403.*deployment_guard_bypass'
expect_eq 'editor: the site was not frozen' "$(status_field active)" 'false'
new_job ten
expect_eq 'duration=banana: exit code 1' "$(act_code main duration=banana)" '1'
expect_match 'duration=banana: invalid_duration from the site' "$(cat "$JOB_DIR/log")" '^::error::HTTP 400 invalid_duration'
expect_eq 'duration=banana: the site was not frozen' "$(status_field active)" 'false'
expect_eq 'duration=(empty): exit code 1' "$(act_code main duration=)" '1'
expect_eq 'duration=(empty): the site was not frozen' "$(status_field active)" 'false'
expect_eq 'http to a non-local host: exit code 1' "$(act_code main site-url=http://example.com)" '1'
expect_match 'http to a non-local host: asks for https' "$(cat "$JOB_DIR/log")" 'must use https'

# --- Plain permalinks ----------------------------------------------------

log 'Plain permalinks (?rest_route=)'
wp rewrite structure ''
wp rewrite flush >/dev/null
# WordPress answers 301 (to the same address with a trailing slash) or 404
# here, depending on the version and the request.
expect_match 'plain permalinks: /wp-json/stillgate/v1/status is not served (301 or 404)' \
	"$(http_code -u "admin:$ADMIN_APP_PASSWORD" "$SITE_URL/wp-json/stillgate/v1/status")" '^(301|404)$'
settle
new_job plain
expect_eq 'main: exit code 0' "$(act_code main duration=10m)" '0'
expect_eq 'main: output frozen=true' "$(job_output frozen)" 'true'
expect_eq 'main: state route=plain' "$(job_state route)" 'plain'
expect_eq 'main: the site is frozen' "$(status_field active)" 'true'
expect_eq 'post: exit code 0' "$(act_code post)" '0'
expect_eq 'post: receipt-clean=true' "$(job_output receipt-clean)" 'true'
expect_eq 'post: released' "$(status_field active)" 'false'

new_job plain_dirty
act main duration=10m || true
wp post update "$POST_ID" --post_title="Edited under plain permalinks" --user=admin >/dev/null
expect_eq 'dirty receipt under plain permalinks: post exit code 1' "$(act_code post)" '1'
expect_eq 'dirty receipt under plain permalinks: changed-ids' "$(job_output changed-ids)" "$POST_ID"
expect_eq 'dirty receipt under plain permalinks: released' "$(status_field active)" 'false'

# --- Nothing left behind -------------------------------------------------

log 'Nothing left behind'
expect_eq 'the site is not frozen at the end' "$(status_field active)" 'false'

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
