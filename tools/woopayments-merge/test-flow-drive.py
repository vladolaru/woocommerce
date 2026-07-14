#!/usr/bin/env python3
"""Focused regressions for deterministic provider flow fixture validation."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_single_wp_runner


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/flow-drive.sh"
REFERENCE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-deterministic-charge.php"
NATIVE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-native-charge.php"
RUN_TOKEN = "wcpay-verify-0123456789abcdef0123456789abcdef"


def write_fake_wp(path: Path, payload: dict) -> None:
    path.write_text(
        "#!/usr/bin/env python3\n"
        "import json\n"
        "import sys\n"
        'if len(sys.argv) > 1 and sys.argv[1] == "eval":\n'
        '    print("WCPAY_NATIVE_TEST_MODE:yes")\n'
        "    raise SystemExit(0)\n"
        f"print(json.dumps({payload!r}))\n",
        encoding="utf-8",
    )
    path.chmod(0o755)


def run_flow_drive(fake_wp: Path, *args: str) -> subprocess.CompletedProcess[str]:
    runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
    env["WP"] = runner
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def run_dispute(payload: dict) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix="flow-drive-provider-fixture-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        write_fake_wp(fake_wp, payload)
        return run_flow_drive(fake_wp, "dispute", "--deterministic")


def test_dispute_rejects_trashed_order_without_provider_identity() -> None:
    result = run_dispute(
        {
            "success": True,
            "order_id": 123,
            "status": "trash",
            "intent_id": "",
            "charge_id": "",
        }
    )

    assert result.returncode != 0
    assert "valid provider-backed order" in result.stderr


def test_dispute_accepts_provider_backed_order() -> None:
    result = run_dispute(
        {
            "success": True,
            "order_id": 123,
            "status": "processing",
            "intent_id": "pi_fixture",
            "charge_id": "ch_fixture",
        }
    )

    assert result.returncode == 0, result.stderr
    response = json.loads(result.stdout)
    assert response["intent_id"] == "pi_fixture"
    assert response["charge_id"] == "ch_fixture"


def test_deterministic_charge_passes_run_token_to_driver() -> None:
    with tempfile.TemporaryDirectory(prefix="flow-drive-run-token-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        fake_wp.write_text(
            """#!/usr/bin/env python3
import json
import sys
print(json.dumps({
    "success": True,
    "order_id": 123,
    "status": "processing",
    "intent_id": "pi_fixture",
    "charge_id": "ch_fixture",
    "received_run_token": sys.argv[-1],
}))
""",
            encoding="utf-8",
        )
        fake_wp.chmod(0o755)

        result = run_flow_drive(
            fake_wp,
            "charge",
            "--deterministic",
            "--run-token",
            RUN_TOKEN,
        )

    assert result.returncode == 0, result.stdout + result.stderr
    assert json.loads(result.stdout)["received_run_token"] == RUN_TOKEN


def test_remote_wp_runner_is_rejected_before_any_store_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="flow-drive-remote-runner-") as temp_dir:
        invoked_marker = Path(temp_dir) / "invoked"
        fake_wp = Path(temp_dir) / "wp"
        fake_wp.write_text(
            "#!/usr/bin/env bash\n"
            f"touch {json.dumps(str(invoked_marker))}\n"
            'printf \'{"order_id":123}\\n\'\n',
            encoding="utf-8",
        )
        fake_wp.chmod(0o755)

        result = subprocess.run(
            ["bash", str(SCRIPT), "charge", "--deterministic"],
            cwd=REPO,
            env={**os.environ, "WP": f"{fake_wp} --ssh=user@remote.example"},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr
        assert "--ssh" in result.stderr
        assert not invoked_marker.exists()


def test_i18n_memory_limit_exec_suffix_is_stripped_before_validation() -> None:
    # The i18n notes gate appends an exactly-shaped memory-limit --exec token to its
    # validated runner; the transport underneath must still validate and drive.
    with tempfile.TemporaryDirectory(prefix="flow-drive-exec-suffix-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        write_fake_wp(
            fake_wp,
            {
                "success": True,
                "order_id": 123,
                "status": "processing",
                "intent_id": "pi_fixture",
                "charge_id": "ch_fixture",
            },
        )
        runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
        env["WP"] = f'{runner} --exec=ini_set("memory_limit","256M");'

        result = subprocess.run(
            ["bash", str(SCRIPT), "dispute", "--deterministic"],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert json.loads(result.stdout)["order_id"] == 123


def test_arbitrary_exec_suffix_is_still_rejected() -> None:
    with tempfile.TemporaryDirectory(prefix="flow-drive-exec-reject-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        write_fake_wp(fake_wp, {"order_id": 123})
        runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
        env["WP"] = f'{runner} --exec=system("curl https://remote.example");'

        result = subprocess.run(
            ["bash", str(SCRIPT), "charge", "--deterministic"],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr


def test_exported_wp_wrapper_function_is_accepted_like_critical_flows() -> None:
    # The critical-flows suite exports bash wrapper functions (wp_ref / wp_target) as
    # $WP; flow-drive must accept those without string validation because the wrappers
    # themselves only wrap validated-shape local runner commands.
    payload = {
        "success": True,
        "order_id": 321,
        "status": "processing",
        "intent_id": "pi_fn",
        "charge_id": "ch_fn",
    }
    script = f"""
wp_fake() {{
    if [ "${{1:-}}" = "eval" ]; then
        printf 'WCPAY_NATIVE_TEST_MODE:yes\\n'
        return 0
    fi
    printf '%s\\n' {json.dumps(json.dumps(payload))}
}}
export -f wp_fake
WP=wp_fake bash {json.dumps(str(SCRIPT))} charge --deterministic --native --type=success
"""
    result = subprocess.run(
        ["bash", "-c", script],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert json.loads(result.stdout)["order_id"] == 321


def test_native_flow_blocks_when_store_is_not_in_test_mode() -> None:
    with tempfile.TemporaryDirectory(prefix="flow-drive-live-mode-") as temp_dir:
        driven_marker = Path(temp_dir) / "driven"
        fake_wp = Path(temp_dir) / "wp"
        fake_wp.write_text(
            "#!/usr/bin/env bash\n"
            'if [ "${1:-}" = "eval" ]; then\n'
            "    printf 'WCPAY_NATIVE_TEST_MODE:no\\n'\n"
            "    exit 0\n"
            "fi\n"
            f"touch {json.dumps(str(driven_marker))}\n"
            'printf \'{"order_id":123,"status":"processing","intent_id":"pi_x","charge_id":"ch_x"}\\n\'\n',
            encoding="utf-8",
        )
        fake_wp.chmod(0o755)

        result = run_flow_drive(fake_wp, "charge", "--deterministic", "--native", "--type=success")

        assert result.returncode == 3, result.stdout + result.stderr
        assert "not provably in test mode" in result.stderr
        assert not driven_marker.exists()


def test_native_flow_proceeds_when_test_mode_probe_confirms() -> None:
    with tempfile.TemporaryDirectory(prefix="flow-drive-test-mode-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        write_fake_wp(
            fake_wp,
            {
                "success": True,
                "order_id": 123,
                "status": "processing",
                "intent_id": "pi_fixture",
                "charge_id": "ch_fixture",
            },
        )

        result = run_flow_drive(fake_wp, "charge", "--deterministic", "--native", "--type=success")

        assert result.returncode == 0, result.stdout + result.stderr
        assert json.loads(result.stdout)["order_id"] == 123


def test_charge_drivers_persist_run_token_before_payment_mutation() -> None:
    reference = REFERENCE_DRIVER.read_text(encoding="utf-8")
    native = NATIVE_DRIVER.read_text(encoding="utf-8")

    assert "'protocol'       => $run_token" in reference
    assert reference.index("$run_token = trim") < reference.index("$simulator->simulate")
    assert "$order->update_meta_data( '_wcpay_verify_run_token', $run_token );" in native
    assert native.index("_wcpay_verify_run_token") < native.index("$gateway->process_payment")


def test_charge_drivers_normalize_fixture_price_without_persisting_product_changes() -> None:
    for driver in (REFERENCE_DRIVER, NATIVE_DRIVER):
        source = driver.read_text(encoding="utf-8")

        assert "woocommerce_product_get_price" in source
        assert "woocommerce_product_get_regular_price" in source
        assert "woocommerce_product_get_sale_price" in source
        assert "$product->set_regular_price" not in source
        assert "$product->set_sale_price" not in source
        assert "$product->set_price" not in source
        assert "$product->save()" not in source


def test_charge_drivers_isolate_and_exactly_restore_the_duplicate_payment_session() -> None:
    for driver, payment_mutation in (
        (REFERENCE_DRIVER, "$simulator->simulate"),
        (NATIVE_DRIVER, "$gateway->process_payment"),
    ):
        source = driver.read_text(encoding="utf-8")

        assert "SESSION_KEY_PROCESSING_ORDER" in source
        assert "get_session_data()" in source
        assert "array_key_exists( $session_key, $session_data )" in source
        assert "maybe_unserialize( $session_data[ $session_key ] )" in source
        assert source.count("$session->save_data();") >= 2
        assert source.index("$session->set( $session_key, null );") < source.index(
            payment_mutation
        )
        assert "? $previous_processing_order : null" in source
