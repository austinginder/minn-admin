#!/bin/bash
# Every PHP suite (wp eval-file), one at a time. Release pre-flight part 3
# runs this alongside run-all.sh, which runs the browser suites.
#
#   ./run-php.sh [output-dir]
#
# Single-site suites run on the site this checkout sits in, or MINN_PHP_SITE.
# The network suites need a multisite lab and SKIP without one:
#   MINN_PHP_NETWORK     the network's root path
#   MINN_PHP_SUBSITE     URL of a site on it other than the main one
#   MINN_PHP_NET2_URL    URL of a site on a second network (WP Multi Network)
#   MINN_PHP_NET2_USER   that network's super admin, who is not one on the first
# A suite fails on a non-zero exit, a FAIL line or a fatal error. Nothing is
# retried: these run in the CLI process, not through the web stack, so the
# load that makes browser suites flaky does not reach them.
set -u
cd "$(dirname "$0")/.."

OUT="${1:-/tmp/minn-run-php-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
: >"$OUT/summary.txt"
SITE="${MINN_PHP_SITE:-$(cd ../../.. && pwd)}"
NET="${MINN_PHP_NETWORK:-}"
SUB="${MINN_PHP_SUBSITE:-}"
NET2_URL="${MINN_PHP_NET2_URL:-}"
NET2_USER="${MINN_PHP_NET2_USER:-}"

pass=0
fail=0
skip=0
failed=()

# run <suite> <where> [wp args...]; where is single, subsite or net2.
run() {
	local name=$1 where=$2 log args start code fails
	shift 2
	log="$OUT/$name-$where.log"
	case $where in
	single) args=(--path="$SITE") ;;
	subsite)
		if [ -z "$NET" ] || [ -z "$SUB" ]; then
			echo "SKIP  $name ($where): set MINN_PHP_NETWORK and MINN_PHP_SUBSITE" | tee -a "$OUT/summary.txt"
			skip=$((skip + 1))
			return
		fi
		args=(--path="$NET" --url="$SUB")
		;;
	net2)
		if [ -z "$NET" ] || [ -z "$NET2_URL" ] || [ -z "$NET2_USER" ]; then
			echo "SKIP  $name ($where): set MINN_PHP_NETWORK, MINN_PHP_NET2_URL and MINN_PHP_NET2_USER" | tee -a "$OUT/summary.txt"
			skip=$((skip + 1))
			return
		fi
		args=(--path="$NET" --url="$NET2_URL" --user="$NET2_USER")
		;;
	esac
	start=$(date +%s)
	wp eval-file "tests/$name.test.php" "${args[@]}" "$@" >"$log" 2>&1
	code=$?
	fails=$(grep -c '^FAIL' "$log")
	if [ $code -ne 0 ] || [ "$fails" -gt 0 ] || grep -q 'Fatal error' "$log"; then
		echo "FAIL  $name ($where) exit=$code fails=$fails $(($(date +%s) - start))s → $log" | tee -a "$OUT/summary.txt"
		fail=$((fail + 1))
		failed+=("$name ($where)")
	else
		echo "PASS  $name ($where) $(grep -c '^PASS' "$log") checks, $(grep -c '^SKIP' "$log") skipped $(($(date +%s) - start))s" | tee -a "$OUT/summary.txt"
		pass=$((pass + 1))
	fi
}

# Single site. Suites that switch users themselves take none.
run core-icons single
run duplicate-copy single --user=admin
run language-packs single
run notice-link-label single
run removed-owners single --user=admin
run roundtrip-panels single --user=admin
run roundtrip-settings single --user=admin
run route-gates single --user=admin
run security-v030-orders single
run security-v031-acf single --user=admin
run security-v031-caps single --user=admin
run security-v031-scope single --user=admin
run security-v031-wpcode single --user=admin
run security-v034-boundaries single --user=admin
run security-v042-registry single --user=admin
run security-v043-emr single --require=tests/security-v043-emr-const.php
run security-v043-rc single --user=admin
run security-v043-registry single --user=admin
run security-v044-rc single --user=admin
run security-v045-rc single --user=admin
run security-v045-delta-acf single --user=admin
run security-v045-delta-data single --user=admin
run security-v045-delta-routes single --user=admin
run security-v045-delta-surfaces single --user=admin
run security-v045-registry single --user=admin
run sweep-v044 single --user=admin
run write-fences single --user=admin

# A network, from a site that is not the main one.
run route-gates subsite --user=admin
run write-fences subsite --user=admin
run multisite-db-redaction subsite --user=admin
run multisite-languages subsite --user=admin
run multisite-network-caps subsite --user=admin
run security-v030-wsal subsite
run security-v031-add-existing subsite --user=admin
run security-v043-registry subsite --user=admin
run security-v044-rc subsite --user=admin
run security-v045-rc subsite --user=admin
run security-v045-delta-data subsite --user=admin
run security-v045-delta-routes subsite --user=admin
run security-v045-delta-surfaces subsite --user=admin
run security-v045-delta-surfaces net2
run multi-network-system net2

# roundtrip-surfaces replays bodies a browser run captured; see its header.
echo "SKIP  roundtrip-surfaces: needs a browser capture first; run ./roundtrip.sh" | tee -a "$OUT/summary.txt"

echo "PHP suites: $pass passed, $fail failed, $((skip + 1)) skipped → $OUT" | tee -a "$OUT/summary.txt"
for f in "${failed[@]+"${failed[@]}"}"; do
	echo "  failed: $f" | tee -a "$OUT/summary.txt"
done
[ $fail -eq 0 ]
