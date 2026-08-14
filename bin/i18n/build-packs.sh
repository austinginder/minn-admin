#!/usr/bin/env bash
#
# Compiles languages/<locale>.po into a WordPress LANGUAGE PACK:
#
#   minn-admin-<locale>.zip
#     ├── minn-admin-<locale>.mo                     (PHP side, WP < 6.5)
#     ├── minn-admin-<locale>.l10n.php               (PHP side, WP 6.5+ fast path)
#     ├── minn-admin-<locale>.po                     (source + METADATA)
#     └── minn-admin-<locale>-<md5 of app.js>.json   (SPA side, JED)
#
# Files sit at the zip ROOT because Language_Pack_Upgrader extracts them
# straight into wp-content/languages/plugins/.
#
# THE .po IS NOT OPTIONAL, however dead it looks at runtime.
# wp_get_installed_translations() — what core uses to decide whether a
# translation update is pending — reads headers from the .po and `continue`s
# past any .mo with no .po beside it (wp-includes/l10n.php). Ship without it
# and the site reports NOTHING installed, so every pack is offered again on
# every update check, forever. It is also where Project-Id-Version lives, and
# that is the version the updater compares. Real w.org packs ship .po, .mo and
# .l10n.php together; this matches them.
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

# Stamped into each packed .po as Project-Id-Version. The copy in git stays
# version-less on purpose: stamping there would rewrite thirteen catalogs on
# every release for a header nobody reads until it is packed.
VERSION="$( sed -n "s/^define( 'MINN_ADMIN_VERSION', '\([^']*\)' );/\1/p" "$ROOT/minn-admin.php" )"
[ -n "$VERSION" ] || { echo "could not read MINN_ADMIN_VERSION from minn-admin.php" >&2; exit 1; }

command -v wp >/dev/null || { echo "wp-cli is required" >&2; exit 1; }

mkdir -p "$DIST"
rm -f "$DIST"/*.zip "$DIST"/*.mo "$DIST"/*.json "$DIST"/*.po "$DIST"/*.l10n.php 2>/dev/null || true

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

echo "packing Minn Admin $VERSION"
printf '%-8s %8s %8s %10s  %s\n' LOCALE ENTRIES JSON BYTES SHA256
for locale in "${LOCALES[@]}"; do
	src="$LANG_DIR/$locale.po"
	[ -f "$src" ] || { echo "missing $src" >&2; continue; }

	# Some catalogs serve more than one locale byte for byte (see ALIASES in
	# locales.js: en_GB's spellings are also Australian, Canadian, New Zealand
	# and South African). Each gets its own pack, because core looks a pack up
	# by the exact locale code.
	targets="$( node -e "process.stdout.write(require('$HERE/locales.js').packedAs('$locale').join(' '))" )"

	for target in $targets; do
	work="$( mktemp -d )"

	# Build on a COPY: make-json rewrites the .po it is given.
	cp "$src" "$work/minn-admin-$target.po"

	# Stamp the release version so the updater has something to compare. Core
	# surfaces exactly four .po headers (wp_get_pomo_file_data) and this is the
	# only one that can carry a version.
	perl -0pi -e "s/^\"Project-Id-Version: [^\\\\]*/\"Project-Id-Version: Minn Admin $VERSION/m" "$work/minn-admin-$target.po"

	# The Language: header has to name the locale the pack is FOR, or the
	# alias packs all announce themselves as en_GB.
	perl -0pi -e "s/^\"Language: [^\\\\]*/\"Language: $target/m" "$work/minn-admin-$target.po"

	# 1. .mo FIRST, from the complete catalog. Destination must be an explicit
	#    FILE: given a directory, wp-cli 2.12 tries to write the archive to the
	#    directory path itself, warns "Is a directory", and produces NOTHING —
	#    a pack with only JSON in it, which translates the app and leaves every
	#    PHP string English. Verified below rather than trusted.
	wp i18n make-mo "$work/minn-admin-$target.po" "$work/minn-admin-$target.mo" --quiet

	# 2. The 6.5+ fast path, also from the complete catalog. load_textdomain()
	#    prefers this over parsing the .mo.
	wp i18n make-php "$work/minn-admin-$target.po" "$work" --quiet

	# 3. THEN the JED files, with --no-purge so the .po keeps its JS entries.
	wp i18n make-json "$work/minn-admin-$target.po" "$work" --no-purge --quiet

	[ -s "$work/minn-admin-$target.mo" ] || { echo "FAIL $target: no .mo produced" >&2; exit 1; }
	[ -s "$work/minn-admin-$target.l10n.php" ] || { echo "FAIL $target: no .l10n.php produced" >&2; exit 1; }
	[ -n "$( find "$work" -name '*.json' -print -quit )" ] || { echo "FAIL $target: no JED json produced" >&2; exit 1; }
	# The .po must survive make-json, or core sees no installed translation.
	grep -q "^\"Project-Id-Version: Minn Admin $VERSION" "$work/minn-admin-$target.po" \
		|| { echo "FAIL $target: .po lost its stamped Project-Id-Version" >&2; exit 1; }

	entries=$( grep -c '^msgid ' "$src" || true )
	json_count=$( find "$work" -name '*.json' | wc -l | tr -d ' ' )

	zip_path="$DIST/minn-admin-$target.zip"
	( cd "$work" && zip -q -X "$zip_path" ./*.mo ./*.po ./*.l10n.php ./*.json 2>/dev/null )

	bytes=$( wc -c < "$zip_path" | tr -d ' ' )
	sha=$( shasum -a 256 "$zip_path" | cut -d' ' -f1 )
	printf '%-8s %8s %8s %10s  %s\n' "$target" "$entries" "$json_count" "$bytes" "$sha"

	rm -rf "$work"
	done
done

echo
echo "packs in $DIST"
