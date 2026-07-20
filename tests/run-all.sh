#!/bin/bash
# Sequential full-suite runner (release pre-flight / overnight run).
#
#   MINN_TEST_PASS=… ./run-all.sh [output-dir]
#
# Conventions this encodes (see the repo's suite lessons):
#   - ONE suite at a time: parallel suite load storms the local FrankenPHP
#     stack (crash-restart windows read as fake regressions).
#   - Before each suite, wait until the site answers fast (<1s) — the
#     settle guard from the same lesson.
#   - A failed suite retries ONCE after a 60s settle: a red that passes on
#     retry is environment noise and is reported as PASS(retry).
#   - Full per-suite logs land in the output dir (never piped through tail:
#     truncated logs eat the PASS lines needed for diagnosis).
set -u
cd "$(dirname "$0")"

OUT="${1:-/tmp/minn-run-all-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
: "${MINN_TEST_PASS:?set MINN_TEST_PASS}"
BASE="${MINN_TEST_URL:-https://minnadmin.localhost}"

settle() {
	local tries=0 t ok
	while [ $tries -lt 60 ]; do
		t=$(curl -sk -o /dev/null -w '%{time_total}' --max-time 5 "$BASE/" 2>/dev/null || echo 9)
		ok=$(echo "$t < 1.0" | bc 2>/dev/null || echo 0)
		[ "$ok" = "1" ] && return 0
		tries=$((tries + 1))
		sleep 5
	done
	echo "WARNING: site never settled (<1s) — continuing anyway" | tee -a "$OUT/summary.txt"
	return 1
}

pass=0
fail=0
failed=()
total=$(ls ./*.test.js | wc -l | tr -d ' ')
i=0
overall_start=$(date +%s)
# Resuming into an existing record must APPEND — a bare tee here wiped the
# summary on relaunch, so the resume-skip below never saw prior PASSes and
# every "resume" silently restarted from suite 1.
if [ -s "$OUT/summary.txt" ]; then
	echo "Resuming $total suites → $OUT" | tee -a "$OUT/summary.txt"
else
	echo "Running $total suites sequentially → $OUT" | tee "$OUT/summary.txt"
fi

for f in *.test.js; do
	i=$((i + 1))
	# Resume: a suite already recorded as PASS in this output dir is skipped
	# (FAILs and unrecorded suites run), so an interrupted run picks up
	# where it stopped when relaunched with the same output dir.
	if grep -qE "] $f +PASS" "$OUT/summary.txt" 2>/dev/null; then
		printf '[%3d/%s] %-40s %-12s\n' "$i" "$total" "$f" "SKIP(done)" | tee -a "$OUT/summary.txt"
		pass=$((pass + 1))
		continue
	fi
	settle
	start=$(date +%s)
	status=FAIL
	# Clear stale logs from a prior attempt: the count-grep below reads both
	# files, and a leftover retry.log misreports a fresh pass's numbers.
	rm -f "$OUT/$f.log" "$OUT/$f.retry.log"
	if node "$f" >"$OUT/$f.log" 2>&1; then
		status=PASS
	else
		sleep 60
		settle
		if node "$f" >"$OUT/$f.retry.log" 2>&1; then
			status="PASS(retry)"
		fi
	fi
	dur=$(($(date +%s) - start))
	line=$(grep -Eho '[0-9]+/[0-9]+ passed' "$OUT/$f.log" "$OUT/$f.retry.log" 2>/dev/null | tail -1)
	printf '[%3d/%s] %-40s %-12s %4ss  %s\n' "$i" "$total" "$f" "$status" "$dur" "${line:-}" | tee -a "$OUT/summary.txt"
	case $status in
	FAIL)
		fail=$((fail + 1))
		failed+=("$f")
		;;
	*) pass=$((pass + 1)) ;;
	esac
done

overall=$((($(date +%s) - overall_start) / 60))
echo "== $pass/$total suites passed, $fail failed, ${overall}m total ==" | tee -a "$OUT/summary.txt"
for f in "${failed[@]:-}"; do
	[ -n "$f" ] && echo "FAILED: $f (log: $OUT/$f.retry.log)" | tee -a "$OUT/summary.txt"
done
[ $fail -eq 0 ]
