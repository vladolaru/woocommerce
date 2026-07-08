#!/usr/bin/env python3
"""Regression checks for the critical-flows runner fail-closed contract."""

from __future__ import annotations

import json
import os
import shlex
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
RUNNER = REPO / "tools/woopayments-critical-flows/run.sh"
COMMON = REPO / "tools/woopayments-critical-flows/lib/common.sh"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def run_runner(
    *args: str,
    evidence_dir: Path,
    extra_env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(RUNNER), *args],
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
    path.write_text(
        json.dumps(
            {
                "flow": flow,
                "store_results": [
                    {
                        "store": store,
                        "verdict": verdict,
                        "end_state": "order paid",
                        "ux_observations": ["expected controls were usable"],
                        "visual_diffs": [],
                        "evidence_paths": [f"evidence/{flow}/{store}.png"],
                    }
                ],
                "parity_verdict": verdict,
                "regression_note": "",
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
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
  case "$5" in
    _intent_id) printf '%s\\n' "pi_fake"; exit 0 ;;
    _charge_id) printf '%s\\n' "ch_fake"; exit 0 ;;
  esac
fi
if [ "$1" = "eval" ]; then
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
        assert "captured order_id=123" in result.stdout
        assert "EXERCISER NOT WIRED" not in result.stdout
        assert "PASS log-clean target" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["failed"] == 0
        assert rollup["results"] == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
            }
        ]


def test_card_checkout_flow_blocks_when_exerciser_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' "FLOW-DRIVE FAIL (charge): account is not connected" >&2
exit 1
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
            extra_env={"SC01_FLOW_DRIVER": str(flow_driver)},
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
        assert rollup["results"] == [
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
        assert rollup["results"] == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
            }
        ]


def test_full_layer_blocks_when_agent_specs_are_only_queued() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "all",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "Layer D: running deterministic flow scripts" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1


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
        assert rollup["results"] == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
            }
        ]


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
        assert rollup["results"] == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "FAIL",
                "exit_code": 1,
                "agent_verdict": "FAIL - functional",
                "evidence_path": str(result_path),
            }
        ]


def test_agent_layer_blocks_when_result_lacks_requested_store() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "ref",
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

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert "missing agent result for target" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0


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


def main() -> None:
    test_card_checkout_flow_passes_with_clean_exercised_order()
    test_card_checkout_flow_blocks_when_exerciser_fails()
    test_agent_layer_queued_specs_are_blocked_until_executed()
    test_full_layer_blocks_when_agent_specs_are_only_queued()
    test_runner_creates_missing_evidence_directory()
    test_agent_layer_accepts_completed_agent_result()
    test_agent_layer_fails_on_functional_agent_result()
    test_agent_layer_blocks_when_result_lacks_requested_store()
    test_log_clean_assertion_passes_when_scan_is_clean()
    test_log_clean_assertion_fails_when_php_errors_are_found()
    test_log_clean_assertion_blocks_when_scan_cannot_run()
    print("PASS test_card_checkout_flow_passes_with_clean_exercised_order")
    print("PASS test_card_checkout_flow_blocks_when_exerciser_fails")
    print("PASS test_agent_layer_queued_specs_are_blocked_until_executed")
    print("PASS test_full_layer_blocks_when_agent_specs_are_only_queued")
    print("PASS test_runner_creates_missing_evidence_directory")
    print("PASS test_agent_layer_accepts_completed_agent_result")
    print("PASS test_agent_layer_fails_on_functional_agent_result")
    print("PASS test_agent_layer_blocks_when_result_lacks_requested_store")
    print("PASS test_log_clean_assertion_passes_when_scan_is_clean")
    print("PASS test_log_clean_assertion_fails_when_php_errors_are_found")
    print("PASS test_log_clean_assertion_blocks_when_scan_cannot_run")


if __name__ == "__main__":
    main()
