#!/usr/bin/env bash
#
# Translation step of a release. Run AFTER the version bump and BEFORE the
# GitHub release is created.
#
#   bin/i18n/release.sh v0.30.0
#   bin/i18n/release.sh v0.30.0 --core-only    # no model calls
#
# What it does, in order:
#   1. regenerates languages/minn-admin.pot from the current source
#   2. reports what changed since the last .pot, so the cost of the cycle is
#      visible before anything is spent
#   3. re-runs each wave-1 locale (reviewed entries are never overwritten)
#   4. validates and compiles every catalog
#   5. builds one zip per locale into dist/languages/
#   6. stamps them into manifest.json with their sha256
#
# The .pot diff is what keeps this cheap. A release that adds forty strings
# costs forty translations, not a full regeneration, because pass 1 and 2 of
# translate.js carry everything already settled straight through.
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/../.." && pwd )"
cd "$ROOT"

TAG="${1:-}"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: bin/i18n/release.sh v<x.y.z> [--core-only]" >&2; exit 2; }
CORE_ONLY=""
[ "${2:-}" = "--core-only" ] && CORE_ONLY="--core-only"

POT="languages/minn-admin.pot"
PREV="$( mktemp )"
trap 'rm -f "$PREV"' EXIT
[ -f "$POT" ] && cp "$POT" "$PREV"

echo "==> 1. regenerating $POT"
wp i18n make-pot . "$POT" --ignore-domain --exclude=tests,docs,.wp-playground,.github,bin,dist

echo
echo "==> 2. what changed"
if [ -s "$PREV" ]; then
	before=$( grep -c '^msgid ' "$PREV" || true )
	after=$( grep -c '^msgid ' "$POT" || true )
	# Compare msgid sets, ignoring line references and timestamps.
	added=$( comm -13 \
		<( grep '^msgid ' "$PREV" | sort -u ) \
		<( grep '^msgid ' "$POT" | sort -u ) | wc -l | tr -d ' ' )
	removed=$( comm -23 \
		<( grep '^msgid ' "$PREV" | sort -u ) \
		<( grep '^msgid ' "$POT" | sort -u ) | wc -l | tr -d ' ' )
	echo "    $before -> $after entries   (+$added new, -$removed gone)"
	echo "    only the $added new ones cost anything to translate."
else
	echo "    no previous .pot to compare against"
fi

echo
echo "==> 3. regenerating catalogs"
LOCALES=$( node -e "console.log(require('./bin/i18n/locales.js').wave(1).map(l=>l.code).join(' '))" )
for locale in $LOCALES; do
	printf '    %-7s ' "$locale"
	node bin/i18n/translate.js "$locale" $CORE_ONLY 2>&1 | grep -E '^wrote' || echo '(failed)'
done

echo
echo "==> 4. validating"
for f in languages/*.po; do
	msgfmt -c -o /dev/null "$f" 2>/dev/null || { echo "    FAIL $f does not compile" >&2; exit 1; }
done
echo "    all catalogs compile"

echo
echo "==> 4b. quality report"
# Not a gate. This is the read-before-you-ship pass: coverage per locale,
# placeholder integrity, plural form counts, and the signature of a batch
# that quietly came back in English.
for locale in $LOCALES; do
	node bin/i18n/qa.js "$locale" | sed 's/^/    /'
done

echo
echo "==> 5. building packs"
./bin/i18n/build-packs.sh

echo
echo "==> 6. stamping manifest"
node bin/release-manifest.js "$TAG"

echo
echo "Next: attach dist/languages/*.zip to the $TAG GitHub release alongside"
echo "minn-admin.zip, then commit the manifest."
