#!/usr/bin/env bash
# MA-01 — deterministic customer/admin WooPayments access-control parity.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:-}"
RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
DRIVER="${MA01_DRIVER:-$DIR/class-woopaymentscriticalflowsma01driver.php}"
EVIDENCE_TOOL="$DIR/ma01-evidence.py"
FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/MA-01-open-admin-as-non-admin"
REF_PROBE="$FLOW_EVIDENCE_DIR/ref-probe.json"
TARGET_PROBE="$FLOW_EVIDENCE_DIR/target-probe.json"
COMPARISON="$FLOW_EVIDENCE_DIR/comparison.json"
PROBE="$FLOW_EVIDENCE_DIR/$S-probe.json"
EXECUTION="$FLOW_EVIDENCE_DIR/$S-execution.json"
MANIFEST="$FLOW_EVIDENCE_DIR/$S-manifest.json"
REF_URL="${REF_URL:-http://localhost:8082}"
TARGET_URL="${TARGET_URL:-http://store8889.localhost:8889}"

if [ "$S" != "ref" ] && [ "$S" != "target" ]; then
  echo "[MA-01/${S:-unknown}] BLOCKED: store role must be ref or target."
  exit 3
fi
if [[ ! "$RUN_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]]; then
  echo "[MA-01/$S] BLOCKED: runner invocation stamp is unavailable."
  exit 3
fi
if [ "$RUN_SCOPE" != "partial" ] && [ "$RUN_SCOPE" != "full" ]; then
  echo "[MA-01/$S] BLOCKED: runner scope is unavailable."
  exit 3
fi
if [[ ! "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]]; then
  echo "[MA-01/$S] BLOCKED: runner evidence context is unavailable."
  exit 3
fi
if [ "${CRITICAL_FLOWS_FLOW_ID:-}" != "MA-01-open-admin-as-non-admin" ] \
  || [ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" != "clean-debug-log" ]; then
  echo "[MA-01/$S] BLOCKED: exact log evidence context is unavailable."
  exit 3
fi
if [ ! -f "$DRIVER" ] || [ ! -f "$EVIDENCE_TOOL" ]; then
  echo "[MA-01/$S] BLOCKED: deterministic dependency is missing."
  exit 3
fi

if [ "$REF_URL" != "http://localhost:8082" ] \
  || [ "$TARGET_URL" != "http://store8889.localhost:8889" ]; then
  echo "[MA-01/$S] BLOCKED: only the exact local browser origins are allowed."
  exit 3
fi
BASE_URL="$REF_URL"
if [ "$S" = "target" ]; then
  BASE_URL="$TARGET_URL"
fi

flow_evidence_path_is_safe() {
  case "$FLOW_EVIDENCE_DIR" in
    /*) ;;
    *) return 3 ;;
  esac
  case "$FLOW_EVIDENCE_DIR" in
    */../*|*/..|*/./*|*/.) return 3 ;;
  esac

  local component="$FLOW_EVIDENCE_DIR"
  while :; do
    [ ! -L "$component" ] || return 3
    [ "$component" != "/" ] || break
    component="${component%/*}"
    [ -n "$component" ] || component="/"
  done
}

if ! flow_evidence_path_is_safe; then
  echo "[MA-01/$S] BLOCKED: evidence archive path is unsafe."
  exit 3
fi
if ! mkdir -p -- "$FLOW_EVIDENCE_DIR"; then
  echo "[MA-01/$S] BLOCKED: evidence archive directory is unavailable."
  exit 3
fi
if ! flow_evidence_path_is_safe || [ ! -d "$FLOW_EVIDENCE_DIR" ] || [ ! -w "$FLOW_EVIDENCE_DIR" ]; then
  echo "[MA-01/$S] BLOCKED: evidence archive directory is unsafe or unwritable."
  exit 3
fi

write_normalized() {
  local value="$1" destination="$2"
  rm -f "$destination"
  [ -n "$value" ] || return 3
  printf '%s\n' "$value" > "$destination"
}

create_execution() {
  local status="$1" exit_code="$2" probe_rc="$3" comparison_rc="$4" log_rc="$5"
  shift 5
  local -a arguments=(
    execution
    --store "$S"
    --status "$status"
    --exit-code "$exit_code"
    --run-stamp "$RUN_STAMP"
    --output "$EXECUTION"
    --probe "$PROBE"
    --probe-exit-code "$probe_rc"
    --log-assertion-exit-code "$log_rc"
  )
  local source
  for source in "$@"; do
    arguments+=(--verdict-source "$source")
  done
  if [ "$S" = "target" ]; then
    arguments+=(--comparison "$COMPARISON" --comparison-exit-code "$comparison_rc")
  fi
  rm -f "$EXECUTION"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
}

create_manifest() {
  local status="$1" exit_code="$2"
  shift 2
  local -a arguments=(
    manifest
    --store "$S"
    --status "$status"
    --exit-code "$exit_code"
    --run-stamp "$RUN_STAMP"
    --run-scope "$RUN_SCOPE"
    --output "$MANIFEST"
  )
  local source file
  for source in "$@"; do
    arguments+=(--verdict-source "$source")
  done
  if [ "$S" = "ref" ]; then
    arguments+=(--file "$REF_PROBE" --file "$FLOW_EVIDENCE_DIR/ref-execution.json")
  else
    for file in "$REF_PROBE" "$TARGET_PROBE" "$COMPARISON" "$FLOW_EVIDENCE_DIR/target-execution.json"; do
      arguments+=(--file "$file")
    done
  fi
  rm -f "$MANIFEST"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
}

if [ "$S" = "ref" ]; then
  rm -f \
    "$REF_PROBE" "$TARGET_PROBE" "$COMPARISON" \
    "$FLOW_EVIDENCE_DIR/ref-execution.json" "$FLOW_EVIDENCE_DIR/target-execution.json" \
    "$FLOW_EVIDENCE_DIR/ref-manifest.json" "$FLOW_EVIDENCE_DIR/target-manifest.json"
else
  rm -f \
    "$TARGET_PROBE" "$COMPARISON" \
    "$FLOW_EVIDENCE_DIR/target-execution.json" "$FLOW_EVIDENCE_DIR/target-manifest.json"
fi

echo "[MA-01/$S] exercise: probe customer denial and administrator admission"
raw="$(wp_store "$S" --user=1 eval-file - "$S" probe "$RUN_STAMP" "$BASE_URL" < "$DRIVER" 2>&1)"
driver_rc=$?
if [ "$driver_rc" -ne 0 ]; then
  raw=""
fi
normalized="$(printf '%s\n' "$raw" | python3 "$EVIDENCE_TOOL" normalize-probe \
  --store "$S" --run-stamp "$RUN_STAMP" 2>/dev/null)"
probe_rc=$?
unset raw
if [ "$probe_rc" -ne 0 ] && [ "$probe_rc" -ne 1 ] && [ "$probe_rc" -ne 3 ]; then
  probe_rc=3
  normalized=""
fi
if ! write_normalized "$normalized" "$PROBE"; then
  echo "[MA-01/$S] BLOCKED: probe output could not be normalized."
  exit 3
fi
unset normalized

comparison_rc=0
if [ "$S" = "target" ]; then
  comparison_output="$(python3 "$EVIDENCE_TOOL" compare \
    --reference "$REF_PROBE" --target "$TARGET_PROBE" --run-stamp "$RUN_STAMP" 2>/dev/null)"
  comparison_rc=$?
  if [ "$comparison_rc" -ne 0 ] && [ "$comparison_rc" -ne 1 ] && [ "$comparison_rc" -ne 3 ]; then
    comparison_rc=3
    comparison_output=""
  fi
  if [ -n "$comparison_output" ] \
    && printf '%s\n' "$comparison_output" | python3 "$EVIDENCE_TOOL" validate-comparison \
      --run-stamp "$RUN_STAMP" --expected-exit-code "$comparison_rc"; then
    write_normalized "$comparison_output" "$COMPARISON" || comparison_rc=3
  else
    rm -f "$COMPARISON"
    comparison_rc=3
  fi
  unset comparison_output
  if [ "$comparison_rc" -eq 0 ]; then
    echo "[MA-01/$S] cross-store access-control parity: PASS"
  elif [ "$comparison_rc" -eq 1 ]; then
    echo "[MA-01/$S] cross-store access-control parity: FAIL"
  else
    echo "[MA-01/$S] cross-store access-control parity: BLOCKED"
  fi
fi

assert_log_clean "$S"
log_rc=$?

declare -a verdict_sources=()
if [ "$probe_rc" -eq 1 ] || [ "$comparison_rc" -eq 1 ] || [ "$log_rc" -eq 1 ]; then
  status="fail"
  verdict_rc=1
  [ "$probe_rc" -eq 1 ] && verdict_sources+=("probe_failed")
  [ "$S" = "target" ] && [ "$comparison_rc" -eq 1 ] && verdict_sources+=("comparison_failed")
  [ "$log_rc" -eq 1 ] && verdict_sources+=("log_assertion_failed")
elif [ "$probe_rc" -ne 0 ] || [ "$comparison_rc" -ne 0 ] || [ "$log_rc" -ne 0 ]; then
  status="blocked"
  verdict_rc=3
  [ "$probe_rc" -ne 0 ] && verdict_sources+=("probe_blocked")
  [ "$S" = "target" ] && [ "$comparison_rc" -ne 0 ] && verdict_sources+=("comparison_blocked")
  [ "$log_rc" -ne 0 ] && verdict_sources+=("log_assertion_blocked")
else
  status="pass"
  verdict_rc=0
fi

if ! create_execution "$status" "$verdict_rc" "$probe_rc" "$comparison_rc" "$log_rc" \
  ${verdict_sources[@]+"${verdict_sources[@]}"}; then
  rm -f "$EXECUTION" "$MANIFEST"
  echo "[MA-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if ! create_manifest "$status" "$verdict_rc" ${verdict_sources[@]+"${verdict_sources[@]}"}; then
  rm -f "$MANIFEST"
  echo "[MA-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi

if [ "$verdict_rc" -eq 1 ]; then
  echo "[MA-01/$S] deterministic verdict: FAIL"
  exit 1
fi
if [ "$verdict_rc" -ne 0 ]; then
  echo "[MA-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi
echo "[MA-01/$S] deterministic verdict: PASS"
exit 0
