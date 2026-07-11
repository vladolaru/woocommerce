#!/usr/bin/env bash
#
# Preserved WooPayments hook-shape parity gate.
#
# Captures every required preserved hook argument shape on the reference WooPayments
# plugin store and native target store, then diffs type/class/array-key shape. It can
# also compare pre-captured JSON snapshots for focused regression tests.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRIVER="$SELF_DIR/hook-shape-parity.php"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"

REF_WP=""
TARGET_WP=""
REF_STATE=""
TARGET_STATE=""
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/hook-shape-parity"
PRINT_PLAN=0
SELF_CHECK=0

usage() {
	cat >&2 <<'USAGE'
usage:
  hook-shape-parity.sh --ref "<ref wp>" --target "<target wp>" [options]
  hook-shape-parity.sh --ref-state <file> --target-state <file> [options]

Options:
  --ref "<wp>"              Reference store WP-CLI command.
  --target "<wp>"           Target store WP-CLI command.
  --ref-state <file>        Pre-captured reference JSON snapshot.
  --target-state <file>     Pre-captured target JSON snapshot.
  --out-dir <path>          Evidence output directory.
  --self-check              Compare the plugin-owned reference store with itself.
  --print-plan              Print the normalized hook probe plan as JSON, then exit.
  -h, --help                Show this help.
USAGE
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

validate_wp_runner() {
	local role="$1"
	local runner="$2"
	local reason

	if ! reason="$(woopayments_validate_local_wp_runner "$runner" 2>&1)"; then
		usage_error "unsafe --$role WP runner: ${reason:-runner validation failed}"
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--ref-state=*) REF_STATE="${1#--ref-state=}"; shift ;;
		--ref-state) REF_STATE="${2:-}"; shift 2 ;;
		--target-state=*) TARGET_STATE="${1#--target-state=}"; shift ;;
		--target-state) TARGET_STATE="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--self-check) SELF_CHECK=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ ! -f "$DRIVER" ]; then
	blocked "capture driver is missing: $DRIVER"
fi
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	blocked "local runner safety library is missing: $LOCAL_RUNNER_SAFETY"
fi
if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
if ! command -v php >/dev/null 2>&1; then
	blocked "php is required."
fi

# shellcheck source=./local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"

required_hooks_json() {
	php "$DRIVER" --inventory | python3 -c 'import json, sys; print(json.dumps(json.load(sys.stdin)["required_hooks"]))'
}

print_plan() {
	python3 - "$REF_WP" "$TARGET_WP" "$OUT_DIR" "$DRIVER" "$(required_hooks_json)" "$SELF_CHECK" <<'PY'
import json
import sys

ref_wp, target_wp, out_dir, driver, required_hooks_raw, self_check = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_hook_shape_gate_plan.v1",
            "ref_wp": ref_wp,
            "target_wp": target_wp,
            "out_dir": out_dir,
            "capture_driver": driver,
            "required_hooks": json.loads(required_hooks_raw),
            "self_check": self_check == "1",
        },
        sort_keys=True,
    )
)
PY
}

if [ "$PRINT_PLAN" -eq 1 ]; then
	if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
		usage_error "--ref and --target are required with --print-plan."
	fi
	validate_wp_runner "ref" "$REF_WP"
	validate_wp_runner "target" "$TARGET_WP"
	print_plan
	exit 0
fi

if [ -n "$REF_STATE" ] || [ -n "$TARGET_STATE" ]; then
	if [ -z "$REF_STATE" ] || [ -z "$TARGET_STATE" ]; then
		usage_error "--ref-state and --target-state must be provided together."
	fi
	if [ ! -f "$REF_STATE" ]; then
		usage_error "reference state not found: $REF_STATE"
	fi
	if [ ! -f "$TARGET_STATE" ]; then
		usage_error "target state not found: $TARGET_STATE"
	fi
else
	if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
		usage_error "provide --ref/--target or --ref-state/--target-state."
	fi
	validate_wp_runner "ref" "$REF_WP"
	validate_wp_runner "target" "$TARGET_WP"
fi

mkdir -p "$OUT_DIR"

last_json_line() {
	grep -E '^\{' | tail -1
}

capture_state() {
	local role="$1"
	local wp_cmd="$2"
	local output_file="$3"
	local raw rc json

	printf 'Hook shape parity gate: capturing %s store...\n' "$role" >&2
	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$role" < "$DRIVER" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'BLOCKED: %s hook capture failed.\n' "$role" >&2
		printf '%s\n' "$raw" | tail -40 >&2
		exit 3
	fi

	printf '%s\n' "$json" > "$output_file"
}

if [ -z "$REF_STATE" ]; then
	REF_STATE="$OUT_DIR/reference-hook-shapes.json"
	TARGET_STATE="$OUT_DIR/target-hook-shapes.json"
	capture_state "reference" "$REF_WP" "$REF_STATE"
	capture_state "target" "$TARGET_WP" "$TARGET_STATE"
fi

python3 - "$REF_STATE" "$TARGET_STATE" "$OUT_DIR/hook-shape-parity.json" "$(required_hooks_json)" "$SELF_CHECK" <<'PY'
import ipaddress
import json
import sys
from pathlib import Path
from urllib.parse import urlparse, urlunparse

ref_path = Path(sys.argv[1])
target_path = Path(sys.argv[2])
rollup_path = Path(sys.argv[3])
required_hooks = json.loads(sys.argv[4])
self_check = sys.argv[5] == "1"


def load(path):
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def normalize_local_site_url(value):
    parsed = urlparse(str(value))
    host = (parsed.hostname or "").lower()
    local_host = host in {"localhost", "host.docker.internal", "gateway.docker.internal"} or host.endswith(
        ".localhost"
    )
    if not local_host:
        try:
            local_host = ipaddress.ip_address(host).is_loopback
        except ValueError:
            local_host = False
    if parsed.scheme not in {"http", "https"} or not local_host or parsed.username or parsed.password:
        return None
    return urlunparse((parsed.scheme.lower(), parsed.netloc.lower(), parsed.path.rstrip("/"), "", "", ""))


def shape_summary(shape):
    shape_type = shape.get("type", "unknown")
    if shape_type == "object":
        return f"object {shape.get('class', '(unknown)')}"
    if shape_type == "array":
        keys = ",".join(shape.get("keys", []))
        return f"array keys=[{keys}]"
    return shape_type


def class_sets(shape):
    classes = set()
    class_name = shape.get("class")
    if class_name:
        classes.add(class_name)
    for class_name in shape.get("legacy_classes", []):
        if class_name:
            classes.add(class_name)
    return classes


def compare_shape(label, ref_shape, target_shape, failures):
    ref_type = ref_shape.get("type")
    target_type = target_shape.get("type")
    if ref_type != target_type:
        failures.append(f"{label}: reference {shape_summary(ref_shape)} target {shape_summary(target_shape)}")
        return

    if ref_type == "array":
        ref_keys = ref_shape.get("keys", [])
        target_keys = target_shape.get("keys", [])
        missing_keys = sorted(set(ref_keys) - set(target_keys))
        if missing_keys:
            failures.append(f"{label}: target array missing reference keys {missing_keys}")

        ref_values = ref_shape.get("values") if isinstance(ref_shape.get("values"), dict) else {}
        target_values = target_shape.get("values") if isinstance(target_shape.get("values"), dict) else {}
        for key, ref_value in ref_values.items():
            if key not in target_values:
                continue
            if isinstance(ref_value, dict) and isinstance(target_values[key], dict):
                compare_shape(f"{label}.{key}", ref_value, target_values[key], failures)
        return

    if ref_type == "object":
        if not (class_sets(ref_shape) & class_sets(target_shape)):
            failures.append(f"{label}: reference {shape_summary(ref_shape)} target {shape_summary(target_shape)}")
            return

        ref_methods = set(ref_shape.get("methods", []))
        target_methods = set(target_shape.get("methods", []))
        missing_methods = sorted(ref_methods - target_methods)
        if missing_methods:
            failures.append(f"{label}: target object missing methods {missing_methods}")


def compare_arg(hook, index, ref_arg, target_arg, failures):
    compare_shape(f"{hook} arg[{index}]", ref_arg, target_arg, failures)


reference = load(ref_path)
target = load(target_path)
reference_hooks = reference.get("hooks", {})
target_hooks = target.get("hooks", {})
failures = []
blocked = []

for expected_role, payload in (("reference", reference), ("target", target)):
    observed_role = payload.get("role")
    if observed_role != expected_role:
        failures.append(
            f"{expected_role} snapshot role must be {expected_role}, got {observed_role!r}"
        )

expected_owners = {"reference": "plugin", "target": "plugin" if self_check else "native"}
site_urls = {}
for role, payload in (("reference", reference), ("target", target)):
    expected_owner = expected_owners[role]
    if payload.get("runtime_owner") != expected_owner:
        blocked.append(f"{role} runtime owner must be {expected_owner}")
    normalized_site = normalize_local_site_url(payload.get("site_url"))
    if normalized_site is None:
        blocked.append(f"{role} snapshot must identify a local site URL")
    else:
        site_urls[role] = normalized_site

if len(site_urls) == 2:
    same_site = site_urls["reference"] == site_urls["target"]
    if self_check and not same_site:
        blocked.append("self-check snapshots must identify the same local site")
    if not self_check and same_site:
        blocked.append("cross-store snapshots must identify distinct local sites")

for role, payload in (("reference", reference), ("target", target)):
    preconditions = payload.get("preconditions")
    if not isinstance(preconditions, dict):
        blocked.append(f"{role} capture preconditions are missing")
    elif preconditions.get("ready") is not True:
        reasons = preconditions.get("reasons") or ["unknown"]
        blocked.append(f"{role} capture preconditions unmet: {', '.join(str(reason) for reason in reasons)}")

capture_errors = []
for role, payload in (("reference", reference), ("target", target)):
    for error in payload.get("errors", []):
        capture_errors.append(f"{role} capture error: {error}")
blocked.extend(capture_errors)

if capture_errors or (blocked and not failures):
    rollup = {
        "schema": "woopayments_hook_shape_gate_result.v1",
        "status": "blocked",
        "required_hooks": required_hooks,
        "failures": [],
        "blocked": blocked,
        "reference": reference,
        "target": target,
    }
    rollup_path.parent.mkdir(parents=True, exist_ok=True)
    rollup_path.write_text(json.dumps(rollup, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    for reason in blocked:
        print(f"BLOCKED: {reason}", file=sys.stderr)
    raise SystemExit(3)

for hook in required_hooks:
    if hook not in reference_hooks:
        failures.append(f"reference missing required hook: {hook}")
        continue
    if hook not in target_hooks:
        failures.append(f"target missing required hook: {hook}")
        continue

    if reference_hooks[hook].get("observed") is not True:
        failures.append(f"reference required hook was not observed: {hook}")
    if target_hooks[hook].get("observed") is not True:
        failures.append(f"target required hook was not observed: {hook}")
    if reference_hooks[hook].get("capture") != "surrounding_path":
        failures.append(f"reference required hook did not use its surrounding product path: {hook}")
    if target_hooks[hook].get("capture") != "surrounding_path":
        failures.append(f"target required hook did not use its surrounding product path: {hook}")

    ref_args = reference_hooks[hook].get("args", [])
    target_args = target_hooks[hook].get("args", [])
    if len(ref_args) != len(target_args):
        failures.append(f"{hook}: reference arg count {len(ref_args)} target arg count {len(target_args)}")
        continue

    for index, (ref_arg, target_arg) in enumerate(zip(ref_args, target_args)):
        compare_arg(hook, index, ref_arg, target_arg, failures)

status = "fail" if failures else "pass"
rollup = {
    "schema": "woopayments_hook_shape_gate_result.v1",
    "status": status,
    "required_hooks": required_hooks,
    "failures": failures,
    "blocked": [],
    "reference": reference,
    "target": target,
}
rollup_path.parent.mkdir(parents=True, exist_ok=True)
rollup_path.write_text(json.dumps(rollup, sort_keys=True, indent=2) + "\n", encoding="utf-8")

if failures:
    for failure in failures:
        print(failure, file=sys.stderr)
    print("FAIL: preserved WooPayments hook argument shape drift detected.", file=sys.stderr)
    raise SystemExit(1)

print("PASS: preserved WooPayments hook argument shapes match.")
PY
