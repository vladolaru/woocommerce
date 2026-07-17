#!/usr/bin/env bash
# Shared helpers for the critical-flows parity suite.
# Store selection + WP-CLI wrappers + state assertions. Source this from flows/*.sh.
#
# Stores (dual-store oracle):
#   reference (:8082) = current WC + WooPayments extension (golden)
#   target    (:8889) = native WooPayments-in-core (this checkout)
#
# NOTE: the exact WP-CLI invocation per store depends on the local env wiring.
# Defaults below match the documented topology (target via wp-env, reference via its
# docker-compose container). Override with env vars if your setup differs.

set -uo pipefail

COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$COMMON_DIR/../../.." && pwd)}"
WC_DIR="${WC_DIR:-$REPO_ROOT/plugins/woocommerce}"
EVIDENCE_DIR="${EVIDENCE_DIR:-$REPO_ROOT/tools/woopayments-critical-flows/evidence}"
LOG_OBSERVER_DRIVER="$COMMON_DIR/../flows/class-woopaymentscriticalflowslogobserver.php"

CRITICAL_FLOWS_LOG_OBSERVER_PID="${CRITICAL_FLOWS_LOG_OBSERVER_PID:-}"
CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR="${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:-}"
CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE="${CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE:-${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:+$CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR.exit}}"
CRITICAL_FLOWS_LOG_OBSERVER_STORE="${CRITICAL_FLOWS_LOG_OBSERVER_STORE:-}"
CRITICAL_FLOWS_LOG_OBSERVER_ID="${CRITICAL_FLOWS_LOG_OBSERVER_ID:-}"
CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT="${CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT:-}"
CRITICAL_FLOWS_LOG_OBSERVER_TRAP_INSTALLED="${CRITICAL_FLOWS_LOG_OBSERVER_TRAP_INSTALLED:-0}"

# Reference store container (current WooPayments extension), :8082.
REF_CONTAINER="${REF_CONTAINER:-wcpay_wp_default}"
REF_WP_COMMAND="${REF_WP_COMMAND:-}"
# Target store: wp-env wrapper from the WC dir, :8889.
TARGET_WPENV_CWD="${TARGET_WPENV_CWD:-wp-content/plugins/woocommerce}"
TARGET_WP_COMMAND="${TARGET_WP_COMMAND:-}"

run_wp_command_string() {
  local command="$1"
  shift

  # Intentionally split the configured local WP runner string, matching the
  # verification harness's WP="docker exec ..." convention.
  # shellcheck disable=SC2086
  $command "$@"
}

# wp_target <wp-cli args...> : run WP-CLI against the native target store.
wp_target() {
  if [ -n "$TARGET_WP_COMMAND" ]; then
    run_wp_command_string "$TARGET_WP_COMMAND" "$@"
    return $?
  fi
  ( cd "$WC_DIR" && pnpm wp-env run --env-cwd="$TARGET_WPENV_CWD" cli wp "$@" )
}
# wp_ref <wp-cli args...> : run WP-CLI against the reference store.
wp_ref() {
  if [ -n "$REF_WP_COMMAND" ]; then
    run_wp_command_string "$REF_WP_COMMAND" "$@"
    return $?
  fi
  # -i is load-bearing: flow drivers feed PHP through stdin (wp eval-file -);
  # without it docker exec passes an empty stdin and the driver silently no-ops.
  docker exec -i -u www-data "$REF_CONTAINER" wp "$@"
}

# wp_store <ref|target> <args...> : dispatch by store name.
wp_store() { local s="$1"; shift; case "$s" in ref) wp_ref "$@";; target) wp_target "$@";; *) echo "unknown store: $s" >&2; return 2;; esac; }

critical_flows_wp_eval_with_context() { # <store> <php-source-without-opening-tag>
  local s="$1" source="$2" context_key="${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}"
  [[ "$context_key" =~ ^[0-9a-f]{64}$ ]] || return 3
  {
    printf '<?php\nputenv( "CRITICAL_FLOWS_RUN_CONTEXT_KEY=%s" );\n' "$context_key"
    printf '%s\n' "$source"
  } | wp_store "$s" eval-file -
}

critical_flows_log_observer_action() ( # <store> <observe|stop|recover>
  set +x
  local s="${1:-}" action="${2:-}" run_stamp first_line
  local -a pipe_status

  [ "$#" -eq 2 ] || return 3
  case "$s" in
    ref|target) ;;
    *) return 3 ;;
  esac
  case "$action" in
    observe|stop|recover) ;;
    *) return 3 ;;
  esac
  [[ "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]] || return 3
  [[ "${CRITICAL_FLOWS_FLOW_ID:-}" =~ ^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(-[a-z0-9]+)*$ ]] || return 3
  [ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" = "clean-debug-log" ] || return 3
  [ -f "$LOG_OBSERVER_DRIVER" ] || return 3
  IFS= read -r first_line < "$LOG_OBSERVER_DRIVER" || return 3
  [ "$first_line" = '<?php' ] || return 3
  run_stamp="${CRITICAL_FLOWS_RUN_STAMP:-${RUN_STAMP:-}}"

  {
	printf '<?php\nputenv( "CRITICAL_FLOWS_RUN_CONTEXT_KEY=%s" );\n' \
	  "$CRITICAL_FLOWS_RUN_CONTEXT_KEY"
	printf 'putenv( "CRITICAL_FLOWS_STORE=%s" );\n' "$s"
	printf 'putenv( "CRITICAL_FLOWS_FLOW_ID=%s" );\n' "$CRITICAL_FLOWS_FLOW_ID"
	printf 'putenv( "CRITICAL_FLOWS_LOG_PURPOSE=%s" );\n' "$CRITICAL_FLOWS_LOG_PURPOSE"
    tail -n +2 "$LOG_OBSERVER_DRIVER"
  } | wp_store "$s" eval-file - "$run_stamp" "$action" 2>/dev/null
  pipe_status=( "${PIPESTATUS[@]}" )
  [ "${pipe_status[0]}" -eq 0 ] && [ "${pipe_status[1]}" -eq 0 ] || return 3
)

critical_flows_log_observer_clear_state() {
  local sidecar="${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:-}"
  if [ -n "$sidecar" ]; then
    rm -f "$sidecar" "$sidecar.exit" "$sidecar.pid" "$sidecar.supervisor" 2>/dev/null || true
  fi
  CRITICAL_FLOWS_LOG_OBSERVER_PID=""
  CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR=""
  CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE=""
  CRITICAL_FLOWS_LOG_OBSERVER_STORE=""
  CRITICAL_FLOWS_LOG_OBSERVER_ID=""
  CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT=""
  export CRITICAL_FLOWS_LOG_OBSERVER_PID CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR
  export CRITICAL_FLOWS_LOG_OBSERVER_ID CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT
}

critical_flows_log_observer_wait_for_exit() { # <timeout-seconds>
  python3 - "${CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE:-}" "$1" <<'PY'
import sys
import time
from pathlib import Path

path = Path(sys.argv[1]) if sys.argv[1] else None
deadline = time.monotonic() + max(0.1, float(sys.argv[2]))
while time.monotonic() < deadline:
    if path is not None and path.is_file() and path.read_text(encoding="utf-8").strip():
        raise SystemExit(0)
    time.sleep(0.02)
raise SystemExit(1)
PY
}

critical_flows_log_observer_signal_tree() { # <pid> <TERM|KILL>
  python3 - "$1" "$2" <<'PY'
import os
import signal
import subprocess
import sys

root = int(sys.argv[1])
signum = {"TERM": signal.SIGTERM, "KILL": signal.SIGKILL}[sys.argv[2]]
try:
    listing = subprocess.run(
        ["ps", "ax", "-o", "pid=,ppid="],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
except (OSError, subprocess.SubprocessError):
    listing = ""

children = {}
for line in listing.splitlines():
    fields = line.split()
    if len(fields) != 2 or not all(field.isdigit() for field in fields):
        continue
    pid, parent = map(int, fields)
    children.setdefault(parent, []).append(pid)

ordered = []
pending = list(children.get(root, ()))
while pending:
    pid = pending.pop()
    ordered.append(pid)
    pending.extend(children.get(pid, ()))
for pid in reversed(ordered):
    try:
        os.kill(pid, signum)
    except (ProcessLookupError, PermissionError):
        pass
try:
    os.kill(root, signum)
except (ProcessLookupError, PermissionError):
    pass
PY
}

critical_flows_log_observer_job_is_owned() { # <pid>
  local expected_pid="${1:-}" job_pid
  [[ "$expected_pid" =~ ^[1-9][0-9]*$ ]] || return 1
  while IFS= read -r job_pid; do
    [ "$job_pid" = "$expected_pid" ] && return 0
  done < <(jobs -pr 2>/dev/null)
  return 1
}

critical_flows_log_observer_recover() {
  local s="${CRITICAL_FLOWS_LOG_OBSERVER_STORE:-}"
  local recovery_pid
  [ -n "$s" ] || return 0
  [ -f "$LOG_OBSERVER_DRIVER" ] || return 0
  critical_flows_log_observer_action "$s" recover >/dev/null 2>/dev/null &
  recovery_pid=$!
  if ! python3 - "$recovery_pid" <<'PY'
import os
import sys
import time

pid = int(sys.argv[1])
deadline = time.monotonic() + 2
while time.monotonic() < deadline:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        raise SystemExit(0)
    except PermissionError:
        raise SystemExit(1)
    time.sleep(0.02)
raise SystemExit(1)
PY
  then
    critical_flows_log_observer_signal_tree "$recovery_pid" TERM
    python3 - "$recovery_pid" <<'PY'
import os
import sys
import time

pid = int(sys.argv[1])
deadline = time.monotonic() + 1
while time.monotonic() < deadline:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        raise SystemExit(0)
    time.sleep(0.02)
raise SystemExit(1)
PY
    if [ "$?" -ne 0 ]; then
      critical_flows_log_observer_signal_tree "$recovery_pid" KILL
    fi
  fi
  wait "$recovery_pid" 2>/dev/null || true
}

critical_flows_log_observer_cleanup() {
  local pid="${CRITICAL_FLOWS_LOG_OBSERVER_PID:-}"
  [ -n "$pid" ] || { critical_flows_log_observer_clear_state; return 0; }

  if critical_flows_log_observer_job_is_owned "$pid"; then
    critical_flows_log_observer_signal_tree "$pid" TERM
    critical_flows_log_observer_wait_for_exit 2 >/dev/null 2>&1 || {
      if critical_flows_log_observer_job_is_owned "$pid"; then
        critical_flows_log_observer_signal_tree "$pid" KILL
      fi
    }
  fi
  wait "$pid" 2>/dev/null || true
  critical_flows_log_observer_recover
  critical_flows_log_observer_clear_state
}

critical_flows_log_observer_install_trap() {
  [ "${CRITICAL_FLOWS_LOG_OBSERVER_TRAP_INSTALLED:-0}" = "1" ] && return 0
  CRITICAL_FLOWS_LOG_OBSERVER_TRAP_INSTALLED=1
  trap 'critical_flows_log_observer_cleanup' EXIT
  trap 'critical_flows_log_observer_cleanup; exit 130' INT
  trap 'critical_flows_log_observer_cleanup; exit 143' TERM
}

critical_flows_log_observer_wait_for_ready() { # <timeout-seconds>
  python3 - \
    "${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:-}" \
    "${CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE:-}" \
    "$1" \
    "${CRITICAL_FLOWS_LOG_OBSERVER_STORE:-}" \
    "${CRITICAL_FLOWS_FLOW_ID:-}" \
    "${CRITICAL_FLOWS_LOG_PURPOSE:-}" <<'PY'
import json
import re
import sys
import time
from pathlib import Path

sidecar = Path(sys.argv[1])
exit_file = Path(sys.argv[2])
deadline = time.monotonic() + max(0.1, float(sys.argv[3]))
expected_store, expected_flow_id, expected_purpose = sys.argv[4:]
offset = 0
while time.monotonic() < deadline:
    try:
        with sidecar.open("r", encoding="utf-8") as stream:
            stream.seek(offset)
            while True:
                encoded = stream.readline()
                if not encoded:
                    break
                offset = stream.tell()
                try:
                    record = json.loads(encoded)
                except (UnicodeError, json.JSONDecodeError):
                    continue
                if not isinstance(record, dict):
                    continue
                if record.get("kind") == "blocked":
                    raise SystemExit(2)
                if record.get("kind") != "ready":
                    continue
                observer_id = record.get("observer_id")
                key_fingerprint = record.get("key_fingerprint")
                path_contexts = record.get("paths")
                if (
                    not isinstance(observer_id, str)
                    or re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", observer_id) is None
                    or not isinstance(key_fingerprint, str)
                    or re.fullmatch(r"sha256:[0-9a-f]{64}", key_fingerprint) is None
                    or record.get("store") != expected_store
                    or record.get("flow_id") != expected_flow_id
                    or record.get("purpose") != expected_purpose
                    or not isinstance(record.get("marker_created_at"), str)
                    or not isinstance(path_contexts, list)
                    or not path_contexts
                    or path_contexts != sorted(path_contexts, key=lambda item: item.get("path_id", "") if isinstance(item, dict) else "")
                    or any(
                        not isinstance(item, dict)
                        or set(item) != {"path", "path_id"}
                        or not isinstance(item["path"], str)
                        or Path(item["path"]).name != item["path"]
                        or re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", item["path_id"]) is None
                        for item in path_contexts
                    )
                ):
                    raise SystemExit(2)
                print(f"{observer_id}\t{key_fingerprint}")
                raise SystemExit(0)
    except (OSError, UnicodeError):
        pass
    try:
        if exit_file.is_file() and exit_file.read_text(encoding="utf-8").strip():
            raise SystemExit(2)
    except (OSError, UnicodeError):
        raise SystemExit(2)
    time.sleep(0.02)
raise SystemExit(1)
PY
}

critical_flows_log_observer_start() { # <store>
  local s="$1" ready_timeout sidecar exit_file ready_record rc
  ready_timeout="${CRITICAL_FLOWS_LOG_OBSERVER_READY_TIMEOUT:-5}"

  case "$ready_timeout" in
    ''|*[!0-9]*) ready_timeout=5 ;;
  esac
  [ "$ready_timeout" -ge 1 ] 2>/dev/null || ready_timeout=1
  [ "$ready_timeout" -le 30 ] 2>/dev/null || ready_timeout=30
  [ -f "$LOG_OBSERVER_DRIVER" ] || return 1
  case "${TMPDIR:-}" in
    /*) ;;
    *) return 1 ;;
  esac

  mkdir -p "$TMPDIR" || return 1
  sidecar="$(mktemp "$TMPDIR/woopayments-critical-flows-observer-sidecar.XXXXXX")" || return 1
  exit_file="$sidecar.exit"
  chmod 600 "$sidecar"

  CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR="$sidecar"
  CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE="$exit_file"
  CRITICAL_FLOWS_LOG_OBSERVER_STORE="$s"
  export CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR
  critical_flows_log_observer_install_trap

  (
    set +e
    critical_flows_log_observer_action "$s" observe > "$sidecar" 2>/dev/null
    observer_rc=$?
    printf '%s\n' "$observer_rc" > "$exit_file"
  ) &
  CRITICAL_FLOWS_LOG_OBSERVER_PID=$!
  export CRITICAL_FLOWS_LOG_OBSERVER_PID

  ready_record="$(critical_flows_log_observer_wait_for_ready "$ready_timeout")"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    critical_flows_log_observer_cleanup
    return 1
  fi

  IFS=$'\t' read -r CRITICAL_FLOWS_LOG_OBSERVER_ID CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT <<< "$ready_record"
  export CRITICAL_FLOWS_LOG_OBSERVER_ID CRITICAL_FLOWS_LOG_OBSERVER_KEY_FINGERPRINT
  return 0
}

critical_flows_log_observer_validate_sidecar() {
  python3 - \
    "${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:-}" \
    "${CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE:-}" \
    "${CRITICAL_FLOWS_RUN_STAMP:-${RUN_STAMP:-}}" \
    "${CRITICAL_FLOWS_LOG_OBSERVER_ID:-}" \
    "${CRITICAL_FLOWS_LOG_OBSERVER_STORE:-}" \
    "${CRITICAL_FLOWS_FLOW_ID:-}" \
    "${CRITICAL_FLOWS_LOG_PURPOSE:-}" <<'PY'
import datetime
import hashlib
import hmac
import json
import os
import re
import sys
from collections import Counter
from pathlib import Path

sidecar_path, exit_path, run_stamp, observer_id, store, flow_id, purpose = sys.argv[1:]
key_hex = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
if re.fullmatch(r"[0-9a-f]{64}", key_hex) is None:
    raise SystemExit(1)
key = bytes.fromhex(key_hex)

try:
    sidecar = Path(sidecar_path)
    if sidecar.stat().st_size > 1024 * 1024:
        raise SystemExit(1)
    exit_code = Path(exit_path).read_text(encoding="utf-8").strip()
    encoded_records = sidecar.read_text(encoding="utf-8").splitlines()
except (OSError, UnicodeError):
    raise SystemExit(1)
if exit_code != "0" or not 2 <= len(encoded_records) <= 1000:
    raise SystemExit(1)

previous = "0" * 64
ready = None
complete = None
categories = Counter()
line_count = 0
terminal_count = 0
retained_records = []
path_contexts = None
marker_created_at = None
common_fields = {
    "schema", "sequence", "kind", "run_stamp", "store", "flow_id", "purpose",
    "marker_created_at", "observer_id", "paths", "previous_hmac", "hmac",
}
for sequence, encoded in enumerate(encoded_records, start=1):
    try:
        record = json.loads(encoded)
    except (TypeError, json.JSONDecodeError):
        raise SystemExit(1)
    if not isinstance(record, dict) or record.get("hmac") is None:
        raise SystemExit(1)
    retained_record = dict(record)
    signature = record.pop("hmac")
    kind = record.get("kind")
    expected_fields = {
        "ready": common_fields | {"status", "path_count", "origin_binding", "key_fingerprint"},
        "line": common_fields | {"path", "line", "category", "fingerprint"},
        "complete": common_fields | {"status"},
    }.get(kind)
    canonical = json.dumps(record, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    expected = hmac.new(
        key,
        (previous + "\0" + canonical).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    if (
        expected_fields is None
        or set(retained_record) != expected_fields
        or record.get("schema") != "woopayments_debug_log_observer_record.v2"
        or record.get("sequence") != sequence
        or record.get("run_stamp") != run_stamp
        or record.get("store") != store
        or record.get("flow_id") != flow_id
        or record.get("purpose") != purpose
        or record.get("observer_id") != observer_id
        or record.get("previous_hmac") != "hmac-sha256:" + previous
        or signature != "hmac-sha256:" + expected
    ):
        raise SystemExit(1)
    record_paths = record.get("paths")
    if (
        not isinstance(record.get("marker_created_at"), str)
        or not isinstance(record_paths, list)
        or not record_paths
        or record_paths != sorted(record_paths, key=lambda item: item.get("path_id", "") if isinstance(item, dict) else "")
        or any(
            not isinstance(item, dict)
            or set(item) != {"path", "path_id"}
            or not isinstance(item["path"], str)
            or Path(item["path"]).name != item["path"]
            or not isinstance(item["path_id"], str)
            or re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", item["path_id"]) is None
            for item in record_paths
        )
    ):
        raise SystemExit(1)
    try:
        datetime.datetime.fromisoformat(record["marker_created_at"].replace("Z", "+00:00"))
    except ValueError:
        raise SystemExit(1)
    if path_contexts is None:
        path_contexts = record_paths
        marker_created_at = record["marker_created_at"]
    elif record_paths != path_contexts or record["marker_created_at"] != marker_created_at:
        raise SystemExit(1)
    previous = expected
    retained_records.append(retained_record)
    if kind == "ready":
        if ready is not None or sequence != 1 or record.get("status") != "pass":
            raise SystemExit(1)
        ready = record
    elif kind == "line":
        category = record.get("category")
        if category not in {
            "allowlisted_noise",
            "deprecated",
            "fatal_error",
            "notice",
            "other",
            "parse_error",
            "strict_standards",
            "terminal",
            "warning",
        }:
            raise SystemExit(1)
        if (
            not isinstance(record.get("path"), str)
            or Path(record["path"]).name != record["path"]
            or not isinstance(record.get("line"), int)
            or isinstance(record.get("line"), bool)
            or record["line"] < 1
            or not isinstance(record.get("fingerprint"), str)
            or re.fullmatch(r"sha256:[0-9a-f]{64}", record["fingerprint"]) is None
        ):
            raise SystemExit(1)
        categories[category] += 1
        line_count += 1
        terminal_count += int(category == "terminal")
    elif kind == "complete":
        if complete is not None or record.get("status") != "pass":
            raise SystemExit(1)
        complete = record
    else:
        raise SystemExit(1)

if (
    ready is None
    or complete is None
    or retained_records[-1].get("kind") != "complete"
    or not isinstance(ready.get("path_count"), int)
    or ready["path_count"] < 1
    or terminal_count != ready["path_count"]
    or ready.get("key_fingerprint") != "sha256:" + hashlib.sha256(key).hexdigest()
    or sum(categories.get(category, 0) for category in {
        "deprecated", "fatal_error", "notice", "parse_error", "strict_standards", "warning",
    }) > 20
    or categories.get("allowlisted_noise", 0) > 100
):
    raise SystemExit(1)

summary = {
    "schema": "woopayments_debug_log_observer_summary.v2",
    "store": store,
    "flow_id": flow_id,
    "purpose": purpose,
    "marker_created_at": marker_created_at,
    "observer_id": observer_id,
    "paths": path_contexts,
    "key_fingerprint": ready["key_fingerprint"],
    "origin_binding": ready.get("origin_binding"),
    "records": retained_records,
    "record_count": len(encoded_records),
    "line_event_count": line_count,
    "category_counts": dict(sorted(categories.items())),
    "chain_head": "hmac-sha256:" + previous,
}
print(json.dumps(summary, separators=(",", ":"), sort_keys=True))
PY
}

critical_flows_log_observer_finish() { # <store>
  local s="$1" timeout rc summary pid
  timeout="${CRITICAL_FLOWS_LOG_OBSERVER_STOP_TIMEOUT:-5}"
  pid="${CRITICAL_FLOWS_LOG_OBSERVER_PID:-}"

  [ -n "$pid" ] || return 1
  CRITICAL_FLOWS_LOG_OBSERVER_STORE="$s"
  CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE="${CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE:-${CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR:-}.exit}"
  case "$timeout" in
    ''|*[!0-9]*) timeout=5 ;;
  esac
  [ "$timeout" -ge 1 ] 2>/dev/null || timeout=1
  [ "$timeout" -le 30 ] 2>/dev/null || timeout=30

  if critical_flows_log_observer_action "$s" stop >/dev/null 2>/dev/null; then
    rc=0
  else
    rc=$?
  fi
  if [ "$rc" -ne 0 ] || ! critical_flows_log_observer_wait_for_exit "$timeout"; then
    critical_flows_log_observer_cleanup
    return 1
  fi

  summary="$(critical_flows_log_observer_validate_sidecar)"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    critical_flows_log_observer_cleanup
    return 1
  fi
  CRITICAL_FLOWS_LOG_OBSERVER_SUMMARY="$summary"
  export CRITICAL_FLOWS_LOG_OBSERVER_SUMMARY

  wait "$pid" 2>/dev/null || true
  critical_flows_log_observer_clear_state
  return 0
}

# ---- assertions (Layer D) -------------------------------------------------
# Each prints PASS/FAIL and returns 0/1; capture for the verdict rollup.

wp_php_literal() { # <value>
  php -r 'echo var_export($argv[1], true);' "$1"
}

mark_log_clean_start() { # <store>
  local s raw rc run_stamp_literal store_literal flow_literal purpose_literal
  s="$1"
  case "$s" in
    ref|target) ;;
    *) echo "BLOCKED log-clean marker for $s: marker_command_failed"; return 3 ;;
  esac
  if [[ ! "${CRITICAL_FLOWS_FLOW_ID:-}" =~ ^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(-[a-z0-9]+)*$ ]] \
    || [ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" != "clean-debug-log" ]; then
    echo "BLOCKED log-clean marker for $s: marker_command_failed"
    return 3
  fi
  run_stamp_literal="$(wp_php_literal "${CRITICAL_FLOWS_RUN_STAMP:-${RUN_STAMP:-}}")"
  store_literal="$(wp_php_literal "$s")"
  flow_literal="$(wp_php_literal "$CRITICAL_FLOWS_FLOW_ID")"
  purpose_literal="$(wp_php_literal "$CRITICAL_FLOWS_LOG_PURPOSE")"
  if [ -z "$run_stamp_literal" ] || [ -z "$store_literal" ] || [ -z "$flow_literal" ] || [ -z "$purpose_literal" ]; then
    echo "BLOCKED log-clean marker for $s: marker_command_failed"
    return 3
  fi
  raw="$(critical_flows_wp_eval_with_context "$s" '
$run_stamp = '"$run_stamp_literal"';
$store = '"$store_literal"';
$flow_id = '"$flow_literal"';
$purpose = '"$purpose_literal"';
$paths = array();
if ( defined( "WP_DEBUG_LOG" ) && is_string( WP_DEBUG_LOG ) && "" !== WP_DEBUG_LOG && "1" !== WP_DEBUG_LOG ) {
	$paths[] = WP_DEBUG_LOG;
}
if ( defined( "WP_CONTENT_DIR" ) ) {
	$paths[] = WP_CONTENT_DIR . "/debug.log";
}
$paths   = array_values( array_unique( array_filter( $paths ) ) );
$markers = array();
if ( ! is_string( $run_stamp ) || ! preg_match( "/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/", $run_stamp ) ) {
	WP_CLI::error( "The debug.log marker run stamp is invalid." );
}
if ( ! in_array( $store, array( "ref", "target" ), true ) ) {
	WP_CLI::error( "The debug.log marker store is invalid." );
}
if ( ! is_string( $flow_id ) || ! preg_match( "/^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/", $flow_id ) ) {
	WP_CLI::error( "The debug.log marker flow ID is invalid." );
}
if ( "clean-debug-log" !== $purpose ) {
	WP_CLI::error( "The debug.log marker purpose is invalid." );
}
$context_key_hex = getenv( "CRITICAL_FLOWS_RUN_CONTEXT_KEY" );
if ( ! is_string( $context_key_hex ) || ! preg_match( "/^[0-9a-f]{64}$/", $context_key_hex ) ) {
	WP_CLI::error( "The debug.log marker context key is invalid." );
}
$context_key = hex2bin( $context_key_hex );
if ( false === $context_key || 32 !== strlen( $context_key ) ) {
	WP_CLI::error( "The debug.log marker context key is invalid." );
}
$origin_nonce   = wp_generate_uuid4();
$observer_id    = wp_generate_uuid4();
$marker_created_at = gmdate( "c" );
$seen_basenames = array();
$seen_path_ids  = array();

foreach ( $paths as $path ) {
	if ( ! is_string( $path ) || "" === $path ) {
		continue;
	}
	if ( file_exists( $path ) ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			WP_CLI::error( "A configured debug.log path cannot receive a canary." );
		}
	} elseif ( ! is_dir( dirname( $path ) ) || ! is_writable( dirname( $path ) ) ) {
		WP_CLI::error( "A configured debug.log path cannot be created for a canary." );
	}
	$basename = basename( $path );
	if ( "" === $basename || isset( $seen_basenames[ $basename ] ) ) {
		WP_CLI::error( "Configured debug.log paths must have unique basenames." );
	}
	$seen_basenames[ $basename ] = true;

	$canary_line = "[woopayments-critical-flows-log-canary] sha256:" . hash( "sha256", $run_stamp . "\0" . wp_generate_uuid4() );
	$canary_bytes = $canary_line . PHP_EOL;
	$written      = @file_put_contents( $path, $canary_bytes, FILE_APPEND | LOCK_EX );
	if ( false === $written || strlen( $canary_bytes ) !== $written ) {
		WP_CLI::error( "A configured debug.log path could not receive a canary." );
	}

	clearstatcache( true, $path );
	$stat = @stat( $path );
	if (
		false === $stat
		|| ! isset( $stat["dev"], $stat["ino"], $stat["size"], $stat["uid"], $stat["gid"], $stat["mode"] )
		|| ! is_int( $stat["size"] )
		|| $stat["size"] < strlen( $canary_bytes )
	) {
		WP_CLI::error( "A configured debug.log identity could not be marked." );
	}
	$stream = @fopen( $path, "rb" );
	if ( false === $stream || 0 !== fseek( $stream, $stat["size"] - strlen( $canary_bytes ) ) ) {
		is_resource( $stream ) && fclose( $stream );
		WP_CLI::error( "A configured debug.log path could not be marked." );
	}
	$observed_canary_bytes = fread( $stream, strlen( $canary_bytes ) );
	if ( $observed_canary_bytes !== $canary_bytes || 0 !== fseek( $stream, 0 ) ) {
		fclose( $stream );
		WP_CLI::error( "A configured debug.log path could not be marked." );
	}
	$line_count = 0;
	while ( false !== fgets( $stream ) ) {
		++$line_count;
	}
	fclose( $stream );
	$prefix_fingerprint = @hash_file( "sha256", $path );
	if ( ! is_string( $prefix_fingerprint ) || 64 !== strlen( $prefix_fingerprint ) || $line_count < 1 ) {
		WP_CLI::error( "A configured debug.log path could not be hashed." );
	}
	$canonical_path = realpath( $path );
	if ( ! is_string( $canonical_path ) || "" === $canonical_path || DIRECTORY_SEPARATOR !== $canonical_path[0] || false !== strpos( $canonical_path, "\0" ) ) {
		WP_CLI::error( "A configured debug.log path could not be canonicalized." );
	}
	$path_id = "hmac-sha256:" . hash_hmac( "sha256", "woopayments_debug_log_path.v1\0" . $canonical_path, $context_key );
	if ( isset( $seen_path_ids[ $path_id ] ) ) {
		WP_CLI::error( "Configured debug.log paths must have unique identities." );
	}
	$seen_path_ids[ $path_id ] = true;

	$markers[ $canonical_path ] = array(
		"path_id"              => $path_id,
		"line_count"           => $line_count,
		"byte_count"           => $stat["size"],
		"identity_fingerprint" => "sha256:" . hash( "sha256", $stat["dev"] . ":" . $stat["ino"] ),
		"prefix_fingerprint"   => "sha256:" . $prefix_fingerprint,
		"canary_fingerprint"   => "sha256:" . hash( "sha256", $canary_line ),
		"owner"                 => $stat["uid"],
		"group"                 => $stat["gid"],
		"mode"                  => $stat["mode"] & 0777,
	);
}

$key_fingerprint = "sha256:" . hash( "sha256", $context_key );
$origin_fields   = array(
	"woopayments_debug_log_origin.v2",
	$run_stamp,
	$store,
	$flow_id,
	$purpose,
	$marker_created_at,
	$origin_nonce,
	$observer_id,
	$key_fingerprint,
);
$origin_paths = $markers;
uasort(
	$origin_paths,
	static function ( $left, $right ) {
		return strcmp( $left["path_id"], $right["path_id"] );
	}
);
foreach ( $origin_paths as $origin_path => $origin_observation ) {
	$origin_fields[] = $origin_observation["path_id"];
	$origin_fields[] = basename( $origin_path );
	$origin_fields[] = (string) $origin_observation["line_count"];
	$origin_fields[] = (string) $origin_observation["byte_count"];
	$origin_fields[] = $origin_observation["identity_fingerprint"];
	$origin_fields[] = $origin_observation["prefix_fingerprint"];
	$origin_fields[] = $origin_observation["canary_fingerprint"];
	$origin_fields[] = (string) $origin_observation["owner"];
	$origin_fields[] = (string) $origin_observation["group"];
	$origin_fields[] = (string) $origin_observation["mode"];
}
$origin_binding = "hmac-sha256:" . hash_hmac( "sha256", implode( "\0", $origin_fields ), $context_key );

update_option(
	"woopayments_critical_flows_debug_log_marker",
	array(
		"schema"          => "woopayments_debug_log_marker.v6",
		"created_at"      => $marker_created_at,
		"run_stamp"       => $run_stamp,
		"store"           => $store,
		"flow_id"         => $flow_id,
		"purpose"         => $purpose,
		"origin_nonce"    => $origin_nonce,
		"observer_id"     => $observer_id,
		"key_fingerprint" => $key_fingerprint,
		"origin_binding"  => $origin_binding,
		"paths"           => $markers,
	),
	false
);

WP_CLI::line(
	wp_json_encode(
		array(
			"status"          => "pass",
			"run_stamp"       => $run_stamp,
			"store"           => $store,
			"flow_id"         => $flow_id,
			"purpose"         => $purpose,
			"marker_created_at" => $marker_created_at,
			"origin_nonce"    => $origin_nonce,
			"observer_id"     => $observer_id,
			"key_fingerprint" => $key_fingerprint,
			"origin_binding"  => $origin_binding,
			"paths"           => $paths,
			"markers"         => $markers,
		)
	)
);
' 2>&1)"
  rc=$?

  if [ "$rc" -ne 0 ]; then
    echo "BLOCKED log-clean marker for $s: marker_command_failed"
    return 3
  fi

  if [ -n "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" ]; then
    if ! critical_flows_log_observer_start "$s"; then
      echo "BLOCKED log-clean marker for $s: observer_readiness_timeout"
      return 3
    fi
  fi

  echo "[$s] log-clean marker recorded"
  return 0
}

assert_order_status() { # <store> <order_id> <expected_status>
  local s="$1" id="$2" want="$3" raw rc got

  case "$id" in
    ''|*[!0-9]*)
      echo "BLOCKED order #$id status probe: invalid order id"
      return 3
      ;;
  esac

  # wp eval instead of `wc shop_order get`: the WC REST-backed CLI requires --user
  # and returns a 401 otherwise, which (with stderr silenced) read as an empty
  # status and reported a false product FAIL. A probe failure must be BLOCKED,
  # never a product verdict.
  raw="$(wp_store "$s" eval "
\$order = wc_get_order( $id );
if ( \$order ) {
	WP_CLI::line( 'order_status=' . \$order->get_status() );
} else {
	WP_CLI::line( 'order_status_probe=order_not_found' );
}
" 2>&1)"
  rc=$?
  got="$(printf '%s\n' "$raw" | sed -n 's/^order_status=//p' | tail -1)"

  if [ "$rc" -ne 0 ] || { [ -z "$got" ] && ! printf '%s\n' "$raw" | grep -q '^order_status_probe=order_not_found$'; }; then
    echo "BLOCKED order #$id status probe failed on $s (not a product verdict):"
    printf '%s\n' "$raw" | tail -5 | sed 's/^/    /'
    return 3
  fi
  if [ -z "$got" ]; then
    echo "FAIL order #$id status: order not found on $s"
    return 1
  fi
  [ "$got" = "$want" ] && { echo "PASS order #$id status=$got"; return 0; }
  echo "FAIL order #$id status=$got want=$want"; return 1
}

assert_order_meta_present() { # <store> <order_id> <meta_key>  (Bucket-E key must exist + non-empty)
  local s="$1" id="$2" key="$3" key_literal script val

  case "$id" in
    ''|*[!0-9]*)
      echo "FAIL order #$id $key missing/empty"
      return 1
      ;;
  esac

  key_literal="$(wp_php_literal "$key")"
  # The value is emitted behind an anchored marker: the target's wp-env/pnpm wrapper
  # prints banner lines to STDOUT, so a bare "is output non-empty" check could never
  # fail on the target (the banner alone satisfied it) - a vacuous PASS.
  script="$(cat <<PHP
\$order = wc_get_order( $id );
if ( ! \$order ) {
	WP_CLI::line( 'order_meta_probe=order_not_found' );
} else {
	\$value = \$order->get_meta( $key_literal, true );
	if ( is_array( \$value ) || is_object( \$value ) ) {
		\$value = wp_json_encode( \$value );
	}
	if ( null !== \$value && "" !== (string) \$value ) {
		WP_CLI::line( 'order_meta_value=' . (string) \$value );
	} else {
		WP_CLI::line( 'order_meta_probe=missing' );
	}
}
PHP
)"
  # Keep stderr separate from the value: a failed probe (auth, fatal, container
  # down) must surface as BLOCKED with its diagnostics, not read as "meta empty".
  local raw rc
  raw="$(wp_store "$s" eval "$script" 2>&1)"
  rc=$?
  val="$(printf '%s\n' "$raw" | sed -n 's/^order_meta_value=//p' | tail -1)"

  if [ "$rc" -ne 0 ] || { [ -z "$val" ] && ! printf '%s\n' "$raw" | grep -q '^order_meta_probe='; }; then
    echo "BLOCKED order #$id $key probe failed on $s (not a product verdict):"
    printf '%s\n' "$raw" | tail -5 | sed 's/^/    /'
    return 3
  fi
  [ -n "$val" ] && { echo "PASS order #$id $key=$val"; return 0; }
  echo "FAIL order #$id $key missing/empty"; return 1
}

assert_log_clean() { # <store>  (no PHP notice/warning/fatal/deprecation since marker)
  local s raw rc evidence_file store_literal flow_literal purpose_literal
  s="$1"
  evidence_file="${LOG_SCAN_EVIDENCE_FILE:-}"
  case "$s" in
    ref|target) ;;
    *) echo "BLOCKED log-clean check for $s: invalid_scan_evidence"; return 3 ;;
  esac
  if [[ ! "${CRITICAL_FLOWS_FLOW_ID:-}" =~ ^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(-[a-z0-9]+)*$ ]] \
    || [ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" != "clean-debug-log" ]; then
    echo "BLOCKED log-clean check for $s: invalid_scan_evidence"
    return 3
  fi
  store_literal="$(wp_php_literal "$s")"
  flow_literal="$(wp_php_literal "$CRITICAL_FLOWS_FLOW_ID")"
  purpose_literal="$(wp_php_literal "$CRITICAL_FLOWS_LOG_PURPOSE")"
  if [ -z "$store_literal" ] || [ -z "$flow_literal" ] || [ -z "$purpose_literal" ]; then
    echo "BLOCKED log-clean check for $s: invalid_scan_evidence"
    return 3
  fi
  if [ -n "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" ]; then
    if ! critical_flows_log_observer_finish "$s"; then
      echo "BLOCKED log-clean check for $s: log_observer_failed"
      return 3
    fi
  fi
  raw="$(critical_flows_wp_eval_with_context "$s" '
$expected_store = '"$store_literal"';
$expected_flow_id = '"$flow_literal"';
$expected_purpose = '"$purpose_literal"';
$paths = array();
if ( defined( "WP_DEBUG_LOG" ) && is_string( WP_DEBUG_LOG ) && "" !== WP_DEBUG_LOG && "1" !== WP_DEBUG_LOG ) {
	$paths[] = WP_DEBUG_LOG;
}
if ( defined( "WP_CONTENT_DIR" ) ) {
	$paths[] = WP_CONTENT_DIR . "/debug.log";
}
$paths              = array_values( array_unique( array_filter( $paths ) ) );
$canonical_paths    = array();
foreach ( $paths as $configured_path ) {
	$canonical_path = is_string( $configured_path ) ? realpath( $configured_path ) : false;
	if ( is_string( $canonical_path ) && "" !== $canonical_path && DIRECTORY_SEPARATOR === $canonical_path[0] && false === strpos( $canonical_path, "\0" ) ) {
		$canonical_paths[] = $canonical_path;
	}
}
$paths              = array_values( array_unique( $canonical_paths ) );
$observations       = array();
$matches            = array();
$ignored_matches    = array();
$blocker_code       = "";
$marker_created_at  = "";
$marker_run_stamp   = "";
$marker_store       = "";
$marker_flow_id     = "";
$marker_purpose     = "";
$origin_nonce       = "";
$observer_id        = "";
$key_fingerprint    = "";
$origin_binding     = "";
$marker_paths       = array();
$fingerprint_pattern = "/^sha256:[0-9a-f]{64}$/";
$uuid_pattern        = "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/";
$marker_keys         = array( "schema", "created_at", "run_stamp", "store", "flow_id", "purpose", "origin_nonce", "observer_id", "key_fingerprint", "origin_binding", "paths" );
$path_keys           = array( "path_id", "line_count", "byte_count", "identity_fingerprint", "prefix_fingerprint", "canary_fingerprint", "owner", "group", "mode" );
$ignored_line_fragments = array(
	"sopreda/archi/zoho/class-zoho-integration.php",
);
$marker = get_option( "woopayments_critical_flows_debug_log_marker", array() );
$context_key_hex = getenv( "CRITICAL_FLOWS_RUN_CONTEXT_KEY" );
$context_key     = is_string( $context_key_hex ) && preg_match( "/^[0-9a-f]{64}$/", $context_key_hex ) ? hex2bin( $context_key_hex ) : false;

if (
	! is_array( $marker )
	|| $marker_keys !== array_keys( $marker )
	|| "woopayments_debug_log_marker.v6" !== $marker["schema"]
	|| ! is_string( $marker["created_at"] )
	|| false === strtotime( $marker["created_at"] )
	|| ! is_string( $marker["run_stamp"] )
	|| ! preg_match( "/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/", $marker["run_stamp"] )
	|| $expected_store !== $marker["store"]
	|| $expected_flow_id !== $marker["flow_id"]
	|| $expected_purpose !== $marker["purpose"]
	|| ! in_array( $marker["store"], array( "ref", "target" ), true )
	|| ! is_string( $marker["flow_id"] )
	|| ! preg_match( "/^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/", $marker["flow_id"] )
	|| "clean-debug-log" !== $marker["purpose"]
	|| ! is_string( $marker["origin_nonce"] )
	|| ! preg_match( $uuid_pattern, $marker["origin_nonce"] )
	|| ! is_string( $marker["observer_id"] )
	|| ! preg_match( $uuid_pattern, $marker["observer_id"] )
	|| ! is_string( $marker["key_fingerprint"] )
	|| ! preg_match( $fingerprint_pattern, $marker["key_fingerprint"] )
	|| ! is_string( $marker["origin_binding"] )
	|| ! preg_match( "/^hmac-sha256:[0-9a-f]{64}$/", $marker["origin_binding"] )
	|| ! is_array( $marker["paths"] )
	|| false === $context_key
	|| 32 !== strlen( $context_key )
) {
	$blocker_code = "invalid_marker";
} else {
	$marker_created_at = $marker["created_at"];
	$marker_run_stamp  = $marker["run_stamp"];
	$marker_store      = $marker["store"];
	$marker_flow_id    = $marker["flow_id"];
	$marker_purpose    = $marker["purpose"];
	$origin_nonce      = $marker["origin_nonce"];
	$observer_id       = $marker["observer_id"];
	$key_fingerprint   = $marker["key_fingerprint"];
	$origin_binding    = $marker["origin_binding"];
	$seen_basenames    = array();
	foreach ( $marker["paths"] as $marker_path => $marker_observation ) {
		$basename = is_string( $marker_path ) ? basename( $marker_path ) : "";
		if (
			! is_string( $marker_path )
			|| "" === $marker_path
			|| DIRECTORY_SEPARATOR !== $marker_path[0]
			|| realpath( $marker_path ) !== $marker_path
			|| "" === $basename
			|| isset( $seen_basenames[ $basename ] )
			|| ! is_array( $marker_observation )
			|| $path_keys !== array_keys( $marker_observation )
			|| ! is_string( $marker_observation["path_id"] )
			|| ! preg_match( "/^hmac-sha256:[0-9a-f]{64}$/", $marker_observation["path_id"] )
			|| $marker_observation["path_id"] !== "hmac-sha256:" . hash_hmac( "sha256", "woopayments_debug_log_path.v1\0" . $marker_path, $context_key )
			|| ! is_int( $marker_observation["line_count"] )
			|| $marker_observation["line_count"] < 1
			|| ! is_int( $marker_observation["byte_count"] )
			|| $marker_observation["byte_count"] < 1
			|| ! preg_match( $fingerprint_pattern, $marker_observation["identity_fingerprint"] )
			|| ! preg_match( $fingerprint_pattern, $marker_observation["prefix_fingerprint"] )
			|| ! preg_match( $fingerprint_pattern, $marker_observation["canary_fingerprint"] )
			|| ! is_int( $marker_observation["owner"] )
			|| ! is_int( $marker_observation["group"] )
			|| ! is_int( $marker_observation["mode"] )
			|| $marker_observation["mode"] < 0
			|| $marker_observation["mode"] > 0777
		) {
			$blocker_code = "invalid_marker";
			break;
		}
		$seen_basenames[ $basename ] = true;
		$marker_paths[ $marker_path ] = $marker_observation;
	}
}

if ( "" === $blocker_code ) {
	$origin_fields = array(
		"woopayments_debug_log_origin.v2",
		$marker_run_stamp,
		$marker_store,
		$marker_flow_id,
		$marker_purpose,
		$marker_created_at,
		$origin_nonce,
		$observer_id,
		$key_fingerprint,
	);
	$origin_paths = $marker_paths;
	uasort(
		$origin_paths,
		static function ( $left, $right ) {
			return strcmp( $left["path_id"], $right["path_id"] );
		}
	);
	foreach ( $origin_paths as $origin_path => $origin_observation ) {
		$origin_fields[] = $origin_observation["path_id"];
		$origin_fields[] = basename( $origin_path );
		$origin_fields[] = (string) $origin_observation["line_count"];
		$origin_fields[] = (string) $origin_observation["byte_count"];
		$origin_fields[] = $origin_observation["identity_fingerprint"];
		$origin_fields[] = $origin_observation["prefix_fingerprint"];
		$origin_fields[] = $origin_observation["canary_fingerprint"];
		$origin_fields[] = (string) $origin_observation["owner"];
		$origin_fields[] = (string) $origin_observation["group"];
		$origin_fields[] = (string) $origin_observation["mode"];
	}
	$expected_key_fingerprint = "sha256:" . hash( "sha256", $context_key );
	$expected_origin_binding  = "hmac-sha256:" . hash_hmac( "sha256", implode( "\0", $origin_fields ), $context_key );
	if ( $key_fingerprint !== $expected_key_fingerprint || $origin_binding !== $expected_origin_binding ) {
		$blocker_code = "invalid_marker_origin";
	}
}
if ( "" === $blocker_code && empty( $paths ) ) {
	$blocker_code = "no_configured_paths";
}
if ( "" === $blocker_code && array_diff( array_keys( $marker_paths ), $paths ) ) {
	$blocker_code = "marker_path_mismatch";
}
if ( "" === $blocker_code && array_diff( $paths, array_keys( $marker_paths ) ) ) {
	$blocker_code = "missing_marker_path";
}

if ( "" === $blocker_code ) {
	foreach ( $paths as $path ) {
		if ( ! is_string( $path ) || "" === $path || ! is_readable( $path ) ) {
			$blocker_code = "missing_marked_path";
			break;
		}
		$basename           = basename( $path );
		$marker_observation = $marker_paths[ $path ];
		$marker_line        = $marker_observation["line_count"];
		$marker_byte        = $marker_observation["byte_count"];
		clearstatcache( true, $path );
		$stat = @stat( $path );
		if ( false === $stat || ! isset( $stat["dev"], $stat["ino"], $stat["size"], $stat["uid"], $stat["gid"], $stat["mode"] ) ) {
			$blocker_code = "log_read_failed";
			break;
		}
		$end_byte = $stat["size"];
		$stream = @fopen( $path, "rb" );
		if ( false === $stream ) {
			$blocker_code = "log_read_failed";
			break;
		}
		$remaining   = min( $marker_byte, $end_byte );
		$prefix_hash = hash_init( "sha256" );
		$prefix_tail = "";
		while ( $remaining > 0 ) {
			$chunk = fread( $stream, min( 65536, $remaining ) );
			if ( false === $chunk || "" === $chunk ) {
				$blocker_code = "log_read_failed";
				break;
			}
			hash_update( $prefix_hash, $chunk );
			$prefix_tail = substr( $prefix_tail . $chunk, -512 );
			$remaining  -= strlen( $chunk );
		}
		if ( "" !== $blocker_code ) {
			fclose( $stream );
			break;
		}
		$canary_tail = rtrim( $prefix_tail, "\r\n" );
		$canary_at   = strrpos( $canary_tail, "\n" );
		$canary_line = false === $canary_at ? $canary_tail : substr( $canary_tail, $canary_at + 1 );
		$canary_line = rtrim( $canary_line, "\r" );
		$observed_identity_fingerprint = "sha256:" . hash( "sha256", $stat["dev"] . ":" . $stat["ino"] );
		$observed_prefix_fingerprint   = "sha256:" . hash_final( $prefix_hash );
		$observed_canary_fingerprint   = "sha256:" . hash( "sha256", $canary_line );
		$observed_owner = $stat["uid"];
		$observed_group = $stat["gid"];
		$observed_mode  = $stat["mode"] & 0777;
		$end_line       = $marker_line;
		while ( false !== ( $line = fgets( $stream ) ) ) {
			++$end_line;
			$should_ignore = preg_match( "/Function _load_textdomain_just_in_time was called/i", $line );
			foreach ( $ignored_line_fragments as $ignored_line_fragment ) {
				if ( false !== strpos( $line, $ignored_line_fragment ) ) {
					$should_ignore = true;
					break;
				}
			}
			$line_bytes = rtrim( $line, "\r\n" );
			if ( $should_ignore ) {
				if ( count( $ignored_matches ) < 100 ) {
					$ignored_matches[] = array(
						"path"        => $basename,
						"line"        => $end_line,
						"category"    => "allowlisted_noise",
						"fingerprint" => "sha256:" . hash( "sha256", $line_bytes ),
					);
				}
				continue;
			}
			if ( preg_match( "/\\b(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards)\\b/i", $line, $matched ) ) {
				$category = strtolower( str_replace( " ", "_", $matched[2] ) );
				if ( count( $matches ) < 20 ) {
					$matches[] = array(
						"path"        => $basename,
						"line"        => $end_line,
						"category"    => $category,
						"fingerprint" => "sha256:" . hash( "sha256", $line_bytes ),
					);
				}
			}
		}
		fclose( $stream );
		$observations[] = array(
			"path"                          => $basename,
			"path_id"                       => $marker_observation["path_id"],
			"start_line_count"              => $marker_line,
			"end_line_count"                => $end_line,
			"start_byte_count"              => $marker_byte,
			"end_byte_count"                => $end_byte,
			"marker_identity_fingerprint"   => $marker_observation["identity_fingerprint"],
			"observed_identity_fingerprint" => $observed_identity_fingerprint,
			"marker_prefix_fingerprint"     => $marker_observation["prefix_fingerprint"],
			"observed_prefix_fingerprint"   => $observed_prefix_fingerprint,
			"marker_canary_fingerprint"     => $marker_observation["canary_fingerprint"],
			"observed_canary_fingerprint"   => $observed_canary_fingerprint,
			"marker_owner"                  => $marker_observation["owner"],
			"observed_owner"                => $observed_owner,
			"marker_group"                  => $marker_observation["group"],
			"observed_group"                => $observed_group,
			"marker_mode"                   => $marker_observation["mode"],
			"observed_mode"                 => $observed_mode,
		);
		if ( $marker_observation["identity_fingerprint"] !== $observed_identity_fingerprint ) {
			$blocker_code = "log_identity_changed";
			break;
		}
		if ( $marker_observation["prefix_fingerprint"] !== $observed_prefix_fingerprint ) {
			$blocker_code = "log_prefix_changed";
			break;
		}
		if ( $marker_observation["canary_fingerprint"] !== $observed_canary_fingerprint ) {
			$blocker_code = "log_canary_changed";
			break;
		}
		if (
			$marker_observation["owner"] !== $observed_owner
			|| $marker_observation["group"] !== $observed_group
			|| $marker_observation["mode"] !== $observed_mode
		) {
			$blocker_code = "log_metadata_changed";
			break;
		}
		if ( $end_byte < $marker_byte ) {
			$blocker_code = "log_truncated";
			break;
		}
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			"status"            => "" !== $blocker_code ? "blocked" : ( empty( $matches ) ? "pass" : "fail" ),
			"run_stamp"         => $marker_run_stamp,
			"store"             => $marker_store,
			"flow_id"           => $marker_flow_id,
			"purpose"           => $marker_purpose,
			"marker_created_at" => $marker_created_at,
			"origin_nonce"      => $origin_nonce,
			"observer_id"       => $observer_id,
			"key_fingerprint"   => $key_fingerprint,
			"origin_binding"    => $origin_binding,
			"observations"      => $observations,
			"matches"           => $matches,
			"ignored_matches"   => $ignored_matches,
			"blocker_code"      => $blocker_code,
		)
	)
);
' 2>&1)"
  rc=$?

  if [ "$rc" -ne 0 ]; then
    echo "BLOCKED log-clean check for $s: debug.log scan command failed"
    return 3
  fi

  LOG_SCAN_RAW="$raw" python3 - \
    "$s" \
    "$evidence_file" \
    "${CRITICAL_FLOWS_RUN_STAMP:-${RUN_STAMP:-}}" \
    "${CRITICAL_FLOWS_FLOW_ID:-}" \
    "${CRITICAL_FLOWS_LOG_PURPOSE:-}" <<'PY'
import collections
import datetime
import hashlib
import hmac
import json
import os
import re
import sys
from pathlib import Path

store, evidence_file, run_stamp, flow_id, purpose = sys.argv[1:]
raw = os.environ.get("LOG_SCAN_RAW", "")
decoder = json.JSONDecoder()
payload = None
for index, char in enumerate(raw):
    if char != "{":
        continue
    try:
        candidate, _ = decoder.raw_decode(raw[index:])
    except json.JSONDecodeError:
        continue
    if isinstance(candidate, dict) and "status" in candidate:
        payload = candidate
        break

if payload is None:
    print(f"BLOCKED log-clean check for {store}: debug.log scan emitted no JSON")
    sys.exit(3)

BLOCKER_CODES = {
    "ambiguous_path",
    "invalid_marker",
    "invalid_marker_origin",
    "invalid_observer_summary",
    "invalid_run_binding",
    "invalid_scan_evidence",
    "log_canary_changed",
    "log_history_changed",
    "log_identity_changed",
    "log_metadata_changed",
    "log_prefix_changed",
    "log_read_failed",
    "log_truncated",
    "marker_path_mismatch",
    "missing_marked_path",
    "missing_marker_path",
    "missing_path_observation",
    "no_configured_paths",
    "stale_marker",
}
MATCH_CATEGORIES = {
    "fatal_error",
    "parse_error",
    "warning",
    "notice",
    "deprecated",
    "strict_standards",
}
OBSERVER_CATEGORIES = MATCH_CATEGORIES | {"allowlisted_noise", "other", "terminal"}
SAFE_FIELDS = {
    "status",
    "run_stamp",
    "store",
    "flow_id",
    "purpose",
    "marker_created_at",
    "origin_nonce",
    "observer_id",
    "key_fingerprint",
    "origin_binding",
    "observations",
    "matches",
    "ignored_matches",
    "blocker_code",
}
OBSERVATION_FIELDS = {
    "path",
    "path_id",
    "start_line_count",
    "end_line_count",
    "start_byte_count",
    "end_byte_count",
    "marker_identity_fingerprint",
    "observed_identity_fingerprint",
    "marker_prefix_fingerprint",
    "observed_prefix_fingerprint",
    "marker_canary_fingerprint",
    "observed_canary_fingerprint",
    "marker_owner",
    "observed_owner",
    "marker_group",
    "observed_group",
    "marker_mode",
    "observed_mode",
}
SUMMARY_FIELDS = {
    "schema",
    "store",
    "flow_id",
    "purpose",
    "marker_created_at",
    "observer_id",
    "paths",
    "key_fingerprint",
    "origin_binding",
    "records",
    "record_count",
    "line_event_count",
    "category_counts",
    "chain_head",
}


def safe_block(code, summary=None):
    return {
        "status": "blocked",
        "run_stamp": run_stamp,
        "store": store,
        "flow_id": flow_id,
        "purpose": purpose,
        "marker_created_at": "",
        "origin_nonce": "",
        "observer_id": "",
        "key_fingerprint": "",
        "origin_binding": "",
        "observations": [],
        "matches": [],
        "ignored_matches": [],
        "blocker_code": code,
        "observer_summary": summary if isinstance(summary, dict) else {},
    }


def exact_int(value):
    return isinstance(value, int) and not isinstance(value, bool)


def valid_fingerprint(value):
    return isinstance(value, str) and re.fullmatch(r"sha256:[0-9a-f]{64}", value) is not None


def valid_hmac(value):
    return isinstance(value, str) and re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", value) is not None


def valid_uuid(value):
    return isinstance(value, str) and re.fullmatch(
        r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}",
        value,
    ) is not None


def valid_basename(value):
    return (
        isinstance(value, str)
        and bool(value)
        and len(value) <= 255
        and Path(value).name == value
        and value not in {".", ".."}
    )


def parse_run_epoch(value):
    try:
        return datetime.datetime.strptime(value.split("-", 1)[0], "%Y%m%dT%H%M%SZ").replace(
            tzinfo=datetime.timezone.utc
        ).timestamp()
    except (TypeError, ValueError):
        return None


def parse_marker_epoch(value):
    try:
        return datetime.datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
    except (AttributeError, ValueError):
        return None


def validate_safe_records(records, observations, categories, maximum):
    if not isinstance(records, list) or len(records) > maximum:
        return False
    by_path = {item["path"]: item for item in observations}
    for record in records:
        if not isinstance(record, dict) or set(record) != {
            "path", "line", "category", "fingerprint",
        }:
            return False
        record_path = record.get("path")
        if not valid_basename(record_path):
            return False
        observation = by_path.get(record_path)
        if (
            observation is None
            or not exact_int(record.get("line"))
            or not observation["start_line_count"] < record["line"] <= observation["end_line_count"]
            or record.get("category") not in categories
            or not valid_fingerprint(record.get("fingerprint"))
        ):
            return False
    return True


def validate_observer_summary(summary, scan, key):
    if not isinstance(summary, dict) or set(summary) != SUMMARY_FIELDS:
        return False
    records = summary.get("records")
    counts = summary.get("category_counts")
    expected_paths = sorted(
        ({"path": item["path"], "path_id": item["path_id"]} for item in scan.get("observations", [])),
        key=lambda item: item["path_id"],
    )
    if (
        summary.get("schema") != "woopayments_debug_log_observer_summary.v2"
        or summary.get("store") != scan.get("store")
        or summary.get("flow_id") != scan.get("flow_id")
        or summary.get("purpose") != scan.get("purpose")
        or summary.get("marker_created_at") != scan.get("marker_created_at")
        or summary.get("observer_id") != scan.get("observer_id")
        or summary.get("paths") != expected_paths
        or summary.get("key_fingerprint") != scan.get("key_fingerprint")
        or summary.get("origin_binding") != scan.get("origin_binding")
        or summary.get("key_fingerprint") != "sha256:" + hashlib.sha256(key).hexdigest()
        or not isinstance(records, list)
        or not 3 <= len(records) <= 1000
        or not exact_int(summary.get("record_count"))
        or summary["record_count"] != len(records)
        or not exact_int(summary.get("line_event_count"))
        or not 1 <= summary["line_event_count"] <= 998
        or not isinstance(counts, dict)
        or not set(counts).issubset(OBSERVER_CATEGORIES)
        or any(not exact_int(count) or count < 1 for count in counts.values())
        or sum(counts.values()) != summary["line_event_count"]
        or counts.get("terminal", 0) != len(scan.get("observations", []))
        or not valid_hmac(summary.get("chain_head"))
        or len(json.dumps(summary, sort_keys=True, separators=(",", ":")).encode("utf-8")) > 1024 * 1024
    ):
        return False

    previous = "0" * 64
    derived_counts = collections.Counter()
    ready = None
    complete = None
    observations = {item["path"]: item for item in scan.get("observations", [])}
    common_fields = {
        "schema", "sequence", "kind", "run_stamp", "store", "flow_id", "purpose",
        "marker_created_at", "observer_id", "paths", "previous_hmac", "hmac",
    }
    for sequence, signed_record in enumerate(records, start=1):
        if not isinstance(signed_record, dict):
            return False
        record = dict(signed_record)
        signature = record.pop("hmac", None)
        kind = record.get("kind")
        expected_fields = {
            "ready": common_fields | {"status", "path_count", "key_fingerprint", "origin_binding"},
            "line": common_fields | {"path", "line", "category", "fingerprint"},
            "complete": common_fields | {"status"},
        }.get(kind)
        canonical = json.dumps(record, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
        expected = hmac.new(
            key, (previous + "\0" + canonical).encode("utf-8"), hashlib.sha256
        ).hexdigest()
        if (
            expected_fields is None
            or set(signed_record) != expected_fields
            or record.get("schema") != "woopayments_debug_log_observer_record.v2"
            or record.get("sequence") != sequence
            or record.get("run_stamp") != scan.get("run_stamp")
            or record.get("store") != scan.get("store")
            or record.get("flow_id") != scan.get("flow_id")
            or record.get("purpose") != scan.get("purpose")
            or record.get("marker_created_at") != scan.get("marker_created_at")
            or record.get("observer_id") != scan.get("observer_id")
            or record.get("paths") != expected_paths
            or record.get("previous_hmac") != "hmac-sha256:" + previous
            or signature != "hmac-sha256:" + expected
        ):
            return False
        previous = expected
        if kind == "ready":
            if (
                ready is not None
                or sequence != 1
                or record.get("status") != "pass"
                or record.get("path_count") != len(observations)
                or record.get("key_fingerprint") != scan.get("key_fingerprint")
                or record.get("origin_binding") != scan.get("origin_binding")
            ):
                return False
            ready = record
        elif kind == "line":
            path = record.get("path")
            line = record.get("line")
            category = record.get("category")
            observation = observations.get(path)
            if (
                observation is None
                or not exact_int(line)
                or (
                    line != observation["end_line_count"]
                    if category == "terminal"
                    else line < 1
                )
                or category not in OBSERVER_CATEGORIES
                or not valid_fingerprint(record.get("fingerprint"))
            ):
                return False
            derived_counts[category] += 1
        else:
            if complete is not None or sequence != len(records) or record.get("status") != "pass":
                return False
            complete = record
    if (
        ready is None
        or complete is None
        or dict(sorted(derived_counts.items())) != counts
        or sum(derived_counts.values()) != summary["line_event_count"]
        or summary["record_count"] != summary["line_event_count"] + 2
        or summary["chain_head"] != "hmac-sha256:" + previous
        or sum(derived_counts.get(category, 0) for category in MATCH_CATEGORIES) > 20
        or derived_counts.get("allowlisted_noise", 0) > 100
    ):
        return False
    return True


validation_blocker = ""
summary = None
key_hex = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
try:
    key = bytes.fromhex(key_hex) if re.fullmatch(r"[0-9a-f]{64}", key_hex) else b""
    summary = json.loads(os.environ.get("CRITICAL_FLOWS_LOG_OBSERVER_SUMMARY", ""))
except (TypeError, ValueError, json.JSONDecodeError):
    key = b""

if not isinstance(payload, dict) or set(payload) != SAFE_FIELDS or len(key) != 32:
    validation_blocker = "invalid_scan_evidence"
else:
    observations = payload.get("observations")
    if not isinstance(observations, list) or not 1 <= len(observations) <= 8:
        validation_blocker = "missing_path_observation"
    else:
        seen = set()
        for observation in observations:
            if (
                not isinstance(observation, dict)
                or set(observation) != OBSERVATION_FIELDS
                or not valid_basename(observation.get("path"))
                or not valid_hmac(observation.get("path_id"))
                or observation["path"] in seen
                or any(
                    not exact_int(observation.get(field))
                    for field in {
                        "start_line_count", "end_line_count", "start_byte_count", "end_byte_count",
                        "marker_owner", "observed_owner", "marker_group", "observed_group",
                        "marker_mode", "observed_mode",
                    }
                )
                or observation["start_line_count"] < 1
                or observation["start_byte_count"] < 1
                or not 0 <= observation["marker_mode"] <= 0o777
                or not 0 <= observation["observed_mode"] <= 0o777
                or any(
                    not valid_fingerprint(observation.get(field))
                    for field in {
                        "marker_identity_fingerprint", "observed_identity_fingerprint",
                        "marker_prefix_fingerprint", "observed_prefix_fingerprint",
                        "marker_canary_fingerprint", "observed_canary_fingerprint",
                    }
                )
            ):
                validation_blocker = "invalid_scan_evidence"
                break
            seen.add(observation["path"])
            if (
                observation["end_line_count"] < observation["start_line_count"]
                or observation["end_byte_count"] < observation["start_byte_count"]
            ):
                validation_blocker = "log_truncated"
                break
            if observation["marker_identity_fingerprint"] != observation["observed_identity_fingerprint"]:
                validation_blocker = "log_identity_changed"
                break
            if observation["marker_prefix_fingerprint"] != observation["observed_prefix_fingerprint"]:
                validation_blocker = "log_prefix_changed"
                break
            if observation["marker_canary_fingerprint"] != observation["observed_canary_fingerprint"]:
                validation_blocker = "log_canary_changed"
                break
            if any(
                observation[f"marker_{field}"] != observation[f"observed_{field}"]
                for field in ("owner", "group", "mode")
            ):
                validation_blocker = "log_metadata_changed"
                break

    if not validation_blocker:
        if not validate_safe_records(payload.get("matches"), observations, MATCH_CATEGORIES, 20):
            validation_blocker = "invalid_scan_evidence"
        elif not validate_safe_records(
            payload.get("ignored_matches"), observations, {"allowlisted_noise"}, 100
        ):
            validation_blocker = "invalid_scan_evidence"

    if not validation_blocker:
        if (
            payload.get("run_stamp") != run_stamp
            or payload.get("store") != store
            or payload.get("flow_id") != flow_id
            or payload.get("purpose") != purpose
            or store not in {"ref", "target"}
            or re.fullmatch(r"[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(?:-[a-z0-9]+)*", flow_id) is None
            or purpose != "clean-debug-log"
            or not valid_uuid(payload.get("origin_nonce"))
            or not valid_uuid(payload.get("observer_id"))
            or not valid_fingerprint(payload.get("key_fingerprint"))
            or not valid_hmac(payload.get("origin_binding"))
        ):
            validation_blocker = "invalid_run_binding"
        else:
            origin_fields = [
                "woopayments_debug_log_origin.v2",
                payload["run_stamp"],
                payload["store"],
                payload["flow_id"],
                payload["purpose"],
                payload["marker_created_at"],
                payload["origin_nonce"],
                payload["observer_id"],
                payload["key_fingerprint"],
            ]
            for observation in sorted(observations, key=lambda item: item["path_id"]):
                origin_fields.extend(
                    [
                        observation["path_id"],
                        observation["path"],
                        str(observation["start_line_count"]),
                        str(observation["start_byte_count"]),
                        observation["marker_identity_fingerprint"],
                        observation["marker_prefix_fingerprint"],
                        observation["marker_canary_fingerprint"],
                        str(observation["marker_owner"]),
                        str(observation["marker_group"]),
                        str(observation["marker_mode"]),
                    ]
                )
            expected_fingerprint = "sha256:" + hashlib.sha256(key).hexdigest()
            expected_binding = "hmac-sha256:" + hmac.new(
                key, "\0".join(origin_fields).encode("utf-8"), hashlib.sha256
            ).hexdigest()
            if (
                payload["key_fingerprint"] != expected_fingerprint
                or not hmac.compare_digest(payload["origin_binding"], expected_binding)
            ):
                validation_blocker = "invalid_marker_origin"

    if not validation_blocker and not validate_observer_summary(summary, payload, key):
        validation_blocker = "invalid_observer_summary"

    status = payload.get("status")
    blocker_code = payload.get("blocker_code")
    if not validation_blocker and status not in {"pass", "fail", "blocked"}:
        validation_blocker = "invalid_scan_evidence"
    if not validation_blocker and status == "pass" and (payload["matches"] or blocker_code):
        validation_blocker = "invalid_scan_evidence"
    if not validation_blocker and status == "fail" and (not payload["matches"] or blocker_code):
        validation_blocker = "invalid_scan_evidence"
    if not validation_blocker and status == "blocked" and blocker_code not in BLOCKER_CODES:
        validation_blocker = "invalid_scan_evidence"
    if not validation_blocker and status in {"pass", "fail"}:
        run_epoch = parse_run_epoch(run_stamp)
        marker_epoch = parse_marker_epoch(payload.get("marker_created_at"))
        if run_epoch is None:
            validation_blocker = "invalid_run_binding"
        elif marker_epoch is None:
            validation_blocker = "invalid_marker"
        elif abs(marker_epoch - run_epoch) > 900:
            validation_blocker = "stale_marker"

    if not validation_blocker:
        terminal_counts = collections.Counter(match["category"] for match in payload["matches"])
        ignored_count = len(payload["ignored_matches"])
        observer_counts = summary["category_counts"]
        if any(observer_counts.get(category, 0) > terminal_counts.get(category, 0) for category in MATCH_CATEGORIES):
            validation_blocker = "log_history_changed"
        elif observer_counts.get("allowlisted_noise", 0) > ignored_count:
            validation_blocker = "log_history_changed"
        elif any(observer_counts.get(category, 0) < terminal_counts.get(category, 0) for category in MATCH_CATEGORIES):
            validation_blocker = "invalid_observer_summary"

if validation_blocker:
    if validation_blocker == "log_history_changed" and isinstance(summary, dict):
        payload["status"] = "blocked"
        payload["blocker_code"] = validation_blocker
        payload["observer_summary"] = summary
    else:
        payload = safe_block(validation_blocker, summary)
else:
    payload["observer_summary"] = summary

if evidence_file:
    evidence = {
        "schema": "woopayments_debug_log_scan.v6",
        "store": store,
        "scan": payload,
    }
    path = Path(evidence_file)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")

status = payload.get("status")
observations = payload.get("observations") or []
matches = payload.get("matches") or []
path_note = ", ".join(item["path"] for item in observations) if observations else "no paths"

if status == "pass":
    print(f"PASS log-clean {store}: scanned {path_note}")
    sys.exit(0)
if status == "fail":
    print(f"FAIL log-clean {store}: PHP log entries found in {path_note}")
    for match in matches[:10]:
        print(f"  {match['path']}:{match['line']}:{match['category']}:{match['fingerprint']}")
    sys.exit(1)

reason = payload.get("blocker_code") or "invalid_scan_evidence"
print(f"BLOCKED log-clean check for {store}: {reason}; paths={path_note}")
sys.exit(3)
PY
}

# ref_vs_target_diff <metric-name> <ref-value> <target-value>
# Parity helper: equal => PASS, else FAIL with both values for the verdict note.
ref_vs_target_diff() { # <name> <ref> <target>
  [ "$2" = "$3" ] && { echo "PASS $1 ref==target ($2)"; return 0; }
  echo "FAIL $1 ref=$2 target=$3"; return 1
}

mkdir -p "$EVIDENCE_DIR"
