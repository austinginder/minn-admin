#!/usr/bin/env bash
#
# Builds the release zip: minn-admin.zip, next to the plugin directory.
#
# This exists so the exclusion list lives in git rather than in a release
# runbook, where it had already drifted: `languages/` was shipping 5.3MB of
# .pot and .po to every user in every locale, none of which WordPress reads at
# runtime. The catalogs users actually load are LANGUAGE PACKS, built by
# bin/i18n/build-packs.sh and downloaded only for the locales a site uses.
#
# What is excluded and why:
#   .git, .gitignore, .DS_Store  repository plumbing
#   .github/                     repo assets (screenshots)
#   .wp-playground/              Playground blueprint, a README artifact
#   tests/                       dev only, and 18MB of it
#   CLAUDE.md                    agent instructions, dev only
#   bin/                         the i18n and release toolchain, dev only
#   dist/                        build output, including the packs themselves
#   languages/                   translation SOURCES + reviewed notes; runtime
#                                reads language PACKS from WP_LANG_DIR/plugins
#
# docs/ SHIPS: includes/class-minn-admin-rest.php serves docs/user-guide.md.
#
# Usage: bin/build-zip.sh   → ../minn-admin.zip (from wp-content/plugins/)
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/.." && pwd )"
NAME="$( basename "$ROOT" )"
PARENT="$( dirname "$ROOT" )"
OUT="$PARENT/minn-admin.zip"

command -v zip >/dev/null || { echo "zip is required" >&2; exit 1; }

rm -f "$OUT"
cd "$PARENT"
zip -r -q -X "$OUT" "$NAME" \
	-x "$NAME/.git/*" \
	-x "$NAME/.gitignore" \
	-x "$NAME/.github/*" \
	-x "$NAME/.wp-playground/*" \
	-x "$NAME/tests/*" \
	-x "$NAME/bin/*" \
	-x "$NAME/dist/*" \
	-x "$NAME/CLAUDE.md" \
	-x "$NAME/languages/*" \
	-x "*.DS_Store"

# Assert rather than trust: a silently fattened zip is invisible until someone
# downloads it, and a silently EMPTIED one bricks the plugin.
#
# Listed ONCE into a variable rather than piped per check. Under `set -o
# pipefail`, `unzip -l … | grep -q …` reports FAILURE on a match: grep exits at
# the first hit, unzip takes SIGPIPE, and the pipeline inherits its status.
listing="$( unzip -l "$OUT" )"

leaked="$( grep -cE "$NAME/(tests|bin|dist|languages|\.git)/|\.(po|pot)$|CLAUDE\.md" <<< "$listing" || true )"
[ "$leaked" = "0" ] || {
	echo "FAIL: $leaked dev file(s) leaked into the zip" >&2
	grep -E "$NAME/(tests|bin|dist|languages)/|\.(po|pot)$" <<< "$listing" | head >&2
	exit 1
}

for required in "$NAME/minn-admin.php" "$NAME/manifest.json" "$NAME/assets/js/app.js" "$NAME/docs/user-guide.md"; do
	grep -q " $required\$" <<< "$listing" || { echo "FAIL: $required missing from the zip" >&2; exit 1; }
done

printf '%s\n' "$OUT"
printf '  %s bytes, %s files\n' "$( wc -c < "$OUT" | tr -d ' ' )" "$( unzip -l "$OUT" | tail -1 | awk '{print $2}' )"
printf '  sha256 %s\n' "$( shasum -a 256 "$OUT" | cut -d' ' -f1 )"
