#!/usr/bin/env python3
"""Focused regression checks for the REST route parity gate harness."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/rest-route-parity.sh"
EXCEPTIONS = REPO / "tools/woopayments-merge/rest-route-exceptions.txt"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def test_committed_exceptions_match_the_pinned_10_8_route_oracle() -> None:
    source = EXCEPTIONS.read_text(encoding="utf-8")

    assert "/wc/v3/payments/survey/reports-feedback" not in source


def run_gate(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_routes(
    path: Path,
    routes: list[dict[str, str]],
    *,
    role: str | None = None,
    runtime_owner: str | None = None,
    site_url: str | None = None,
) -> None:
    resolved_role = role or ("target" if path.name.startswith("target") else "reference")
    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_rest_route_capture.v1",
                "role": resolved_role,
                "runtime_owner": runtime_owner
                or ("native" if resolved_role == "target" else "plugin"),
                "site_url": site_url or f"http://{resolved_role}.localhost",
                "routes": routes,
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


def write_exceptions(path: Path, rows: str = "") -> None:
    path.write_text(
        """# WooPayments REST route parity exceptions.
#
# Format:
# METHOD<TAB>LEGACY_ROUTE<TAB>DISPOSITION<TAB>NATIVE_SUCCESSOR<TAB>SIGNOFF<TAB>REASON
#
"""
        + rows,
        encoding="utf-8",
    )


def base_routes() -> list[dict[str, str]]:
    return [
        {"method": "GET", "route": "/wc/v3/payments/accounts"},
        {"method": "GET", "route": "/wc/v3/payments/charges/order/{order_id}"},
        {"method": "POST", "route": "/wc/v3/payments/payment_intents"},
    ]


def test_usage_requires_ref_target_or_snapshot_files() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--ref-state" in result.stderr


def test_print_plan_describes_live_route_probe() -> None:
    result = run_gate("--ref", REF_WP, "--target", TARGET_WP, "--print-plan")

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_rest_route_gate_plan.v1"
    assert payload["ref_wp"] == REF_WP
    assert payload["target_wp"] == TARGET_WP
    assert payload["route_prefix"] == "/wc/v3/payments/"


def test_gate_passes_matching_snapshots() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"
        out_dir = tmp_path / "evidence"
        routes = base_routes()

        write_routes(ref_state, routes)
        write_routes(target_state, routes)
        write_exceptions(exceptions)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: native WooPayments REST routes cover the reference route table." in result.stdout

        rollup = json.loads((out_dir / "rest-route-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["failures"] == []


def test_gate_blocks_cross_store_snapshots_with_wrong_runtime_owner() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"

        write_routes(ref_state, base_routes())
        write_routes(target_state, base_routes(), runtime_owner="plugin")
        write_exceptions(exceptions)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
        )

        assert result.returncode == 3
        assert "target runtime owner must be native" in result.stderr


def test_gate_blocks_cross_store_snapshots_from_the_same_site() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"
        shared_url = "http://same-store.localhost"

        write_routes(ref_state, base_routes(), site_url=shared_url)
        write_routes(target_state, base_routes(), site_url=shared_url)
        write_exceptions(exceptions)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
        )

        assert result.returncode == 3
        assert "distinct local sites" in result.stderr


def test_gate_supports_explicit_plugin_self_check_snapshots() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"
        shared_url = "http://reference.localhost"

        write_routes(ref_state, base_routes(), site_url=shared_url)
        write_routes(
            target_state,
            base_routes(),
            runtime_owner="plugin",
            site_url=shared_url,
        )
        write_exceptions(exceptions)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
            "--self-check",
        )

        assert result.returncode == 0, result.stderr


def test_gate_fails_when_reference_route_is_missing_on_target() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"
        out_dir = tmp_path / "evidence"

        write_routes(ref_state, base_routes())
        write_routes(target_state, base_routes()[:2])
        write_exceptions(exceptions)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "missing target route: POST /wc/v3/payments/payment_intents" in result.stderr

        rollup = json.loads((out_dir / "rest-route-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "POST /wc/v3/payments/payment_intents" in "\n".join(rollup["failures"])


def test_gate_accepts_signed_exception_for_missing_reference_route() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"
        out_dir = tmp_path / "evidence"

        write_routes(ref_state, base_routes())
        write_routes(target_state, base_routes()[:2])
        write_exceptions(
            exceptions,
            "POST\t/wc/v3/payments/payment_intents\tSUPERSEDED\tPOST /wc-admin/settings/payments/woopayments/payment-intents\tTask 6.1\tNative route supersedes the legacy route.\n",
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 0, result.stderr
        rollup = json.loads((out_dir / "rest-route-parity.json").read_text(encoding="utf-8"))
        assert rollup["exceptions_applied"] == ["POST /wc/v3/payments/payment_intents"]


def test_gate_rejects_exceptions_without_signoff_or_reason() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"

        write_routes(ref_state, base_routes())
        write_routes(target_state, base_routes())
        write_exceptions(
            exceptions,
            "POST\t/wc/v3/payments/survey/reports-feedback\tDROPPED\tnone\t\t\n",
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
        )

        assert result.returncode == 1
        assert "exception missing signoff/reason" in result.stderr


def test_gate_rejects_stale_exception_rows() -> None:
    with tempfile.TemporaryDirectory(prefix="rest-route-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref-routes.json"
        target_state = tmp_path / "target-routes.json"
        exceptions = tmp_path / "exceptions.txt"

        write_routes(ref_state, base_routes())
        write_routes(target_state, base_routes())
        write_exceptions(
            exceptions,
            "POST\t/wc/v3/payments/unknown\tDROPPED\tnone\tTask 6.1\tUnknown route should not stay in the exceptions file.\n",
        )

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--exceptions",
            str(exceptions),
        )

        assert result.returncode == 1
        assert "stale exception route" in result.stderr


def test_verify_runs_rest_route_parity_gate() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "rest-route-parity.sh" in verify_source
