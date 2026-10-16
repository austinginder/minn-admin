#!/usr/bin/env bash
# The round-trip harnesses, in order: editor panels, settings tabs, surface
# edit modals, then Minn's own pages. The modal and page passes capture what
# the real client sends (Playwright) and replay it inside rolled-back
# transactions. Each harness keeps a ratchet of open findings ($known): a
# listed failure reports KNOWN, an unlisted one fails the run.
#
# The surface pass runs three times: on the lab's items as they are, then
# with each item's free-text fields holding values a lab rarely does
# (lib/roundtrip-seed.php: "rich" is %XX-shaped text, an entity, inline
# markup and line breaks; "empty" is an unnamed item). Seeds are put back
# after each run, and on any exit. Last, roundtrip-coverage.test.php checks
# that every write route either had a save replayed here or is listed in
# fixtures/roundtrip-coverage.json with the reason it has none.
#
# The seed state (every cell a seed wrote, with what it held) lives at one
# path per site, outside the run's temp folder, so a run that never reached
# its restore (SIGKILL, a crash, a closed laptop) is put back by the next
# run before anything else. Cleanup keeps the file only when a restore fails.
#
# Run from tests/: MINN_TEST_PASS=… ./roundtrip.sh   (MINN_TEST_WP points at the site root)
set -u
cd "$(dirname "$0")"
WP_PATH="${MINN_TEST_WP:-$(cd ../../../.. && pwd)}"
TMP="$(mktemp -d)"
STATE_DIR="${TMPDIR:-/tmp}"
SEED_STATE="${STATE_DIR%/}/minn-roundtrip-seed-$(printf %s "$WP_PATH" | cksum | cut -d' ' -f1).json"
export MINN_RT_COVERED="$TMP/covered.txt"
: >"$MINN_RT_COVERED"
status=0
run() { echo "== $1"; shift; "$@" || status=1; }
seed() { MINN_RT_SEED="$1" MINN_RT_SEED_SET="${2:-}" MINN_RT_SEED_FILE="$SEED_STATE" wp eval-file lib/roundtrip-seed.php --user=admin --path="$WP_PATH"; }
cleanup() {
	# A seed still in place is put back before anything else goes; its state
	# file stays only if that fails, for the next run (or a hand) to finish.
	if [ -f "$SEED_STATE" ]; then seed restore || echo "FAIL  seeds not restored: state kept in $SEED_STATE"; fi
	rm -rf "$TMP"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

if [ -f "$SEED_STATE" ]; then
	# A run that was killed before its restore left seeded values in the
	# lab: every pass below would read them as the lab's own.
	echo "== restore a seed an earlier run left in place ($SEED_STATE)"
	seed restore || { echo "FAIL  the earlier run's seeds could not be put back: state kept in $SEED_STATE"; exit 1; }
fi

run panels   wp eval-file roundtrip-panels.test.php --user=admin --path="$WP_PATH"
run settings wp eval-file roundtrip-settings.test.php --user=admin --path="$WP_PATH"
run capture-surfaces node roundtrip-surfaces-capture.js "$TMP/surfaces.json"
run surfaces env MINN_RT_CAPTURE="$TMP/surfaces.json" wp eval-file roundtrip-surfaces.test.php --user=admin --path="$WP_PATH"
for set in rich empty; do
	if seed apply "$set"; then
		run "capture-surfaces [$set]" env MINN_RT_SEED_FILE="$SEED_STATE" node roundtrip-surfaces-capture.js "$TMP/surfaces-$set.json"
		run "surfaces [$set]" env MINN_RT_CAPTURE="$TMP/surfaces-$set.json" wp eval-file roundtrip-surfaces.test.php --user=admin --path="$WP_PATH"
	else
		echo "FAIL  seeding [$set]"
		status=1
	fi
	seed restore || { echo "FAIL  seeds [$set] not restored: state kept in $SEED_STATE"; exit 1; }
done
run capture-pages node roundtrip-pages-capture.js "$TMP/pages.json"
run pages env MINN_RT_CAPTURE="$TMP/pages.json" wp eval-file roundtrip-surfaces.test.php --user=admin --path="$WP_PATH"
run coverage wp eval-file roundtrip-coverage.test.php --user=admin --path="$WP_PATH"
exit $status
