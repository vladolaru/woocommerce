#!/usr/bin/env python3
"""Wiring and safety regressions for MD-03/MD-04 resolution flows."""

from __future__ import annotations

import json
import os
import re
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FLOWS = ROOT / "tools/woopayments-critical-flows/flows"
WINNING_FLOW = FLOWS / "MD-03-winning-dispute.sh"
LOSING_FLOW = FLOWS / "MD-04-losing-dispute.sh"
STORE_ADAPTER = FLOWS / "class-woopayments-critical-flows-md-resolution-store.php"
SHELL_CONTRACT = ROOT / "tools/woopayments-critical-flows/lib/md-resolution-shell-contract.sh"
EVIDENCE_TOOL = FLOWS / "md-resolution-evidence.py"
FLOW_DRIVE = ROOT / "tools/woopayments-merge/flow-drive.sh"
COMMON = ROOT / "tools/woopayments-critical-flows/lib/common.sh"
README = ROOT / "tools/woopayments-critical-flows/README.md"
RUN_STAMP = "20260720T030000Z-4242"
CONTEXT_KEY = "44" * 32


FAKE_GATE_SOURCE = r'''#!/usr/bin/env python3
import argparse
import importlib.util
import json
import os
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--outcome", required=True)
parser.add_argument("--store", required=True)
parser.add_argument("--runtime-owner", required=True)
parser.add_argument("--run-stamp", required=True)
parser.add_argument("--out-dir", required=True)
parser.add_argument("--wp-command", required=True)
parser.add_argument("--stripe-command", required=True)
parser.add_argument("--flow-drive", required=True)
parser.add_argument("--store-adapter", required=True)
parser.add_argument("--poll-tries", required=True)
parser.add_argument("--poll-delay", required=True)
args = parser.parse_args()

spec = importlib.util.spec_from_file_location("resolution_evidence", os.environ["REAL_EVIDENCE_TOOL"])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
profile = module.outcome_profile(args.outcome)
suffix = f"{args.store}_{args.outcome}"
identity = {
    "order_id": 101 if args.store == "ref" else 202,
    "charge_id": f"ch_{suffix}",
    "intent_id": f"pi_{suffix}",
    "dispute_id": f"dp_{suffix}",
}
drive = {
    "op": "dispute",
    "order_id": identity["order_id"],
    "charge_id": identity["charge_id"],
    "intent_id": identity["intent_id"],
    "status": "on-hold",
    "order_currency": "USD",
}
mode = os.environ.get("FAKE_GATE_MODE", "pass")
response_class = "unknown" if mode == "blocked" else "trusted_success"
journal = [
    module.build_journal_record(args.outcome, args.store, args.run_stamp, 1, "fresh_dispute_create_armed", {}),
    module.build_journal_record(args.outcome, args.store, args.run_stamp, 2, "fresh_dispute_created", identity),
    module.build_journal_record(
        args.outcome,
        args.store,
        args.run_stamp,
        3,
        "evidence_submit_armed",
        identity,
        marker=profile["evidence_marker"],
    ),
    module.build_journal_record(
        args.outcome,
        args.store,
        args.run_stamp,
        4,
        "evidence_submit_observed",
        identity,
        marker=profile["evidence_marker"],
        response_class=response_class,
    ),
]
submission = {
    "trusted": mode != "blocked",
    "response_class": response_class,
    "submit": True,
    "marker": profile["evidence_marker"],
    "http_status": 0 if mode == "blocked" else 200,
    "dispute_id": identity["dispute_id"],
}
notes = {
    "created": True,
    "evidence_submitted": mode != "fail",
    "funds_reinstated": args.outcome == "won",
    "fees_deducted": args.outcome == "lost",
}
store_facts = {
    "available": True,
    "dispute_id": identity["dispute_id"],
    "dispute_status": profile["terminal_status"],
    "charge_id": identity["charge_id"],
    "intent_id": identity["intent_id"],
    "order_id": identity["order_id"],
    "order_status": "completed" if args.outcome == "won" else "refunded",
    "order_total_minor": 5000,
    "currency": "usd",
    "notes": notes,
    "refunds": [] if args.outcome == "won" else [{"amount_minor": 5000, "reason_family": "dispute"}],
}
transactions = [{"id": f"txn_debit_{suffix}", "amount": -5000, "fee": 1500, "net": -6500}]
if args.outcome == "won":
    transactions.append(
        {"id": f"txn_reversal_{suffix}", "amount": 5000, "fee": -1500, "net": 6500}
    )
provider_facts = {
    "available": True,
    "livemode": False,
    "dispute_id": identity["dispute_id"],
    "dispute_status": profile["terminal_status"],
    "charge_id": identity["charge_id"],
    "intent_id": identity["intent_id"],
    "amount": 5000,
    "currency": "usd",
    "balance_transactions": transactions,
}
packet = module.build_store_packet(
    outcome=args.outcome,
    store=args.store,
    run_stamp=args.run_stamp,
    runtime_owner=args.runtime_owner,
    drive=drive,
    journal=journal,
    submission=submission,
    store_facts=store_facts,
    provider_facts=provider_facts,
    blockers=[],
)
out_dir = Path(args.out_dir)
out_dir.mkdir()
(out_dir / f"{args.store}-store-packet.json").write_text(
    json.dumps(packet, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8"
)
raise SystemExit({"pass": 0, "fail": 1, "blocked": 3}[packet["status"]])
'''


def test_explicit_entrypoints_select_only_their_immutable_outcome() -> None:
    expected = (
        (WINNING_FLOW, "won", "MD-03-winning-dispute"),
        (LOSING_FLOW, "lost", "MD-04-losing-dispute"),
    )
    for path, outcome, flow_id in expected:
        assert path.is_file()
        source = path.read_text(encoding="utf-8")
        assert f"MD_RESOLUTION_OUTCOME={outcome}" in source
        assert f"MD_RESOLUTION_FLOW_ID={flow_id}" in source
        assert 'source "$DIR/../lib/common.sh"' in source
        assert 'source "$DIR/../lib/md-resolution-shell-contract.sh"' in source
        assert source.count("md_resolution_run") == 1


def test_shared_shell_contract_is_local_single_attempt_and_evidence_bound() -> None:
    assert SHELL_CONTRACT.is_file()
    source = SHELL_CONTRACT.read_text(encoding="utf-8")
    for required in (
        "woopayments_validate_local_wp_runner",
        "woopayments_validate_approved_docker_runner",
        "wpcom-local --json transact status",
        "async_jobs_ready",
        "stripe listen",
        'python3 "$GATE"',
        'assert_log_clean "$S"',
        "normalize-log-scan",
        "compare",
        "execution",
        "manifest",
        "validate-bound-manifest",
        'ref/ref-store-packet.json',
        'ref/ref-manifest.json',
        '--status pass --exit-code 0',
        "evidence directory already exists",
        "cleanup-fatal",
        "exit 70",
    ):
        assert required in source
    assert source.count('python3 "$GATE"') == 1
    assert "for attempt" not in source
    assert "retry" not in source.lower()
    assert "rm -rf" not in source
    assert "docker system prune" not in source
    assert "wp db reset" not in source
    assert "play" + "writer" not in source.lower()


def test_shared_shell_preserves_known_fail_over_blocked_and_preserves_cleanup() -> None:
    cases = {
        (0, 0, 0): "pass 0",
        (1, 0, 0): "fail 1",
        (3, 0, 0): "blocked 3",
        (70, 0, 0): "cleanup 70",
        (1, 3, 1): "fail 1",
        (0, 1, 0): "fail 1",
    }
    for arguments, expected in cases.items():
        completed = subprocess.run(
            [
                "bash",
                "-c",
                'source "$1"; shift; md_resolution_classify_verdict "$@"',
                "bash",
                str(SHELL_CONTRACT),
                *(str(value) for value in arguments),
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert completed.returncode == 0, completed.stderr
        assert completed.stdout.strip() == expected


def test_store_adapter_uses_one_authenticated_allowlisted_rest_boundary() -> None:
    assert STORE_ADAPTER.is_file()
    source = STORE_ADAPTER.read_text(encoding="utf-8")
    for required in (
        "final class WooPayments_Critical_Flows_MD_Resolution_Store",
        "current_user_can( 'manage_woocommerce' )",
        "WP_REST_Request( 'POST'",
        "WP_REST_Request( 'GET'",
        "'/wc/v3/payments/disputes/'",
        "rest_do_request",
        "'uncategorized_text'",
        "'submit'   => true",
        "'metadata' => array()",
        "'__evidence_submitted_at'",
        "wc_get_order_notes",
        "'limit'    => 50",
        "get_refunds",
        "winning_evidence",
        "losing_evidence",
        "get_stripe_account_id",
        "WooPaymentsAccountService",
        "get_account_id",
        "'account_id'  => $account_id",
        "'site_locale' => (string) get_locale()",
        "wp_json_encode",
    ):
        assert required in source
    for forbidden in (
        "billing_email",
        "shipping_address",
        "customer_ip",
        "get_customer_note",
        "SELECT ",
        "$wpdb",
    ):
        assert forbidden not in source
    assert "Payment dispute has been updated" in source
    assert "Payment dispute funds have been reinstated" in source
    assert "Payment dispute and fees have been deducted" in source


def test_store_adapter_has_valid_php_syntax() -> None:
    completed = subprocess.run(
        ["php", "-l", str(STORE_ADAPTER)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr


def prepare_fake_boundaries(tmp_path: Path) -> tuple[Path, Path]:
    fake_root = tmp_path / "fake-repo"
    safety = fake_root / "tools/woopayments-merge/local-runner-safety.sh"
    safety.parent.mkdir(parents=True)
    safety.write_text(
        "woopayments_validate_local_wp_runner() { return 0; }\n"
        "woopayments_validate_approved_docker_runner() { return 0; }\n",
        encoding="utf-8",
    )
    gate = tmp_path / "fake-resolution-gate.py"
    gate.write_text(FAKE_GATE_SOURCE, encoding="utf-8")
    return fake_root, gate


def run_fake_flow(
    tmp_path: Path,
    fake_root: Path,
    gate: Path,
    *,
    store: str,
    outcome: str = "won",
    mode: str = "pass",
    cleanup_fail: bool = False,
    log_cleanup_fatal: bool = False,
) -> subprocess.CompletedProcess[str]:
    flow_id = "MD-03-winning-dispute" if outcome == "won" else "MD-04-losing-dispute"
    shell = f'''
source "{COMMON}"
source "{SHELL_CONTRACT}"
md_resolution_wpcom_preflight() {{ return 0; }}
critical_flows_log_observer_cleanup() {{ [ "${{FAKE_CLEANUP_FAIL:-0}}" -eq 0 ]; }}
assert_log_clean() {{
    if [ "${{FAKE_LOG_CLEANUP_FATAL:-0}}" -eq 1 ]; then
        return 70
    fi
    printf '{{"schema":"woopayments_debug_log_scan.v6","store":"%s","scan":{{"status":"pass","run_stamp":"%s","store":"%s","flow_id":"%s","purpose":"clean-debug-log","matches":[],"blocker_code":""}}}}\n' "$S" "$RUN_STAMP" "$S" "$FLOW_ID" > "$LOG_SCAN_EVIDENCE_FILE"
    return 0
}}
MD_RESOLUTION_OUTCOME={outcome}
MD_RESOLUTION_FLOW_ID={flow_id}
md_resolution_run
'''
    env = {
        **os.environ,
        "REPO_ROOT": str(fake_root),
        "EVIDENCE_DIR": str(tmp_path / "evidence"),
        "STORE_NAME": store,
        "CRITICAL_FLOWS_RUN_STAMP": RUN_STAMP,
        "CRITICAL_FLOWS_RUN_SCOPE": "partial",
        "CRITICAL_FLOWS_RUN_CONTEXT_KEY": CONTEXT_KEY,
        "CRITICAL_FLOWS_FLOW_ID": flow_id,
        "CRITICAL_FLOWS_LOG_PURPOSE": "clean-debug-log",
        "MD_RESOLUTION_GATE": str(gate),
        "MD_RESOLUTION_EVIDENCE_TOOL": str(EVIDENCE_TOOL),
        "MD_RESOLUTION_STORE_ADAPTER": str(STORE_ADAPTER),
        "MD_RESOLUTION_FLOW_DRIVE": str(FLOW_DRIVE),
        "REAL_EVIDENCE_TOOL": str(EVIDENCE_TOOL),
        "FAKE_GATE_MODE": mode,
        "FAKE_CLEANUP_FAIL": "1" if cleanup_fail else "0",
        "FAKE_LOG_CLEANUP_FATAL": "1" if log_cleanup_fatal else "0",
        "REF_WP_COMMAND": "docker exec -i ref-cli wp --allow-root",
        "REF_CONTAINER": "ref-cli",
        "WOOPAYMENTS_APPROVED_REF_CONTAINER": "ref-cli",
        "TARGET_WP_COMMAND": "docker exec -i target-cli wp --allow-root",
        "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target-cli",
    }
    return subprocess.run(
        ["bash", "-c", shell],
        cwd=ROOT,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_shell_seals_independent_reference_and_target_pass_manifests(tmp_path: Path) -> None:
    fake_root, gate = prepare_fake_boundaries(tmp_path)

    reference = run_fake_flow(tmp_path, fake_root, gate, store="ref")
    target = run_fake_flow(tmp_path, fake_root, gate, store="target")

    assert reference.returncode == 0, reference.stdout + reference.stderr
    assert target.returncode == 0, target.stdout + target.stderr
    flow_dir = tmp_path / "evidence/runs" / f"{RUN_STAMP}-partial/MD-03-winning-dispute"
    for store in ("ref", "target"):
        store_dir = flow_dir / store
        manifest = json.loads((store_dir / f"{store}-manifest.json").read_text())
        assert manifest["status"] == "pass"
        assert manifest["store"] == store
        assert {path.name for path in store_dir.iterdir()} == {
            *manifest["files"],
            f"{store}-manifest.json",
        }
    assert (flow_dir / "target/comparison.json").is_file()


def test_shell_preserves_packet_fail_and_blocked_verdicts(tmp_path: Path) -> None:
    expected = {"fail": 1, "blocked": 3}
    for mode, exit_code in expected.items():
        case_dir = tmp_path / mode
        fake_root, gate = prepare_fake_boundaries(case_dir)
        completed = run_fake_flow(case_dir, fake_root, gate, store="ref", mode=mode)

        assert completed.returncode == exit_code, completed.stdout + completed.stderr
        manifest_path = (
            case_dir
            / "evidence/runs"
            / f"{RUN_STAMP}-partial/MD-03-winning-dispute/ref/ref-manifest.json"
        )
        manifest = json.loads(manifest_path.read_text())
        assert manifest["status"] == mode
        assert manifest["exit_code"] == exit_code


def test_shell_preserves_gate_cleanup_fatal_exit_70(tmp_path: Path) -> None:
    fake_root, _ = prepare_fake_boundaries(tmp_path)
    gate = tmp_path / "cleanup-fatal-gate.py"
    gate.write_text("raise SystemExit(70)\n", encoding="utf-8")

    completed = run_fake_flow(tmp_path, fake_root, gate, store="ref")

    assert completed.returncode == 70
    assert "cleanup-fatal" in completed.stderr


def test_reference_cleanup_failure_cannot_leave_an_oracle_for_target_mutation(
    tmp_path: Path,
) -> None:
    fake_root, gate = prepare_fake_boundaries(tmp_path)

    reference = run_fake_flow(
        tmp_path,
        fake_root,
        gate,
        store="ref",
        cleanup_fail=True,
    )

    assert reference.returncode == 70
    flow_dir = tmp_path / "evidence/runs" / f"{RUN_STAMP}-partial/MD-03-winning-dispute"
    assert not (flow_dir / "ref/ref-manifest.json").exists()

    target = run_fake_flow(tmp_path, fake_root, gate, store="target")

    assert target.returncode == 3
    assert "passing same-run reference resolution manifest is unavailable" in target.stdout
    assert not (flow_dir / "target").exists()


def test_log_observer_finish_cleanup_failure_is_cleanup_fatal(tmp_path: Path) -> None:
    fake_root, gate = prepare_fake_boundaries(tmp_path)

    completed = run_fake_flow(
        tmp_path,
        fake_root,
        gate,
        store="ref",
        log_cleanup_fatal=True,
    )

    assert completed.returncode == 70
    assert "cleanup-fatal" in completed.stderr
    flow_dir = tmp_path / "evidence/runs" / f"{RUN_STAMP}-partial/MD-03-winning-dispute"
    assert not (flow_dir / "ref/ref-manifest.json").exists()


def test_target_without_passing_same_run_reference_cannot_mutate(tmp_path: Path) -> None:
    fake_root, gate = prepare_fake_boundaries(tmp_path)

    completed = run_fake_flow(tmp_path, fake_root, gate, store="target")

    assert completed.returncode == 3
    assert "passing same-run reference resolution manifest is unavailable" in completed.stdout
    target_dir = (
        tmp_path
        / "evidence/runs"
        / f"{RUN_STAMP}-partial/MD-03-winning-dispute/target"
    )
    assert not (target_dir / "target-store-packet.json").exists()
    assert not (target_dir / "target-manifest.json").exists()


def test_target_cannot_mutate_after_same_run_reference_failure(tmp_path: Path) -> None:
    fake_root, gate = prepare_fake_boundaries(tmp_path)
    reference = run_fake_flow(tmp_path, fake_root, gate, store="ref", mode="fail")

    assert reference.returncode == 1, reference.stdout + reference.stderr
    target = run_fake_flow(tmp_path, fake_root, gate, store="target")

    assert target.returncode == 3
    assert "passing same-run reference resolution manifest is unavailable" in target.stdout
    target_dir = (
        tmp_path
        / "evidence/runs"
        / f"{RUN_STAMP}-partial/MD-03-winning-dispute/target"
    )
    assert not target_dir.exists()


def test_maintained_resolution_contracts_document_the_wired_no_replay_boundary() -> None:
    expected = (
        (FLOWS / "MD-03-winning-dispute.md", "MD-03-winning-dispute.sh", "winning_evidence"),
        (FLOWS / "MD-04-losing-dispute.md", "MD-04-losing-dispute.sh", "losing_evidence"),
    )
    for path, entrypoint, marker in expected:
        source = path.read_text(encoding="utf-8")
        assert "NOT YET WIRED" not in source
        assert entrypoint in source
        assert marker in source
        assert "never retries submission" in source
        assert "same-run reference manifest revalidates as `PASS`" in source
        assert "context-bound manifest" in source
        assert "wpcom-local transact listen" in source

    readme = README.read_text(encoding="utf-8")
    assert "MD-03/MD-04 dispute resolution uses a stricter non-replayable" in readme
    assert re.search(r"\| MD-03 \|.*\| PENDING \|", readme)
    assert re.search(r"\| MD-04 \|.*\| PENDING \|", readme)
