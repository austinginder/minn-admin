#!/bin/bash
# Runs Minn Admin against the NEXT WordPress, on a dedicated Cove site.
#
#   MINN_TEST_PASS=… ./core-latest.sh [flags] [output-dir]
#
# Why this exists: every other suite run happens on minnadmin.localhost, which
# tracks a RELEASED WordPress. A core release can break the plugin in ways no
# amount of testing against the current version reveals, and by the time it
# ships the breakage is already in front of users. This runs the core-coupled
# part of the suite against whatever WordPress is ahead of stable, so a release
# is cut knowing the next core does not break it.
#
# Version selection (--resolve prints it and exits):
#   a beta/RC when one is in flight (wp.org's beta channel offers a version the
#   stable channel does not), otherwise trunk (nightly). Between releases there
#   is no beta for weeks at a time, so trunk is the resting state rather than a
#   fallback nobody notices.
#
# Flags:
#   --resolve      print the target WordPress version and exit
#   --no-update    run against whatever the site already has
#   --version=X    pin to a specific version (6.9-RC1, nightly, 7.1)
#   --full         run EVERY suite, not the core-coupled list (hours; most
#                  third-party suites SKIP or fail for want of their plugin,
#                  so this is for investigation, never a release gate)
#   --list         print the core-coupled suite list and exit
set -u
cd "$(dirname "$0")"

SITE="${MINN_CORE_SITE:-minnadmin-core-latest}"
BASE="https://$SITE.localhost"
ROOT="${MINN_CORE_ROOT:-$HOME/Cove/Sites/$SITE.localhost/public}"

# The core-coupled list: suites whose subject is WordPress itself, not a
# third-party plugin. These are the ones a core release can actually break —
# block serialization, the REST shapes Minn reads, uploads, revisions,
# rewrites, translations, the upgrader. Adapter suites are deliberately absent:
# their fixtures are not installed here, and a plugin breaking is a plugin
# release's problem, not core's.
SUITES=(
	# boot + contract
	boot-status.test.js
	contract.test.js
	zero-external.test.js
	nonce-recovery.test.js
	# the editor: block serialization + core block markup
	markdown.test.js
	paste.test.js
	island-runs.test.js
	nested-islands.test.js
	patterns.test.js
	core-blocks-extend.test.js
	image-swap.test.js
	# core post APIs: autosaves, revisions, locks, post fields
	autosave.test.js
	revision-diff.test.js
	revision-fields.test.js
	lock.test.js
	editor-sidebar.test.js
	post-format.test.js
	# core collections Minn re-exposes
	content-list.test.js
	terms.test.js
	users.test.js
	comment-bulk.test.js
	media-flow.test.js
	media-editor.test.js
	media-bulk.test.js
	# site plumbing: rewrites, options, widgets, menus, translations
	custom-css.test.js
	discussion-defaults.test.js
	site-language.test.js
	language-remove.test.js
	widget-drag.test.js
	menu-drag.test.js
	role-defaults.test.js
	profile.test.js
	# diagnostics + the updater, which read core internals directly
	system.test.js
	database.test.js
	core-update-visibility.test.js
	# rendering + i18n
	i18n.test.js
	a11y-chrome.test.js
	rtl.test.js
	# phone widths: layout primitives are what a core release moves first.
	# mobile-surfaces stays out with the adapter suites — it resolves its
	# families from the boot payload and has no providers to resolve here.
	mobile-editor.test.js
)

# Deliberately absent, so the next run does not re-litigate them. Each needs a
# third-party plugin registered to have a subject at all, which would mean
# rebuilding the fixture farm here and defeats the point of a bare site:
#   auto-blocks, inspector-child-text  anchor-blocks (registered blocks)
#   cpt-create                         Custom Post Type UI (its stored types)
#   rewrite-rules                      Rewrite Rules Inspector
#   extensions                         a populated plugin list (icons, scroll)
# They stay covered by run-all.sh on minnadmin.

UPDATE=1
FULL=0
PIN=""
OUT=""
for arg in "$@"; do
	case "$arg" in
	--resolve) RESOLVE_ONLY=1 ;;
	--no-update) UPDATE=0 ;;
	--full) FULL=1 ;;
	--list)
		printf '%s\n' "${SUITES[@]}"
		exit 0
		;;
	--version=*) PIN="${arg#--version=}" ;;
	-*)
		echo "unknown flag: $arg" >&2
		exit 2
		;;
	*) OUT="$arg" ;;
	esac
done

# What is ahead of stable? The beta channel answers with the RC while one is in
# flight and with plain stable when none is; comparing the two channels is what
# distinguishes "there is a beta" from "there is not", without parsing version
# strings for a -RC suffix that alpha builds do not carry.
resolve_version() {
	local stable beta
	stable=$(curl -s --max-time 20 'https://api.wordpress.org/core/version-check/1.7/' |
		python3 -c 'import sys,json; print(json.load(sys.stdin)["offers"][0]["version"])' 2>/dev/null)
	beta=$(curl -s --max-time 20 'https://api.wordpress.org/core/version-check/1.7/?channel=beta' |
		python3 -c 'import sys,json; print(json.load(sys.stdin)["offers"][0]["version"])' 2>/dev/null)
	if [ -n "$beta" ] && [ -n "$stable" ] && [ "$beta" != "$stable" ]; then
		echo "$beta"
	else
		echo nightly
	fi
}

TARGET="${PIN:-$(resolve_version)}"
if [ -n "${RESOLVE_ONLY:-}" ]; then
	echo "$TARGET"
	exit 0
fi

: "${MINN_TEST_PASS:?set MINN_TEST_PASS (admin password for $BASE)}"
[ -d "$ROOT" ] || {
	echo "No site at $ROOT." >&2
	echo "Create it with:  cove add $SITE nightly" >&2
	echo "(then symlink minn-admin + minn-dev-fixtures.php into it — see tests/README.md)" >&2
	exit 2
}

# Point the harness at THIS site. MINN_TEST_WP is not optional here: the plugin
# dir is a symlink to the dev site, so the harness's default (four levels up
# from tests/) resolves through it and every wp-cli call in a suite would land
# on minnadmin.localhost instead — the suites would pass while testing nothing.
export MINN_TEST_URL="$BASE"
export MINN_TEST_WP="$ROOT"

if [ "$UPDATE" = "1" ]; then
	echo "Updating $SITE to WordPress ${TARGET}…"
	# CLI, never the web upgrader: WP_Upgrader under FrankenPHP dies with a
	# corrupted heap mid-install (the install usually lands, the response never
	# does). --force so a same-version re-run still refreshes the files.
	wp --path="$ROOT" core update --version="$TARGET" --force || {
		echo "core update failed" >&2
		exit 1
	}
	wp --path="$ROOT" core update-db
	wp --path="$ROOT" rewrite flush --hard >/dev/null 2>&1
fi

RUNNING=$(wp --path="$ROOT" core version)
echo "WordPress $RUNNING at $BASE"
wp --path="$ROOT" plugin list --name=minn-admin --field=status | grep -q active || {
	echo "minn-admin is not active on $SITE" >&2
	exit 1
}

OUT="${OUT:-/tmp/minn-core-latest-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"

if [ "$FULL" = "1" ]; then
	echo "Full run against WordPress $RUNNING → $OUT"
	exec ./run-all.sh "$OUT"
fi

settle() {
	local tries=0 fast=0 t ok code probe
	while [ $tries -lt 60 ]; do
		probe=$(curl -sk -o /dev/null -w '%{http_code} %{time_total}' --max-time 8 "$BASE/wp-json/?minn_settle=$tries" 2>/dev/null || echo "000 9")
		code=${probe%% *}
		t=${probe##* }
		ok=$(echo "$t < 3.0" | bc 2>/dev/null || echo 0)
		case $code in
		200 | 301 | 302) ;;
		*) ok=0 ;;
		esac
		if [ "$ok" = "1" ]; then
			fast=$((fast + 1))
			[ $fast -ge 2 ] && return 0
		else
			fast=0
		fi
		tries=$((tries + 1))
		sleep 5
	done
	echo "WARNING: site never settled — continuing anyway" | tee -a "$OUT/summary.txt"
	return 1
}

pass=0
fail=0
failed=()
total=${#SUITES[@]}
i=0
start_all=$(date +%s)
echo "Minn Admin $(sed -n 's/.*MINN_ADMIN_VERSION., .\([0-9.]*\).*/\1/p' ../minn-admin.php | head -1) on WordPress $RUNNING — $total core-coupled suites → $OUT" | tee "$OUT/summary.txt"

node auth-setup.js >"$OUT/auth-setup.log" 2>&1 ||
	echo "WARNING: auth setup failed — suites will form-login (see $OUT/auth-setup.log)" | tee -a "$OUT/summary.txt"

for f in "${SUITES[@]}"; do
	i=$((i + 1))
	[ -f "$f" ] || {
		printf '[%2d/%s] %-34s %s\n' "$i" "$total" "$f" "MISSING" | tee -a "$OUT/summary.txt"
		continue
	}
	settle
	s=$(date +%s)
	status=FAIL
	rm -f "$OUT/$f.log" "$OUT/$f.retry.log"
	if node "$f" >"$OUT/$f.log" 2>&1; then
		status=PASS
	else
		sleep 45
		settle
		node "$f" >"$OUT/$f.retry.log" 2>&1 && status="PASS(retry)"
	fi
	line=$(grep -Eho '[0-9]+/[0-9]+ passed|SKIP[^\n]*' "$OUT/$f.log" "$OUT/$f.retry.log" 2>/dev/null | tail -1)
	printf '[%2d/%s] %-34s %-12s %4ss  %s\n' "$i" "$total" "$f" "$status" "$(($(date +%s) - s))" "${line:-}" | tee -a "$OUT/summary.txt"
	case $status in
	FAIL)
		fail=$((fail + 1))
		failed+=("$f")
		;;
	*) pass=$((pass + 1)) ;;
	esac
done

echo "== $pass/$total passed, $fail failed on WordPress $RUNNING, $((($(date +%s) - start_all) / 60))m ==" | tee -a "$OUT/summary.txt"
for f in "${failed[@]:-}"; do
	[ -n "$f" ] && echo "FAILED: $f (log: $OUT/$f.retry.log)" | tee -a "$OUT/summary.txt"
done
[ $fail -eq 0 ]
