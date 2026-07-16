#!/usr/bin/env bash
# Orchestrator for the critical-flows parity suite.
# Runs the deterministic (Layer D) flow scripts, dispatches the agent-driven (Layer A)
# flow specs, and rolls up a verdict per flow per store into evidence/rollup.json.
#
# Usage:
#   ./run.sh --store both --layer all                # everything
#   ./run.sh --store target --flow SC-04             # one flow, native only
#   ./run.sh --layer deterministic                   # CI-able Layer D subset
#   ./run.sh --layer agent --agent-results-dir path  # ingest completed Layer A JSON evidence
#
# Layer A flows are NOT executed by this script directly. The runner ingests completed
# JSON evidence from browser agents, and queues missing/incomplete specs in
# evidence/agent-queue.txt for a supervisor/workflow to drive with agent-specs/_template.md.
# This keeps the deterministic suite self-contained and CI-able while routing the
# judgment-heavy flows to agents.

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/lib/common.sh"

STORE="both" LAYER="all" ONLY_FLOW="" AGENT_RESULTS_DIR="${AGENT_RESULTS_DIR:-}" EVIDENCE_CONTEXT_FILE="${EVIDENCE_CONTEXT_FILE:-}" REF_URL="" TARGET_URL=""
while [ $# -gt 0 ]; do case "$1" in
  --store) STORE="$2"; shift 2;; --layer) LAYER="$2"; shift 2;; --flow) ONLY_FLOW="$2"; shift 2;;
  --agent-results-dir) AGENT_RESULTS_DIR="$2"; shift 2;;
  --context-file) EVIDENCE_CONTEXT_FILE="$2"; shift 2;;
  --ref-url) REF_URL="${2:-}"; shift 2;;
  --target-url) TARGET_URL="${2:-}"; shift 2;;
  *) echo "unknown arg: $1" >&2; exit 2;; esac; done

case "$STORE" in both|ref|target) ;; *) echo "unknown store: $STORE" >&2; exit 2;; esac
case "$LAYER" in all|deterministic|agent) ;; *) echo "unknown layer: $LAYER" >&2; exit 2;; esac

# Scope: only a full run (both stores, all layers, no flow filter) may ever claim the
# suite green. Partial runs keep their per-run verdicts but are marked machine-visibly.
if [ "$STORE" = "both" ] && [ "$LAYER" = "all" ] && [ -z "$ONLY_FLOW" ]; then
  RUN_SCOPE="full"
else
  RUN_SCOPE="partial"
fi
# PID suffix keeps archive dirs unique when two runs share the same second —
# otherwise the second run would silently overwrite the first's "append-only" archive.
RUN_STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"

if [ "$LAYER" != "agent" ] && [ "$STORE" != "ref" ] && { [ -z "$ONLY_FLOW" ] || [[ "MC-06-automatic-rates-refresh" == "$ONLY_FLOW"* ]]; }; then
  if [ -z "$REF_URL" ] || [ -z "$TARGET_URL" ]; then
    echo "BLOCKED: explicit --ref-url and --target-url are required when MC-06 can run." >&2
    exit 3
  fi
fi

stores() { case "$STORE" in both) echo "ref target";; ref|target) echo "$STORE";; esac; }

# Layer-D store identity probe: the dual-store oracle is only meaningful when the
# reference actually runs the plugin, the target actually runs native, and they are
# distinct stores. Without this, swapped or duplicated WP commands would record
# earned-looking PASS rows for both stores while comparing a store against itself.
probe_store_identity() { # <ref|target>
  local s="$1" raw rc owner home expected
  raw="$(wp_store "$s" eval '
$active_plugins = (array) get_option( "active_plugins", array() );
if ( is_multisite() ) {
	$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( "active_sitewide_plugins", array() ) ) );
}
$owner = in_array( "woocommerce-payments/woocommerce-payments.php", $active_plugins, true ) ? "plugin" : "none";
if ( class_exists( "\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter" ) && function_exists( "wc_get_container" ) ) {
	try {
		$owner = (string) wc_get_container()->get( "\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter" )->get_runtime_owner();
	} catch ( Throwable $e ) {
		$owner = "probe_failed";
	}
}
WP_CLI::line( "store_identity_owner=" . $owner );
WP_CLI::line( "store_identity_home=" . home_url() );
' 2>&1)"
  rc=$?
  owner="$(printf '%s\n' "$raw" | sed -n 's/^store_identity_owner=//p' | tail -1)"
  home="$(printf '%s\n' "$raw" | sed -n 's/^store_identity_home=//p' | tail -1)"
  case "$s" in ref) expected="plugin";; target) expected="native";; esac

  if [ "$rc" -ne 0 ] || [ -z "$owner" ] || [ -z "$home" ]; then
    echo "BLOCKED: $s store identity probe failed (cannot attribute verdicts to a runtime):" >&2
    printf '%s\n' "$raw" | tail -5 | sed 's/^/    /' >&2
    exit 3
  fi
  if [ "$owner" != "$expected" ]; then
    echo "BLOCKED: $s store runtime owner is '$owner', expected '$expected' — refusing to record verdicts against the wrong runtime." >&2
    exit 3
  fi
  if [ "$s" = "ref" ]; then PROBE_HOME_REF="$home"; else PROBE_HOME_TARGET="$home"; fi
  echo "[$s] store identity: owner=$owner home=$home"
}

spec_requires_agent_layer() { # <spec.md>
  ! grep -qi 'No browser layer is required' "$1"
}

agent_oracle_mode() { # <spec.md>
  if grep -qiE 'Agent oracle mode:[[:space:]]*target-only' "$1"; then
    echo "target-only"
  else
    echo "comparable"
  fi
}

run_no_browser_deterministic_flow() { # <flow-base> <store>
  local base="$1" store="$2" rc log_rc marker_rc out_dir validation_output validation_rc trusted_fail evidence_block manifest

  case "$base" in
    MA-10-i18n-order-notes)
      if [ "$store" = "ref" ]; then
        FLOW_RESULT_REASON="reference extension same-note-family oracle is not wired"
        echo "[MA-10/ref] BLOCKED: $FLOW_RESULT_REASON"
        return 3
      fi
      if [ -z "$TARGET_WP_COMMAND" ]; then
        FLOW_RESULT_REASON="TARGET_WP_COMMAND is required for the MA-10 gate"
        echo "[MA-10/$store] BLOCKED: TARGET_WP_COMMAND is required for i18n-notes-gate.sh"
        return 3
      fi
      if [ ! -f "$I18N_NOTES_GATE" ]; then
        FLOW_RESULT_REASON="MA-10 i18n notes gate is missing"
        echo "[MA-10/$store] BLOCKED: i18n notes gate is missing: $I18N_NOTES_GATE"
        return 3
      fi

      echo "[MA-10/$store] exercise: validate localized native WooPayments order notes"
      out_dir="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/MA-10-i18n-order-notes"
      mkdir -p "$out_dir"
      mark_log_clean_start "$store"
      marker_rc=$?
      if [ "$marker_rc" -ne 0 ]; then
        FLOW_RESULT_REASON="log-clean marker could not be recorded before MA-10"
        return 3
      fi
      bash "$I18N_NOTES_GATE" --target "$TARGET_WP_COMMAND" --out-dir "$out_dir"
      rc=$?
      LOG_SCAN_EVIDENCE_FILE="$out_dir/debug-log-scan.json" assert_log_clean "$store"
      log_rc=$?
      validation_rc=0
      if [ "$rc" -eq 0 ] || [ "$rc" -eq 1 ] || [ "$rc" -eq 3 ]; then
        validation_output="$(python3 "$MA10_EVIDENCE_VALIDATOR" --evidence-dir "$out_dir" --gate-exit "$rc" 2>&1)"
        validation_rc=$?
        if [ "$validation_rc" -ne 0 ]; then
          printf '%s\n' "$validation_output"
        fi
        if { [ "$validation_rc" -eq 0 ] || [ "$validation_rc" -eq 1 ]; } && [ ! -f "$out_dir/manifest.json" ]; then
          echo "BLOCKED: MA-10 evidence validator returned without a manifest"
          validation_rc=3
        fi
      else
        validation_rc=3
      fi

      trusted_fail=0
      evidence_block=0
      [ "$validation_rc" -eq 1 ] && trusted_fail=1
      if [ "$validation_rc" -ne 0 ] && [ "$validation_rc" -ne 1 ]; then
        evidence_block=1
      fi
      if [ "$rc" -eq 1 ] && [ "$validation_rc" -ne 3 ]; then
        trusted_fail=1
      fi
      [ "$log_rc" -eq 1 ] && trusted_fail=1

      if [ -f "$out_dir/manifest.json" ]; then
        manifest="$out_dir/manifest.json"
        FLOW_EVIDENCE_PATH="$manifest"
        FLOW_EVIDENCE_SHA256="$(python3 -c 'import hashlib, sys; print("sha256:" + hashlib.sha256(open(sys.argv[1], "rb").read()).hexdigest())' "$manifest")"
        FLOW_RESULT_REASON="validated MA-10 evidence manifest"
      fi

      if [ "$evidence_block" -ne 0 ]; then
        if [ -z "$FLOW_RESULT_REASON" ]; then
          if [ "$rc" -eq 70 ]; then
            FLOW_RESULT_REASON="MA-10 gate cleanup failed; no verdict was trusted"
          else
            FLOW_RESULT_REASON="MA-10 evidence validation did not complete"
          fi
        fi
        rc=3
      elif [ "$trusted_fail" -ne 0 ]; then
        rc=1
      elif [ "$rc" -eq 3 ] || [ "$log_rc" -eq 3 ]; then
        rc=3
      else
        rc=0
      fi
      ;;
    MC-06-automatic-rates-refresh)
      if [ "$store" = "ref" ]; then
        echo "[MC-06/ref] deterministic verdict: PASS (cross-store rate comparison runs on target iteration)"
        return 0
      fi
      if [ -z "$REF_WP_COMMAND" ] || [ -z "$TARGET_WP_COMMAND" ]; then
        echo "[MC-06/$store] BLOCKED: REF_WP_COMMAND and TARGET_WP_COMMAND are required for mc-rates-gate.sh"
        return 3
      fi
      if [ ! -f "$MC_RATES_GATE" ]; then
        echo "[MC-06/$store] BLOCKED: multi-currency rates gate is missing: $MC_RATES_GATE"
        return 3
      fi

      echo "[MC-06/$store] exercise: compare automatic rates refresh across reference and target"
      bash "$MC_RATES_GATE" --ref "$REF_WP_COMMAND" --target "$TARGET_WP_COMMAND" --ref-url "$REF_URL" --target-url "$TARGET_URL" --currency-from USD --currencies-to GBP,EUR --out-dir "$EVIDENCE_DIR/MC-06-automatic-rates-refresh"
      rc=$?
      ;;
    *)
      echo "unknown no-browser deterministic flow: $base" >&2
      return 2
      ;;
  esac

  if [ "$rc" -eq 0 ]; then
    echo "[$base/$store] deterministic verdict: PASS"
    return 0
  fi
  if [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
    echo "[$base/$store] deterministic verdict: BLOCKED"
    return 3
  fi

  echo "[$base/$store] deterministic verdict: FAIL"
  return 1
}

AGENT_RESULTS_DIR="${AGENT_RESULTS_DIR:-$EVIDENCE_DIR/agent-results}"
RESULTS_JSONL="$EVIDENCE_DIR/rollup-results.jsonl"
ROLLUP_JSON="$EVIDENCE_DIR/rollup.json"
AGENT_QUEUE="$EVIDENCE_DIR/agent-queue.txt"
I18N_NOTES_GATE="${I18N_NOTES_GATE:-$REPO_ROOT/tools/woopayments-merge/i18n-notes-gate.sh}"
MA10_EVIDENCE_VALIDATOR="${MA10_EVIDENCE_VALIDATOR:-$DIR/flows/ma10-validate.py}"
MC_RATES_GATE="${MC_RATES_GATE:-$REPO_ROOT/tools/woopayments-merge/mc-rates-gate.sh}"
MATRIX_TSV="${MATRIX_TSV:-$DIR/matrix.tsv}"
# Test seam: lets the self-tests run against a minimal flows set so properties like
# "uncovered matrix rows alone refuse green" can be pinned in isolation.
FLOWS_DIR="${FLOWS_DIR:-$DIR/flows}"
PASS_COUNT=0
FAIL_COUNT=0
BLOCKED_COUNT=0
QUEUED_AGENT_COUNT=0

record_result() {
  local flow="$1" layer="$2" store="$3" status="$4" exit_code="$5"
  local agent_verdict="${6:-}" evidence_path="${7:-}" reason="${8:-}" evidence_sha256="${9:-}"

  python3 - "$RESULTS_JSONL" "$flow" "$layer" "$store" "$status" "$exit_code" "$agent_verdict" "$evidence_path" "$reason" "$evidence_sha256" <<'PY'
import datetime
import json
import sys
from pathlib import Path

path, flow, layer, store, status, exit_code, agent_verdict, evidence_path, reason, evidence_sha256 = sys.argv[1:]
payload = {
    "flow": flow,
    "layer": layer,
    "store": store,
    "status": status,
    "exit_code": int(exit_code),
    "recorded_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
}
if agent_verdict:
    payload["agent_verdict"] = agent_verdict
if evidence_path:
    payload["evidence_path"] = evidence_path
    if evidence_sha256:
        payload["evidence_sha256"] = evidence_sha256
if reason:
    payload["reason"] = reason
with Path(path).open("a", encoding="utf-8") as stream:
    stream.write(json.dumps(payload, sort_keys=True) + "\n")
PY

  case "$status" in
    PASS) PASS_COUNT=$((PASS_COUNT + 1)) ;;
    FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
    BLOCKED) BLOCKED_COUNT=$((BLOCKED_COUNT + 1)) ;;
  esac
}

validate_mo01_manifest() {
  local manifest_path="$1" expected_store="$2" expected_status="$3" expected_exit_code="$4"
  local structural_digest structural_rc semantic_digest semantic_rc
  structural_digest="$(python3 - "$manifest_path" "$expected_store" "$expected_status" "$expected_exit_code" "$RUN_STAMP" "$RUN_SCOPE" <<'PY'
import hashlib
import json
import re
import sys
from pathlib import Path

manifest_path, expected_store, expected_status, expected_exit_code, run_stamp, run_scope = sys.argv[1:]
path = Path(manifest_path)


def digest_payload(payload):
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def digest_file(candidate):
    return "sha256:" + hashlib.sha256(candidate.read_bytes()).hexdigest()


def refuse(reason):
    print(reason)
    raise SystemExit(3)


if path.is_symlink() or not path.is_file():
    refuse("MO-01 evidence manifest must be a regular non-symlinked file")
path = path.resolve()

try:
    raw = path.read_bytes()
    manifest = json.loads(raw.decode("utf-8"))
except (OSError, UnicodeError, ValueError) as exc:
    refuse(f"MO-01 evidence manifest is unreadable: {exc}")

required_fields = {
    "schema",
    "flow",
    "run_stamp",
    "run_scope",
    "store",
    "status",
    "exit_code",
    "verdict_sources",
    "files",
    "payload_sha256",
}
if not isinstance(manifest, dict) or set(manifest) != required_fields:
    refuse("MO-01 evidence manifest has an invalid field set")
if manifest.get("schema") != "woopayments_mo01_manifest.v1":
    refuse("MO-01 evidence manifest has an invalid schema")
if manifest.get("flow") != "MO-01-manual-capture-order":
    refuse("MO-01 evidence manifest has an invalid flow binding")
if manifest.get("run_stamp") != run_stamp or manifest.get("run_scope") != run_scope:
    refuse("MO-01 evidence manifest belongs to a different runner invocation")
if manifest.get("store") != expected_store:
    refuse("MO-01 evidence manifest has an invalid store binding")
if manifest.get("status") != expected_status or manifest.get("exit_code") != int(expected_exit_code):
    refuse("MO-01 evidence manifest contradicts the deterministic verdict")
if manifest.get("payload_sha256") != digest_payload(manifest):
    refuse("MO-01 evidence manifest payload digest does not match")
verdict_sources = manifest.get("verdict_sources")
allowed_sources = {
    "pass": set(),
    "fail": {
        "pre_state_failed",
        "post_state_failed",
        "comparison_failed",
        "capture_operation_failed",
        "log_assertion_failed",
    },
    "blocked": {
        "authorization_driver_blocked",
        "authorization_order_id_missing",
        "pre_state_blocked",
        "post_state_blocked",
        "capture_operation_blocked",
        "comparison_blocked",
        "log_assertion_blocked",
    },
}
if (
    not isinstance(verdict_sources, list)
    or not all(isinstance(source, str) and source for source in verdict_sources)
    or len(verdict_sources) != len(set(verdict_sources))
    or not set(verdict_sources).issubset(allowed_sources[expected_status])
    or (expected_status == "pass" and verdict_sources)
    or (expected_status != "pass" and not verdict_sources)
):
    refuse("MO-01 evidence manifest has invalid verdict sources")

files = manifest.get("files")
if not isinstance(files, dict):
    refuse("MO-01 evidence manifest files must be an object")
allowed = (
    {"ref-pre.json", "ref-post.json", "ref-execution.json"}
    if expected_store == "ref"
    else {
        "ref-pre.json",
        "ref-post.json",
        "ref-execution.json",
        "target-pre.json",
        "target-post.json",
        "target-execution.json",
        "comparison.json",
    }
)
if not set(files).issubset(allowed):
    refuse("MO-01 evidence manifest contains an unexpected artifact")
if expected_status == "pass" and set(files) != allowed:
    refuse("Passing MO-01 evidence manifest is incomplete")

artifact_payloads = {}
for filename, binding in files.items():
    if not isinstance(binding, dict) or set(binding) != {"file_sha256", "payload_sha256"}:
        refuse(f"MO-01 manifest binding is malformed: {filename}")
    if not all(
        isinstance(binding.get(field), str)
        and re.fullmatch(r"sha256:[0-9a-f]{64}", binding[field])
        for field in ("file_sha256", "payload_sha256")
    ):
        refuse(f"MO-01 manifest binding has an invalid digest: {filename}")
    artifact = path.parent / filename
    if artifact.is_symlink() or not artifact.is_file() or artifact.resolve().parent != path.parent:
        refuse(f"MO-01 manifest artifact escapes the archive or is not a regular file: {filename}")
    try:
        artifact_raw = artifact.read_bytes()
        payload = json.loads(artifact_raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        refuse(f"MO-01 manifest artifact is unreadable ({filename}): {exc}")
    if not isinstance(payload, dict):
        refuse(f"MO-01 manifest artifact is not an object: {filename}")
    if binding["file_sha256"] != "sha256:" + hashlib.sha256(artifact_raw).hexdigest():
        refuse(f"MO-01 manifest artifact byte digest does not match: {filename}")
    if binding["payload_sha256"] != payload.get("payload_sha256"):
        refuse(f"MO-01 manifest artifact payload binding does not match: {filename}")
    if payload.get("payload_sha256") != digest_payload(payload):
        refuse(f"MO-01 manifest artifact payload digest does not match: {filename}")
    expected_schema = (
        "woopayments_mo01_comparison.v1"
        if filename == "comparison.json"
        else "woopayments_mo01_execution.v1"
        if filename.endswith("-execution.json")
        else "woopayments_mo01_normalized.v1"
    )
    if payload.get("schema") != expected_schema or payload.get("run_stamp") != run_stamp:
        refuse(f"MO-01 manifest artifact has an invalid schema/run binding: {filename}")
    artifact_payloads[filename] = payload
    if filename.endswith("-execution.json"):
        store = filename.removesuffix("-execution.json")
        if payload.get("store") != store:
            refuse(f"MO-01 execution artifact has an invalid role binding: {filename}")
    elif filename != "comparison.json":
        store, phase = filename.removesuffix(".json").split("-")
        if payload.get("store") != store or payload.get("phase") != phase:
            refuse(f"MO-01 manifest artifact has an invalid role binding: {filename}")

if "comparison.json" in artifact_payloads:
    expected_inputs = {
        "ref_pre": artifact_payloads.get("ref-pre.json", {}).get("payload_sha256"),
        "ref_post": artifact_payloads.get("ref-post.json", {}).get("payload_sha256"),
        "target_pre": artifact_payloads.get("target-pre.json", {}).get("payload_sha256"),
        "target_post": artifact_payloads.get("target-post.json", {}).get("payload_sha256"),
    }
    if artifact_payloads["comparison.json"].get("inputs") != expected_inputs:
        refuse("MO-01 comparison does not bind the manifest's normalized evidence")

print("sha256:" + hashlib.sha256(raw).hexdigest())
PY
)"
  structural_rc=$?
  if [ "$structural_rc" -ne 0 ]; then
    printf '%s\n' "$structural_digest"
    return 3
  fi

  semantic_digest="$(python3 "$DIR/flows/mo01-compare.py" validate-bound-manifest \
    --manifest "$manifest_path" \
    --store "$expected_store" \
    --run-stamp "$RUN_STAMP" \
    --run-scope "$RUN_SCOPE" \
    --expected-status "$expected_status" \
    --expected-exit-code "$expected_exit_code")"
  semantic_rc=$?
  if [ "$semantic_rc" -ne 0 ]; then
    printf '%s\n' "$semantic_digest"
    return 3
  fi
  if [ "$semantic_digest" != "$structural_digest" ]; then
    echo "MO-01 structural and semantic manifest digests disagree"
    return 3
  fi
  printf '%s\n' "$semantic_digest"
}

agent_result_verdict() {
  local flow="$1" store="$2" result_file="$3" expected_oracle_mode="$4"

  python3 - "$flow" "$store" "$result_file" "$EVIDENCE_CONTEXT_FILE" "$DIR" "$expected_oracle_mode" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

flow, store, result_file, context_file, module_dir, expected_oracle_mode = sys.argv[1:]
sys.path.insert(0, module_dir)

from evidence_context import EvidenceContextError, validate_context, validate_imported_result


def clean(value):
    return " ".join(str(value).split())


result_sha256 = ""


def emit(status, exit_code, verdict, reason, queue_required=True):
    print(
        "\t".join(
            [
                status,
                str(exit_code),
                clean(verdict),
                clean(reason),
                "1" if queue_required else "0",
                result_sha256,
            ]
        )
    )


def classify_verdict(verdict):
    normalized = clean(verdict).upper().replace("—", "-")
    if normalized.startswith("PASS"):
        return "PASS", 0
    if normalized.startswith("FAIL"):
        return "FAIL", 1
    if normalized.startswith("BLOCKED"):
        return "BLOCKED", 3
    return "BLOCKED", 3


try:
    result_bytes = Path(result_file).read_bytes()
    result_sha256 = f"sha256:{hashlib.sha256(result_bytes).hexdigest()}"
    payload = json.loads(result_bytes.decode("utf-8"))
except Exception as exc:
    emit("BLOCKED", 3, "BLOCKED", f"invalid agent result JSON: {exc}")
    raise SystemExit(0)

try:
    if not context_file:
        raise EvidenceContextError("evidence_context_missing", "current critical-flow context was not supplied")
    context = json.loads(Path(context_file).read_text(encoding="utf-8"))
    validate_context(context)
    validate_imported_result(payload, context, flow, store)
except EvidenceContextError as exc:
    emit("BLOCKED", 3, "BLOCKED", f"{exc.code}: {exc}")
    raise SystemExit(0)
except Exception as exc:
    emit("BLOCKED", 3, "BLOCKED", f"evidence_context_invalid: {exc}")
    raise SystemExit(0)

payload_flow = payload.get("flow")
if payload_flow and payload_flow != flow:
    emit("BLOCKED", 3, "BLOCKED", f"agent result flow mismatch: expected {flow}, got {payload_flow}")
    raise SystemExit(0)

oracle_mode = clean(payload.get("oracle_mode", "comparable")).lower()
if oracle_mode != expected_oracle_mode:
    emit(
        "BLOCKED",
        3,
        "BLOCKED",
        f"agent oracle mode mismatch: expected {expected_oracle_mode}, got {oracle_mode or '<missing>'}",
    )
    raise SystemExit(0)

store_results = payload.get("store_results")
if not isinstance(store_results, list):
    emit("BLOCKED", 3, "BLOCKED", "agent result missing store_results list")
    raise SystemExit(0)

store_result = None
for candidate in store_results:
    if isinstance(candidate, dict) and candidate.get("store") == store:
        store_result = candidate
        break

if store_result is None:
    emit("BLOCKED", 3, "BLOCKED", f"missing agent result for {store}")
    raise SystemExit(0)

verdict = clean(store_result.get("verdict", ""))
status, exit_code = classify_verdict(verdict)

if expected_oracle_mode == "target-only":
    reference_result = next(
        (
            candidate
            for candidate in store_results
            if isinstance(candidate, dict) and candidate.get("store") == "ref"
        ),
        None,
    )
    reference_status, _ = classify_verdict(
        clean(reference_result.get("verdict", "")) if reference_result else ""
    )
    parity_verdict = clean(payload.get("parity_verdict", ""))
    parity_status, _ = classify_verdict(parity_verdict)

    if reference_status != "BLOCKED" or parity_status != "BLOCKED":
        emit(
            "BLOCKED",
            3,
            "BLOCKED",
            "target-only evidence must keep the reference and parity verdicts blocked/not comparable",
        )
    elif status == "FAIL":
        emit("FAIL", 1, verdict, f"target-only agent verdict: {verdict}", False)
    elif store == "ref" and status == "BLOCKED":
        emit("BLOCKED", 3, verdict or "BLOCKED", "target-only reference is intentionally not comparable", False)
    elif store == "target" and status == "PASS":
        emit("PASS", 0, verdict, f"target-only agent result accepted: {verdict}; parity not comparable", False)
    elif status == "BLOCKED":
        emit("BLOCKED", 3, verdict or "BLOCKED", f"target-only agent verdict: {verdict or '<missing>'}")
    else:
        emit("BLOCKED", 3, verdict or "BLOCKED", "invalid target-only store verdict")
    raise SystemExit(0)

if status == "FAIL":
    emit("FAIL", 1, verdict, f"agent verdict: {verdict}")
elif status == "BLOCKED":
    if verdict:
        emit("BLOCKED", 3, verdict, f"agent verdict: {verdict}")
    else:
        emit("BLOCKED", 3, "BLOCKED", "unknown agent verdict: <missing>")
elif store == "target":
    parity_verdict = clean(payload.get("parity_verdict", ""))
    parity_status, parity_exit_code = classify_verdict(parity_verdict)
    if parity_status == "PASS":
        emit("PASS", 0, verdict, f"agent result accepted: {verdict}")
    elif parity_status == "FAIL":
        emit("FAIL", 1, parity_verdict, f"agent parity verdict: {parity_verdict}")
    else:
        emit("BLOCKED", parity_exit_code, parity_verdict or "BLOCKED", f"unknown agent parity verdict: {parity_verdict or '<missing>'}")
else:
    emit("PASS", exit_code, verdict, f"agent result accepted: {verdict}")
PY
}

write_rollup() {
  python3 - "$ROLLUP_JSON" "$RESULTS_JSONL" "$STORE" "$LAYER" "$ONLY_FLOW" "$PASS_COUNT" "$FAIL_COUNT" "$BLOCKED_COUNT" "$QUEUED_AGENT_COUNT" "$AGENT_QUEUE" "$EVIDENCE_CONTEXT_FILE" "$MATRIX_TSV" "$RUN_SCOPE" "$RUN_STAMP" <<'PY'
import json
import sys
from pathlib import Path

(
    rollup_path,
    results_jsonl,
    store,
    layer,
    only_flow,
    passed,
    failed,
    blocked,
    queued_agent,
    agent_queue,
    context_file,
    matrix_tsv,
    run_scope,
    run_stamp,
) = sys.argv[1:]
results = []
results_path = Path(results_jsonl)
if results_path.exists():
    for line in results_path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            results.append(json.loads(line))

# Matrix coverage: a matrix row is covered by this run when at least one result row
# was recorded for it (a BLOCKED/queued row counts as covered-but-blocked; a row whose
# flow has no spec file produces no result at all and is UNCOVERED). Without this, a
# green run of the implemented specs would report suite "pass" with most of the
# critical-flows matrix never exercised — fail-open by omission.
matrix_rows = []
matrix_path = Path(matrix_tsv)
if matrix_path.is_file():
    for line in matrix_path.read_text(encoding="utf-8").splitlines():
        if not line.strip() or line.startswith("#") or line.startswith("id\t"):
            continue
        cells = line.split("\t")
        if len(cells) >= 5:
            matrix_rows.append({"id": cells[0], "layers": cells[2], "status": cells[4]})

covered_ids = {row["flow"].split("-", 2)[0] + "-" + row["flow"].split("-", 2)[1] for row in results if "-" in row["flow"]}
uncovered = sorted(row["id"] for row in matrix_rows if row["id"] not in covered_ids)

failed_count = int(failed)
blocked_count = int(blocked)
status = "fail" if failed_count else "blocked" if blocked_count else "pass"
if run_scope == "full" and status == "pass" and (uncovered or not matrix_rows):
    # A full-scope run may not claim the suite green while matrix rows lack any
    # evidence (or the matrix itself is missing).
    status = "blocked"

payload = {
    "schema": "woopayments_critical_flows_rollup.v1",
    "status": status,
    "scope": run_scope,
    "run_stamp": run_stamp,
    "store": store,
    "layer": layer,
    "flow": only_flow or "all",
    "summary": {
        "passed": int(passed),
        "failed": failed_count,
        "blocked": blocked_count,
        "queued_agent_specs": int(queued_agent),
    },
    "matrix": {
        "total": len(matrix_rows),
        "covered": len(matrix_rows) - len(uncovered),
        "uncovered": len(uncovered),
        "uncovered_ids": uncovered,
    },
    "results": results,
}
if int(queued_agent):
    payload["agent_queue"] = agent_queue
if context_file and Path(context_file).is_file():
    try:
        context = json.loads(Path(context_file).read_text(encoding="utf-8"))
        payload["context_sha256"] = context.get("context_sha256")
        payload["aggregate_run_id"] = context.get("aggregate_run_id")
    except Exception:
        pass
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

preserve_run_history() {
  # Append-only run history: a partial --flow run previously clobbered the only
  # machine record of the last full run. The top-level rollup stays as the "latest"
  # pointer for existing consumers; every run is also archived immutably.
  local run_dir="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE"
  mkdir -p "$run_dir"
  cp "$ROLLUP_JSON" "$run_dir/rollup.json" 2>/dev/null || true
  cp "$RESULTS_JSONL" "$run_dir/rollup-results.jsonl" 2>/dev/null || true
  if [ -s "$AGENT_QUEUE" ]; then
    cp "$AGENT_QUEUE" "$run_dir/agent-queue.txt" 2>/dev/null || true
  fi
  echo "  run archived -> $run_dir"
}

echo "== critical-flows suite =="
echo "stores=$(stores) layer=$LAYER flow=${ONLY_FLOW:-all} scope=$RUN_SCOPE"
if [ "$LAYER" != "agent" ]; then
  PROBE_HOME_REF=""
  PROBE_HOME_TARGET=""
  for s in $(stores); do
    probe_store_identity "$s"
  done
  if [ -n "$PROBE_HOME_REF" ] && [ -n "$PROBE_HOME_TARGET" ] && [ "$PROBE_HOME_REF" = "$PROBE_HOME_TARGET" ]; then
    echo "BLOCKED: reference and target resolve to the same store ($PROBE_HOME_REF) — dual-store parity would be vacuous." >&2
    exit 3
  fi
fi
# Truncate the latest-results file only after the identity probes: a probe block
# exits before write_rollup, and truncating earlier would leave the previous run's
# rollup.json paired with an emptied results file.
: > "$RESULTS_JSONL"
if [ "$LAYER" != "agent" ]; then
  echo "Layer D: running deterministic flow scripts (flows/*.sh)"
  for f in "$FLOWS_DIR"/*.sh; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .sh)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "--- $base ---"
    for s in $(stores); do
      mark_log_clean_start "$s"
      marker_rc=$?
      if [ "$marker_rc" -ne 0 ]; then
        printf '  [%-7s] %s on %s\n' "BLOCKED" "$base" "$s"
        record_result "$base" deterministic "$s" BLOCKED "$marker_rc" "" "" "log-clean marker could not be recorded"
        continue
      fi
      CRITICAL_FLOWS_RUN_SCOPE="$RUN_SCOPE" CRITICAL_FLOWS_RUN_STAMP="$RUN_STAMP" STORE_NAME="$s" bash "$f"
      rc=$?
      if [ "$rc" -eq 0 ]; then
        status="PASS"
      elif [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
        status="BLOCKED"
      else
        status="FAIL"
      fi
      evidence_path=""
      evidence_sha256=""
      result_reason=""
      if [ "$base" = "MO-01-manual-capture-order" ]; then
        manifest_path="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/$base/$s-manifest.json"
        expected_manifest_status="$(printf '%s' "$status" | tr '[:upper:]' '[:lower:]')"
        if [ ! -f "$manifest_path" ]; then
          status="BLOCKED"
          rc=3
          result_reason="MO-01 deterministic evidence manifest is missing"
        else
          manifest_validation="$(validate_mo01_manifest "$manifest_path" "$s" "$expected_manifest_status" "$rc")"
          manifest_rc=$?
          if [ "$manifest_rc" -ne 0 ]; then
            status="BLOCKED"
            rc=3
            result_reason="$manifest_validation"
          else
            evidence_path="$manifest_path"
            evidence_sha256="$manifest_validation"
            result_reason="manifest-bound deterministic evidence"
          fi
        fi
      fi
      printf '  [%-7s] %s on %s\n' "$status" "$base" "$s"
      record_result "$base" deterministic "$s" "$status" "$rc" "" "$evidence_path" "$result_reason" "$evidence_sha256"
    done
  done
  for f in "$FLOWS_DIR"/*.md; do
    [ -e "$f" ] || continue
    spec_requires_agent_layer "$f" && continue
    base="$(basename "$f" .md)"
    # A wired deterministic script supersedes the no-browser markdown fallback.
    # Without this guard, a D-only flow would run once through its script and a
    # second time through run_no_browser_deterministic_flow, recording two rows.
    [ -f "$FLOWS_DIR/$base.sh" ] && continue
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "--- $base ---"
    for s in $(stores); do
      FLOW_EVIDENCE_PATH=""
      FLOW_EVIDENCE_SHA256=""
      FLOW_RESULT_REASON=""
      run_no_browser_deterministic_flow "$base" "$s"
      rc=$?
      if [ "$rc" -eq 0 ]; then
        status="PASS"
      elif [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
        status="BLOCKED"
      else
        status="FAIL"
      fi
      printf '  [%-7s] %s on %s\n' "$status" "$base" "$s"
      record_result "$base" deterministic "$s" "$status" "$rc" "" "$FLOW_EVIDENCE_PATH" "$FLOW_RESULT_REASON" "$FLOW_EVIDENCE_SHA256"
    done
  done
fi

if [ "$LAYER" != "deterministic" ]; then
  echo "Layer A: ingesting agent results from $AGENT_RESULTS_DIR or building dispatch manifest (flows/*.md)"
  : > "$AGENT_QUEUE"
  for f in "$FLOWS_DIR"/*.md; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .md)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    if ! spec_requires_agent_layer "$f"; then
      continue
    fi
    oracle_mode="$(agent_oracle_mode "$f")"
    result_file="$AGENT_RESULTS_DIR/$base.json"
    spec_queued=0
    for s in $(stores); do
      if [ -f "$result_file" ]; then
        IFS=$'\t' read -r status rc agent_verdict reason queue_required evidence_sha256 < <(agent_result_verdict "$base" "$s" "$result_file" "$oracle_mode")
        if [ "$status" = "PASS" ] || [ "$status" = "FAIL" ]; then
          printf '  [%-7s] %s on %s (%s)\n' "$status" "$base" "$s" "$reason"
          record_result "$base" agent "$s" "$status" "$rc" "$agent_verdict" "$result_file" "$reason" "$evidence_sha256"
        else
          printf '  [%-7s] %s on %s (%s)\n' "BLOCKED" "$base" "$s" "$reason"
          record_result "$base" agent "$s" BLOCKED 3 "$agent_verdict" "$result_file" "$reason" "$evidence_sha256"
          if [ "$queue_required" != "0" ]; then
            spec_queued=1
          fi
        fi
      else
        printf '  [%-7s] %s on %s (agent spec queued)\n' "BLOCKED" "$base" "$s"
        record_result "$base" agent "$s" BLOCKED 3 "" "" "agent spec queued; no result file"
        spec_queued=1
      fi
    done
    if [ "$spec_queued" -eq 1 ]; then
      echo "$f" >> "$AGENT_QUEUE"
    fi
  done
  QUEUED_AGENT_COUNT="$(wc -l < "$AGENT_QUEUE" | tr -d ' ')"
  echo "  queued $QUEUED_AGENT_COUNT agent-driven flow specs -> $AGENT_QUEUE"
  if [ "$QUEUED_AGENT_COUNT" -ne 0 ]; then
    echo "  drive these with agent-specs/_template.md, respecting each flow spec's oracle mode (manually or via a workflow)."
  fi
fi

write_rollup
preserve_run_history

UNCOVERED_COUNT="$(python3 -c '
import json, sys
payload = json.load(open(sys.argv[1]))
print(payload.get("matrix", {}).get("uncovered", 0))
' "$ROLLUP_JSON" 2>/dev/null || echo 0)"

echo "Summary: $PASS_COUNT passed, $FAIL_COUNT failed, $BLOCKED_COUNT blocked, $QUEUED_AGENT_COUNT agent specs queued."
echo "Matrix coverage: $UNCOVERED_COUNT of the matrix rows have no evidence this run (see rollup.json matrix.uncovered_ids)."
echo "== done. Evidence under $EVIDENCE_DIR =="

if [ "$FAIL_COUNT" -ne 0 ]; then
  exit 1
fi
if [ "$BLOCKED_COUNT" -ne 0 ]; then
  exit 3
fi
if [ "$RUN_SCOPE" = "full" ] && [ "$UNCOVERED_COUNT" -ne 0 ]; then
  # A full-scope run cannot claim the suite green while matrix rows lack evidence.
  exit 3
fi
exit 0
