#!/usr/bin/env bash
#
# Translation PREP for a release cycle. Run once feature strings are frozen,
# BEFORE the version bump. Catalogs do not need the version number; the
# package stamp does. Generating translations after the bump puts the longest
# task on the cut's critical path.
#
#   bin/i18n/prep.sh              # regenerate .pot, report debt, export chunks
#   bin/i18n/prep.sh --force      # wipe /tmp/minn-i18n even if .done.json exist
#
# This script TRANSLATES NOTHING and STAMPS NOTHING. It:
#   1. regenerates languages/minn-admin.pot from the current source
#   2. reports what changed since the last .pot
#   3. reports missing.js --all (nonzero is expected until the grok pass)
#   4. exports --missing-only chunks to /tmp/minn-i18n
#
# Then dispatch grok against those chunks (see bin/i18n/README.md), import,
# and gate with `node bin/i18n/missing.js --all`. At cut time, AFTER the
# version bump, run `bin/i18n/release.sh vX.Y.Z` to validate, pack, and stamp.
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/../.." && pwd )"
cd "$ROOT"

FORCE=""
[ "${1:-}" = "--force" ] && FORCE=1

OUT="${MINN_I18N_OUT:-/tmp/minn-i18n}"
POT="languages/minn-admin.pot"
PREV="$( mktemp )"
trap 'rm -f "$PREV"' EXIT
[ -f "$POT" ] && cp "$POT" "$PREV"

WP_PATH="${MINN_TEST_WP:-/Users/austin/Cove/Sites/minnadmin.localhost/public}"

echo "==> 1. regenerating $POT"
wp --path="$WP_PATH" i18n make-pot . "$POT" --ignore-domain --exclude=tests,docs,.wp-playground,.github,bin,dist

echo
echo "==> 2. what changed"
if [ -s "$PREV" ]; then
	before=$( grep -c '^msgid ' "$PREV" || true )
	after=$( grep -c '^msgid ' "$POT" || true )
	added=$( comm -13 \
		<( grep '^msgid ' "$PREV" | sort -u ) \
		<( grep '^msgid ' "$POT" | sort -u ) | wc -l | tr -d ' ' )
	removed=$( comm -23 \
		<( grep '^msgid ' "$PREV" | sort -u ) \
		<( grep '^msgid ' "$POT" | sort -u ) | wc -l | tr -d ' ' )
	echo "    $before -> $after entries   (+$added new, -$removed gone)"
else
	echo "    no previous .pot to compare against"
fi

echo
echo "==> 3. current debt (missing.js --all)"
node bin/i18n/missing.js --all | sed 's/^/    /' || true

if [ -d "$OUT" ] && ls "$OUT"/*.done.json >/dev/null 2>&1; then
	if [ -z "$FORCE" ]; then
		echo
		echo "    $OUT already has .done.json files. Another grok pass may own it."
		echo "    Re-run with --force to wipe and re-export, or set MINN_I18N_OUT."
		exit 2
	fi
fi

echo
echo "==> 4. exporting missing-only chunks -> $OUT"
mkdir -p "$OUT"
# Drop stale source chunks from a prior cycle; keep nothing that could pair
# an old id with a new string.
find "$OUT" -maxdepth 1 -type f -name '*.json' -delete
node -e "require('./bin/i18n/locales.js').LOCALES.forEach(l=>console.log(l.code))" | while read -r loc; do
	node bin/i18n/export-batch.js "$loc" --missing-only --out "$OUT"
done

echo
echo "Next: translate every $OUT/<locale>.NN.json into <locale>.NN.done.json"
echo "(cwd must be $OUT so grok can write). Then:"
echo
echo "    cd $ROOT"
echo "    for loc in \$(node -e \"require('./bin/i18n/locales.js').LOCALES.forEach(l=>console.log(l.code))\"); do"
echo "      node bin/i18n/import-batch.js \$loc --out $OUT"
echo "      node bin/i18n/missing.js \$loc"
echo "    done"
echo
echo "Do NOT run release.sh until the version bump. It stamps manifest.json."
