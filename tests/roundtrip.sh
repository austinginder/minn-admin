#!/usr/bin/env bash
# The round-trip harnesses, in order: editor panels, settings tabs, surface
# edit modals, then Minn's own pages. The modal and page passes capture what
# the real client sends (Playwright) and replay it inside rolled-back
# transactions. Each harness keeps a ratchet of open findings ($known): a
# listed failure reports KNOWN, an unlisted one fails the run.
#
# Run from tests/: MINN_TEST_PASS=… ./roundtrip.sh   (MINN_TEST_WP points at the site root)
set -u
cd "$(dirname "$0")"
WP_PATH="${MINN_TEST_WP:-$(cd ../../../.. && pwd)}"
TMP="$(mktemp -d)"
status=0
run() { echo "== $1"; shift; "$@" || status=1; }
run panels   wp eval-file roundtrip-panels.test.php --user=admin --path="$WP_PATH"
run settings wp eval-file roundtrip-settings.test.php --user=admin --path="$WP_PATH"
run capture-surfaces node roundtrip-surfaces-capture.js "$TMP/surfaces.json"
MINN_RT_CAPTURE="$TMP/surfaces.json" run surfaces wp eval-file roundtrip-surfaces.test.php --user=admin --path="$WP_PATH"
run capture-pages node roundtrip-pages-capture.js "$TMP/pages.json"
MINN_RT_CAPTURE="$TMP/pages.json" run pages wp eval-file roundtrip-surfaces.test.php --user=admin --path="$WP_PATH"
rm -rf "$TMP"
exit $status
