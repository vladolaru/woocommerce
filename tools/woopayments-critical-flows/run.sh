#!/usr/bin/env bash
# Orchestrator for the critical-flows parity suite.
# Runs the deterministic (Layer D) flow scripts, dispatches the agent-driven (Layer A)
# flow specs, and rolls up a verdict per flow per store into evidence/rollup.json.
#
# Usage:
#   ./run.sh --store both --layer all                # everything
#   ./run.sh --store target --flow SC-04             # one flow, native only
#   ./run.sh --layer deterministic                   # CI-able subset (Layer D scripts only)
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

STORE="both" LAYER="all" ONLY_FLOW="" AGENT_RESULTS_DIR="${AGENT_RESULTS_DIR:-}"
while [ $# -gt 0 ]; do case "$1" in
  --store) STORE="$2"; shift 2;; --layer) LAYER="$2"; shift 2;; --flow) ONLY_FLOW="$2"; shift 2;;
  --agent-results-dir) AGENT_RESULTS_DIR="$2"; shift 2;;
  *) echo "unknown arg: $1" >&2; exit 2;; esac; done

case "$STORE" in both|ref|target) ;; *) echo "unknown store: $STORE" >&2; exit 2;; esac
case "$LAYER" in all|deterministic|agent) ;; *) echo "unknown layer: $LAYER" >&2; exit 2;; esac

stores() { case "$STORE" in both) echo "ref target";; ref|target) echo "$STORE";; esac; }

AGENT_RESULTS_DIR="${AGENT_RESULTS_DIR:-$EVIDENCE_DIR/agent-results}"
RESULTS_JSONL="$EVIDENCE_DIR/rollup-results.jsonl"
ROLLUP_JSON="$EVIDENCE_DIR/rollup.json"
AGENT_QUEUE="$EVIDENCE_DIR/agent-queue.txt"
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
  local flow="$1" store="$2" result_file="$3"

  python3 - "$flow" "$store" "$result_file" <<'PY'
import json
import sys
from pathlib import Path

flow, store, result_file = sys.argv[1:]


def clean(value):
    return " ".join(str(value).split())


def emit(status, exit_code, verdict, reason):
    print("\t".join([status, str(exit_code), clean(verdict), clean(reason)]))


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

payload_flow = payload.get("flow")
if payload_flow and payload_flow != flow:
    emit("BLOCKED", 3, "BLOCKED", f"agent result flow mismatch: expected {flow}, got {payload_flow}")
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
if status == "FAIL":
    emit("FAIL", 1, verdict, f"agent verdict: {verdict}")
elif status == "BLOCKED":
    emit("BLOCKED", 3, verdict or "BLOCKED", f"unknown agent verdict: {verdict or '<missing>'}")
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
  python3 - "$ROLLUP_JSON" "$RESULTS_JSONL" "$STORE" "$LAYER" "$ONLY_FLOW" "$PASS_COUNT" "$FAIL_COUNT" "$BLOCKED_COUNT" "$QUEUED_AGENT_COUNT" "$AGENT_QUEUE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, results_jsonl, store, layer, only_flow, passed, failed, blocked, queued_agent, agent_queue = sys.argv[1:]
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
fi

if [ "$LAYER" != "deterministic" ]; then
  echo "Layer A: ingesting agent results from $AGENT_RESULTS_DIR or building dispatch manifest (flows/*.md)"
  : > "$AGENT_QUEUE"
  for f in "$DIR"/flows/*.md; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .md)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    result_file="$AGENT_RESULTS_DIR/$base.json"
    spec_queued=0
    for s in $(stores); do
      if [ -f "$result_file" ]; then
        IFS=$'\t' read -r status rc agent_verdict reason < <(agent_result_verdict "$base" "$s" "$result_file")
        if [ "$status" = "PASS" ] || [ "$status" = "FAIL" ]; then
          printf '  [%-7s] %s on %s (%s)\n' "$status" "$base" "$s" "$reason"
          record_result "$base" agent "$s" "$status" "$rc" "$agent_verdict" "$result_file"
        else
          printf '  [%-7s] %s on %s (%s)\n' "BLOCKED" "$base" "$s" "$reason"
          record_result "$base" agent "$s" BLOCKED 3
          spec_queued=1
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
    echo "  drive these with agent-specs/_template.md against both stores (manually or via a workflow)."
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
