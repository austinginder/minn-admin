#!/bin/bash
# Provisions (or re-provisions) the next-core test site. Idempotent: run it
# again any time, including after `cove add`, and it only fills in what is
# missing.
#
#   ./core-latest-seed.sh            → seeds minnadmin-core-latest
#   MINN_CORE_SITE=other ./core-latest-seed.sh
#
# The site is deliberately thin — core, the plugin, and the dev-fixtures
# mu-plugin. But "thin" is not "empty": a handful of core-coupled suites need
# real content of a shape core alone does not create (a term tree, two images,
# a second installed language). Those fixtures live here rather than in a
# README, because a recipe nobody can run is a recipe that rots.
set -u

SITE="${MINN_CORE_SITE:-minnadmin-core-latest}"
ROOT="${MINN_CORE_ROOT:-$HOME/Cove/Sites/$SITE.localhost/public}"
DEV="${MINN_DEV_ROOT:-$HOME/Cove/Sites/minnadmin.localhost/public}"
PASS="${MINN_CORE_PASS:-minn-core-latest-1}"

[ -d "$ROOT" ] || {
	echo "No site at $ROOT. Create it first:" >&2
	echo "  cove add $SITE nightly" >&2
	exit 2
}
w() { wp --path="$ROOT" "$@"; }

echo "Seeding $SITE ($(w core version))"

# --- the plugin under test, and the fixtures mu-plugin, as symlinks so both
# --- sites always run the same code
ln -sfn "$DEV/wp-content/plugins/minn-admin" "$ROOT/wp-content/plugins/minn-admin"
ln -sfn "$DEV/wp-content/mu-plugins/minn-dev-fixtures.php" "$ROOT/wp-content/mu-plugins/minn-dev-fixtures.php"
w plugin activate minn-admin >/dev/null 2>&1
echo "  plugin: $(w plugin list --name=minn-admin --field=status)"

# --- accounts the suites expect (helpers' MINN_TEST_USER2, role sweeps)
w user update admin --user_pass="$PASS" --skip-email >/dev/null 2>&1
w user get minn-editor >/dev/null 2>&1 || w user create minn-editor editor@example.com --role=editor --user_pass='minn-editor-pass-1' >/dev/null
w user get minn-author >/dev/null 2>&1 || w user create minn-author author@example.com --role=author --user_pass='minn-author-pass-1' >/dev/null
echo "  users: admin, minn-editor, minn-author"

# --- /minn-admin/ needs pretty permalinks (a plain-permalink site falls back
# --- to ?minn_admin=1, which is not what the suites drive)
[ -n "$( w option get permalink_structure )" ] || w rewrite structure '/%postname%/' --hard >/dev/null 2>&1
w rewrite flush --hard >/dev/null 2>&1

# --- a hierarchical term tree (terms.test.js asserts the indented tree; a
# --- flat category list cannot prove it)
seed_term() {
	local tax="$1" slug="$2" name="$3" parent="${4:-}"
	w term get "$tax" "$slug" --by=slug >/dev/null 2>&1 && return 0
	if [ -n "$parent" ]; then
		local pid
		pid=$( w term get "$tax" "$parent" --by=slug --field=term_id 2>/dev/null )
		w term create "$tax" "$name" --slug="$slug" --parent="$pid" >/dev/null 2>&1
	else
		w term create "$tax" "$name" --slug="$slug" >/dev/null 2>&1
	fi
}
seed_term category projects Projects
seed_term category woodworking Woodworking projects
seed_term category sailing Sailing projects
seed_term category dinghies Dinghies sailing
for tag in wordpress performance security; do seed_term post_tag "$tag" "$tag"; done
echo "  terms: Projects > Woodworking / Sailing > Dinghies, plus 3 tags"

# --- two attachments with known titles (image-swap.test.js builds its markup
# --- from their real URLs). Drawn with GD rather than shipped as binaries so
# --- the repo stays text.
for pair in "gal-red 255 0 0" "gal-blue 0 0 255"; do
	set -- $pair
	name="$1" r="$2" g="$3" b="$4"
	if [ -z "$( w post list --post_type=attachment --name="$name" --field=ID 2>/dev/null )" ]; then
		tmp="/tmp/$name.png"
		w eval "\$im = imagecreatetruecolor( 64, 64 ); imagefill( \$im, 0, 0, imagecolorallocate( \$im, $r, $g, $b ) ); imagepng( \$im, '$tmp' );" >/dev/null 2>&1
		[ -f "$tmp" ] && w media import "$tmp" --title="$name" >/dev/null 2>&1 && rm -f "$tmp"
	fi
done
echo "  media: $( w post list --post_type=attachment --format=count ) attachment(s)"

# --- a SECOND installed language, so removing one can be shown to leave the
# --- others intact. language-remove installs the specific locales it asserts
# --- about; this just keeps the site from being single-language between runs.
w language core list --status=installed --field=language 2>/dev/null | grep -q de_DE ||
	w language core install de_DE >/dev/null 2>&1
echo "  languages: $( w language core list --status=installed --field=language 2>/dev/null | tr '\n' ' ' )"

# --- the plugin's OWN language packs. Minn ships these as release assets and
# --- core installs them alongside an update, so a site already on the current
# --- version never receives them and its catalog is empty (rtl asserts against
# --- the Persian one). Copy from the dev site, which runs the same build.
if [ -d "$DEV/wp-content/languages/plugins" ]; then
	mkdir -p "$ROOT/wp-content/languages/plugins"
	cp -p "$DEV"/wp-content/languages/plugins/minn-admin-* "$ROOT/wp-content/languages/plugins/" 2>/dev/null
fi
echo "  plugin packs: $( ls "$ROOT"/wp-content/languages/plugins/ 2>/dev/null | grep -c '^minn-admin-' ) file(s)"

# --- a second custom post type. The Content subtitle names a lone custom type
# --- ("Posts, pages & Patterns") and only switches to the counted plural at
# --- two or more, which is the form i18n asserts _n() + sprintf against.
w eval 'if ( ! array_key_exists( "minn_lab_note", (array) get_option( "minn_admin_post_types", array() ) ) ) { $t = (array) get_option( "minn_admin_post_types", array() ); $t["minn_lab_note"] = array( "singular" => "Lab note", "plural" => "Lab notes", "public" => 1, "show_in_rest" => 1, "supports" => array( "title", "editor" ) ); update_option( "minn_admin_post_types", $t ); }' >/dev/null 2>&1
w rewrite flush --hard >/dev/null 2>&1
echo "  post types: $( w post-type list --public=1 --field=name --format=csv 2>/dev/null | tr '\n' ' ' )"

# --- a nav menu with items (menu-drag.test.js drags rows; an empty Structure
# --- page has no rows to drag). The dev-fixtures mu-plugin registers the
# --- locations, so a menu created here has somewhere to be assigned.
if [ -z "$( w menu list --fields=term_id --format=ids 2>/dev/null )" ]; then
	w menu create "Main Menu" >/dev/null 2>&1
	home_id=$( w post list --post_type=page --posts_per_page=1 --field=ID 2>/dev/null | head -1 )
	[ -n "$home_id" ] && w menu item add-post "Main Menu" "$home_id" >/dev/null 2>&1
	for item in About Services Contact; do
		w menu item add-custom "Main Menu" "$item" "/${item}" >/dev/null 2>&1
	done
	loc=$( w menu location list --field=location 2>/dev/null | head -1 )
	[ -n "$loc" ] && w menu location assign "Main Menu" "$loc" >/dev/null 2>&1
fi
echo "  menus: $( w menu list --fields=name,count --format=csv 2>/dev/null | tail -n +2 | tr '\n' ' ' )"

echo "Done. Run:  MINN_TEST_PASS=$PASS ./core-latest.sh"
