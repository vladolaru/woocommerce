#!/usr/bin/env python3
"""Focused regression checks for the converted-currency harness gate."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO))

from tools.woopayments_test_runner import adapt_wp_runner_arguments


MERGE_DIR = REPO / "tools/woopayments-merge"
GATE = MERGE_DIR / "converted-currency-gate.sh"
STATE_HELPER = MERGE_DIR / "multi-currency-runtime-state.sh"
LOCAL_RUNNER_SAFETY = MERGE_DIR / "local-runner-safety.sh"
CURRENCY = "GBP"


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def expected_option_names(currency: str = CURRENCY) -> list[str]:
    currency_lc = currency.lower()
    return [
        "woocommerce_currency",
        "_wcpay_feature_customer_multi_currency",
        "wcpay_multi_currency_setup_completed",
        "wcpay_multi_currency_enabled_currencies",
        "wcpay_multi_currency_cached_currencies",
        "_transient_wcpay_currency_format",
        "_transient_timeout_wcpay_currency_format",
        "_transient_wcpay_locale_info",
        "_transient_timeout_wcpay_locale_info",
        f"wcpay_multi_currency_exchange_rate_{currency_lc}",
        f"wcpay_multi_currency_manual_rate_{currency_lc}",
        f"wcpay_multi_currency_price_rounding_{currency_lc}",
        f"wcpay_multi_currency_price_charm_{currency_lc}",
    ]


def initial_options(role: str) -> dict[str, dict[str, object]]:
    options: dict[str, dict[str, object]] = {}
    autoload_values = ("yes", "no", "auto", "on", "off")
    for index, option_name in enumerate(expected_option_names()):
        if "manual_rate" in option_name:
            options[option_name] = {"exists": False}
            continue
        value = "USD" if option_name == "woocommerce_currency" else f"{role}:{option_name}"
        options[option_name] = {
            "exists": True,
            "value": value,
            "autoload": autoload_values[index % len(autoload_values)],
        }
    return options


def make_fake_wp(
    path: Path,
    *,
    role: str,
    call_log: Path,
    state_path: Path,
    fail_snapshot: bool = False,
    stale_refresh: bool = False,
    restore_mismatch: bool = False,
    pause_marker: str = "",
    pause_ready: Path | None = None,
) -> dict[str, dict[str, object]]:
    original = initial_options(role)
    state_path.write_text(json.dumps(original, sort_keys=True), encoding="utf-8")
    runtime = "plugin" if role == "reference" else "native"

    write_executable(
        path,
        f'''#!/usr/bin/env python3
import base64
import hashlib
import json
import sys
import time
from pathlib import Path

role = {role!r}
runtime = {runtime!r}
call_log = Path({str(call_log)!r})
state_path = Path({str(state_path)!r})
fail_snapshot = {fail_snapshot!r}
stale_refresh = {stale_refresh!r}
restore_mismatch = {restore_mismatch!r}
pause_marker = {pause_marker!r}
pause_ready = Path({str(pause_ready or "")!r})
args = " ".join(sys.argv[1:])

markers = (
    "woopayments_mc_state_snapshot",
    "woopayments_mc_state_configure_automatic",
    "woopayments_mc_state_build",
    "woopayments_mc_state_inspect",
    "woopayments_mc_state_restore",
)
marker = next((candidate for candidate in markers if candidate in args), "")
if not marker and "wcpay_multi_currency_manual_rate_" in args:
    marker = "legacy_manual_configuration"
elif not marker and "_wcpay_multi_currency_order_exchange_rate" in args:
    marker = "order_money_meta"
elif not marker and args == "wc-native-payments status":
    marker = "native_owner"

with call_log.open("a", encoding="utf-8") as handle:
    handle.write(json.dumps({{"role": role, "marker": marker, "args": args}}) + "\\n")

if pause_marker and marker == pause_marker and not pause_ready.exists():
    pause_ready.write_text("ready\\n", encoding="utf-8")
    time.sleep(60)

if marker == "native_owner":
    print("Owner: native" if role == "target" else "Owner: plugin")
    raise SystemExit(0)

state = json.loads(state_path.read_text(encoding="utf-8"))
option_names = {expected_option_names()!r}

if marker == "woopayments_mc_state_snapshot":
    if fail_snapshot:
        print("synthetic snapshot failure", file=sys.stderr)
        raise SystemExit(1)
    snapshot_options = {{}}
    for option_name in option_names:
        record = state[option_name]
        snapshot_record = {{"exists": record["exists"]}}
        if record["exists"]:
            snapshot_record.update({{
                "option_value_b64": base64.b64encode(
                    record["value"].encode("utf-8")
                ).decode("ascii"),
                "autoload": record["autoload"],
            }})
        snapshot_options[option_name] = snapshot_record
    payload_json = json.dumps({{
        "schema": "woopayments_mc_option_snapshot_payload.v1",
        "option_names": option_names,
        "options": snapshot_options,
    }}, sort_keys=True, separators=(",", ":"))
    print(json.dumps({{
        "schema": "woopayments_mc_option_snapshot.v1",
        "role": role,
        "snapshot_b64": base64.b64encode(payload_json.encode("utf-8")).decode("ascii"),
        "snapshot_sha256": hashlib.sha256(payload_json.encode("utf-8")).hexdigest(),
        "option_names": option_names,
        "option_count": len(option_names),
    }}))
    raise SystemExit(0)

if marker in {{"woopayments_mc_state_configure_automatic", "legacy_manual_configuration"}}:
    now = int(time.time())
    for option_name in option_names:
        if option_name == "woocommerce_currency":
            continue
        if "manual_rate" in option_name:
            state[option_name] = {{"exists": False}}
        else:
            state[option_name] = {{"exists": True, "value": "mutated", "autoload": "no"}}
    state_path.write_text(json.dumps(state, sort_keys=True), encoding="utf-8")
    print(json.dumps({{
        "schema": "woopayments_mc_automatic_configure.v1",
        "role": role,
        "runtime": runtime,
        "configured": True,
        "store_currency": "USD",
        "currencies_to": ["GBP"],
        "rate_modes": {{"GBP": "automatic"}},
        "manual_rates_absent": marker != "legacy_manual_configuration",
        "shared_options_verified": True,
        "refresh_started_at": now,
        "cache_absent_after_delete": True,
    }}))
    raise SystemExit(0)

if marker == "woopayments_mc_state_build":
    now = int(time.time())
    print(json.dumps({{
        "schema": "woopayments_mc_runtime_build.v1",
        "role": role,
        "runtime": runtime,
        "provider": "woopayments",
        "provider_registered": True,
        "provider_available": True,
        "state_built": True,
        "built_at": now,
    }}))
    raise SystemExit(0)

if marker == "woopayments_mc_state_inspect":
    now = int(time.time())
    observed = now - 600 if stale_refresh else now
    print(json.dumps({{
        "schema": "woopayments_mc_rate_observation.v1",
        "role": role,
        "runtime": runtime,
        "provider": "woopayments",
        "provider_registered": True,
        "provider_available": True,
        "fetched": observed,
        "updated": observed,
        "observed_at": now,
        "rates": {{"GBP": "0.81"}},
        "missing": [],
        "cache_errored": False,
    }}))
    raise SystemExit(0)

if marker == "woopayments_mc_state_restore":
    envelope = json.loads(sys.stdin.read())
    payload_bytes = base64.b64decode(envelope["snapshot_b64"], validate=True)
    digest_matches = hashlib.sha256(payload_bytes).hexdigest() == envelope["snapshot_sha256"]
    payload = json.loads(payload_bytes)
    restored_state = {{}}
    for option_name in payload["option_names"]:
        record = payload["options"][option_name]
        restored = {{"exists": record["exists"]}}
        if record["exists"]:
            restored.update({{
                "value": base64.b64decode(record["option_value_b64"], validate=True).decode("utf-8"),
                "autoload": record["autoload"],
            }})
        restored_state[option_name] = restored
    mismatches = []
    if restore_mismatch:
        restored_state["wcpay_multi_currency_enabled_currencies"] = {{
            "exists": True,
            "value": "still-mutated",
            "autoload": "no",
        }}
        mismatches.append("wcpay_multi_currency_enabled_currencies:value_mismatch")
    state_path.write_text(json.dumps(restored_state, sort_keys=True), encoding="utf-8")
    verified = digest_matches and not mismatches
    print(json.dumps({{
        "schema": "woopayments_mc_option_restore.v1",
        "role": role,
        "restored": verified,
        "verified": verified,
        "snapshot_sha256": envelope["snapshot_sha256"],
        "option_count": len(option_names),
        "restored_count": len(option_names),
        "cache_verified_count": len(option_names) if verified else len(option_names) - 1,
        "mismatches": mismatches,
    }}))
    raise SystemExit(0)

if marker == "order_money_meta":
    print(json.dumps({{
        "missing": [],
        "meta": {{
            "_charge_id": "ch_" + role,
            "_wcpay_payment_transaction_id": "txn_" + role,
            "_wcpay_transaction_fee": "175",
            "_wcpay_net": "4825",
            "_wcpay_intent_currency": "gbp",
            "_wcpay_multi_currency_stripe_exchange_rate": "0.81",
            "_wcpay_multi_currency_order_exchange_rate": "0.81",
            "_wcpay_multi_currency_order_default_currency": "USD",
        }},
    }}))
    raise SystemExit(0)

print("unexpected fake WP invocation: " + args, file=sys.stderr)
raise SystemExit(2)
''',
    )
    return original


def prepare_harness(root: Path) -> Path:
    harness_dir = root / "harness"
    harness_dir.mkdir()
    shutil.copy2(GATE, harness_dir / GATE.name)
    shutil.copy2(LOCAL_RUNNER_SAFETY, harness_dir / LOCAL_RUNNER_SAFETY.name)
    if STATE_HELPER.is_file():
        shutil.copy2(STATE_HELPER, harness_dir / STATE_HELPER.name)

    write_executable(
        harness_dir / "flow-drive.sh",
        """#!/usr/bin/env bash
printf '{"role":null,"marker":"flow","args":"charge"}\n' >> "$HARNESS_CALL_LOG"
if [[ "$WP" == *"target"* ]]; then
  printf '{"order_id":202,"order_currency":"GBP","charge_id":"ch_target","intent_id":"pi_target","transaction_id":"txn_target"}\n'
else
  printf '{"order_id":101,"order_currency":"GBP","charge_id":"ch_reference","intent_id":"pi_reference","transaction_id":"txn_reference"}\n'
fi
""",
    )
    write_executable(
        harness_dir / "financial-reconcile.sh",
        """#!/usr/bin/env bash
printf '{"role":null,"marker":"reconcile","args":"%s"}\n' "$1" >> "$HARNESS_CALL_LOG"
if [ "${FAIL_RECONCILE_ORDER_ID:-}" = "$1" ]; then
  exit 1
fi
exit 0
""",
    )
    return harness_dir / GATE.name


def run_gate(
    root: Path,
    *,
    fail_snapshot_role: str = "",
    stale_refresh_role: str = "",
    restore_mismatch_role: str = "",
    fail_reconcile_order_id: str = "",
    pause_role: str = "",
    pause_marker: str = "",
    pause_ready: Path | None = None,
) -> tuple[
    subprocess.CompletedProcess[str],
    Path,
    Path,
    Path,
    dict[str, dict[str, object]],
    dict[str, dict[str, object]],
]:
    gate = prepare_harness(root)
    ref_wp = root / "reference-wp"
    target_wp = root / "target-wp"
    call_log = root / "calls.jsonl"
    ref_state = root / "reference-state.json"
    target_state = root / "target-state.json"
    out_dir = root / "evidence"
    ref_original = make_fake_wp(
        ref_wp,
        role="reference",
        call_log=call_log,
        state_path=ref_state,
        fail_snapshot=fail_snapshot_role == "reference",
        stale_refresh=stale_refresh_role == "reference",
        restore_mismatch=restore_mismatch_role == "reference",
        pause_marker=pause_marker if pause_role == "reference" else "",
        pause_ready=pause_ready,
    )
    target_original = make_fake_wp(
        target_wp,
        role="target",
        call_log=call_log,
        state_path=target_state,
        fail_snapshot=fail_snapshot_role == "target",
        stale_refresh=stale_refresh_role == "target",
        restore_mismatch=restore_mismatch_role == "target",
        pause_marker=pause_marker if pause_role == "target" else "",
        pause_ready=pause_ready,
    )

    args, env = adapt_wp_runner_arguments(
        [
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency",
            CURRENCY,
        ],
        os.environ.copy(),
    )
    env.update(
        {
            "HARNESS_CALL_LOG": str(call_log),
            "FAIL_RECONCILE_ORDER_ID": fail_reconcile_order_id,
            "CONVERTED_CURRENCY_OUT_DIR": str(out_dir),
        }
    )
    result = subprocess.run(
        ["bash", str(gate), *args],
        cwd=REPO,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    return (
        result,
        call_log,
        ref_state,
        target_state,
        ref_original,
        target_original,
    )


def read_calls(path: Path) -> list[dict[str, str]]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


def assert_state(path: Path, expected: dict[str, dict[str, object]]) -> None:
    assert json.loads(path.read_text(encoding="utf-8")) == expected


def test_reusable_state_helper_restores_exact_option_rows_only() -> None:
    helper = STATE_HELPER.read_text(encoding="utf-8")
    gate = GATE.read_text(encoding="utf-8")

    assert "SELECT option_value, autoload" in helper
    assert "option_value_b64" in helper
    assert "ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)" in helper
    assert "maybe_serialize( \\$cache_value ) !== \\$expected_value" in helper
    assert "woopayments_mc_validate_snapshot_file" in helper
    assert "woopayments_mc_validate_restore" in helper
    assert 'woopayments_validate_approved_docker_runner "$REF_WP" ref' in gate
    assert 'woopayments_validate_approved_docker_runner "$TARGET_WP" target' in gate
    assert "'automatic', false" in helper
    assert "delete_option( 'wcpay_multi_currency_manual_rate_'" in helper
    assert "0.80" not in helper
    assert "mysqldump" not in helper.lower()
    assert "database rollback" not in helper.lower()

    restore_arm = gate.index("# Arm both restores before the first update_option/delete_option operation.")
    first_configuration = gate.index(
        'prepare_automatic_rates "$REF_WP" reference plugin', restore_arm
    )
    assert restore_arm < first_configuration
    for handled_signal, exit_code in (("HUP", 129), ("INT", 130), ("TERM", 143)):
        assert f"trap 'handle_signal {exit_code}' {handled_signal}" in gate


def test_state_helper_accepts_large_valid_json_without_repassing_it_as_an_argument() -> None:
    with tempfile.TemporaryDirectory(prefix="converted-currency-state-helper-") as tmp:
        root = Path(tmp)
        fake_wp = root / "fake-wp"
        write_executable(
            fake_wp,
            """#!/usr/bin/env python3
import json

print(json.dumps({"schema": "large", "payload": "x" * 1_500_000}))
""",
        )

        result = subprocess.run(
            [
                "bash",
                "-c",
                'source "$1"; woopayments_mc_wp_eval_json "$2" large "ignored"',
                "bash",
                str(STATE_HELPER),
                str(fake_wp),
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0, result.stderr
        assert json.loads(result.stdout) == {
            "schema": "large",
            "payload": "x" * 1_500_000,
        }


def test_rejects_unsafe_wp_runner_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        gate = prepare_harness(root)
        invocation_log = root / "invoked"
        spy = root / "spy-wp"
        write_executable(
            spy,
            f"#!/usr/bin/env bash\nprintf invoked >> {str(invocation_log)!r}\nexit 1\n",
        )

        result = subprocess.run(
            [
                "bash",
                str(gate),
                "--ref",
                f"{spy} --http=https://merchant.example.com",
                "--target",
                str(spy),
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        assert not invocation_log.exists()


def test_automatic_rates_restore_exact_state_and_record_residue() -> None:
    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        result, calls_path, ref_state, target_state, ref_original, target_original = (
            run_gate(root)
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert_state(ref_state, ref_original)
        assert_state(target_state, target_original)
        calls = read_calls(calls_path)
        for role, runtime in (("reference", "plugin"), ("target", "native")):
            role_calls = [call for call in calls if call.get("role") == role]
            markers = [call["marker"] for call in role_calls]
            assert markers.index("woopayments_mc_state_snapshot") < markers.index(
                "woopayments_mc_state_configure_automatic"
            )
            assert "legacy_manual_configuration" not in markers
            assert "woopayments_mc_state_build" in markers
            assert "woopayments_mc_state_inspect" in markers
            assert markers[-1] == "woopayments_mc_state_restore"
            configure = next(
                call for call in role_calls if call["marker"] == "woopayments_mc_state_configure_automatic"
            )
            assert f"'{runtime}'" in configure["args"]

        evidence = json.loads(
            (root / "evidence/converted-currency-gate.json").read_text(
                encoding="utf-8"
            )
        )
        assert evidence["schema"] == "woopayments_converted_currency_gate_result.v1"
        assert evidence["status"] == "pass"
        assert evidence["created_order_ids"] == {"reference": 101, "target": 202}
        assert evidence["reference"]["rate_refresh"]["status"] == "pass"
        assert evidence["target"]["rate_refresh"]["status"] == "pass"
        assert evidence["reference"]["rate_refresh"]["runtime"] == "plugin"
        assert evidence["target"]["rate_refresh"]["runtime"] == "native"
        assert evidence["reference"]["rate_refresh"]["checks"][
            "shared_options_verified"
        ] is True
        assert evidence["target"]["rate_refresh"]["checks"][
            "shared_options_verified"
        ] is True
        assert evidence["option_restore"]["reference"]["verified"] is True
        assert evidence["option_restore"]["target"]["verified"] is True
        residue = {
            (item["role"], item["resource"], item["id"])
            for item in evidence["provider_residue"]
        }
        assert residue == {
            ("reference", "charge", "ch_reference"),
            ("reference", "payment_intent", "pi_reference"),
            ("reference", "provider_transaction", "txn_reference"),
            ("target", "charge", "ch_target"),
            ("target", "payment_intent", "pi_target"),
            ("target", "provider_transaction", "txn_target"),
        }
        assert all(item["disposition"] == "retained" for item in evidence["provider_residue"])

        for role in ("reference", "target"):
            snapshot = json.loads(
                (root / f"evidence/{role}-option-snapshot.json").read_text(
                    encoding="utf-8"
                )
            )
            payload = json.loads(
                base64.b64decode(snapshot["snapshot_b64"], validate=True)
            )
            assert payload["option_names"] == expected_option_names()
            assert all(
                "autoload" in record
                for record in payload["options"].values()
                if record["exists"]
            )


def test_restores_both_stores_after_later_reconciliation_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        result, calls_path, ref_state, target_state, ref_original, target_original = (
            run_gate(root, fail_reconcile_order_id="202")
        )

        assert result.returncode != 0
        assert "financial reconciliation failed" in result.stderr
        assert_state(ref_state, ref_original)
        assert_state(target_state, target_original)
        calls = read_calls(calls_path)
        restore_calls = [
            call for call in calls if call["marker"] == "woopayments_mc_state_restore"
        ]
        assert [call["role"] for call in restore_calls] == ["target", "reference"]
        evidence = json.loads(
            (root / "evidence/converted-currency-gate.json").read_text(
                encoding="utf-8"
            )
        )
        assert evidence["status"] == "fail"
        assert evidence["trigger"] == "normal"
        assert evidence["created_order_ids"] == {"reference": 101, "target": 202}
        assert evidence["reference"]["reconciliation"]["passed"] is True
        assert evidence["target"]["reconciliation"]["passed"] is False


def test_snapshot_restore_and_refresh_mismatches_fail_closed() -> None:
    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        result, calls_path, _ref_state, _target_state, _ref_original, _target_original = (
            run_gate(root, fail_snapshot_role="target")
        )

        assert result.returncode != 0
        markers = [call["marker"] for call in read_calls(calls_path)]
        assert "woopayments_mc_state_configure_automatic" not in markers

    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        result, calls_path, ref_state, target_state, ref_original, target_original = (
            run_gate(root, stale_refresh_role="target")
        )

        assert result.returncode != 0
        assert_state(ref_state, ref_original)
        assert_state(target_state, target_original)
        calls = read_calls(calls_path)
        assert not any(call["marker"] == "flow" for call in calls)
        assert [
            call["role"]
            for call in calls
            if call["marker"] == "woopayments_mc_state_restore"
        ] == ["target", "reference"]

    with tempfile.TemporaryDirectory(prefix="converted-currency-gate-") as tmp:
        root = Path(tmp)
        result, _calls_path, ref_state, target_state, ref_original, target_original = (
            run_gate(root, restore_mismatch_role="target")
        )

        assert result.returncode == 3
        assert_state(ref_state, ref_original)
        assert json.loads(target_state.read_text(encoding="utf-8")) != target_original
        evidence = json.loads(
            (root / "evidence/converted-currency-gate.json").read_text(
                encoding="utf-8"
            )
        )
        assert evidence["status"] == "blocked"
        assert evidence["option_restore"]["target"]["verified"] is False


def test_hup_int_and_term_restore_armed_state() -> None:
    for handled_signal, expected_code, pause_marker in (
        (signal.SIGHUP, 129, "woopayments_mc_state_build"),
        (signal.SIGINT, 130, "woopayments_mc_state_build"),
        (signal.SIGTERM, 143, "woopayments_mc_state_build"),
        (signal.SIGTERM, 143, "woopayments_mc_state_restore"),
    ):
        with tempfile.TemporaryDirectory(prefix="converted-currency-gate-signal-") as tmp:
            root = Path(tmp)
            gate = prepare_harness(root)
            ref_wp = root / "reference-wp"
            target_wp = root / "target-wp"
            call_log = root / "calls.jsonl"
            ref_state = root / "reference-state.json"
            target_state = root / "target-state.json"
            pause_ready = root / "pause-ready"
            out_dir = root / "evidence"
            ref_original = make_fake_wp(
                ref_wp,
                role="reference",
                call_log=call_log,
                state_path=ref_state,
            )
            target_original = make_fake_wp(
                target_wp,
                role="target",
                call_log=call_log,
                state_path=target_state,
                pause_marker=pause_marker,
                pause_ready=pause_ready,
            )
            args, env = adapt_wp_runner_arguments(
                [
                    "--ref",
                    str(ref_wp),
                    "--target",
                    str(target_wp),
                    "--currency",
                    CURRENCY,
                ],
                os.environ.copy(),
            )
            env["HARNESS_CALL_LOG"] = str(call_log)
            env["CONVERTED_CURRENCY_OUT_DIR"] = str(out_dir)
            process = subprocess.Popen(
                ["bash", str(gate), *args],
                cwd=REPO,
                env=env,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                start_new_session=True,
            )
            deadline = time.monotonic() + 10
            while not pause_ready.exists() and process.poll() is None:
                if time.monotonic() >= deadline:
                    process.kill()
                    raise AssertionError("fake target refresh did not reach signal point")
                time.sleep(0.05)
            assert process.poll() is None

            os.killpg(process.pid, handled_signal)
            stdout, stderr = process.communicate(timeout=15)

            assert process.returncode == expected_code, (stdout, stderr)
            assert_state(ref_state, ref_original)
            assert_state(target_state, target_original)
            calls = read_calls(call_log)
            restore_roles = [
                call["role"]
                for call in calls
                if call["marker"] == "woopayments_mc_state_restore"
            ]
            if pause_marker == "woopayments_mc_state_restore":
                assert restore_roles == ["target", "target", "reference"]
            else:
                assert restore_roles == ["target", "reference"]
            evidence = json.loads(
                (out_dir / "converted-currency-gate.json").read_text(encoding="utf-8")
            )
            assert evidence["trigger"] == "signal"
            assert evidence["option_restore"]["reference"]["verified"] is True
            assert evidence["option_restore"]["target"]["verified"] is True


def main() -> None:
    tests = [
        test_reusable_state_helper_restores_exact_option_rows_only,
        test_state_helper_accepts_large_valid_json_without_repassing_it_as_an_argument,
        test_rejects_unsafe_wp_runner_before_invocation,
        test_automatic_rates_restore_exact_state_and_record_residue,
        test_restores_both_stores_after_later_reconciliation_failure,
        test_snapshot_restore_and_refresh_mismatches_fail_closed,
        test_hup_int_and_term_restore_armed_state,
    ]
    for test in tests:
        test()
    print(f"PASS: {len(tests)} converted-currency gate regression tests passed.")


if __name__ == "__main__":
    main()
