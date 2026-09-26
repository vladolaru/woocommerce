#!/usr/bin/env bash
#
# Fixture checks for bundle-size-gate.sh: the measured-bundle capture and the
# bundle-mode comparator (compare-measured-gates.py) it drives.

set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GATE="$SELF_DIR/bundle-size-gate.sh"
TMP_BASE="${TMPDIR:?TMPDIR is required}"
WORK_DIR="$(mktemp -d "$TMP_BASE/bundle-size-gate.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

fail() {
	echo "FAIL: $1" >&2
	exit 1
}

expect_exit() {
	local expected="$1" label="$2"
	shift 2
	set +e
	"$@" > "$WORK_DIR/out.txt" 2>&1
	local rc=$?
	set -e
	if [ "$rc" -ne "$expected" ]; then
		echo "FAIL: $label expected exit $expected, got $rc" >&2
		cat "$WORK_DIR/out.txt" >&2
		exit 1
	fi
}

assert_json() {
	local path="$1" script="$2"
	python3 - "$path" <<PY || fail "$path did not satisfy: $script"
import json, sys
data = json.load(open(sys.argv[1]))
assert $script
PY
}

# --- capture: wcpay-plugin profile (explicit) ---
plugin_repo="$WORK_DIR/wcpay-plugin"
mkdir -p "$plugin_repo/dist"
printf 'body { color: red; }\n' > "$plugin_repo/dist/checkout.css"
printf 'console.log("classic");\n' > "$plugin_repo/dist/checkout.js"
bash "$GATE" capture --repo "$plugin_repo" --profile wcpay-plugin --out "$WORK_DIR/plugin-capture.json" > "$WORK_DIR/capture.log"
grep -q 'Captured bundle sizes to' "$WORK_DIR/capture.log" || fail 'capture did not print its confirmation line'
assert_json "$WORK_DIR/plugin-capture.json" 'data["schema"] == "woopayments_measured_gate.v1" and data["mode"] == "bundle" and data["profile"] == "wcpay-plugin"'
assert_json "$WORK_DIR/plugin-capture.json" 'data["assets"]["classic-card.js"]["status"] == "present" and data["assets"]["classic-card.js"]["path"] == "dist/checkout.js" and data["assets"]["classic-card.js"]["raw_bytes"] == len(open("'"$plugin_repo"'/dist/checkout.js", "rb").read()) and data["assets"]["classic-card.js"]["gzip_bytes"] == len(__import__("gzip").compress(open("'"$plugin_repo"'/dist/checkout.js", "rb").read(), compresslevel=9))'
assert_json "$WORK_DIR/plugin-capture.json" 'data["assets"]["blocks-express-checkout.js"] == {"status": "missing", "path": None}'
assert_json "$WORK_DIR/plugin-capture.json" 'data["assets"]["woopay.js"] == {"status": "missing", "path": "dist/woopay.js"}'

# --- capture: wc-core profile (auto-detected from the plugins/woocommerce layout) ---
core_repo="$WORK_DIR/wc-core"
mkdir -p "$core_repo/plugins/woocommerce/assets/js/frontend"
printf 'console.log("classic-core");\n' > "$core_repo/plugins/woocommerce/assets/js/frontend/woopayments-checkout.js"
bash "$GATE" capture --repo "$core_repo" --out "$WORK_DIR/core-capture.json" > /dev/null
assert_json "$WORK_DIR/core-capture.json" 'data["profile"] == "wc-core"'
assert_json "$WORK_DIR/core-capture.json" 'data["assets"]["classic-card.js"]["status"] == "present" and data["assets"]["classic-card.js"]["path"] == "plugins/woocommerce/assets/js/frontend/woopayments-checkout.js"'
assert_json "$WORK_DIR/core-capture.json" 'data["assets"]["settings-main.css"] == {"status": "missing", "path": None}'

# --- capture: a missing repo directory fails closed ---
expect_exit 2 'capture on a missing repo fails' bash "$GATE" capture --repo "$WORK_DIR/does-not-exist" --out "$WORK_DIR/missing.json"

# --- capture: missing required arguments fail closed ---
expect_exit 2 'capture without --out fails' bash "$GATE" capture --repo "$plugin_repo"
expect_exit 2 'unknown mode fails' bash "$GATE" unknown-mode

write_bundle_capture() {
	local path="$1" body="$2"
	python3 - "$path" "$body" <<'PY'
import json, sys
path, body = sys.argv[1], sys.argv[2]
data = {"schema": "woopayments_measured_gate.v1", "mode": "bundle", "profile": "wc-core", "assets": json.loads(body)}
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh)
PY
}

# --- compare: identical sizes pass with no budget ---
write_bundle_capture "$WORK_DIR/ref.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 100, "gzip_bytes": 40}}'
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 100, "gzip_bytes": 40}}'
expect_exit 0 'identical sizes pass without a budget' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q 'RESULT: PASS bundle byte gate' "$WORK_DIR/out.txt" || fail 'identical-size pass did not print PASS'

# --- compare: any growth fails when no budget covers the asset ---
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 101, "gzip_bytes": 40}}'
expect_exit 1 'unbudgeted growth fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q 'FAIL  a.js: raw_bytes 100 -> 101 exceeds limit 100' "$WORK_DIR/out.txt" || fail 'unbudgeted growth did not report the exact limit'

# --- compare: raw_growth_bytes/gzip_growth_bytes budget rows allow bounded growth ---
budget="$WORK_DIR/budget.json"
cat > "$budget" <<'JSON'
{"assets":{"a.js":{"raw_growth_bytes":5,"gzip_growth_bytes":2}}}
JSON
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 105, "gzip_bytes": 42}}'
expect_exit 0 'growth within budget passes' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'RESULT: PASS bundle byte gate with explicit budget' "$WORK_DIR/out.txt" || fail 'budgeted pass did not name the budget file'
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 106, "gzip_bytes": 42}}'
expect_exit 1 'growth past the budget fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'FAIL  a.js: raw_bytes 100 -> 106 exceeds limit 105' "$WORK_DIR/out.txt" || fail 'budgeted growth failure did not report the exact limit'

# --- compare: an absolute ceiling overrides the ref-relative default ---
cat > "$budget" <<'JSON'
{"assets":{"a.js":{"raw_bytes":50,"gzip_bytes":50}}}
JSON
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 50, "gzip_bytes": 40}}'
expect_exit 0 'absolute ceiling at the limit passes' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 51, "gzip_bytes": 40}}'
expect_exit 1 'absolute ceiling past the limit fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'exceeds limit 50' "$WORK_DIR/out.txt" || fail 'absolute-ceiling failure did not cite the absolute limit'

# --- compare: allow_missing lets a dropped asset pass; without it, the same drop fails ---
write_bundle_capture "$WORK_DIR/target.json" '{}'
cat > "$budget" <<'JSON'
{"assets":{"a.js":{"allow_missing":true}}}
JSON
expect_exit 0 'allowed drop passes' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'ALLOW a.js: present in ref, missing in target' "$WORK_DIR/out.txt" || fail 'allowed drop did not print the ALLOW note'
expect_exit 1 'unallowed drop fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q 'FAIL  a.js: present in ref, missing in target' "$WORK_DIR/out.txt" || fail 'unallowed drop did not fail'

# --- compare: allow_new requires an explicit ceiling; a bare allow_new still fails ---
write_bundle_capture "$WORK_DIR/ref.json" '{}'
write_bundle_capture "$WORK_DIR/target.json" '{"b.js": {"status": "present", "path": "b.js", "raw_bytes": 10, "gzip_bytes": 5}}'
cat > "$budget" <<'JSON'
{"assets":{"b.js":{"allow_new":true}}}
JSON
expect_exit 1 'allow_new without an explicit ceiling fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'FAIL  b.js: allow_new requires explicit raw_bytes/gzip_bytes ceilings' "$WORK_DIR/out.txt" || fail 'allow_new without a ceiling did not report the exact reason'
cat > "$budget" <<'JSON'
{"assets":{"b.js":{"allow_new":true,"raw_bytes":10,"gzip_bytes":5}}}
JSON
expect_exit 0 'allow_new with an explicit ceiling passes' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'ALLOW b.js: missing in ref, present in target' "$WORK_DIR/out.txt" || fail 'allowed new asset did not print the ALLOW note'
expect_exit 1 'a new asset without allow_new fails' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q 'FAIL  b.js: missing in ref, present in target' "$WORK_DIR/out.txt" || fail 'unallowed new asset did not fail'

# --- compare: an asset missing from both captures is skipped, not a failure ---
write_bundle_capture "$WORK_DIR/ref.json" '{}'
write_bundle_capture "$WORK_DIR/target.json" '{}'
expect_exit 0 'both-missing asset is skipped' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"

# --- compare: a wildcard budget row applies to every asset it does not name explicitly ---
write_bundle_capture "$WORK_DIR/ref.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 100, "gzip_bytes": 40}, "c.js": {"status": "present", "path": "c.js", "raw_bytes": 100, "gzip_bytes": 40}}'
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 110, "gzip_bytes": 40}, "c.js": {"status": "present", "path": "c.js", "raw_bytes": 105, "gzip_bytes": 40}}'
cat > "$budget" <<'JSON'
{"assets":{"*":{"raw_growth_bytes":10},"c.js":{"raw_growth_bytes":5}}}
JSON
expect_exit 0 'wildcard budget row covers the unnamed asset' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
write_bundle_capture "$WORK_DIR/target.json" '{"a.js": {"status": "present", "path": "a.js", "raw_bytes": 111, "gzip_bytes": 40}, "c.js": {"status": "present", "path": "c.js", "raw_bytes": 105, "gzip_bytes": 40}}'
expect_exit 1 'the per-asset rule overrides the wildcard for the named asset' bash "$GATE" compare --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json" --budget "$budget"
grep -q 'FAIL  a.js: raw_bytes 100 -> 111 exceeds limit 110' "$WORK_DIR/out.txt" || fail 'wildcard-covered asset did not fail past the wildcard limit'

# --- compare: a schema/mode mismatch fails closed ---
printf '{"schema": "other", "mode": "bundle", "assets": {}}' > "$WORK_DIR/bad-schema.json"
expect_exit 1 'a wrong schema fails closed' bash "$GATE" compare --ref "$WORK_DIR/bad-schema.json" --target "$WORK_DIR/target.json"
grep -q 'ERROR: ref schema' "$WORK_DIR/out.txt" || fail 'wrong schema did not report the schema mismatch'

echo 'PASS: bundle-size-gate fixture tests passed.'
