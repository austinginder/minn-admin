#!/usr/bin/env bash
#
# Rebuild the demo site from nothing: reset its database and uploads, install
# WordPress, activate the plugins in plugins.txt and run seed.php. A reset
# erases everything, so only two kinds of site are touched: one this script
# seeded before (it carries the minn_demo_seeded option) and a fresh install
# (one user, at most the sample post). Anything else is refused.
#
# The plugins must already sit in the site's plugins folder; most are
# commercial, so copy them from a licensed install. Minn is the copy this
# script belongs to, linked or copied in as minn-admin.
#
# Usage: MINN_DEMO_PASS=… bin/demo/seed.sh <wp-path> <url>
#   e.g. MINN_DEMO_PASS=… bin/demo/seed.sh ~/Sites/minndemo/public https://minndemo.localhost
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
WP_PATH="${1:?usage: seed.sh <wp-path> <url>}"
URL="${2:?usage: seed.sh <wp-path> <url>}"
PASS="${MINN_DEMO_PASS:?set MINN_DEMO_PASS to the demo admin password}"

site() { wp --path="$WP_PATH" "$@"; }
# Reads before the reset load nothing that could fail on a broken site.
peek() { site --skip-plugins --skip-themes "$@" 2>/dev/null; }

if ! peek option get minn_demo_seeded >/dev/null; then
	users="$( peek user list --format=count || echo 0 )"
	posts="$( peek post list --post_type=post --post_status=any --format=count || echo 0 )"
	if [ "$users" != "1" ] || [ "$posts" -gt 1 ]; then
		echo "Refusing: $WP_PATH is neither a seeded demo site nor a fresh install." >&2
		exit 1
	fi
fi

echo "Resetting $URL"
site db reset --yes
rm -rf "${WP_PATH:?}/wp-content/uploads"
site core install --url="$URL" --title="Larkspur & Fern" --admin_user=admin --admin_password="$PASS" \
	--admin_email=june@larkspur.example --skip-email
site theme activate twentytwentyfive
# The demo site talks to no one: several plugins post site content to their
# own servers on their own (OttoKit sends every JetEngine item to its
# webhook), and update and licence checks have nothing to find here.
site config set WP_HTTP_BLOCK_EXTERNAL true --raw
# Before any plugin: one of them (301 Redirects) fails to load without pretty permalinks.
site rewrite structure '/%postname%/'

# PHP's notices from plugins, which say nothing about the seed.
quiet() { grep -v -E '^(PHP )?(Notice|Deprecated|Warning):' || true; }
plugins=()
while read -r plugin; do
	plugins+=( "$plugin" )
done < <( grep -v '^#' "$HERE/plugins.txt" | sed '/^$/d' )
missing() {
	comm -23 <( printf '%s\n' "${plugins[@]}" | sort ) <( site plugin list --status=active --field=name </dev/null 2>/dev/null | sort )
}
activate() {
	for plugin in "$@"; do
		site plugin activate "$plugin" </dev/null >/dev/null 2>&1 || { echo "Could not activate $plugin" >&2; exit 1; }
	done
}
# Every listed plugin is active, or the seed stops: a plugin missing from the
# run leaves its surfaces empty with nothing said.
check() {
	local gone
	gone="$( missing )"
	if [ -n "$gone" ]; then
		echo "Not active $1:" $gone >&2
		exit 1
	fi
}

# No cron while seeding. A request running alongside the activations can save
# the plugin list it read before the latest one, and a different plugin fell
# out on each of two runs; cron jobs would also change rows mid-seed.
site config set DISABLE_WP_CRON true --raw
trap 'site config delete DISABLE_WP_CRON >/dev/null 2>&1 || true' EXIT
echo "Activating plugins"
activate "${plugins[@]}"
for try in 1 2; do
	gone="$( missing )"
	[ -z "$gone" ] && break
	echo "Activating again:" $gone
	activate $gone
done
check "after activation"

site eval-file "$HERE/seed.php" </dev/null 2>&1 | quiet
check "after seeding"
# Rules with every plugin loaded (a flush with --skip-plugins drops their routes).
site rewrite flush </dev/null 2>&1 | quiet
echo "Done: $URL/minn-admin/"
