#!/usr/bin/env bash
#
# Translation step of a release. Run AFTER the version bump and BEFORE the
# GitHub release is created.
#
#   bin/i18n/release.sh v0.30.0
#   bin/i18n/release.sh v0.30.0 --allow-missing   # ship holes as English
#
# This script TRANSLATES NOTHING. Translation is a Claude Code session's job,
# during the cycle, through export-batch.js / import-batch.js (see README.md).
# What this does is verify and build, in order:
#   1. regenerates languages/minn-admin.pot from the current source
#   2. reports what changed since the last .pot, so the size of the cycle's
#      translation debt is visible
#   3. COMPLETENESS GATE: every shipped catalog must answer every .pot entry
#      (reviewed + existing + core glossary). A miss means strings landed
#      after the last translation pass — run the session flow, then re-run
#      this. --allow-missing ships them as English fallthrough instead.
#   4. validates and compiles every catalog
#   5. builds one zip per locale into dist/languages/
#   6. stamps manifest.json — per-language versions: only catalogs whose
#      CONTENT changed get this release's version and a new package URL, so
#      a site is only offered the languages that really changed. The stamp
#      ends with the exact list of zips to attach to the GitHub release.
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/../.." && pwd )"
cd "$ROOT"

TAG="${1:-}"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: bin/i18n/release.sh v<x.y.z> [--allow-missing]" >&2; exit 2; }
ALLOW_MISSING=""
[ "${2:-}" = "--allow-missing" ] && ALLOW_MISSING=1

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
else
	echo "    no previous .pot to compare against"
fi

echo
echo "==> 3. completeness gate"
if node bin/i18n/missing.js --all | sed 's/^/    /'; then
	echo "    every catalog answers every entry"
else
	if [ -n "$ALLOW_MISSING" ]; then
		echo "    MISSING ENTRIES SHIPPED AS ENGLISH (--allow-missing)" >&2
	else
		cat >&2 <<-'MSG'

		FAIL: catalogs are missing entries. Translate them in a Claude Code
		session first — per locale:

		    node bin/i18n/export-batch.js <locale> --missing-only
		    (translate the chunk files, write <locale>.NN.done.json)
		    node bin/i18n/import-batch.js <locale>

		then re-run this script. Or pass --allow-missing to ship the holes
		as English fallthrough.
		MSG
		exit 1
	fi
fi

echo
echo "==> 4. validating"
for f in languages/*.po; do
	msgfmt -c -o /dev/null "$f" 2>/dev/null || { echo "    FAIL $f does not compile" >&2; exit 1; }
	node bin/i18n/validate.js "$f" > /dev/null || { echo "    FAIL $f failed validate.js" >&2; exit 1; }
done
echo "    all catalogs compile and validate"

echo
echo "==> 4b. quality report"
# Not a gate. This is the read-before-you-ship pass: coverage per locale,
# placeholder integrity, plural form counts, and the signature of a batch
# that quietly came back in English.
for f in languages/*.po; do
	locale="$( basename "$f" .po )"
	node bin/i18n/qa.js "$locale" | sed 's/^/    /'
done

echo
echo "==> 5. building packs"
./bin/i18n/build-packs.sh

echo
echo "==> 6. stamping manifest (per-language versions)"
node bin/release-manifest.js "$TAG"

echo
echo "Attach ONLY the zips listed above to the $TAG GitHub release alongside"
echo "minn-admin.zip, then commit the manifest with the release commit."
