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

if [ "$LAYER" != "agent" ] && [ "$STORE" != "ref" ] && { [ -z "$ONLY_FLOW" ] || [[ "MC-06-automatic-rates-refresh" == "$ONLY_FLOW"* ]]; }; then
  if [ -z "$REF_URL" ] || [ -z "$TARGET_URL" ]; then
    echo "BLOCKED: explicit --ref-url and --target-url are required when MC-06 can run." >&2
    exit 3
  fi
fi

stores() { case "$STORE" in both) echo "ref target";; ref|target) echo "$STORE";; esac; }

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
  local base="$1" store="$2" rc

  case "$base" in
    MA-10-i18n-order-notes)
      if [ "$store" = "ref" ]; then
        echo "[MA-10/ref] deterministic verdict: PASS (reference oracle is covered by the target-native i18n gate)"
        return 0
      fi
      if [ -z "$TARGET_WP_COMMAND" ]; then
        echo "[MA-10/$store] BLOCKED: TARGET_WP_COMMAND is required for i18n-notes-gate.sh"
        return 3
      fi
      if [ ! -f "$I18N_NOTES_GATE" ]; then
        echo "[MA-10/$store] BLOCKED: i18n notes gate is missing: $I18N_NOTES_GATE"
        return 3
      fi

      echo "[MA-10/$store] exercise: validate localized native WooPayments order notes"
      bash "$I18N_NOTES_GATE" --target "$TARGET_WP_COMMAND" --out-dir "$EVIDENCE_DIR/MA-10-i18n-order-notes"
      rc=$?
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
MC_RATES_GATE="${MC_RATES_GATE:-$REPO_ROOT/tools/woopayments-merge/mc-rates-gate.sh}"
PASS_COUNT=0
FAIL_COUNT=0
BLOCKED_COUNT=0
QUEUED_AGENT_COUNT=0

: > "$RESULTS_JSONL"

record_result() {
  local flow="$1" layer="$2" store="$3" status="$4" exit_code="$5"
  local agent_verdict="${6:-}" evidence_path="${7:-}"

  python3 - "$RESULTS_JSONL" "$flow" "$layer" "$store" "$status" "$exit_code" "$agent_verdict" "$evidence_path" <<'PY'
import json
import sys
from pathlib import Path

path, flow, layer, store, status, exit_code, agent_verdict, evidence_path = sys.argv[1:]
payload = {
    "flow": flow,
    "layer": layer,
    "store": store,
    "status": status,
    "exit_code": int(exit_code),
}
if agent_verdict:
    payload["agent_verdict"] = agent_verdict
if evidence_path:
    payload["evidence_path"] = evidence_path
with Path(path).open("a", encoding="utf-8") as stream:
    stream.write(json.dumps(payload, sort_keys=True) + "\n")
PY

  case "$status" in
    PASS) PASS_COUNT=$((PASS_COUNT + 1)) ;;
    FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
    BLOCKED) BLOCKED_COUNT=$((BLOCKED_COUNT + 1)) ;;
  esac
}

agent_result_verdict() {
  local flow="$1" store="$2" result_file="$3" expected_oracle_mode="$4"

  python3 - "$flow" "$store" "$result_file" "$EVIDENCE_CONTEXT_FILE" "$DIR" "$expected_oracle_mode" <<'PY'
import json
import sys
from pathlib import Path

flow, store, result_file, context_file, module_dir, expected_oracle_mode = sys.argv[1:]
sys.path.insert(0, module_dir)

from evidence_context import EvidenceContextError, validate_context, validate_imported_result


def clean(value):
    return " ".join(str(value).split())


def emit(status, exit_code, verdict, reason, queue_required=True):
    print("\t".join([status, str(exit_code), clean(verdict), clean(reason), "1" if queue_required else "0"]))


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
    payload = json.loads(Path(result_file).read_text(encoding="utf-8"))
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
  python3 - "$ROLLUP_JSON" "$RESULTS_JSONL" "$STORE" "$LAYER" "$ONLY_FLOW" "$PASS_COUNT" "$FAIL_COUNT" "$BLOCKED_COUNT" "$QUEUED_AGENT_COUNT" "$AGENT_QUEUE" "$EVIDENCE_CONTEXT_FILE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, results_jsonl, store, layer, only_flow, passed, failed, blocked, queued_agent, agent_queue, context_file = sys.argv[1:]
results = []
results_path = Path(results_jsonl)
if results_path.exists():
    for line in results_path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            results.append(json.loads(line))

failed_count = int(failed)
blocked_count = int(blocked)
status = "fail" if failed_count else "blocked" if blocked_count else "pass"
payload = {
    "schema": "woopayments_critical_flows_rollup.v1",
    "status": status,
    "store": store,
    "layer": layer,
    "flow": only_flow or "all",
    "summary": {
        "passed": int(passed),
        "failed": failed_count,
        "blocked": blocked_count,
        "queued_agent_specs": int(queued_agent),
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

echo "== critical-flows suite =="
echo "stores=$(stores) layer=$LAYER flow=${ONLY_FLOW:-all}"
if [ "$LAYER" != "agent" ]; then
  echo "Layer D: running deterministic flow scripts (flows/*.sh)"
  for f in "$DIR"/flows/*.sh; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .sh)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "--- $base ---"
    for s in $(stores); do
      mark_log_clean_start "$s"
      marker_rc=$?
      if [ "$marker_rc" -ne 0 ]; then
        printf '  [%-7s] %s on %s\n' "BLOCKED" "$base" "$s"
        record_result "$base" deterministic "$s" BLOCKED "$marker_rc"
        continue
      fi
      STORE_NAME="$s" bash "$f"
      rc=$?
      if [ "$rc" -eq 0 ]; then
        status="PASS"
      elif [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
        status="BLOCKED"
      else
        status="FAIL"
      fi
      printf '  [%-7s] %s on %s\n' "$status" "$base" "$s"
      record_result "$base" deterministic "$s" "$status" "$rc"
    done
  done
  for f in "$DIR"/flows/*.md; do
    [ -e "$f" ] || continue
    spec_requires_agent_layer "$f" && continue
    base="$(basename "$f" .md)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "--- $base ---"
    for s in $(stores); do
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
      record_result "$base" deterministic "$s" "$status" "$rc"
    done
  done
fi

if [ "$LAYER" != "deterministic" ]; then
  echo "Layer A: ingesting agent results from $AGENT_RESULTS_DIR or building dispatch manifest (flows/*.md)"
  : > "$AGENT_QUEUE"
  for f in "$DIR"/flows/*.md; do
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
        IFS=$'\t' read -r status rc agent_verdict reason queue_required < <(agent_result_verdict "$base" "$s" "$result_file" "$oracle_mode")
        if [ "$status" = "PASS" ] || [ "$status" = "FAIL" ]; then
          printf '  [%-7s] %s on %s (%s)\n' "$status" "$base" "$s" "$reason"
          record_result "$base" agent "$s" "$status" "$rc" "$agent_verdict" "$result_file"
        else
          printf '  [%-7s] %s on %s (%s)\n' "BLOCKED" "$base" "$s" "$reason"
          record_result "$base" agent "$s" BLOCKED 3 "$agent_verdict" "$result_file"
          if [ "$queue_required" != "0" ]; then
            spec_queued=1
          fi
        fi
      else
        printf '  [%-7s] %s on %s (agent spec queued)\n' "BLOCKED" "$base" "$s"
        record_result "$base" agent "$s" BLOCKED 3
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

echo "Summary: $PASS_COUNT passed, $FAIL_COUNT failed, $BLOCKED_COUNT blocked, $QUEUED_AGENT_COUNT agent specs queued."
echo "== done. Evidence under $EVIDENCE_DIR =="

if [ "$FAIL_COUNT" -ne 0 ]; then
  exit 1
fi
if [ "$BLOCKED_COUNT" -ne 0 ]; then
  exit 3
fi
exit 0
