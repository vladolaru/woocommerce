#!/usr/bin/env python3
"""Regression checks for the critical-flows runner fail-closed contract."""

from __future__ import annotations

import importlib.util
import json
import os
import shlex
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
            """#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$FAKE_I18N_GATE_CALLS"
exit 0
""",
        )
        # The command string keeps a trailing flag so the probe exercises the
        # split-command convention: the eval PHP arrives as $3, not $2.
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "--flag" ] && [ "$2" = "eval" ]; then
  if [[ "$3" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=native"
    printf '%s\\n' "store_identity_home=http://target.fake.test"
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
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": f"{fake_wp} --flag",
                "FAKE_I18N_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 0
        assert "MA-10-i18n-order-notes" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout
        assert f"--target {fake_wp} --flag" in calls.read_text(encoding="utf-8")

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        assert strip_recorded_at(rollup) == [
            {
                "flow": "MA-10-i18n-order-notes",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
            }
        ]


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
        assert matrix["uncovered"] > 0
        assert matrix["covered"] == matrix["total"] - matrix["uncovered"]
        assert len(matrix["uncovered_ids"]) == matrix["uncovered"]
        assert "SC-01" not in matrix["uncovered_ids"]
        # A full-scope run may not claim the suite green while matrix rows lack evidence.
        assert rollup["status"] != "pass"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["passed"] >= 6


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
