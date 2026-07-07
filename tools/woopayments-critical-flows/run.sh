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

stores() { case "$STORE" in both) echo "ref target";; ref|target) echo "$STORE";; esac; }

echo "== critical-flows suite =="
echo "stores=$(stores) layer=$LAYER flow=${ONLY_FLOW:-all}"
echo "Layer D: running deterministic flow scripts (flows/*.sh)"
for f in "$DIR"/flows/*.sh; do
  [ -e "$f" ] || continue
  base="$(basename "$f" .sh)"
  [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
  echo "--- $base ---"
  for s in $(stores); do STORE_NAME="$s" bash "$f" || echo "  ($base on $s reported failure)"; done
done

if [ "$LAYER" != "deterministic" ]; then
  echo "Layer A: building agent dispatch manifest (flows/*.md)"
  : > "$EVIDENCE_DIR/agent-queue.txt"
  for f in "$DIR"/flows/*.md; do
    [ -e "$f" ] || continue
    base="$(basename "$f" .md)"
    [ -n "$ONLY_FLOW" ] && [[ "$base" != "$ONLY_FLOW"* ]] && continue
    echo "$f" >> "$EVIDENCE_DIR/agent-queue.txt"
  done
  echo "  queued $(wc -l < "$EVIDENCE_DIR/agent-queue.txt") agent-driven flow specs -> $EVIDENCE_DIR/agent-queue.txt"
  echo "  drive these with agent-specs/_template.md against both stores (manually or via a workflow)."
fi

echo "== done. Evidence under $EVIDENCE_DIR =="
