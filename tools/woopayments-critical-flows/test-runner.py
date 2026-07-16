#!/usr/bin/env python3
"""Regression checks for the critical-flows runner fail-closed contract."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import shlex
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

from tools.woopayments_test_runner import adapt_single_wp_runner


REPO = Path(__file__).resolve().parents[2]
RUNNER = REPO / "tools/woopayments-critical-flows/run.sh"
COMMON = REPO / "tools/woopayments-critical-flows/lib/common.sh"
FLOW_DRIVE = REPO / "tools/woopayments-merge/flow-drive.sh"
CONTEXT_MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"
MA09_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsma09driver.php"
)
MA10_VALIDATOR = REPO / "tools/woopayments-critical-flows/flows/ma10-validate.py"
MO01_COMPARATOR = REPO / "tools/woopayments-critical-flows/flows/mo01-compare.py"
MO02_EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/mo02-evidence.py"
MO02_TEST_EPOCH = int(time.time())


def load_context_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", CONTEXT_MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTEXT_MODULE = load_context_module()


def ensure_context(evidence_dir: Path) -> tuple[Path, dict]:
    path = evidence_dir / "critical-flow-context.json"
    if path.exists():
        return path, json.loads(path.read_text(encoding="utf-8"))
    context = CONTEXT_MODULE.build_context(
        aggregate_run_id="runner-test",
        source={"head_sha": "a" * 40, "worktree_sha256": "sha256:" + "b" * 64},
        stores={
            "ref": {
                "store_fingerprint": "sha256:" + "c" * 64,
                "runtime_owner": "plugin",
                "account_state_sha256": "sha256:" + "d" * 64,
            },
            "target": {
                "store_fingerprint": "sha256:" + "e" * 64,
                "runtime_owner": "native",
                "account_state_sha256": "sha256:" + "f" * 64,
            },
        },
        fixtures={
            "ref": {"subscription_id": "1283"},
            "target": {"subscription_id": "874"},
        },
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(context, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path, context


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def strip_recorded_at(rollup: dict) -> list[dict]:
    """Return rollup result rows with the volatile recorded_at stamp asserted and removed."""
    rows = rollup["results"]
    for row in rows:
        recorded_at = row.pop("recorded_at", None)
        assert isinstance(recorded_at, str) and recorded_at.endswith("Z"), (
            f"result row must carry a UTC recorded_at stamp: {row}"
        )
    return rows


def file_sha256(path: Path) -> str:
    """Return the runner's prefixed digest format for one evidence file."""
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def sc01_fake_wp_source(owner: str, home: str, intent_id: str, charge_id: str) -> str:
    """Fake wp CLI that answers the store-identity probe plus the SC-01 state asserts."""
    return f"""#!/usr/bin/env bash
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value={intent_id}"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value={charge_id}"
    exit 0
  fi
  printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def probe_only_fake_wp_source(owner: str, home: str) -> str:
    """Fake wp CLI that answers the store-identity probe and log-clean evals only."""
    return f"""#!/usr/bin/env bash
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def sp01_fake_wp_source(
    owner: str,
    home: str,
    state_payload: dict,
    *,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for the SP-01 state driver and shared log assertions."""
    payload = json.dumps(state_payload, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
if [ "$1" = "eval-file" ]; then
  cat >/dev/null
  printf '%s\\n' '{payload}'
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo01_state_payload(
    store: str,
    phase: str,
    *,
    status: str = "raw",
    intent_status: str | None = None,
    total_minor: int = 5000,
    runtime_owner: str | None = None,
    blockers: list[str] | None = None,
    provider_currency: str = "USD",
) -> dict:
    """Return one strict fake MO-01 provider/order state snapshot."""
    pre_capture = phase == "pre"
    order_id = 101 if store == "ref" else 202
    intent_id = f"pi_{store}_manual"
    charge_id = f"ch_{store}_manual"
    return {
        "schema": "woopayments_mo01_state.v1",
        "status": status,
        "blockers": blockers
        if blockers is not None
        else ([] if status != "blocked" else ["Provider state is unavailable."]),
        "store": store,
        "phase": phase,
        "runtime_owner": runtime_owner or ("plugin" if store == "ref" else "native"),
        "order": {
            "id": order_id,
            "status": "on-hold" if pre_capture else "processing",
            "paid": not pre_capture,
            "currency": "USD",
            "total_minor": total_minor,
            "payment_method": "woocommerce_payments",
            "intent_id": intent_id,
            "charge_id": charge_id,
            "intention_status": "requires_capture" if pre_capture else "succeeded",
        },
        "provider": {
            "intent_id": intent_id,
            "intent_status": intent_status
            or ("requires_capture" if pre_capture else "succeeded"),
            "intent_amount_minor": total_minor,
            "intent_currency": provider_currency,
            "charge_id": charge_id,
            "charge_amount_minor": total_minor,
            "charge_amount_captured_minor": 0 if pre_capture else total_minor,
            "charge_captured": not pre_capture,
            "charge_currency": provider_currency,
        },
        "notes": {
            "authorization_count": 1,
            "capture_success_count": 0 if pre_capture else 1,
            "capture_failure_count": 0,
        },
    }


def mo01_fake_wp_source(
    owner: str,
    home: str,
    pre_payload: dict,
    post_payload: dict,
    *,
    state_exit_code: int = 0,
    post_state_exit_code: int | None = None,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for MO-01 state snapshots plus shared runner probes."""
    pre = json.dumps(pre_payload, separators=(",", ":"))
    post = json.dumps(post_payload, separators=(",", ":"))
    post_exit_code = state_exit_code if post_state_exit_code is None else post_state_exit_code
    return f"""#!/usr/bin/env bash
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  cat >/dev/null
  state_exit_code={state_exit_code}
  if [ "$5" = "post" ]; then
    state_exit_code={post_exit_code}
  fi
  if [ "$state_exit_code" -ne 0 ]; then
    printf '%s\\n' 'fake provider transport unavailable' >&2
    exit "$state_exit_code"
  fi
  if [ "$5" = "pre" ]; then
    printf '%s\\n' '{pre}'
  else
    printf '%s\\n' '{post}'
  fi
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo02_state_payload(
    store: str,
    phase: str,
    *,
    status: str = "raw",
    authorization_present: bool | None = None,
    total_minor: int = 5000,
    runtime_owner: str | None = None,
    blockers: list[str] | None = None,
) -> dict:
    """Return one strict fake MO-02 authorization-list/order/provider snapshot."""
    pre_capture = phase == "pre"
    present = pre_capture if authorization_present is None else authorization_present
    order_id = 101 if store == "ref" else 202
    intent_id = f"pi_{store}_uncaptured"
    charge_id = f"ch_{store}_uncaptured"
    matched_rows = []
    if present:
        matched_rows.append(
            {
                "charge_id": charge_id,
                "payment_intent_id": intent_id,
                "order_id": order_id,
                "amount_minor": total_minor,
                "amount_captured_minor": 0,
                "currency": "USD",
                "status": "succeeded",
                "created": MO02_TEST_EPOCH,
            }
        )
    return {
        "schema": "woopayments_mo02_state.v1",
        "status": status,
        "blockers": blockers
        if blockers is not None
        else ([] if status != "blocked" else ["Authorizations endpoint is unavailable."]),
        "store": store,
        "phase": phase,
        "runtime_owner": runtime_owner or ("plugin" if store == "ref" else "native"),
        "order": {
            "id": order_id,
            "created": MO02_TEST_EPOCH,
            "status": "on-hold" if pre_capture else "processing",
            "paid": not pre_capture,
            "currency": "USD",
            "total_minor": total_minor,
            "payment_method": "woocommerce_payments",
            "intent_id": intent_id,
            "charge_id": charge_id,
            "intention_status": "requires_capture" if pre_capture else "succeeded",
        },
        "provider": {
            "intent_id": intent_id,
            "intent_status": "requires_capture" if pre_capture else "succeeded",
            "intent_amount_minor": total_minor,
            "intent_currency": "USD",
            "charge_id": charge_id,
            "charge_amount_minor": total_minor,
            "charge_amount_captured_minor": 0 if pre_capture else total_minor,
            "charge_captured": not pre_capture,
            "charge_currency": "USD",
        },
        "authorizations": {
            "route": "/wc/v3/payments/authorizations",
            "http_status": 200,
            "pages_scanned": 1,
            "rows_scanned": 1 if present else 0,
            "observed_at": MO02_TEST_EPOCH,
            "exact_match_count": 1 if present else 0,
            "matched_rows": matched_rows,
        },
        "notes": {
            "authorization_count": 1,
            "capture_success_count": 0 if pre_capture else 1,
            "capture_failure_count": 0,
        },
    }


def mo02_capture_payload(store: str, *, status: str = "pass") -> dict:
    """Return one fake result from the MO-02 row-level capture REST route."""
    order_id = 101 if store == "ref" else 202
    payload = {
        "schema": "woopayments_mo02_capture.v1",
        "status": status,
        "store": store,
        "route": f"/wc/v3/payments/orders/{order_id}/capture_authorization",
        "order_id": order_id,
        "intent_id": f"pi_{store}_uncaptured",
        "charge_id": f"ch_{store}_uncaptured",
        "http_status": 200 if status == "pass" else 503 if status == "blocked" else 400,
        "success": status == "pass",
        "error_code": "" if status == "pass" else "capture_declined",
        "error_message": "" if status == "pass" else "Capture was declined.",
    }
    return payload


def mo02_fake_wp_source(
    owner: str,
    home: str,
    pre_payload: dict,
    post_payload: dict,
    capture_payload: dict,
    call_log: Path,
    *,
    state_exit_code: int = 0,
    post_state_exit_code: int | None = None,
    capture_exit_code: int = 0,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for MO-02 snapshots, row capture, and shared runner probes."""
    pre = json.dumps(pre_payload, separators=(",", ":"))
    post = json.dumps(post_payload, separators=(",", ":"))
    capture = json.dumps(capture_payload, separators=(",", ":"))
    post_exit_code = state_exit_code if post_state_exit_code is None else post_state_exit_code
    return f"""#!/usr/bin/env bash
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  cat >/dev/null
  if [ "$4" = "capture" ]; then
    printf '%s\\n' '{pre_payload["store"]}:capture' >> {shlex.quote(str(call_log))}
    printf '%s\\n' '{capture}'
    exit {capture_exit_code}
  fi
  state_exit_code={state_exit_code}
  if [ "$6" = "post" ]; then
    state_exit_code={post_exit_code}
  fi
  if [ "$state_exit_code" -ne 0 ]; then
    printf '%s\\n' 'fake authorizations transport unavailable' >&2
    exit "$state_exit_code"
  fi
  if [ "$6" = "pre" ]; then
    printf '%s\\n' '{pre}'
  else
    printf '%s\\n' '{post}'
  fi
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def ma09_payload(
    store: str,
    *,
    dataset_count: int = 500,
    status: str = "pass",
    check_overrides: dict[str, bool] | None = None,
    measured_seconds: tuple[float, float] = (0.5, 0.6),
) -> dict:
    """Return one strict fake MA-09 deterministic payload."""
    checks = {
        "route_registered": True,
        "dataset_minimum": dataset_count >= 500,
        "unique_ledger": True,
        "stable_ledger": True,
        "page_one_full": True,
        "deep_page_full": True,
        "pagination_exact": True,
        "type_filter_exact": True,
        "date_filter_exact": True,
    }
    checks.update(check_overrides or {})
    timings = {
        query: {
            "samples_seconds": [0.4, *measured_seconds],
            "measured_median_seconds": sum(measured_seconds) / 2,
        }
        for query in ("page_1", "page_20", "type_filter", "date_filter")
    }
    return {
        "schema": "woopayments_ma09_deterministic.v1",
        "status": status,
        "store": store,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "dataset_count": dataset_count,
        "seeded_count": dataset_count,
        "page_size": 25,
        "deep_page": 20,
        "ledger_sha256": "sha256:" + ("a" if store == "ref" else "b") * 64,
        "type_filter": "charge",
        "type_filter_count": 350,
        "date_filter_after": "2026-07-16 00:00:00",
        "date_filter_before": "2026-07-16 23:59:59",
        "date_filter_count": 125,
        "checks": checks,
        "timings": timings,
        "errors": [] if status != "fail" else ["A deterministic state check failed."],
        "blockers": [] if status != "blocked" else ["The exact 500-row fixture is unavailable."],
    }


def ma09_fake_wp_source(
    owner: str,
    home: str,
    state_payload: dict,
    *,
    log_probe_exit_code: int = 0,
    dirty_log: bool = False,
) -> str:
    """Fake wp CLI for the MA-09 performance driver and shared log assertions."""
    payload = json.dumps(state_payload, separators=(",", ":"))
    log_payload = json.dumps(
        {
            "status": "fail" if dirty_log else "pass",
            "paths": ["/tmp/fake-debug.log"],
            "matches": ["PHP Warning: fake MA-09 warning"] if dirty_log else [],
        },
        separators=(",", ":"),
    )
    return f"""#!/usr/bin/env bash
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  cat >/dev/null
  printf '%s\\n' 'fake WP wrapper banner'
  printf '%s\\n' '{payload}'
  printf '%s\\n' 'fake WP wrapper footer'
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_payload}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def run_ma09_php_driver_with_endpoint_exception(
    failure_mode: str,
) -> subprocess.CompletedProcess[str]:
    """Run the real PHP collector with a registered endpoint that throws in one phase."""
    driver_path = json.dumps(str(MA09_DRIVER))
    encoded_failure_mode = json.dumps(failure_mode)
    source = f"""<?php
namespace Automattic\\WooCommerce\\Internal\\Payments {{
final class NativePaymentsRuntimeArbiter {{}}
}}

namespace {{
final class WP_REST_Request {{
    public array $query = array();
    public function __construct( string $method, string $route ) {{}}
    public function set_query_params( array $query ): void {{ $this->query = $query; }}
}}

final class FakeRestServer {{
    public function get_routes(): array {{
        return array( '/wc/v3/payments/transactions' => array() );
    }}
}}

final class FakeRestResponse {{
    public function __construct( private array $data ) {{}}
    public function get_data(): array {{ return array( 'data' => $this->data ); }}
    public function get_status(): int {{ return 200; }}
}}

function get_option( string $name, $default = false ) {{ return $default; }}
function wc_get_container(): object {{
    return new class {{
        public function get( string $class_name ): object {{
            return new class {{
                public function get_runtime_owner(): string {{ return 'native'; }}
            }};
        }}
    }};
}}
function wp_get_current_user(): object {{
    return new class {{
        public function exists(): bool {{ return true; }}
    }};
}}
function current_user_can( string $capability ): bool {{ return true; }}
function rest_get_server(): object {{ return new FakeRestServer(); }}
function is_wp_error( $response ): bool {{ return false; }}
function wp_json_encode( $value, int $flags = 0 ): string {{ return json_encode( $value, $flags ); }}

$ma09_rows = array();
for ( $index = 500; $index >= 1; $index-- ) {{
    $ma09_rows[] = array(
        'transaction_id' => 'txn_' . $index,
        'type'           => 'charge',
        'date'           => '2026-07-16 12:00:00',
        'amount'         => 1000,
        'fees'           => 30,
        'net'            => 970,
        'currency'       => 'usd',
    );
}}
$ma09_page_twenty_requests = 0;
$ma09_failure_mode = {encoded_failure_mode};

function rest_do_request( WP_REST_Request $request ): FakeRestResponse {{
    global $ma09_rows, $ma09_page_twenty_requests, $ma09_failure_mode;
    $page      = (int) ( $request->query['page'] ?? 1 );
    $page_size = (int) ( $request->query['per_page'] ?? 25 );
    if (
        'type_filter' === $ma09_failure_mode
        && 1 === $page
        && 100 === $page_size
        && 'charge' === ( $request->query['type_is'] ?? '' )
    ) {{
        throw new \\RuntimeException( 'type filter endpoint failure' );
    }}
    if (
        'date_filter' === $ma09_failure_mode
        && 1 === $page
        && 100 === $page_size
        && isset( $request->query['date_after'], $request->query['date_before'] )
    ) {{
        throw new \\RuntimeException( 'date filter endpoint failure' );
    }}
    if ( 'timed' === $ma09_failure_mode && 20 === $page && 25 === $page_size ) {{
        $ma09_page_twenty_requests++;
        if ( 3 === $ma09_page_twenty_requests ) {{
            throw new \\RuntimeException( 'timed endpoint failure' );
        }}
    }}
    usleep( 1000 );
    $offset = ( $page - 1 ) * $page_size;
    return new FakeRestResponse( array_slice( $ma09_rows, $offset, $page_size ) );
}}

$args = array( 'target' );
require {driver_path};
}}
"""

    with tempfile.TemporaryDirectory(prefix="critical-flows-ma09-php-") as tmp:
        harness = Path(tmp) / "ma09-timed-exception.php"
        harness.write_text(source, encoding="utf-8")
        return subprocess.run(
            ["php", str(harness)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


MA10_SENTINELS = [
    "Payment complete.",
    "Payment failed.",
    "Payment authorization expired.",
    "Fee details:",
    "Fee (",
    "Base fee:",
    "Currency conversion fee:",
    "Net payout:",
    "The refund returned status",
    "Refunded",
    "Payment dispute and fees have been deducted",
    "Payment dispute funds have been reinstated",
    "Payment dispute has been updated",
    "dispute overview",
    "Payment has been disputed",
    "Payment inquiry has been raised",
    "A test payment",
]


def ma10_gate_payload(status: str = "pass", *, english_refund: bool = False) -> dict:
    """Return a strict fake MA-10 borrowed-gate result."""
    translated = status != "blocked"
    messages = {
        "charge": "A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).",
        "refund": "A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)",
        "dispute": 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.',
    }
    refund_note = "[wcpay-i18n:refund] Eine Rueckerstattung re_test"
    if english_refund:
        refund_note = "A refund of 25 USD using WooPayments. Reason: test. (<code>re_test</code>)"
    state = {
        "schema": "woopayments_i18n_notes_capture.v1",
        "locale": "de_DE",
        "translation_source": "deterministic_gettext_probe",
        "catalog_evidence": {
            "schema": "woopayments_i18n_catalog_evidence.v1",
            "locale": "de_DE",
            "textdomain": "woocommerce",
            "textdomain_loaded": True,
            "messages": {
                flow: {
                    "message_id": message_id,
                    "translation": f"translated {flow}" if translated else message_id,
                    "translated": translated,
                }
                for flow, message_id in messages.items()
            },
        },
        "orders": [
            {
                "flow": "charge",
                "order_id": 101,
                "notes": ["[wcpay-i18n:charge] Eine Zahlung pi_test_charge"],
            },
            {
                "flow": "refund",
                "order_id": 101,
                "notes": [refund_note, "E-Mail [wcpay-i18n:refund] Rueckerstattete Bestellung"],
            },
            {
                "flow": "dispute",
                "order_id": 202,
                "notes": ["[wcpay-i18n:dispute] Zahlung angefochten ch_test_dispute"],
            },
        ],
    }
    if status == "fail":
        state["orders"][0]["notes"].append("Payment complete.")
    return {
        "schema": "woopayments_i18n_notes_gate_result.v1",
        "status": status,
        "implementation_status": "fail" if status == "fail" else "pass",
        "catalog_status": "blocked" if status == "blocked" else "pass",
        "failures": ["english sentinel found"] if status == "fail" else [],
        "blockers": ["catalog translation unavailable"] if status == "blocked" else [],
        "expected_locale": "de_DE",
        "required_flows": ["charge", "refund", "dispute"],
        "english_sentinels": MA10_SENTINELS,
        "state": state,
    }


def ma10_fake_gate_source(
    status: str = "pass",
    *,
    malformed: bool = False,
    english_refund: bool = False,
    exit_code_override: int | None = None,
) -> str:
    """Fake the borrowed gate while preserving its archived evidence contract."""
    payload = {"schema": "woopayments_i18n_notes_gate_result.v1", "status": "pass"}
    if not malformed:
        payload = ma10_gate_payload(status, english_refund=english_refund)
    encoded_payload = json.dumps(payload, separators=(",", ":"))
    encoded_state = json.dumps(payload.get("state", {}), separators=(",", ":"))
    encoded_catalog = json.dumps(payload.get("state", {}).get("catalog_evidence", {}), separators=(",", ":"))
    exit_code = exit_code_override if exit_code_override is not None else 0 if status == "pass" else 1 if status == "fail" else 3
    return f"""#!/usr/bin/env bash
if [ -n "${{FAKE_I18N_GATE_CALLS:-}}" ]; then
  printf 'gate %s\\n' "$*" >> "$FAKE_I18N_GATE_CALLS"
fi
out_dir=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "--out-dir" ]; then
    out_dir="$2"
    break
  fi
  shift
done
[ -n "$out_dir" ] || exit 2
mkdir -p "$out_dir"
printf '%s\\n' '{encoded_payload}' > "$out_dir/i18n-notes-gate.json"
printf '%s\\n' '{encoded_state}' > "$out_dir/i18n-notes-state.json"
printf '%s\\n' '{encoded_catalog}' > "$out_dir/i18n-catalog-evidence.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_language_snapshot.v1","success":true,"exists":true,"value":"","autoload":"auto"}}' > "$out_dir/i18n-language-snapshot.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_language_restore.v1","success":true,"restored_snapshot_exact":true,"errors":[]}}' > "$out_dir/i18n-language-restore.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_probe_cleanup.v1","success":true,"errors":[]}}' > "$out_dir/i18n-probe-cleanup.json"
printf '%s\\n' '{{"op":"charge","order_id":101,"charge_id":"ch_test_charge","intent_id":"pi_test_charge","transaction_id":"pi_test_charge","intention_status":"succeeded","status":"processing","result":"success"}}' > "$out_dir/charge-flow.json"
printf '%s\\n' '{{"op":"refund","order_id":101,"refund_id":102,"provider_refund_id":"re_test","success":true}}' > "$out_dir/refund-flow.json"
printf '%s\\n' '{{"op":"dispute","order_id":202,"charge_id":"ch_test_dispute","intent_id":"pi_test_dispute","transaction_id":"pi_test_dispute","intention_status":"succeeded","status":"processing","result":"success"}}' > "$out_dir/dispute-flow.json"
if [ "${{FAKE_I18N_GATE_REMOVE_FILE:-}}" ]; then
  rm -f "$out_dir/$FAKE_I18N_GATE_REMOVE_FILE"
fi
if [ -n "${{FAKE_I18N_GATE_CONTRADICT_STATE:-}}" ]; then
  printf '%s\\n' '{{"schema":"woopayments_i18n_notes_capture.v1","tampered":true}}' > "$out_dir/i18n-notes-state.json"
fi
exit {exit_code}
"""


def ma10_fake_wp_source(
    *,
    log_status: str = "pass",
    marker_ok: bool = True,
    scanned_paths: list[str] | None = None,
) -> str:
    """Return a target WP seam with marker-bounded log evidence."""
    scan_payload = {
        "status": log_status,
        "paths": ["/tmp/fake-debug.log"] if scanned_paths is None else scanned_paths,
        "matches": ["PHP Warning: MA-10 fake warning"] if log_status == "fail" else [],
        "ignored_matches": [],
        "marker": {
            "created_at": "2026-07-16T10:00:00Z",
            "paths": {"/tmp/fake-debug.log": 4},
        },
    }
    if log_status == "blocked":
        scan_payload["reason"] = "no readable debug.log path"
    encoded_scan = json.dumps(scan_payload, separators=(",", ":"))
    marker_exit = 0 if marker_ok else 1
    return f"""#!/usr/bin/env bash
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' 'store_identity_owner=native'
    printf '%s\\n' 'store_identity_home=http://target.fake.test'
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\\n' marker >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":4}}}}'
    exit {marker_exit}
  fi
  if [[ "$2" == *"ignored_matches"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\\n' scan >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\\n' '{encoded_scan}'
    exit 0
  fi
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def ma10_live_failure_wp_source() -> str:
    """Return a WP seam that supports the real gate through an early flow failure."""
    catalog = ma10_gate_payload()["state"]["catalog_evidence"]
    encoded_catalog = json.dumps(catalog, separators=(",", ":"))
    scan = {
        "status": "pass",
        "paths": ["/tmp/fake-debug.log"],
        "matches": [],
        "ignored_matches": [],
        "marker": {
            "created_at": "2026-07-16T10:00:00Z",
            "paths": {"/tmp/fake-debug.log": 0},
        },
    }
    encoded_scan = json.dumps(scan, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
set -u
if [[ "${{1:-}}" == --exec=* ]]; then shift; fi
if [ "${{1:-}}" = "eval" ]; then
  if [[ "${{2:-}}" == *"store_identity_owner"* ]]; then
    printf '%s\\n' 'store_identity_owner=native'
    printf '%s\\n' 'store_identity_home=http://target.fake.test'
  elif [[ "${{2:-}}" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
  elif [[ "${{2:-}}" == *"ignored_matches"* ]]; then
    printf '%s\\n' '{encoded_scan}'
  else
    exit 2
  fi
  exit 0
fi
if [ "${{1:-}}" = "wc-native-payments" ] && [ "${{2:-}}" = "status" ]; then
  printf '%s\\n' 'Owner: native'
  exit 0
fi
if [ "${{1:-}}" = "language" ] || [ "${{1:-}}" = "site" ]; then exit 0; fi
if [ "${{1:-}}" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"woopayments_i18n_language_restore.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_language_restore.v1","success":true,"restored_snapshot_exact":true,"errors":[]}}'
  elif [[ "$body" == *"woopayments_i18n_language_snapshot.v1"* ]]; then
    printf '%s\\n' 'WPLANG:en_US'
    printf '%s\\n' '{{"schema":"woopayments_i18n_language_snapshot.v1","success":true,"exists":true,"value":"en_US","autoload":"auto"}}'
  elif [[ "$body" == *"woopayments_i18n_catalog_evidence.v1"* ]]; then
    printf '%s\\n' '{encoded_catalog}'
  elif [[ "$body" == *"Translation probe path already exists"* ]]; then
    printf '%s\\n' '{{"success":true,"path":"/fake/probe.php","errors":[]}}'
  elif [[ "$body" == *"woopayments_i18n_probe_install.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_probe_install.v1","success":true,"path":"/fake/probe.php","sha256":"fake"}}'
  elif [[ "$body" == *"woopayments_i18n_probe_cleanup.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_probe_cleanup.v1","success":true,"errors":[]}}'
  else
    exit 2
  fi
  exit 0
fi
exit 2
"""


def run_runner(
    *args: str,
    evidence_dir: Path,
    extra_env: dict[str, str] | None = None,
    with_context: bool = True,
) -> subprocess.CompletedProcess[str]:
    runner_args = [*args]
    if with_context and "--layer" in args and args[args.index("--layer") + 1] != "deterministic":
        context_path, _ = ensure_context(evidence_dir)
        runner_args.extend(("--context-file", str(context_path)))
    return subprocess.run(
        ["bash", str(RUNNER), *runner_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "EVIDENCE_DIR": str(evidence_dir), **(extra_env or {})},
        check=False,
    )


def write_agent_result(
    results_dir: Path,
    flow: str,
    store: str,
    verdict: str,
) -> Path:
    results_dir.mkdir(parents=True, exist_ok=True)
    path = results_dir / f"{flow}.json"
    _, context = ensure_context(results_dir.parent)
    evidence_dir = results_dir.parent / "artifacts" / flow
    evidence_dir.mkdir(parents=True, exist_ok=True)
    store_results = []
    for result_store in ("ref", "target"):
        artifact = evidence_dir / f"{result_store}.png"
        artifact.write_bytes(f"{flow}:{result_store}".encode("utf-8"))
        store_results.append(
            {
                "store": result_store,
                "verdict": verdict,
                "end_state": "order paid",
                "ux_observations": ["expected controls were usable"],
                "visual_diffs": [],
                "evidence_paths": [str(artifact)],
            }
        )
    payload = CONTEXT_MODULE.stamp_generated_result(
        {
            "flow": flow,
            "store_results": store_results,
            "parity_verdict": verdict,
            "regression_note": "",
        },
        context,
    )
    path.write_text(
        json.dumps(payload, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return path


def write_agent_result_payload(results_dir: Path, flow: str, payload: dict) -> Path:
    results_dir.mkdir(parents=True, exist_ok=True)
    path = results_dir / f"{flow}.json"
    _, context = ensure_context(results_dir.parent)
    for store_result in payload.get("store_results", []):
        if isinstance(store_result, dict):
            store_result["evidence_paths"] = []
    stamped = CONTEXT_MODULE.stamp_generated_result(payload, context)
    path.write_text(json.dumps(stamped, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def test_card_checkout_flow_passes_with_clean_exercised_order() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "wc" ] && [ "$2" = "shop_order" ] && [ "$3" = "get" ]; then
  printf '%s\\n' "processing"
  exit 0
fi
if [ "$1" = "post" ] && [ "$2" = "meta" ] && [ "$3" = "get" ]; then
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=native"
    printf '%s\\n' "store_identity_home=http://target.fake.test"
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value=pi_fake"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value=ch_fake"
    exit 0
  fi
  printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0
        assert "SC-01-card-checkout" in result.stdout
        assert "scope=partial" in result.stdout
        assert "[target] store identity: owner=native home=http://target.fake.test" in result.stdout
        assert "captured order_id=123" in result.stdout
        assert "EXERCISER NOT WIRED" not in result.stdout
        assert "PASS log-clean target" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout
        assert "Matrix coverage:" in result.stdout
        assert "run archived ->" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["scope"] == "partial"
        assert rollup["run_stamp"]
        assert rollup["matrix"]["total"] == 76
        assert rollup["matrix"]["covered"] == 1
        assert "SC-01" not in rollup["matrix"]["uncovered_ids"]
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
            }
        ]


def test_mo01_deterministic_flow_classifies_pass_fail_and_blocked() -> None:
    cases = (
        {
            "name": "pass",
            "target_status": "raw",
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "fail",
            "target_status": "raw",
            "target_intent_status": "succeeded",
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "trusted_fail_log_blocked",
            "target_status": "raw",
            "target_intent_status": "succeeded",
            "target_log_probe_exit_code": 3,
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "pass_log_blocked",
            "target_status": "raw",
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "parity_fail",
            "target_status": "raw",
            "target_total_minor": 5100,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "blocked",
            "target_status": "blocked",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "attribution_blocked",
            "target_status": "raw",
            "target_runtime_owner": "plugin",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "contradictory_blocked",
            "target_status": "raw",
            "target_blockers": ["Provider state is unavailable."],
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "currency_fail",
            "target_status": "raw",
            "target_provider_currency": "EUR",
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "malformed_comparison_blocked",
            "target_status": "raw",
            "malformed_comparison": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_prerequisite_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 3,
            "target_capture_product_failure": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_transport_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "target_capture_product_failure": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_product_fail_post_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "target_capture_product_failure": True,
            "post_state_exit_code": 3,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_usage_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 2,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_pass_post_blocked",
            "target_status": "raw",
            "post_state_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "transport_blocked",
            "target_status": "raw",
            "state_exit_code": 2,
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
    )

    for case in cases:
        name = case["name"]
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-mo01-{name}-") as tmp:
            evidence_dir = Path(tmp)
            flow_driver = evidence_dir / "fake-flow-drive.sh"
            fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
            fake_target_wp = evidence_dir / "fake-target-wp.sh"
            calls = evidence_dir / "calls.txt"
            comparator = MO01_COMPARATOR

            write_executable(
                flow_driver,
                """#!/usr/bin/env bash
printf '%s:%s\\n' "$STORE_NAME" "$1" >> "$MO01_CALL_LOG"
if [ "$1" = "charge" ]; then
  if [ "$STORE_NAME" = "ref" ]; then
    printf '%s\\n' '{"op":"charge","order_id":101,"charge_id":"ch_ref_manual","intent_id":"pi_ref_manual"}'
  else
    printf '%s\\n' '{"op":"charge","order_id":202,"charge_id":"ch_target_manual","intent_id":"pi_target_manual"}'
  fi
  exit 0
fi
if [ "$STORE_NAME" = "target" ] && [ "${MO01_FAKE_CAPTURE_EXIT:-0}" -ne 0 ]; then
  if [ "${MO01_FAKE_CAPTURE_PRODUCT_FAILURE:-0}" -eq 1 ]; then
    printf '%s\n' '{"op":"capture","order_id":202,"intent_id":"pi_target_manual","charge_id":"ch_target_manual","status":"on-hold","intention_status":"requires_capture","success":false,"provider_status":"failed","error_message":"Capture was declined.","error_code":"capture_declined"}' >&2
  else
    printf '%s\n' 'fake WP-CLI/provider transport unavailable' >&2
  fi
  exit "$MO01_FAKE_CAPTURE_EXIT"
fi
printf '%s\\n' "{\"op\":\"capture\",\"order_id\":${!#},\"success\":true}"
""",
            )
            write_executable(
                fake_ref_wp,
                mo01_fake_wp_source(
                    "plugin",
                    "http://ref.fake.test",
                    mo01_state_payload("ref", "pre"),
                    mo01_state_payload("ref", "post"),
                ),
            )
            target_post_payload = mo01_state_payload(
                "target",
                "post",
                total_minor=case.get("target_total_minor", 5000),
                provider_currency=case.get("target_provider_currency", "USD"),
            )
            if case.get("target_capture_exit_code", 0) != 0:
                target_post_payload = mo01_state_payload(
                    "target",
                    "pre",
                    total_minor=case.get("target_total_minor", 5000),
                    provider_currency=case.get("target_provider_currency", "USD"),
                )
                target_post_payload["phase"] = "post"
            write_executable(
                fake_target_wp,
                mo01_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    mo01_state_payload(
                        "target",
                        "pre",
                        status=case["target_status"],
                        intent_status=case.get("target_intent_status"),
                        total_minor=case.get("target_total_minor", 5000),
                        runtime_owner=case.get("target_runtime_owner"),
                        blockers=case.get("target_blockers"),
                        provider_currency=case.get("target_provider_currency", "USD"),
                    ),
                    target_post_payload,
                    state_exit_code=case.get("state_exit_code", 0),
                    post_state_exit_code=case.get("post_state_exit_code"),
                    log_probe_exit_code=case.get("target_log_probe_exit_code", 0),
                ),
            )
            if case.get("malformed_comparison"):
                comparator = evidence_dir / "malformed-comparator.py"
                write_executable(
                    comparator,
                    f"""#!/usr/bin/env python3
import os
import sys

if sys.argv[1] == "compare":
    print("Traceback: comparator crashed")
    raise SystemExit(1)
os.execv(sys.executable, [sys.executable, {json.dumps(str(MO01_COMPARATOR))}, *sys.argv[1:]])
""",
                )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MO-01",
                evidence_dir=evidence_dir,
                extra_env={
                    "MO01_FLOW_DRIVER": str(flow_driver),
                    "MO01_STATE_DRIVER": str(COMMON),
                    "MO01_CALL_LOG": str(calls),
                    "MO01_COMPARATOR": str(comparator),
                    "MO01_FAKE_CAPTURE_EXIT": str(case.get("target_capture_exit_code", 0)),
                    "MO01_FAKE_CAPTURE_PRODUCT_FAILURE": (
                        "1" if case.get("target_capture_product_failure") else "0"
                    ),
                    "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                },
            )

            assert result.returncode == case["expected_rc"], result.stdout + result.stderr
            assert "MO-01-manual-capture-order" in result.stdout
            assert "EXERCISER NOT WIRED" not in result.stdout
            assert "captured authorization order_id=101" in result.stdout
            assert "PASS log-clean ref" in result.stdout
            assert calls.read_text(encoding="utf-8").count("target:capture") == case["target_capture_calls"]

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"] == {
                "blocked": case["blocked"],
                "failed": case["failed"],
                "passed": 2 - case["failed"] - case["blocked"],
                "queued_agent_specs": 0,
            }

            if name == "pass":
                assert "cross-store pre/post parity: PASS" in result.stdout
                assert "[MO-01/target] deterministic verdict: PASS" in result.stdout
                rows = {
                    row["store"]: row
                    for row in rollup["results"]
                    if row["flow"] == "MO-01-manual-capture-order"
                }
                expected_files = {
                    "ref": {"ref-pre.json", "ref-post.json"},
                    "target": {
                        "ref-pre.json",
                        "ref-post.json",
                        "ref-execution.json",
                        "target-pre.json",
                        "target-post.json",
                        "target-execution.json",
                        "comparison.json",
                    },
                }
                expected_files["ref"].add("ref-execution.json")
                for store in ("ref", "target"):
                    row = rows[store]
                    manifest_path = Path(row["evidence_path"])
                    assert manifest_path.name == f"{store}-manifest.json"
                    assert row["evidence_sha256"] == file_sha256(manifest_path)
                    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                    assert manifest["schema"] == "woopayments_mo01_manifest.v1"
                    assert manifest["run_stamp"] == rollup["run_stamp"]
                    assert manifest["run_scope"] == "partial"
                    assert manifest["store"] == store
                    assert manifest["status"] == "pass"
                    assert manifest["exit_code"] == 0
                    assert manifest["verdict_sources"] == []
                    assert set(manifest["files"]) == expected_files[store]
                    unsigned = dict(manifest)
                    unsigned.pop("payload_sha256")
                    assert manifest["payload_sha256"] == "sha256:" + hashlib.sha256(
                        json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode(
                            "utf-8"
                        )
                    ).hexdigest()
                    for filename, binding in manifest["files"].items():
                        artifact = manifest_path.parent / filename
                        payload = json.loads(artifact.read_text(encoding="utf-8"))
                        assert binding == {
                            "file_sha256": file_sha256(artifact),
                            "payload_sha256": payload["payload_sha256"],
                        }

                mutated_dir = evidence_dir / "mutated-coherent-chain"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, mutated_dir)
                mutated_pre_path = mutated_dir / "target-pre.json"
                mutated_pre = json.loads(mutated_pre_path.read_text(encoding="utf-8"))
                mutated_pre["order"]["total_minor"] = 5100
                mutated_pre_unsigned = dict(mutated_pre)
                mutated_pre_unsigned.pop("payload_sha256")
                mutated_pre["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_pre_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_pre_path.write_text(json.dumps(mutated_pre), encoding="utf-8")

                mutated_comparison_path = mutated_dir / "comparison.json"
                mutated_comparison = json.loads(
                    mutated_comparison_path.read_text(encoding="utf-8")
                )
                mutated_comparison["inputs"]["target_pre"] = mutated_pre["payload_sha256"]
                mutated_comparison_unsigned = dict(mutated_comparison)
                mutated_comparison_unsigned.pop("payload_sha256")
                mutated_comparison["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_comparison_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_comparison_path.write_text(
                    json.dumps(mutated_comparison),
                    encoding="utf-8",
                )

                mutated_manifest_path = mutated_dir / "target-manifest.json"
                mutated_manifest = json.loads(
                    mutated_manifest_path.read_text(encoding="utf-8")
                )
                for filename in ("target-pre.json", "comparison.json"):
                    artifact = mutated_dir / filename
                    payload = json.loads(artifact.read_text(encoding="utf-8"))
                    mutated_manifest["files"][filename] = {
                        "file_sha256": file_sha256(artifact),
                        "payload_sha256": payload["payload_sha256"],
                    }
                mutated_manifest_unsigned = dict(mutated_manifest)
                mutated_manifest_unsigned.pop("payload_sha256")
                mutated_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_manifest_path.write_text(
                    json.dumps(mutated_manifest),
                    encoding="utf-8",
                )
                coherent_rehash = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(mutated_manifest_path),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert coherent_rehash.returncode == 3

                symlink_dir = evidence_dir / "symlinked-chain"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, symlink_dir)
                outside_pre = evidence_dir / "outside-target-pre.json"
                shutil.copy2(symlink_dir / "target-pre.json", outside_pre)
                (symlink_dir / "target-pre.json").unlink()
                (symlink_dir / "target-pre.json").symlink_to(outside_pre)
                symlink_validation = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(symlink_dir / "target-manifest.json"),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert symlink_validation.returncode == 3

                relabeled_dir = evidence_dir / "all-pass-relabeled-fail"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, relabeled_dir)
                relabeled_execution_path = relabeled_dir / "target-execution.json"
                relabeled_execution = json.loads(
                    relabeled_execution_path.read_text(encoding="utf-8")
                )
                relabeled_execution.update(
                    {
                        "status": "fail",
                        "exit_code": 1,
                        "verdict_sources": ["capture_operation_failed"],
                    }
                )
                relabeled_execution_unsigned = dict(relabeled_execution)
                relabeled_execution_unsigned.pop("payload_sha256")
                relabeled_execution["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        relabeled_execution_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                relabeled_execution_path.write_text(
                    json.dumps(relabeled_execution),
                    encoding="utf-8",
                )
                relabeled_manifest_path = relabeled_dir / "target-manifest.json"
                relabeled_manifest = json.loads(
                    relabeled_manifest_path.read_text(encoding="utf-8")
                )
                relabeled_manifest.update(
                    {
                        "status": "fail",
                        "exit_code": 1,
                        "verdict_sources": ["capture_operation_failed"],
                    }
                )
                relabeled_manifest["files"]["target-execution.json"] = {
                    "file_sha256": file_sha256(relabeled_execution_path),
                    "payload_sha256": relabeled_execution["payload_sha256"],
                }
                relabeled_manifest_unsigned = dict(relabeled_manifest)
                relabeled_manifest_unsigned.pop("payload_sha256")
                relabeled_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        relabeled_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                relabeled_manifest_path.write_text(
                    json.dumps(relabeled_manifest),
                    encoding="utf-8",
                )
                relabeled_validation = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(relabeled_manifest_path),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "fail",
                        "--expected-exit-code",
                        "1",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert relabeled_validation.returncode == 3
            elif name == "fail":
                assert "provider intent status=succeeded want=requires_capture" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "trusted_fail_log_blocked":
                assert "provider intent status=succeeded want=requires_capture" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                assert Path(target_row["evidence_path"]).is_file()
            elif name == "pass_log_blocked":
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest = json.loads(
                    Path(target_row["evidence_path"]).read_text(encoding="utf-8")
                )
                assert manifest["verdict_sources"] == ["log_assertion_blocked"]
            elif name == "parity_fail":
                assert "cross-store pre/post parity: FAIL" in result.stdout
                assert "pre-capture canonical state differs" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "blocked":
                assert "Provider state is unavailable." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "attribution_blocked":
                assert "runtime owner=plugin want=native" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "contradictory_blocked":
                assert "Provider state is unavailable." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "currency_fail":
                assert "provider intent currency=EUR want=USD" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "malformed_comparison_blocked":
                assert "Comparator emitted malformed or contradictory evidence." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                assert "evidence_path" in target_row, (
                    target_row,
                    result.stdout,
                    result.stderr,
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["comparison_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["comparison_exit_code"] == 3
                assert execution["verdict_sources"] == ["comparison_blocked"]
            elif name in {
                "capture_prerequisite_blocked",
                "capture_usage_blocked",
                "capture_transport_blocked",
            }:
                assert "capture operation blocked" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["status"] == "blocked"
                assert manifest["verdict_sources"] == ["capture_operation_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == case["target_capture_exit_code"]
                assert execution["capture_classification"] == "blocked"
                assert execution["post_state_exit_code"] == 1
            elif name == "capture_product_fail":
                assert "capture operation failed" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == [
                    "capture_operation_failed",
                    "comparison_failed",
                    "post_state_failed",
                ]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 1
                assert execution["capture_classification"] == "product_failure"
            elif name == "capture_product_fail_post_blocked":
                assert "post-capture state unavailable" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["capture_operation_failed"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 1
                assert execution["capture_classification"] == "product_failure"
                assert execution["post_state_exit_code"] == 3
            elif name == "capture_pass_post_blocked":
                assert "post-capture state unavailable" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["post_state_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 0
                assert execution["capture_classification"] == "pass"
                assert execution["post_state_exit_code"] == 3
            else:
                assert "pre-capture state driver could not run" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout


def test_mo02_deterministic_flow_uses_authorizations_api_and_row_capture() -> None:
    cases = (
        {
            "name": "pass",
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "pre_authorization_missing",
            "pre_authorization_present": False,
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "post_authorization_retained",
            "post_authorization_present": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "parity_fail",
            "target_total_minor": 5100,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "blocked",
            "target_status": "blocked",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "post_authorization_present": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_transport_blocked",
            "capture_status": "blocked",
            "capture_exit_code": 2,
            "post_authorization_present": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "pass_log_blocked",
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "malformed_comparison_blocked",
            "malformed_comparison": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
    )

    for case in cases:
        name = case["name"]
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-mo02-{name}-") as tmp:
            evidence_dir = Path(tmp)
            flow_driver = evidence_dir / "fake-flow-drive.sh"
            fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
            fake_target_wp = evidence_dir / "fake-target-wp.sh"
            calls = evidence_dir / "calls.txt"
            comparator = MO02_EVIDENCE

            write_executable(
                flow_driver,
                """#!/usr/bin/env bash
if [ "$1" != "charge" ]; then
  printf '%s\\n' 'MO-02 must capture through the authorizations REST route.' >&2
  exit 9
fi
if [ "$STORE_NAME" = "ref" ]; then
  printf '%s\\n' '{"op":"charge","order_id":101,"charge_id":"ch_ref_uncaptured","intent_id":"pi_ref_uncaptured"}'
else
  printf '%s\\n' '{"op":"charge","order_id":202,"charge_id":"ch_target_uncaptured","intent_id":"pi_target_uncaptured"}'
fi
""",
            )
            write_executable(
                fake_ref_wp,
                mo02_fake_wp_source(
                    "plugin",
                    "http://ref.fake.test",
                    mo02_state_payload("ref", "pre"),
                    mo02_state_payload("ref", "post"),
                    mo02_capture_payload("ref"),
                    calls,
                ),
            )
            target_total = case.get("target_total_minor", 5000)
            write_executable(
                fake_target_wp,
                mo02_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    mo02_state_payload(
                        "target",
                        "pre",
                        status=case.get("target_status", "raw"),
                        authorization_present=case.get("pre_authorization_present"),
                        total_minor=target_total,
                    ),
                    mo02_state_payload(
                        "target",
                        "post",
                        authorization_present=case.get("post_authorization_present"),
                        total_minor=target_total,
                    ),
                    mo02_capture_payload(
                        "target",
                        status=case.get("capture_status", "pass"),
                    ),
                    calls,
                    capture_exit_code=case.get("capture_exit_code", 0),
                    log_probe_exit_code=case.get("target_log_probe_exit_code", 0),
                ),
            )
            if case.get("malformed_comparison"):
                comparator = evidence_dir / "malformed-mo02-evidence.py"
                write_executable(
                    comparator,
                    f"""#!/usr/bin/env python3
import os
import sys

if sys.argv[1] == "compare":
    print("Traceback: comparator crashed")
    raise SystemExit(1)
os.execv(sys.executable, [sys.executable, {json.dumps(str(MO02_EVIDENCE))}, *sys.argv[1:]])
""",
                )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MO-02",
                evidence_dir=evidence_dir,
                extra_env={
                    "MO02_FLOW_DRIVER": str(flow_driver),
                    "MO02_STATE_DRIVER": str(COMMON),
                    "MO02_COMPARATOR": str(comparator),
                    "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                },
            )

            assert result.returncode == case["expected_rc"], result.stdout + result.stderr
            assert "MO-02-manual-capture-uncaptured-tab" in result.stdout
            assert "EXERCISER NOT WIRED" not in result.stdout
            assert "captured authorization order_id=101" in result.stdout
            call_lines = calls.read_text(encoding="utf-8").splitlines() if calls.exists() else []
            assert call_lines.count("target:capture") == case["target_capture_calls"]

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"] == {
                "blocked": case["blocked"],
                "failed": case["failed"],
                "passed": 2 - case["failed"] - case["blocked"],
                "queued_agent_specs": 0,
            }

            target_row = next(row for row in rollup["results"] if row["store"] == "target")
            assert "evidence_path" in target_row, (
                name,
                target_row,
                result.stdout,
                result.stderr,
            )
            manifest_path = Path(target_row["evidence_path"])
            assert target_row["evidence_sha256"] == file_sha256(manifest_path)
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            assert manifest["schema"] == "woopayments_mo02_manifest.v1"
            assert manifest["flow"] == "MO-02-manual-capture-uncaptured-tab"
            assert manifest["run_stamp"] == rollup["run_stamp"]

            if name == "pass":
                assert "authorizations row transition: PASS" in result.stdout
                assert "cross-store pre/post parity: PASS" in result.stdout
                assert "[MO-02/target] deterministic verdict: PASS" in result.stdout
                assert set(manifest["files"]) == {
                    "ref-pre.json",
                    "ref-capture.json",
                    "ref-post.json",
                    "ref-execution.json",
                    "target-pre.json",
                    "target-capture.json",
                    "target-post.json",
                    "target-execution.json",
                    "comparison.json",
                }

                ref_row = next(
                    row
                    for row in rollup["results"]
                    if row["store"] == "ref"
                    and row["flow"] == "MO-02-manual-capture-uncaptured-tab"
                )
                mutated_dir = evidence_dir / "mo02-ref-capture-rebound"
                shutil.copytree(Path(ref_row["evidence_path"]).parent, mutated_dir)
                mutated_capture_path = mutated_dir / "ref-capture.json"
                mutated_capture = json.loads(
                    mutated_capture_path.read_text(encoding="utf-8")
                )
                mutated_capture["order_id"] = 999
                mutated_capture["route"] = (
                    "/wc/v3/payments/orders/999/capture_authorization"
                )
                mutated_capture_unsigned = dict(mutated_capture)
                mutated_capture_unsigned.pop("payload_sha256")
                mutated_capture["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_capture_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_capture_path.write_text(
                    json.dumps(mutated_capture), encoding="utf-8"
                )

                mutated_manifest_path = mutated_dir / "ref-manifest.json"
                mutated_manifest = json.loads(
                    mutated_manifest_path.read_text(encoding="utf-8")
                )
                mutated_manifest["files"]["ref-capture.json"] = {
                    "file_sha256": file_sha256(mutated_capture_path),
                    "payload_sha256": mutated_capture["payload_sha256"],
                }
                mutated_manifest_unsigned = dict(mutated_manifest)
                mutated_manifest_unsigned.pop("payload_sha256")
                mutated_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_manifest_path.write_text(
                    json.dumps(mutated_manifest), encoding="utf-8"
                )
                rebound_validation = subprocess.run(
                    [
                        "python3",
                        str(MO02_EVIDENCE),
                        "validate-bound-manifest",
                        "--manifest",
                        str(mutated_manifest_path),
                        "--store",
                        "ref",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert rebound_validation.returncode == 3
            elif name == "pre_authorization_missing":
                assert "exact authorization row count=0 want=1" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "post_authorization_retained":
                assert "exact authorization row count=1 want=0" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "parity_fail":
                assert "cross-store pre/post parity: FAIL" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "blocked":
                assert "Authorizations endpoint is unavailable." in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "capture_product_fail":
                assert "row capture operation failed" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "capture_transport_blocked":
                assert "row capture operation blocked" in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "pass_log_blocked":
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
                assert manifest["verdict_sources"] == ["log_assertion_blocked"]
            else:
                assert "Comparator emitted malformed or contradictory evidence." in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout


def test_mo02_evidence_rejects_incomplete_fail_transient_http_and_expired_row() -> None:
    run_stamp = "20260716T143519Z-19751"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo02-adversarial-") as tmp:
        evidence_dir = Path(tmp)
        execution = {
            "schema": "woopayments_mo02_execution.v1",
            "status": "fail",
            "store": "ref",
            "run_stamp": run_stamp,
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "authorization_exit_code": 0,
            "authorization_order_id_present": True,
            "pre_state_exit_code": 0,
            "capture_exit_code": 0,
            "capture_classification": "pass",
            "post_state_exit_code": 1,
            "comparison_exit_code": None,
            "log_assertion_exit_code": 0,
        }
        execution["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(execution, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        execution_path = evidence_dir / "ref-execution.json"
        execution_path.write_text(json.dumps(execution), encoding="utf-8")
        manifest = {
            "schema": "woopayments_mo02_manifest.v1",
            "flow": "MO-02-manual-capture-uncaptured-tab",
            "run_stamp": run_stamp,
            "run_scope": "partial",
            "store": "ref",
            "status": "fail",
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "files": {
                "ref-execution.json": {
                    "file_sha256": file_sha256(execution_path),
                    "payload_sha256": execution["payload_sha256"],
                }
            },
        }
        manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest_path = evidence_dir / "ref-manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        incomplete_fail = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "validate-bound-manifest",
                "--manifest",
                str(manifest_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--expected-status",
                "fail",
                "--expected-exit-code",
                "1",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert incomplete_fail.returncode == 3

        transient_capture = mo02_capture_payload("ref", status="fail")
        transient_capture["http_status"] = 503
        transient_capture_result = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "normalize-capture",
                "--store",
                "ref",
                "--order-id",
                "101",
                "--intent-id",
                "pi_ref_uncaptured",
                "--charge-id",
                "ch_ref_uncaptured",
                "--expected-exit-code",
                "1",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(transient_capture),
            text=True,
            capture_output=True,
            check=False,
        )
        assert transient_capture_result.returncode == 3
        assert json.loads(transient_capture_result.stdout)["status"] == "blocked"

        expired_state = mo02_state_payload("ref", "pre")
        expired_state["order"]["created"] = 1784212519
        expired_state["authorizations"]["observed_at"] = 1784212519
        expired_state["authorizations"]["matched_rows"][0].update(
            {"status": "expired", "created": 1}
        )
        expired_state_result = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "normalize-state",
                "--store",
                "ref",
                "--phase",
                "pre",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(expired_state),
            text=True,
            capture_output=True,
            check=False,
        )
        assert expired_state_result.returncode == 1
        expired_payload = json.loads(expired_state_result.stdout)
        assert "authorization status=expired want=succeeded" in expired_payload["errors"]
        assert "authorization created timestamp is outside the active window" in expired_payload[
            "errors"
        ]


def test_mo01_comparator_rejects_swapped_and_malformed_normalized_evidence() -> None:
    run_stamp = "20260716T120000Z-12345"

    def normalize(payload: dict, store: str, phase: str) -> tuple[subprocess.CompletedProcess[str], dict]:
        result = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "normalize",
                "--store",
                store,
                "--phase",
                phase,
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        return result, json.loads(result.stdout)

    wrong_owner, wrong_owner_payload = normalize(
        mo01_state_payload("target", "pre", runtime_owner="plugin"),
        "target",
        "pre",
    )
    assert wrong_owner.returncode == 3
    assert wrong_owner_payload["status"] == "blocked"
    assert "runtime owner=plugin want=native" in wrong_owner_payload["blockers"]

    raw_blocker, raw_blocker_payload = normalize(
        mo01_state_payload(
            "target",
            "pre",
            blockers=["Provider state is unavailable."],
        ),
        "target",
        "pre",
    )
    assert raw_blocker.returncode == 3
    assert raw_blocker_payload["status"] == "blocked"
    assert raw_blocker_payload["blockers"] == ["Provider state is unavailable."]

    currency_mismatch, currency_payload = normalize(
        mo01_state_payload("target", "pre", provider_currency="EUR"),
        "target",
        "pre",
    )
    assert currency_mismatch.returncode == 1
    assert "provider intent currency=EUR want=USD" in currency_payload["errors"]
    assert "provider charge currency=EUR want=USD" in currency_payload["errors"]

    duplicate_raw = subprocess.run(
        [
            "python3",
            str(MO01_COMPARATOR),
            "normalize",
            "--store",
            "target",
            "--phase",
            "pre",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=(
            json.dumps(mo01_state_payload("target", "pre"))
            + "\n"
            + json.dumps(
                mo01_state_payload("target", "pre", intent_status="succeeded")
            )
        ),
        text=True,
        capture_output=True,
        check=False,
    )
    duplicate_payload = json.loads(duplicate_raw.stdout)
    assert duplicate_raw.returncode == 3
    assert duplicate_payload["status"] == "blocked"
    assert duplicate_payload["blockers"] == [
        "State driver must emit exactly one MO-01 payload; observed 2."
    ]

    missing_intent = mo01_state_payload("target", "pre")
    missing_intent["order"]["intent_id"] = ""
    missing_intent["provider"].update(
        {
            "intent_id": "",
            "intent_status": "",
            "intent_amount_minor": 0,
            "intent_currency": "",
            "charge_id": "",
            "charge_amount_minor": 0,
            "charge_amount_captured_minor": 0,
            "charge_captured": False,
            "charge_currency": "",
        }
    )
    missing_intent_result, missing_intent_payload = normalize(
        missing_intent,
        "target",
        "pre",
    )
    assert missing_intent_result.returncode == 1
    assert missing_intent_payload["status"] == "fail"
    assert "order intent id is not provider-backed" in missing_intent_payload["errors"]

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo01-compare-") as tmp:
        root = Path(tmp)
        empty_fail_manifest = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "manifest",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--output",
                str(root / "empty-fail-manifest.json"),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert empty_fail_manifest.returncode == 3
        unbound_operational_fail = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "manifest",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--verdict-source",
                "capture_operation_failed",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--output",
                str(root / "unbound-operational-fail.json"),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert unbound_operational_fail.returncode == 3
        capture_block_mislabeled_fail = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "execution",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--verdict-source",
                "capture_operation_failed",
                "--run-stamp",
                run_stamp,
                "--output",
                str(root / "capture-block-mislabeled-fail.json"),
                "--authorization-exit-code",
                "0",
                "--authorization-order-id-present",
                "true",
                "--pre-state-exit-code",
                "0",
                "--capture-exit-code",
                "3",
                "--capture-classification",
                "blocked",
                "--post-state-exit-code",
                "1",
                "--log-assertion-exit-code",
                "0",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert capture_block_mislabeled_fail.returncode == 3

        normalized: dict[tuple[str, str], dict] = {}
        paths: dict[tuple[str, str], Path] = {}
        for store in ("ref", "target"):
            for phase in ("pre", "post"):
                result, payload = normalize(mo01_state_payload(store, phase), store, phase)
                assert result.returncode == 0
                assert payload["payload_sha256"].startswith("sha256:")
                normalized[(store, phase)] = payload
                path = root / f"{store}-{phase}.json"
                path.write_text(json.dumps(payload), encoding="utf-8")
                paths[(store, phase)] = path

        def compare(target_pre: Path, target_post: Path) -> tuple[subprocess.CompletedProcess[str], dict]:
            result = subprocess.run(
                [
                    "python3",
                    str(MO01_COMPARATOR),
                    "compare",
                    "--reference-pre",
                    str(paths[("ref", "pre")]),
                    "--reference-post",
                    str(paths[("ref", "post")]),
                    "--target-pre",
                    str(target_pre),
                    "--target-post",
                    str(target_post),
                    "--run-stamp",
                    run_stamp,
                ],
                cwd=REPO,
                text=True,
                capture_output=True,
                check=False,
            )
            return result, json.loads(result.stdout)

        valid, valid_payload = compare(
            paths[("target", "pre")],
            paths[("target", "post")],
        )
        assert valid.returncode == 0
        assert valid_payload["status"] == "pass"
        assert valid_payload["inputs"] == {
            "ref_pre": normalized[("ref", "pre")]["payload_sha256"],
            "ref_post": normalized[("ref", "post")]["payload_sha256"],
            "target_pre": normalized[("target", "pre")]["payload_sha256"],
            "target_post": normalized[("target", "post")]["payload_sha256"],
        }
        valid_unsigned = dict(valid_payload)
        valid_unsigned.pop("payload_sha256")
        assert valid_payload["payload_sha256"] == "sha256:" + hashlib.sha256(
            json.dumps(valid_unsigned, sort_keys=True, separators=(",", ":")).encode(
                "utf-8"
            )
        ).hexdigest()

        forged = json.loads(json.dumps(valid_payload))
        forged["inputs"] = {
            "ref_pre": "sha256:" + "1" * 64,
            "ref_post": "sha256:" + "2" * 64,
            "target_pre": "sha256:" + "3" * 64,
            "target_post": "sha256:" + "4" * 64,
        }
        forged["reference"] = {"pre": {}, "post": {}}
        forged["target"] = {"pre": {}, "post": {}}
        forged_unsigned = dict(forged)
        forged_unsigned.pop("payload_sha256")
        forged["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(forged_unsigned, sort_keys=True, separators=(",", ":")).encode(
                "utf-8"
            )
        ).hexdigest()
        forged_validation = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "validate-comparison",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "0",
            ],
            cwd=REPO,
            input=json.dumps(forged),
            text=True,
            capture_output=True,
            check=False,
        )
        assert forged_validation.returncode == 3

        swapped, swapped_payload = compare(paths[("ref", "pre")], paths[("ref", "post")])
        assert swapped.returncode == 3
        assert swapped_payload["status"] == "blocked"
        assert any(
            "target_pre" in reason and "store=ref want=target" in reason
            for reason in swapped_payload["blockers"]
        )
        assert any(
            "target_post" in reason and "runtime owner=plugin want=native" in reason
            for reason in swapped_payload["blockers"]
        )

        malformed_path = root / "target-pre-malformed.json"
        malformed_path.write_text(
            json.dumps(
                {
                    "schema": "woopayments_mo01_normalized.v1",
                    "status": "pass",
                    "store": "target",
                    "phase": "pre",
                    "runtime_owner": "native",
                    "run_stamp": run_stamp,
                    "errors": [],
                    "blockers": [],
                }
            ),
            encoding="utf-8",
        )
        malformed, malformed_payload = compare(malformed_path, paths[("target", "post")])
        assert malformed.returncode == 3
        assert malformed_payload["status"] == "blocked"
        assert any("target-pre-malformed.json" in reason for reason in malformed_payload["blockers"])

        invalid_utf8_path = root / "target-pre-invalid-utf8.json"
        invalid_utf8_path.write_bytes(b"\xff\xfe{not utf8}")
        invalid_utf8, invalid_utf8_payload = compare(
            invalid_utf8_path,
            paths[("target", "post")],
        )
        assert invalid_utf8.returncode == 3
        assert invalid_utf8_payload["status"] == "blocked"
        assert any(
            "target-pre-invalid-utf8.json" in reason
            for reason in invalid_utf8_payload["blockers"]
        )

        contradictory_path = root / "target-pre-contradictory.json"
        contradictory = dict(normalized[("target", "pre")])
        contradictory["errors"] = ["Smuggled error."]
        contradictory["blockers"] = ["Smuggled blocker."]
        contradictory_path.write_text(json.dumps(contradictory), encoding="utf-8")
        contradiction, contradiction_payload = compare(
            contradictory_path,
            paths[("target", "post")],
        )
        assert contradiction.returncode == 3
        assert contradiction_payload["status"] == "blocked"
        assert any("status/error/blocker fields are inconsistent" in reason for reason in contradiction_payload["blockers"])

        unexpected_path = root / "target-pre-unexpected.json"
        unexpected = json.loads(json.dumps(normalized[("target", "pre")]))
        unexpected["unexpected"] = "smuggled"
        unexpected["order"]["unexpected_status"] = "failed"
        unexpected["provider"]["unexpected_currency"] = "EUR"
        unsigned = dict(unexpected)
        unsigned.pop("payload_sha256")
        unexpected["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        unexpected_path.write_text(json.dumps(unexpected), encoding="utf-8")
        unexpected_result, unexpected_payload = compare(
            unexpected_path,
            paths[("target", "post")],
        )
        assert unexpected_result.returncode == 3
        assert unexpected_payload["status"] == "blocked"
        assert any("unexpected normalized fields" in reason for reason in unexpected_payload["blockers"])
        assert any("order has unexpected fields" in reason for reason in unexpected_payload["blockers"])
        assert any("provider has unexpected fields" in reason for reason in unexpected_payload["blockers"])


def test_mo01_runner_rejects_an_invalid_deterministic_manifest() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-mo01-invalid-manifest-") as tmp:
        evidence_dir = Path(tmp)
        flows_dir = evidence_dir / "flows"
        flows_dir.mkdir()
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(
            fake_target_wp,
            probe_only_fake_wp_source("native", "http://target.fake.test"),
        )
        write_executable(
            flows_dir / "MO-01-manual-capture-order.sh",
            """#!/usr/bin/env bash
manifest_dir="$EVIDENCE_DIR/runs/$CRITICAL_FLOWS_RUN_STAMP-$CRITICAL_FLOWS_RUN_SCOPE/MO-01-manual-capture-order"
mkdir -p "$manifest_dir"
printf '%s\n' '{"schema":"woopayments_mo01_manifest.v1","flow":"MO-01-manual-capture-order","run_stamp":"wrong","run_scope":"partial","store":"target","status":"pass","exit_code":0,"verdict_sources":[],"files":{},"payload_sha256":"sha256:0000000000000000000000000000000000000000000000000000000000000000"}' > "$manifest_dir/target-manifest.json"
exit 0
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MO-01",
            evidence_dir=evidence_dir,
            extra_env={
                "FLOWS_DIR": str(flows_dir),
                "TARGET_WP_COMMAND": str(fake_target_wp),
            },
        )

        assert result.returncode == 3
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["summary"] == {
            "blocked": 1,
            "failed": 0,
            "passed": 0,
            "queued_agent_specs": 0,
        }
        row = rollup["results"][0]
        assert row["status"] == "BLOCKED"
        assert row["exit_code"] == 3
        assert "different runner invocation" in row["reason"]
        assert "evidence_path" not in row
        assert "evidence_sha256" not in row


def test_sp01_deterministic_flow_classifies_pass_fail_and_blocked() -> None:
    passing_checks = {
        "exactly_one_woopayments_token": True,
        "visa_4242_token": True,
        "provider_payment_method_id": True,
        "customer_binding": True,
        "setup_intent_succeeded": True,
        "no_orders_created": True,
        "no_charge_created": True,
    }
    cases = (
        (
            "pass",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "pass",
                "checks": passing_checks,
                "fixture": {
                    "user_id": 91,
                    "token_id": 17,
                    "payment_method_id": "pm_unitvisa4242",
                    "setup_intent_id": "seti_unitvisa4242",
                    "wcpay_customer_id": "cus_unitvisa4242",
                },
                "errors": [],
                "blockers": [],
            },
            0,
            "deterministic verdict: PASS",
            0,
        ),
        (
            "fail",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "fail",
                "checks": {**passing_checks, "visa_4242_token": False},
                "fixture": {"user_id": 92, "token_id": 18},
                "errors": ["Saved token last four did not match 4242."],
                "blockers": [],
            },
            1,
            "deterministic verdict: FAIL",
            0,
        ),
        (
            "blocked",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "blocked",
                "checks": {},
                "fixture": {},
                "errors": [],
                "blockers": ["Stripe test key is unavailable."],
            },
            3,
            "deterministic verdict: BLOCKED",
            0,
        ),
        (
            "fail-with-log-probe-blocked",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "fail",
                "checks": {**passing_checks, "customer_binding": False},
                "fixture": {"user_id": 93, "token_id": 19},
                "errors": ["Provider customer binding did not match."],
                "blockers": [],
            },
            3,
            "deterministic verdict: BLOCKED",
            2,
        ),
    )

    for case_name, payload, expected_rc, expected_verdict, log_probe_exit_code in cases:
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-sp01-{case_name}-") as tmp:
            evidence_dir = Path(tmp)
            fake_wp = evidence_dir / "fake-wp.sh"
            write_executable(
                fake_wp,
                sp01_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    payload,
                    log_probe_exit_code=log_probe_exit_code,
                ),
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "deterministic",
                "--flow",
                "SP-01",
                evidence_dir=evidence_dir,
                extra_env={"TARGET_WP_COMMAND": str(fake_wp)},
            )

            assert result.returncode == expected_rc, result.stdout + result.stderr
            assert "SP-01-add-payment-method-card" in result.stdout
            assert expected_verdict in result.stdout
            assert "run archived ->" in result.stdout

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            expected_status = "pass" if 0 == expected_rc else "blocked" if 3 == expected_rc else "fail"
            assert rollup["status"] == expected_status
            assert strip_recorded_at(rollup) == [
                {
                    "flow": "SP-01-add-payment-method-card",
                    "layer": "deterministic",
                    "store": "target",
                    "status": expected_status.upper(),
                    "exit_code": expected_rc,
                }
            ]


def test_ma09_deterministic_flow_compares_exact_datasets_and_timings() -> None:
    invalid_median = ma09_payload("target")
    invalid_median["timings"]["page_1"]["measured_median_seconds"] = 9.0
    empty_filter_failure = ma09_payload(
        "target",
        status="fail",
        check_overrides={"type_filter_exact": False},
    )
    empty_filter_failure["type_filter_count"] = 0
    cases = (
        (
            "pass",
            ma09_payload("ref"),
            ma09_payload("target", measured_seconds=(0.8, 0.9)),
            0,
            "[MA-09/target] deterministic verdict: PASS",
            0,
            False,
        ),
        (
            "dataset-mismatch",
            ma09_payload("ref"),
            ma09_payload("target", dataset_count=501),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "relative-performance-failure",
            ma09_payload("ref", measured_seconds=(0.5, 0.5)),
            ma09_payload("target", measured_seconds=(1.01, 1.02)),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "pagination-failure",
            ma09_payload("ref"),
            ma09_payload(
                "target",
                status="fail",
                check_overrides={"pagination_exact": False},
            ),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "empty-filter-failure",
            ma09_payload("ref"),
            empty_filter_failure,
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "absolute-performance-failure",
            ma09_payload("ref"),
            ma09_payload("target", measured_seconds=(10.01, 0.9)),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "malformed-median",
            ma09_payload("ref"),
            invalid_median,
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "unseeded",
            ma09_payload("ref"),
            ma09_payload("target", dataset_count=499, status="blocked"),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "product-failure-with-log-probe-blocked",
            ma09_payload("ref"),
            ma09_payload(
                "target",
                status="fail",
                check_overrides={"date_filter_exact": False},
            ),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            2,
            False,
        ),
        (
            "dirty-log",
            ma09_payload("ref"),
            ma09_payload("target"),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            True,
        ),
    )

    for case_name, ref_payload, target_payload, expected_rc, target_verdict, target_log_rc, dirty_log in cases:
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma09-{case_name}-") as tmp:
            evidence_dir = Path(tmp)
            fake_ref = evidence_dir / "fake-ref-wp.sh"
            fake_target = evidence_dir / "fake-target-wp.sh"
            fake_driver = evidence_dir / "fake-ma09-driver.php"
            fake_driver.write_text("<?php // Test seam.\n", encoding="utf-8")
            write_executable(
                fake_ref,
                ma09_fake_wp_source("plugin", "http://ref.fake.test", ref_payload),
            )
            write_executable(
                fake_target,
                ma09_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    target_payload,
                    log_probe_exit_code=target_log_rc,
                    dirty_log=dirty_log,
                ),
            )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MA-09",
                evidence_dir=evidence_dir,
                extra_env={
                    "MA09_STATE_DRIVER": str(fake_driver),
                    "REF_WP_COMMAND": str(fake_ref),
                    "TARGET_WP_COMMAND": str(fake_target),
                },
            )

            assert result.returncode == expected_rc, result.stdout + result.stderr
            assert "MA-09-large-dataset-perf" in result.stdout
            assert target_verdict in result.stdout
            assert result.stdout.count("[MA-09/ref] deterministic verdict:") == 1
            assert result.stdout.count("[MA-09/target] deterministic verdict:") == 1

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert len(rollup["results"]) == 2
            assert {row["store"] for row in rollup["results"]} == {"ref", "target"}
            if case_name == "pass":
                run_dir = evidence_dir / "runs" / f"{rollup['run_stamp']}-{rollup['scope']}" / "MA-09-large-dataset-perf"
                assert {path.name for path in run_dir.iterdir()} == {"comparison.json", "ref.json", "target.json"}
                ref_result = json.loads((run_dir / "ref.json").read_text(encoding="utf-8"))
                target_result = json.loads((run_dir / "target.json").read_text(encoding="utf-8"))
                comparison = json.loads((run_dir / "comparison.json").read_text(encoding="utf-8"))
                assert comparison["runner_run_stamp"] == rollup["run_stamp"]
                assert comparison["reference_normalized_sha256"] == ref_result["normalized_sha256"]
                assert comparison["target_normalized_sha256"] == target_result["normalized_sha256"]


def test_ma09_php_driver_classifies_registered_endpoint_exception_as_failure() -> None:
    for failure_mode in ("timed", "type_filter", "date_filter"):
        result = run_ma09_php_driver_with_endpoint_exception(failure_mode)

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout.strip().splitlines()[-1])
        assert payload["status"] == "fail"
        assert payload["blockers"] == []
        assert "The internal REST request threw before returning a response." in payload["errors"]
        if failure_mode == "timed":
            assert "Timed page_20 did not produce three complete samples." in payload["errors"]
            assert payload["timings"]["page_20"]["samples_seconds"][1] is None
            assert payload["timings"]["page_20"]["measured_median_seconds"] is None
        elif failure_mode == "type_filter":
            assert payload["type_filter_count"] == 0
            assert payload["checks"]["type_filter_exact"] is False
        else:
            assert payload["date_filter_count"] == 0
            assert payload["checks"]["date_filter_exact"] is False

        with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma09-{failure_mode}-") as tmp:
            evidence_dir = Path(tmp)
            fake_ref = evidence_dir / "fake-ref-wp.sh"
            fake_target = evidence_dir / "fake-target-wp.sh"
            fake_driver = evidence_dir / "fake-ma09-driver.php"
            fake_driver.write_text("<?php // Test seam.\n", encoding="utf-8")
            write_executable(
                fake_ref,
                ma09_fake_wp_source("plugin", "http://ref.fake.test", ma09_payload("ref")),
            )
            write_executable(
                fake_target,
                ma09_fake_wp_source("native", "http://target.fake.test", payload),
            )

            runner_result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MA-09",
                evidence_dir=evidence_dir,
                extra_env={
                    "MA09_STATE_DRIVER": str(fake_driver),
                    "REF_WP_COMMAND": str(fake_ref),
                    "TARGET_WP_COMMAND": str(fake_target),
                },
            )

            assert runner_result.returncode == 1, runner_result.stdout + runner_result.stderr
            assert "[MA-09/target] deterministic verdict: FAIL" in runner_result.stdout
            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            target_result = next(row for row in rollup["results"] if row["store"] == "target")
            assert target_result["status"] == "FAIL"


def test_card_checkout_flow_passes_on_reference_with_empty_native_flags() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":456,"charge_id":"ch_ref","intent_id":"pi_ref"}'
""",
        )
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "wc" ] && [ "$2" = "shop_order" ] && [ "$3" = "get" ]; then
  printf '%s\\n' "processing"
  exit 0
fi
if [ "$1" = "post" ] && [ "$2" = "meta" ] && [ "$3" = "get" ]; then
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=plugin"
    printf '%s\\n' "store_identity_home=http://ref.fake.test"
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value=pi_ref"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value=ch_ref"
    exit 0
  fi
  printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0
        assert "captured order_id=456" in result.stdout
        assert "native_flag[@]: unbound variable" not in result.stdout


def test_flow_drive_parses_wp_env_json_before_success_footer() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "${1:-}" = "eval" ]; then
  # flow-drive's native live-money guard probes test mode before driving.
  printf 'WCPAY_NATIVE_TEST_MODE:yes\\n'
  exit 0
fi
cat <<'OUT'
ℹ Starting 'wp eval-file - test-lab-beaker-001 2 pm_card_visa 0 ' on the cli container.
{"order_id":137,"charge_id":"ch_fake","intent_id":"pi_fake","status":"processing"}
✔ Ran `wp eval-file - test-lab-beaker-001 2 pm_card_visa 0 ` in 'cli'. (in 7s 723ms)
OUT
""",
        )
        # flow-drive validates $WP as a local-only runner, so the bare delegate path is
        # wrapped in the same Docker-shaped test transport the merge harness tests use.
        runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
        env["WP"] = runner

        result = subprocess.run(
            [
                "bash",
                str(FLOW_DRIVE),
                "charge",
                "--deterministic",
                "--native",
                "--sku",
                "test-lab-beaker-001",
                "--quantity",
                "2",
                "--type",
                "success",
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["op"] == "charge"
        assert payload["order_id"] == 137
        assert payload["charge_id"] == "ch_fake"


def test_flow_drive_rejects_remote_wp_runner_with_exit_2() -> None:
    result = subprocess.run(
        ["bash", str(FLOW_DRIVE), "charge", "--deterministic"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "WP": "wp --ssh=user@remote.example"},
        check=False,
    )

    assert result.returncode == 2
    assert "unsafe WP-CLI command" in result.stderr


def test_common_wp_wrappers_accept_command_strings_with_args() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-command-string-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
printf '%s\\n' "$*"
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
REF_WP_COMMAND={shlex.quote(str(fake_wp) + " --runner-flag")}
wp_ref option get home
"""

        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0
        assert result.stdout.strip() == "--runner-flag option get home"


def test_card_checkout_flow_exports_command_string_helper_to_flow_driver() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
set -e
"$WP" option get home >/dev/null
printf '%s\\n' '{"op":"charge","order_id":789,"charge_id":"ch_export","intent_id":"pi_export"}'
""",
        )
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "--runner-flag" ] && [ "$2" = "option" ]; then
  printf '%s\\n' "http://example.test"
  exit 0
fi
if [ "$1" = "--runner-flag" ] && [ "$2" = "wc" ] && [ "$3" = "shop_order" ]; then
  printf '%s\\n' "processing"
  exit 0
fi
if [ "$1" = "--runner-flag" ] && [ "$2" = "post" ] && [ "$3" = "meta" ]; then
  exit 0
fi
if [ "$1" = "--runner-flag" ] && [ "$2" = "eval" ]; then
  if [[ "$3" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=plugin"
    printf '%s\\n' "store_identity_home=http://ref.fake.test"
    exit 0
  fi
  if [[ "$3" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$3" == *"wc_get_order"* && "$3" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value=pi_export"
    exit 0
  fi
  if [[ "$3" == *"wc_get_order"* && "$3" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value=ch_export"
    exit 0
  fi
  printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": f"{fake_wp} --runner-flag",
            },
        )

        assert result.returncode == 0
        assert "captured order_id=789" in result.stdout
        assert "run_wp_command_string: command not found" not in result.stdout


def test_card_checkout_flow_blocks_when_exerciser_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' "FLOW-DRIVE FAIL (charge): account is not connected" >&2
exit 1
""",
        )
        write_executable(fake_wp, probe_only_fake_wp_source("native", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3
        assert "SC-01-card-checkout" in result.stdout
        assert "BLOCKED" in result.stdout
        assert "deterministic charge exerciser failed" in result.stdout
        assert "account is not connected" in result.stdout
        assert "EXERCISER NOT WIRED" not in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
            }
        ]


def test_agent_layer_queued_specs_are_blocked_until_executed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert "[BLOCKED] SC-14-lpm-wave-1-checkout on target" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
                "reason": "agent spec queued; no result file",
            }
        ]


def test_agent_layer_skips_specs_that_require_no_browser_layer() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 0
        assert "MA-10-i18n-order-notes on target" not in result.stdout
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert rollup["results"] == []


def test_deterministic_layer_runs_no_browser_specs_through_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "i18n-gate-calls.log"

        write_executable(
            fake_gate,
            ma10_fake_gate_source(),
        )
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 0
        assert "MA-10-i18n-order-notes" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout
        calls_lines = calls.read_text(encoding="utf-8").splitlines()
        assert calls_lines[0] == "marker"
        assert calls_lines[1].startswith(f"gate --target {fake_wp} --out-dir ")
        assert calls_lines[2] == "scan"

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        run_dir = evidence_dir / "runs" / f"{rollup['run_stamp']}-{rollup['scope']}"
        manifest = run_dir / "MA-10-i18n-order-notes/manifest.json"
        assert manifest.exists()
        manifest_sha256 = f"sha256:{hashlib.sha256(manifest.read_bytes()).hexdigest()}"
        rows = strip_recorded_at(rollup)
        assert rows == [
            {
                "flow": "MA-10-i18n-order-notes",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "evidence_path": str(manifest),
                "evidence_sha256": manifest_sha256,
                "reason": "validated MA-10 evidence manifest",
            }
        ]


def test_ma10_reference_is_blocked_without_manufacturing_an_oracle() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-ref-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-ref-wp.sh"
        gate_calls = evidence_dir / "gate-calls.log"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "eval" ] && [[ "$2" == *"store_identity_owner"* ]]; then
  printf '%s\\n' 'store_identity_owner=plugin'
  printf '%s\\n' 'store_identity_home=http://reference.fake.test'
  exit 0
fi
exit 2
""",
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(gate_calls),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "reference extension same-note-family oracle is not wired" in result.stdout
        assert not gate_calls.exists()
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert strip_recorded_at(rollup) == [
            {
                "flow": "MA-10-i18n-order-notes",
                "layer": "deterministic",
                "store": "ref",
                "status": "BLOCKED",
                "exit_code": 3,
                "reason": "reference extension same-note-family oracle is not wired",
            }
        ]


def test_ma10_deterministic_flow_fails_when_debug_log_is_dirty() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-dirty-log-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(log_status="fail"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL log-clean target" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_deterministic_flow_blocks_when_gate_evidence_is_malformed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-malformed-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(fake_gate, ma10_fake_gate_source(malformed=True))
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "BLOCKED: MA-10 gate evidence is invalid" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert strip_recorded_at(rollup)[0]["reason"] == "MA-10 evidence validation did not complete"


def test_ma10_blocks_when_one_required_packet_file_is_missing() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-missing-file-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_REMOVE_FILE": "refund-flow.json",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "required evidence files are missing: refund-flow.json" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout


def test_ma10_blocks_when_result_and_state_files_contradict_each_other() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-contradict-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CONTRADICT_STATE": "1",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "gate result state does not match i18n-notes-state.json" in result.stdout


def test_ma10_blocks_when_completed_log_scan_contains_no_scanned_paths() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-empty-log-scan-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(scanned_paths=[]))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "completed debug-log scan contains no scanned paths" in result.stdout


def test_ma10_blocks_when_evidence_validator_crashes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-validator-crash-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        fake_validator = evidence_dir / "fake-validator.py"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())
        fake_validator.write_text("raise RuntimeError('validator crash')\n", encoding="utf-8")

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "MA10_EVIDENCE_VALIDATOR": str(fake_validator),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "RuntimeError: validator crash" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout


def test_ma10_blocks_before_gate_when_current_log_marker_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-marker-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "calls.log"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(marker_ok=False))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["marker"]
        assert "log-clean marker" in result.stdout


def test_ma10_fails_when_refund_marker_only_comes_from_unrelated_email_note() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-english-refund-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source("blocked", english_refund=True))
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL: MA-10 captured English merchant note" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_gate_cleanup_failure_is_blocked_not_product_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-cleanup-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source(exit_code_override=70))
        write_executable(fake_wp, ma10_fake_wp_source(log_status="fail"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout


def test_ma10_preserves_valid_gate_failure_when_log_scan_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-fail-log-block-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source("fail"))
        write_executable(fake_wp, ma10_fake_wp_source(log_status="blocked"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "BLOCKED log-clean check for target" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_real_gate_preserves_early_flow_driver_product_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-real-gate-fail-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-target-wp.sh"
        fake_flow = evidence_dir / "fake-flow.sh"
        write_executable(fake_wp, ma10_live_failure_wp_source())
        write_executable(
            fake_flow,
            """#!/usr/bin/env bash
printf '%s\n' '{"op":"charge","result":"fail","reason":"declined"}'
exit 1
""",
        )
        target_runner, adapted_env = adapt_single_wp_runner(
            str(fake_wp), os.environ.copy(), role="target"
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                **adapted_env,
                "I18N_NOTES_GATE": str(REPO / "tools/woopayments-merge/i18n-notes-gate.sh"),
                "I18N_NOTES_FLOW_DRIVE": str(fake_flow),
                "TARGET_WP_COMMAND": target_runner,
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL: charge flow failed." in result.stderr
        assert "FAIL: MA-10 flow driver reported product failure: charge" in result.stdout
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        row = strip_recorded_at(rollup)[0]
        manifest = Path(row["evidence_path"])
        assert row["status"] == "FAIL"
        assert row["evidence_sha256"] == f"sha256:{hashlib.sha256(manifest.read_bytes()).hexdigest()}"
        manifest_payload = json.loads(manifest.read_text(encoding="utf-8"))
        assert manifest_payload["status"] == "fail"
        assert "i18n-flow-failure.json" in manifest_payload["files"]
        assert "i18n-notes-gate.json" not in manifest_payload["files"]


def test_ma10_early_later_flow_failure_requires_successful_prefix_packets() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-prefix-") as tmp:
        root = Path(tmp)
        for flow, missing_names in (
            ("refund", "charge-flow.json"),
            ("dispute", "charge-flow.json, refund-flow.json"),
        ):
            evidence_dir = root / flow
            evidence_dir.mkdir()
            failed_payload = {"op": flow, "result": "fail", "reason": "fixture failure"}
            support = {
                f"{flow}-flow.json": failed_payload,
                "i18n-flow-failure.json": {
                    "schema": "woopayments_i18n_flow_failure.v1",
                    "flow": flow,
                    "driver_exit": 1,
                    "payload": failed_payload,
                },
                "i18n-language-snapshot.json": {
                    "schema": "woopayments_i18n_language_snapshot.v1",
                    "success": True,
                    "exists": True,
                    "value": "en_US",
                    "autoload": "auto",
                },
                "i18n-language-restore.json": {
                    "schema": "woopayments_i18n_language_restore.v1",
                    "success": True,
                    "restored_snapshot_exact": True,
                    "errors": [],
                },
                "i18n-probe-cleanup.json": {
                    "schema": "woopayments_i18n_probe_cleanup.v1",
                    "success": True,
                    "errors": [],
                },
                "i18n-catalog-evidence.json": ma10_gate_payload()["state"]["catalog_evidence"],
                "debug-log-scan.json": {
                    "schema": "woopayments_debug_log_scan.v1",
                    "store": "target",
                    "scan": {
                        "status": "pass",
                        "paths": ["/tmp/fake-debug.log"],
                        "matches": [],
                        "marker": {
                            "created_at": "2026-07-16T10:00:00Z",
                            "paths": {"/tmp/fake-debug.log": 0},
                        },
                    },
                },
            }
            for name, payload in support.items():
                (evidence_dir / name).write_text(
                    json.dumps(payload) + "\n", encoding="utf-8"
                )

            result = subprocess.run(
                [
                    "python3",
                    str(MA10_VALIDATOR),
                    "--evidence-dir",
                    str(evidence_dir),
                    "--gate-exit",
                    "1",
                ],
                cwd=REPO,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
            )

            assert result.returncode == 3, result.stdout + result.stderr
            assert f"required early-failure evidence files are missing: {missing_names}" in result.stdout


def test_mc06_forwards_explicit_store_urls_to_rates_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-mc-rates-gate.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "mc-rates-gate-args.log"
        ref_url = "http://reference.localhost:8082"
        target_url = "http://target.localhost:8889"

        write_executable(
            fake_gate,
            """#!/usr/bin/env bash
printf '%s\\n' "$@" > "$FAKE_MC_RATES_GATE_CALLS"
exit 0
""",
        )
        # Only the target store is in scope, so only the target command must answer
        # the identity probe. The eval PHP arrives as $3 because of the --flag suffix.
        write_executable(
            fake_target_wp,
            """#!/usr/bin/env bash
if [ "$1" = "--flag" ] && [ "$2" = "eval" ] && [[ "$3" == *"store_identity_owner"* ]]; then
  printf '%s\\n' "store_identity_owner=native"
  printf '%s\\n' "store_identity_home=http://target.fake.test"
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MC-06",
            "--ref-url",
            ref_url,
            "--target-url",
            target_url,
            evidence_dir=evidence_dir,
            extra_env={
                "MC_RATES_GATE": str(fake_gate),
                "REF_WP_COMMAND": "fake-ref-wp --flag",
                "TARGET_WP_COMMAND": f"{fake_target_wp} --flag",
                "FAKE_MC_RATES_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == [
            "--ref",
            "fake-ref-wp --flag",
            "--target",
            f"{fake_target_wp} --flag",
            "--ref-url",
            ref_url,
            "--target-url",
            target_url,
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP,EUR",
            "--out-dir",
            str(evidence_dir / "MC-06-automatic-rates-refresh"),
        ]


def test_mc06_blocks_before_rates_gate_when_an_explicit_url_is_missing() -> None:
    cases = (
        (("--target-url", "http://target.localhost:8889"), "--ref-url"),
        (("--ref-url", "http://reference.localhost:8082"), "--target-url"),
    )

    for provided_args, missing_flag in cases:
        with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
            evidence_dir = Path(tmp)
            fake_gate = evidence_dir / "fake-mc-rates-gate.sh"
            calls = evidence_dir / "mc-rates-gate-invoked"

            write_executable(
                fake_gate,
                """#!/usr/bin/env bash
touch "$FAKE_MC_RATES_GATE_CALLS"
exit 0
""",
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "deterministic",
                "--flow",
                "MC-06",
                *provided_args,
                evidence_dir=evidence_dir,
                extra_env={
                    "MC_RATES_GATE": str(fake_gate),
                    "REF_WP_COMMAND": "fake-ref-wp",
                    "TARGET_WP_COMMAND": "fake-target-wp",
                    "REF_URL": "http://ambient-reference.invalid",
                    "TARGET_URL": "http://ambient-target.invalid",
                    "FAKE_MC_RATES_GATE_CALLS": str(calls),
                },
            )

            assert result.returncode == 3, result.stdout + result.stderr
            assert "BLOCKED" in result.stderr
            assert missing_flag in result.stderr
            assert not calls.exists()


def test_full_layer_blocks_when_agent_specs_are_only_queued() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(fake_wp, probe_only_fake_wp_source("native", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "all",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"TARGET_WP_COMMAND": str(fake_wp)},
        )

        assert result.returncode == 3
        assert "Layer D: running deterministic flow scripts" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1


def test_runner_blocks_before_flows_when_target_runtime_owner_is_wrong() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        driver_invoked = evidence_dir / "flow-driver-invoked"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
touch "$FLOW_DRIVER_INVOKED"
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        # The target store answers the probe as the plugin runtime: verdicts recorded
        # against it would be attributed to the wrong runtime, so the run must block.
        write_executable(fake_wp, probe_only_fake_wp_source("plugin", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FLOW_DRIVER_INVOKED": str(driver_invoked),
            },
        )

        assert result.returncode == 3
        assert "runtime owner is 'plugin', expected 'native'" in result.stderr
        # No flow may execute and no result row may be recorded against the wrong runtime.
        assert not driver_invoked.exists()
        assert "deterministic verdict" not in result.stdout
        results_jsonl = evidence_dir / "rollup-results.jsonl"
        assert not results_jsonl.exists() or results_jsonl.read_text(encoding="utf-8") == ""
        assert not (evidence_dir / "rollup.json").exists()


def test_runner_blocks_when_both_stores_resolve_to_the_same_home() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        driver_invoked = evidence_dir / "flow-driver-invoked"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
touch "$FLOW_DRIVER_INVOKED"
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        # Both stores report the expected owners but the same home URL: the dual-store
        # parity oracle would compare a store against itself, so the run must block.
        write_executable(fake_ref_wp, probe_only_fake_wp_source("plugin", "http://same.fake.test"))
        write_executable(fake_target_wp, probe_only_fake_wp_source("native", "http://same.fake.test"))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "FLOW_DRIVER_INVOKED": str(driver_invoked),
            },
        )

        assert result.returncode == 3
        assert "resolve to the same store" in result.stderr
        assert "http://same.fake.test" in result.stderr
        assert not driver_invoked.exists()
        assert not (evidence_dir / "rollup.json").exists()


def test_full_scope_run_reports_matrix_coverage_and_refuses_green() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_i18n_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_mc_gate = evidence_dir / "fake-mc-rates-gate.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":777,"charge_id":"ch_full","intent_id":"pi_full"}'
""",
        )
        write_executable(fake_ref_wp, sc01_fake_wp_source("plugin", "http://ref.fake.test", "pi_full", "ch_full"))
        write_executable(fake_target_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_full", "ch_full"))
        write_executable(fake_i18n_gate, "#!/usr/bin/env bash\nexit 0\n")
        write_executable(fake_mc_gate, "#!/usr/bin/env bash\nexit 0\n")

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "all",
            "--ref-url",
            "http://ref.fake.test",
            "--target-url",
            "http://target.fake.test",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "I18N_NOTES_GATE": str(fake_i18n_gate),
                "MC_RATES_GATE": str(fake_mc_gate),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "scope=full" in result.stdout
        assert "[ref] store identity: owner=plugin home=http://ref.fake.test" in result.stdout
        assert "[target] store identity: owner=native home=http://target.fake.test" in result.stdout
        assert "Matrix coverage:" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["scope"] == "full"
        assert rollup["run_stamp"]
        matrix = rollup["matrix"]
        assert matrix["total"] == 76
        # Coverage milestone (2026-07-14): every matrix row now has a spec, so a
        # full-scope run leaves nothing uncovered — rows without evidence are
        # BLOCKED/queued, which still refuses green below.
        assert matrix["uncovered"] == 0
        assert matrix["covered"] == matrix["total"] - matrix["uncovered"]
        assert len(matrix["uncovered_ids"]) == matrix["uncovered"]
        # A full-scope run may not claim the suite green while rows lack evidence.
        assert rollup["status"] != "pass"
        assert rollup["summary"]["blocked"] > 0


def test_full_scope_run_refuses_green_when_matrix_rows_are_unspecced() -> None:
    # The uncovered->refuse path must stay pinned in ISOLATION now that the real
    # matrix is fully spec'd: an otherwise-green full-scope run (a minimal flows dir
    # whose single flow passes on both stores — zero failed, zero blocked) must
    # still land blocked/exit 3 purely because a matrix row has no spec at all.
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flows_dir = evidence_dir / "flows"
        flows_dir.mkdir()
        shutil.copy2(RUNNER.parent / "flows/SC-01-card-checkout.sh", flows_dir / "SC-01-card-checkout.sh")
        # The flow sources ../lib/common.sh relative to its own location.
        (evidence_dir / "lib").mkdir()
        shutil.copy2(RUNNER.parent / "lib/common.sh", evidence_dir / "lib/common.sh")
        matrix_tsv = evidence_dir / "matrix.tsv"
        matrix_tsv.write_text(
            "id\ttitle\tlayers\toracle\tstatus\n"
            "SC-01\tCard checkout, shortcode (new card)\tD+A\tcomparable\tPENDING\n"
            "ZZ-99\tSynthetic uncovered row\tD+A\tcomparable\tPENDING\n",
            encoding="utf-8",
        )
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":778,"charge_id":"ch_syn","intent_id":"pi_syn"}'
""",
        )
        write_executable(fake_ref_wp, sc01_fake_wp_source("plugin", "http://ref.fake.test", "pi_syn", "ch_syn"))
        write_executable(fake_target_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_syn", "ch_syn"))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "all",
            "--ref-url",
            "http://ref.fake.test",
            "--target-url",
            "http://target.fake.test",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "MATRIX_TSV": str(matrix_tsv),
                "FLOWS_DIR": str(flows_dir),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        # Otherwise green: the only flow passed on both stores, nothing failed or blocked.
        assert rollup["summary"]["passed"] == 2
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        matrix = rollup["matrix"]
        assert matrix["total"] == 2
        assert matrix["uncovered_ids"] == ["ZZ-99"]
        # The uncovered row ALONE forces the refusal.
        assert rollup["status"] == "blocked"


def test_partial_run_rollup_is_marked_partial_and_keeps_status_semantics() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":555,"charge_id":"ch_partial","intent_id":"pi_partial"}'
""",
        )
        write_executable(fake_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_partial", "ch_partial"))

        result = run_runner(
            "--flow",
            "SC-01",
            "--store",
            "target",
            "--layer",
            "deterministic",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert "scope=partial" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["scope"] == "partial"
        # Partial runs keep the per-run status semantics: uncovered matrix rows do not
        # force a partial run to "blocked" — only a full-scope run refuses green.
        assert rollup["matrix"]["uncovered"] > 0
        assert rollup["status"] == "pass"


def test_consecutive_runs_are_archived_append_only() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        runs_dir = evidence_dir / "runs"

        first = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )
        assert first.returncode == 3
        assert "run archived ->" in first.stdout

        first_dirs = sorted(runs_dir.iterdir())
        assert len(first_dirs) == 1
        first_run_dir = first_dirs[0]
        assert first_run_dir.name.endswith("-partial")
        first_rollup_bytes = (first_run_dir / "rollup.json").read_bytes()
        assert (first_run_dir / "rollup-results.jsonl").exists()
        assert (first_run_dir / "agent-queue.txt").exists()

        # The archive stamp has one-second resolution; make sure the second run
        # lands in a distinct stamp instead of silently reusing the first one.
        time.sleep(1.1)

        second = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )
        assert second.returncode == 3

        run_dirs = sorted(runs_dir.iterdir())
        assert len(run_dirs) == 2
        assert (first_run_dir / "rollup.json").read_bytes() == first_rollup_bytes


def test_runner_creates_missing_evidence_directory() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp) / "nested" / "evidence"

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert (evidence_dir / "rollup.json").exists()
        assert (evidence_dir / "agent-queue.txt").exists()


def test_agent_layer_accepts_completed_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "PASS",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 0
        assert "agent result accepted" in result.stdout
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent result accepted: PASS",
            }
        ]


def test_agent_layer_preserves_target_only_pass_without_requeueing() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result_payload(
            agent_results_dir,
            "SS-10-sepa-token-renewal-cutover",
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "oracle_mode": "target-only",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "BLOCKED",
                        "end_state": "not run - no WooPayments 10.8 reference equivalent",
                        "ux_observations": [],
                        "visual_diffs": [],
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                        "end_state": "SEPA token remained visible and renewed",
                        "ux_observations": ["The saved token remained discoverable."],
                        "visual_diffs": [],
                    },
                ],
                "parity_verdict": "BLOCKED",
                "regression_note": "Target-only continuity passed; parity is not comparable.",
            },
        )

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "agent",
            "--flow",
            "SS-10",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["summary"] == {
            "passed": 1,
            "failed": 0,
            "blocked": 1,
            "queued_agent_specs": 0,
        }
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "layer": "agent",
                "store": "ref",
                "status": "BLOCKED",
                "exit_code": 3,
                "agent_verdict": "BLOCKED",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "target-only reference is intentionally not comparable",
            },
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "layer": "agent",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "target-only agent result accepted: PASS; parity not comparable",
            },
        ]


def test_agent_layer_requeues_invalid_target_only_contracts() -> None:
    cases = {
        "wrong oracle mode": ("comparable", "BLOCKED"),
        "fabricated parity pass": ("target-only", "PASS"),
    }

    for case, (oracle_mode, parity_verdict) in cases.items():
        with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
            evidence_dir = Path(tmp)
            agent_results_dir = evidence_dir / "agent-results"
            write_agent_result_payload(
                agent_results_dir,
                "SS-10-sepa-token-renewal-cutover",
                {
                    "flow": "SS-10-sepa-token-renewal-cutover",
                    "oracle_mode": oracle_mode,
                    "store_results": [
                        {
                            "store": "ref",
                            "verdict": "BLOCKED",
                            "end_state": "not run - no reference equivalent",
                            "ux_observations": [],
                            "visual_diffs": [],
                        },
                        {
                            "store": "target",
                            "verdict": "PASS",
                            "end_state": "SEPA continuity passed",
                            "ux_observations": [],
                            "visual_diffs": [],
                        },
                    ],
                    "parity_verdict": parity_verdict,
                    "regression_note": case,
                },
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "agent",
                "--flow",
                "SS-10",
                evidence_dir=evidence_dir,
                extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
            )

            assert result.returncode == 3, case
            assert "queued 1 agent-driven flow specs" in result.stdout, case
            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"]["blocked"] == 1, case
            assert rollup["summary"]["passed"] == 0, case


def test_agent_layer_fails_on_functional_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "FAIL - functional",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 1
        assert "agent verdict: FAIL - functional" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "FAIL",
                "exit_code": 1,
                "agent_verdict": "FAIL - functional",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent verdict: FAIL - functional",
            }
        ]


def test_agent_layer_preserves_blocked_agent_result_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "BLOCKED - redirect provider unavailable",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "agent verdict: BLOCKED - redirect provider unavailable" in result.stdout
        assert "unknown agent verdict" not in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
                "agent_verdict": "BLOCKED - redirect provider unavailable",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent verdict: BLOCKED - redirect provider unavailable",
            }
        ]


def test_agent_layer_fails_target_when_parity_verdict_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result_payload(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "PASS",
                        "end_state": "order paid",
                        "ux_observations": [],
                        "visual_diffs": [],
                        "evidence_paths": ["evidence/SC-14-lpm-wave-1-checkout/ref.png"],
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                        "end_state": "order paid",
                        "ux_observations": ["target missing the reference affordance"],
                        "visual_diffs": [],
                        "evidence_paths": ["evidence/SC-14-lpm-wave-1-checkout/target.png"],
                    },
                ],
                "parity_verdict": "FAIL - UX",
                "regression_note": "Target payment method is completable but not discoverable.",
            },
        )

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 1
        assert "agent parity verdict: FAIL - UX" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["failed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "ref",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent result accepted: PASS",
            },
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "FAIL",
                "exit_code": 1,
                "agent_verdict": "FAIL - UX",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent parity verdict: FAIL - UX",
            },
        ]


def test_agent_layer_blocks_when_result_lacks_requested_store() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        write_agent_result_payload(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "store_results": [{"store": "ref", "verdict": "PASS", "evidence_paths": []}],
                "parity_verdict": "PASS",
                "regression_note": "missing target",
            },
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert "evidence_store_set_mismatch" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0


def test_agent_layer_blocks_result_when_context_is_not_supplied() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        write_agent_result(agent_results_dir, "SC-14-lpm-wave-1-checkout", "target", "PASS")

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
            with_context=False,
        )

        assert result.returncode == 3
        assert "evidence_context_missing" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout


def test_agent_layer_blocks_result_when_hashed_artifact_changes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(agent_results_dir, "SC-14-lpm-wave-1-checkout", "target", "PASS")
        payload = json.loads(result_path.read_text(encoding="utf-8"))
        artifact_path = Path(payload["store_results"][0]["evidence"][0]["path"])
        artifact_path.write_bytes(b"changed after synthesis")

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "evidence_artifact_mismatch" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout


def run_log_clean_assertion(fake_wp_source: str) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-clean-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(fake_wp, fake_wp_source)
        script = f"""
source {shlex.quote(str(COMMON))}
TARGET_WP_COMMAND={shlex.quote(str(fake_wp))}
assert_log_clean target
"""

        return subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


def test_log_clean_assertion_passes_when_scan_is_clean() -> None:
    result = run_log_clean_assertion(
        """#!/usr/bin/env bash
printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
"""
    )

    assert result.returncode == 0
    assert "PASS log-clean target" in result.stdout


def test_log_clean_assertion_fails_when_php_errors_are_found() -> None:
    result = run_log_clean_assertion(
        """#!/usr/bin/env bash
printf '%s\\n' '{"status":"fail","paths":["/tmp/fake-debug.log"],"matches":["PHP Warning: fake warning"]}'
"""
    )

    assert result.returncode == 1
    assert "FAIL log-clean target" in result.stdout
    assert "PHP Warning: fake warning" in result.stdout


def test_log_clean_assertion_blocks_when_scan_cannot_run() -> None:
    result = run_log_clean_assertion(
        """#!/usr/bin/env bash
printf '%s\\n' "wp unavailable" >&2
exit 2
"""
    )

    assert result.returncode == 3
    assert "BLOCKED log-clean check for target" in result.stdout
    assert "wp unavailable" in result.stdout


def test_deterministic_runner_records_log_marker_before_scanning_logs() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        call_log = evidence_dir / "fake-wp-calls.log"
        marker_file = evidence_dir / "marker-created"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":321,"charge_id":"ch_marker","intent_id":"pi_marker"}'
""",
        )
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "wc" ] && [ "$2" = "shop_order" ] && [ "$3" = "get" ]; then
  printf '%s\\n' "processing"
  exit 0
fi
if [ "$1" = "post" ] && [ "$2" = "meta" ] && [ "$3" = "get" ]; then
  case "$5" in
    _intent_id) printf '%s\\n' "pi_marker"; exit 0 ;;
    _charge_id) printf '%s\\n' "ch_marker"; exit 0 ;;
  esac
fi
if [ "$1" = "eval" ]; then
  printf '%s\\n' "---CALL---" "$2" >> "$FAKE_WP_CALL_LOG"
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=native"
    printf '%s\\n' "store_identity_home=http://target.fake.test"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    touch "$FAKE_MARKER_FILE"
    printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{"/tmp/fake-debug.log":5}}'
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value=pi_marker"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value=ch_marker"
    exit 0
  fi
  if [[ "$2" == *"debug.log"* ]]; then
    if [ -f "$FAKE_MARKER_FILE" ]; then
      printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
    else
      printf '%s\\n' '{"status":"fail","paths":["/tmp/fake-debug.log"],"matches":["debug.log:1: PHP Warning: stale warning"]}'
    fi
    exit 0
  fi
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_WP_CALL_LOG": str(call_log),
                "FAKE_MARKER_FILE": str(marker_file),
            },
        )

        assert result.returncode == 0
        assert "deterministic verdict: PASS" in result.stdout
        calls = [
            chunk.strip()
            for chunk in call_log.read_text(encoding="utf-8").split("---CALL---")
            if chunk.strip()
        ]
        marker_call = next(
            index
            for index, call in enumerate(calls)
            if "woopayments_critical_flows_debug_log_marker" in call
            and "update_option" in call
        )
        scan_call = next(
            index
            for index, call in enumerate(calls)
            if "matches" in call and "debug.log" in call
        )
        assert marker_call < scan_call


def test_log_clean_parser_skips_wrapper_braces_before_payload() -> None:
    result = run_log_clean_assertion(
        """#!/usr/bin/env bash
printf '%s\\n' "ℹ Starting wp eval { not json"
printf '%s\\n' '{"status":"pass","paths":["/tmp/fake-debug.log"],"matches":[]}'
printf '%s\\n' "✔ Ran wp eval"
"""
    )

    assert result.returncode == 0
    assert "PASS log-clean target" in result.stdout


def test_log_clean_scan_ignores_known_wp67_textdomain_notice() -> None:
    source = COMMON.read_text(encoding="utf-8")

    assert "_load_textdomain_just_in_time" in source
    assert "ignored_matches" in source


def test_log_clean_scan_ignores_known_reference_wpcom_zoho_noise() -> None:
    source = COMMON.read_text(encoding="utf-8")

    assert "sopreda/archi/zoho/class-zoho-integration.php" in source
    assert "ignored_matches" in source


def main() -> None:
    tests = [
        test_card_checkout_flow_passes_with_clean_exercised_order,
        test_card_checkout_flow_blocks_when_exerciser_fails,
        test_mc06_forwards_explicit_store_urls_to_rates_gate,
        test_mc06_blocks_before_rates_gate_when_an_explicit_url_is_missing,
        test_agent_layer_queued_specs_are_blocked_until_executed,
        test_full_layer_blocks_when_agent_specs_are_only_queued,
        test_runner_blocks_before_flows_when_target_runtime_owner_is_wrong,
        test_runner_blocks_when_both_stores_resolve_to_the_same_home,
        test_full_scope_run_reports_matrix_coverage_and_refuses_green,
        test_full_scope_run_refuses_green_when_matrix_rows_are_unspecced,
        test_partial_run_rollup_is_marked_partial_and_keeps_status_semantics,
        test_consecutive_runs_are_archived_append_only,
        test_runner_creates_missing_evidence_directory,
        test_agent_layer_accepts_completed_agent_result,
        test_agent_layer_fails_on_functional_agent_result,
        test_agent_layer_preserves_blocked_agent_result_evidence,
        test_agent_layer_fails_target_when_parity_verdict_fails,
        test_agent_layer_blocks_when_result_lacks_requested_store,
        test_log_clean_assertion_passes_when_scan_is_clean,
        test_log_clean_assertion_fails_when_php_errors_are_found,
        test_log_clean_assertion_blocks_when_scan_cannot_run,
        test_log_clean_scan_ignores_known_reference_wpcom_zoho_noise,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
