#!/usr/bin/env bash
# Orchestrator for the critical-flows parity suite.
# Runs the deterministic (Layer D) flow scripts, dispatches the agent-driven (Layer A)
# flow specs, and rolls up a verdict per flow per store into evidence/rollup.json.
#
# Usage:
#   ./run.sh --store both --layer all                # everything
#   ./run.sh --store target --flow SC-04             # one flow, native only
#   ./run.sh --layer deterministic                   # CI-able subset (Layer D scripts only)
#
# Layer A flows are NOT executed by this script directly — they emit a dispatch manifest
# (evidence/agent-queue.json) that the supervisor/workflow consumes to drive browser agents
# using agent-specs/_template.md against both stores. This keeps the deterministic suite
# self-contained and CI-able while routing the judgment-heavy flows to agents.

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/lib/common.sh"

STORE="both" LAYER="all" ONLY_FLOW=""
while [ $# -gt 0 ]; do case "$1" in
  --store) STORE="$2"; shift 2;; --layer) LAYER="$2"; shift 2;; --flow) ONLY_FLOW="$2"; shift 2;;
  *) echo "unknown arg: $1" >&2; exit 2;; esac; done

case "$STORE" in both|ref|target) ;; *) echo "unknown store: $STORE" >&2; exit 2;; esac
case "$LAYER" in all|deterministic|agent) ;; *) echo "unknown layer: $LAYER" >&2; exit 2;; esac

stores() { case "$STORE" in both) echo "ref target";; ref|target) echo "$STORE";; esac; }

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

  python3 - "$RESULTS_JSONL" "$flow" "$layer" "$store" "$status" "$exit_code" <<'PY'
import json
import sys
from pathlib import Path

path, flow, layer, store, status, exit_code = sys.argv[1:]
payload = {
    "flow": flow,
    "layer": layer,
    "store": store,
    "status": status,
    "exit_code": int(exit_code),
}
with Path(path).open("a", encoding="utf-8") as stream:
    stream.write(json.dumps(payload, sort_keys=True) + "\n")
PY

  case "$status" in
    PASS) PASS_COUNT=$((PASS_COUNT + 1)) ;;
    FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
    BLOCKED) BLOCKED_COUNT=$((BLOCKED_COUNT + 1)) ;;
  esac
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
  echo "Layer A: building agent dispatch manifest (flows/*.md)"
  : > "$AGENT_QUEUE"
  for f in "$DIR"/flows/*.md; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .md)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "$f" >> "$AGENT_QUEUE"
    for s in $(stores); do
      printf '  [%-7s] %s on %s (agent spec queued)\n' "BLOCKED" "$base" "$s"
      record_result "$base" agent "$s" BLOCKED 3
    done
  done
  QUEUED_AGENT_COUNT="$(wc -l < "$AGENT_QUEUE" | tr -d ' ')"
  echo "  queued $QUEUED_AGENT_COUNT agent-driven flow specs -> $AGENT_QUEUE"
  echo "  drive these with agent-specs/_template.md against both stores (manually or via a workflow)."
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
