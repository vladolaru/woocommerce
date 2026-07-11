#!/usr/bin/env python3
"""Focused regressions for deterministic provider flow fixture validation."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/flow-drive.sh"
REFERENCE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-deterministic-charge.php"
NATIVE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-native-charge.php"
RUN_TOKEN = "wcpay-verify-0123456789abcdef0123456789abcdef"


def write_fake_wp(path: Path, payload: dict) -> None:
    path.write_text(
        "#!/usr/bin/env python3\n"
        "import json\n"
        f"print(json.dumps({payload!r}))\n",
        encoding="utf-8",
    )
    path.chmod(0o755)


def run_dispute(payload: dict) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix="flow-drive-provider-fixture-") as temp_dir:
        fake_wp = Path(temp_dir) / "wp"
        write_fake_wp(fake_wp, payload)
        return subprocess.run(
            ["bash", str(SCRIPT), "dispute", "--deterministic"],
            cwd=REPO,
            env={**os.environ, "WP": str(fake_wp)},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


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

        result = subprocess.run(
            [
                "bash",
                str(SCRIPT),
                "charge",
                "--deterministic",
                "--run-token",
                RUN_TOKEN,
            ],
            cwd=REPO,
            env={**os.environ, "WP": str(fake_wp)},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 0, result.stdout + result.stderr
    assert json.loads(result.stdout)["received_run_token"] == RUN_TOKEN


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
