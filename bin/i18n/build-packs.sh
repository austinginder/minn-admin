#!/usr/bin/env bash
#
# Compiles languages/<locale>.po into a WordPress LANGUAGE PACK:
#
#   minn-admin-<locale>.zip
#     ├── minn-admin-<locale>.mo                     (PHP side)
#     └── minn-admin-<locale>-<md5 of app.js>.json   (SPA side, JED)
#
# Files sit at the zip ROOT because Language_Pack_Upgrader extracts them
# straight into wp-content/languages/plugins/.
#
# ORDER IS LOAD-BEARING. `wp i18n make-json` PURGES the JS entries from the
# .po by default, so running it before make-mo silently produces a .mo with
# every app.js string missing. --no-purge plus .mo-first is the safe order,
# and this script also works on a COPY of the .po so the source in git can
# never be mutated by a build.
#
# Usage:
#   ./build-packs.sh                # every .po in languages/
#   ./build-packs.sh de_DE fr_FR    # named locales
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/../.." && pwd )"
LANG_DIR="$ROOT/languages"
DIST="$ROOT/dist/languages"

command -v wp >/dev/null || { echo "wp-cli is required" >&2; exit 1; }

mkdir -p "$DIST"
rm -f "$DIST"/*.zip "$DIST"/*.mo "$DIST"/*.json 2>/dev/null || true

if [ $# -gt 0 ]; then
	LOCALES=( "$@" )
else
	LOCALES=()
	for f in "$LANG_DIR"/*.po; do
		[ -e "$f" ] || continue
		LOCALES+=( "$( basename "$f" .po )" )
	done
fi

[ ${#LOCALES[@]} -gt 0 ] || { echo "no .po files in $LANG_DIR" >&2; exit 1; }

printf '%-8s %8s %8s %10s  %s\n' LOCALE ENTRIES JSON BYTES SHA256
for locale in "${LOCALES[@]}"; do
	src="$LANG_DIR/$locale.po"
	[ -f "$src" ] || { echo "missing $src" >&2; continue; }

	work="$( mktemp -d )"
	trap 'rm -rf "$work"' RETURN

	# Build on a COPY: make-json rewrites the .po it is given.
	cp "$src" "$work/minn-admin-$locale.po"

	# 1. .mo FIRST, from the complete catalog. Destination must be an explicit
	#    FILE: given a directory, wp-cli 2.12 tries to write the archive to the
	#    directory path itself, warns "Is a directory", and produces NOTHING —
	#    a pack with only JSON in it, which translates the app and leaves every
	#    PHP string English. Verified below rather than trusted.
	wp i18n make-mo "$work/minn-admin-$locale.po" "$work/minn-admin-$locale.mo" --quiet

	# 2. THEN the JED files, with --no-purge so the .po keeps its JS entries.
	wp i18n make-json "$work/minn-admin-$locale.po" "$work" --no-purge --quiet

	rm -f "$work/minn-admin-$locale.po"

	[ -s "$work/minn-admin-$locale.mo" ] || { echo "FAIL $locale: no .mo produced" >&2; exit 1; }
	[ -n "$( find "$work" -name '*.json' -print -quit )" ] || { echo "FAIL $locale: no JED json produced" >&2; exit 1; }

	entries=$( grep -c '^msgid ' "$src" || true )
	json_count=$( find "$work" -name '*.json' | wc -l | tr -d ' ' )

	zip_path="$DIST/minn-admin-$locale.zip"
	( cd "$work" && zip -q -X "$zip_path" ./*.mo ./*.json 2>/dev/null )

	bytes=$( wc -c < "$zip_path" | tr -d ' ' )
	sha=$( shasum -a 256 "$zip_path" | cut -d' ' -f1 )
	printf '%-8s %8s %8s %10s  %s\n' "$locale" "$entries" "$json_count" "$bytes" "$sha"

	rm -rf "$work"
	trap - RETURN
done

echo
echo "packs in $DIST"
