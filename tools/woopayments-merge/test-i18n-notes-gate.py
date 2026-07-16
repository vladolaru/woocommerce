#!/usr/bin/env python3
"""Focused regression checks for the i18n order-notes gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/i18n-notes-gate.sh"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def run_gate(*args: str, extra_env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    process_env = os.environ.copy()
    if extra_env:
        process_env.update(extra_env)
    command_args, process_env = adapt_wp_runner_arguments(list(args), process_env)
    return subprocess.run(
        ["bash", str(SCRIPT), *command_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, invocations_path: Path, *, native_owner: str = "none") -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
if [[ "${{1:-}}" == --exec=* ]]; then
	shift
fi
printf '%s\\n' "$*" >> {json.dumps(str(invocations_path))}
if [ "$1" = "wc-native-payments" ] && [ "$2" = "status" ]; then
\tprintf 'Owner: %s\\n' {json.dumps(native_owner)}
\tprintf 'Native enabled: %s\\n' "$([ {json.dumps(native_owner)} = native ] && printf yes || printf no)"
\texit 0
fi
if [ "$1" = "eval" ]; then
\tprintf 'en_US\\n'
\texit 0
fi
if [ "$1" = "language" ] && [ "$2" = "core" ] && [ "$3" = "install" ]; then
\texit 0
fi
if [ "$1" = "site" ] && [ "$2" = "switch-language" ]; then
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def write_state(
    path: Path,
    *,
    locale: str = "de_DE",
    orders: list[dict] | None = None,
    translation_source: str = "catalog",
    catalog_translated: bool = True,
) -> None:
    if orders is None:
        orders = localized_orders()

    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_i18n_notes_capture.v1",
                "locale": locale,
                "translation_source": translation_source,
                "catalog_evidence": {
                    "schema": "woopayments_i18n_catalog_evidence.v1",
                    "locale": locale,
                    "textdomain": "woocommerce",
                    "textdomain_loaded": True,
                    "messages": {
                        "charge": {
                            "message_id": "A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).",
                            "translation": "Eine Zahlung von %1$s wurde mit %2$s erfolgreich belastet (<a>%3$s</a>)." if catalog_translated else "A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).",
                            "translated": catalog_translated,
                        },
                        "refund": {
                            "message_id": "A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)",
                            "translation": "Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)" if catalog_translated else "A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)",
                            "translated": catalog_translated,
                        },
                        "dispute": {
                            "message_id": 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.',
                            "translation": 'Zahlung ueber %1$s wurde mit Grund "%2$s" angefochten. <a href="%4$s" target="_blank" rel="noopener noreferrer">Antwort faellig bis %3$s</a>.' if catalog_translated else 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.',
                            "translated": catalog_translated,
                        },
                    },
                },
                "orders": orders,
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


def localized_orders() -> list[dict]:
    return [
        {
            "flow": "charge",
            "order_id": 101,
            "notes": [
                "Zahlung abgeschlossen.",
                "<strong>Gebuehrendetails:</strong><div><p>Gebuehr (2.9% + $0.30): $1.75 USD</p><p>Nettoauszahlung: $48.25 USD</p></div>",
            ],
        },
        {
            "flow": "refund",
            "order_id": 101,
            "notes": [
                "Rueckerstattung erstellt.",
            ],
        },
        {
            "flow": "dispute",
            "order_id": 202,
            "notes": [
                "Zahlungsanfrage wurde erstellt.",
                "Zahlungsstreitigkeit wurde aktualisiert.",
            ],
        },
    ]


def test_usage_requires_target_or_snapshot_state() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--state" in result.stderr


def test_print_plan_describes_live_i18n_probe() -> None:
    result = run_gate("--target", TARGET_WP, "--print-plan")

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_i18n_notes_gate_plan.v1"
    assert payload["target_wp"] == TARGET_WP
    assert payload["locale"] == "de_DE"
    assert payload["wp_cli_memory_limit"] == "256M"
    assert payload["required_flows"] == ["charge", "refund", "dispute"]
    assert payload["translation_probe"]["plugin"] == "woopayments-i18n-notes-gate-translations.php"
    assert "Payment complete." in payload["english_sentinels"]
    assert "A test payment" in payload["english_sentinels"]


def test_gate_passes_localized_snapshot() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        out_dir = tmp_path / "evidence"
        write_state(state)

        result = run_gate("--state", str(state), "--out-dir", str(out_dir))

        assert result.returncode == 0, result.stderr
        assert "PASS: native WooPayments order notes are localized and deduplicated." in result.stdout

        rollup = json.loads((out_dir / "i18n-notes-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["failures"] == []


def test_gate_fails_when_english_sentinel_is_present() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        out_dir = tmp_path / "evidence"
        orders = localized_orders()
        orders[0]["notes"].append("Payment complete.")
        write_state(state, orders=orders)

        result = run_gate("--state", str(state), "--out-dir", str(out_dir))

        assert result.returncode == 1
        assert "english sentinel found" in result.stderr

        rollup = json.loads((out_dir / "i18n-notes-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "Payment complete." in "\n".join(rollup["failures"])


def test_gate_fails_when_note_body_is_duplicated() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        orders = localized_orders()
        orders[2]["notes"].append("Zahlungsanfrage wurde erstellt.")
        write_state(state, orders=orders)

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "duplicate order note" in result.stderr


def test_gate_fails_when_required_flow_has_no_notes() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        orders = [order for order in localized_orders() if order["flow"] != "dispute"]
        write_state(state, orders=orders)

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "missing required flow notes: dispute" in result.stderr


def test_gate_fails_when_flows_only_contain_unrelated_localized_notes() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        state = Path(tmp) / "state.json"
        write_state(
            state,
            orders=[
                {"flow": flow, "order_id": index, "notes": ["Allgemeine Bestellnotiz."]}
                for index, flow in enumerate(("charge", "refund", "dispute"), start=1)
            ],
        )

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "missing flow-specific localized note" in result.stderr


def test_gate_blocks_when_release_catalog_lacks_required_message_ids() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        out_dir = tmp_path / "evidence"
        write_state(state, catalog_translated=False)

        result = run_gate("--state", str(state), "--out-dir", str(out_dir))

        assert result.returncode == 3
        assert "catalog translation unavailable" in result.stderr
        rollup = json.loads((out_dir / "i18n-notes-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["implementation_status"] == "pass"
        assert rollup["catalog_status"] == "blocked"


def test_deterministic_probe_requires_exact_per_flow_markers() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        state = Path(tmp) / "state.json"
        write_state(state, translation_source="deterministic_gettext_probe")

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "missing deterministic merchant-note marker" in result.stderr


def test_deterministic_probe_rejects_marker_on_unrelated_refund_email() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        state = Path(tmp) / "state.json"
        write_state(
            state,
            translation_source="deterministic_gettext_probe",
            orders=[
                {
                    "flow": "charge",
                    "order_id": 101,
                    "notes": ["[wcpay-i18n:charge] Eine Zahlung pi_test"],
                },
                {
                    "flow": "refund",
                    "order_id": 101,
                    "notes": [
                        "A refund of 25 USD using WooPayments. Reason: test. (<code>re_test</code>)",
                        "E-Mail [wcpay-i18n:refund] Rueckerstattete Bestellung",
                    ],
                },
                {
                    "flow": "dispute",
                    "order_id": 202,
                    "notes": ["[wcpay-i18n:dispute] Zahlung angefochten"],
                },
            ],
        )

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "english merchant note found: refund" in result.stderr
        assert "missing deterministic merchant-note marker for refund" in result.stderr


def test_deterministic_probe_rejects_marker_and_provider_id_split_across_notes() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        state = Path(tmp) / "state.json"
        provider_ids = {"charge": "pi_test", "refund": "re_test", "dispute": "ch_test"}
        write_state(
            state,
            translation_source="deterministic_gettext_probe",
            orders=[
                {
                    "flow": flow,
                    "order_id": index,
                    "notes": [
                        f"[wcpay-i18n:{flow}] unrelated",
                        f"Lokalisierte Notiz {provider_ids[flow]}",
                    ],
                }
                for index, flow in enumerate(("charge", "refund", "dispute"), start=101)
            ],
        )

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        for flow in ("charge", "refund", "dispute"):
            assert f"missing deterministic merchant-note marker for {flow}" in result.stderr


def test_gate_fails_when_locale_was_not_switched() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        write_state(state, locale="en_US")

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "captured locale en_US does not match expected de_DE" in result.stderr


def test_live_gate_blocks_before_language_switch_when_target_is_not_native_owned() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        invocations_path = tmp_path / "wp-invocations.txt"

        make_fake_wp(fake_wp, invocations_path, native_owner="none")

        result = run_gate(
            "--target",
            str(fake_wp),
            "--out-dir",
            str(tmp_path / "evidence"),
        )

        assert result.returncode == 3
        assert "target native payments owner is not native: none" in result.stderr

        invocations = invocations_path.read_text(encoding="utf-8").splitlines()
        assert invocations == ["wc-native-payments status"]


def test_live_gate_rejects_remote_target_runner_before_any_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        invocations_path = tmp_path / "wp-invocations.txt"

        make_fake_wp(fake_wp, invocations_path, native_owner="native")

        result = run_gate(
            "--target",
            f"{fake_wp} --ssh=user@remote.example",
            "--out-dir",
            str(tmp_path / "evidence"),
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr
        assert "--ssh" in result.stderr
        assert not invocations_path.exists()


def test_verify_runs_i18n_notes_gate_for_cross_store_mode() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "i18n-notes-gate.sh" in verify_source


def test_live_dispute_probe_uses_distinct_quantity_to_avoid_duplicate_session_guard() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert 'drive_flow "dispute" "$dispute_flow" dispute --deterministic --native --quantity=3' in source


def test_live_gate_installs_controlled_gettext_probe_for_new_payment_note_strings() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "install_translation_probe" in source
    assert "capture_catalog_evidence" in source
    assert "'Fee (%1\\$s): %2\\$s' => 'Gebuehr (%1\\$s): %2\\$s'" in source
    assert "'A payment of %1\\$s was <strong>successfully charged</strong> using %2\\$s (<a>%3\\$s</a>).' => '[wcpay-i18n:charge]" in source
    assert "'A refund of %1\\$s %5\\$s using %2\\$s. Reason: %3\\$s. (<code>%4\\$s</code>)' => '[wcpay-i18n:refund]" in source
    assert "'Payment dispute has been updated' => '[wcpay-i18n:dispute] Zahlungsdisput wurde aktualisiert'" in source


def test_live_gate_classifies_flow_driver_product_failure_as_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-flow-fail-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "fake-wp.sh"
        fake_flow = tmp_path / "fake-flow.sh"
        out_dir = tmp_path / "evidence"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -u
if [[ "${1:-}" == --exec=* ]]; then shift; fi
if [ "${1:-}" = "wc-native-payments" ] && [ "${2:-}" = "status" ]; then
  printf '%s\n' 'Owner: native'
  exit 0
fi
if [ "${1:-}" = "language" ] || [ "${1:-}" = "site" ]; then exit 0; fi
if [ "${1:-}" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"woopayments_i18n_language_restore.v1"* ]]; then
    printf '%s\n' '{"schema":"woopayments_i18n_language_restore.v1","success":true,"restored_snapshot_exact":true,"errors":[]}'
  elif [[ "$body" == *"woopayments_i18n_language_snapshot.v1"* ]]; then
    printf '%s\n' 'WPLANG:en_US'
    printf '%s\n' '{"schema":"woopayments_i18n_language_snapshot.v1","success":true,"exists":true,"value":"en_US","autoload":"auto"}'
  elif [[ "$body" == *"woopayments_i18n_catalog_evidence.v1"* ]]; then
    printf '%s\n' '{"schema":"woopayments_i18n_catalog_evidence.v1","locale":"de_DE","textdomain":"woocommerce","textdomain_loaded":true,"messages":{}}'
  elif [[ "$body" == *"Translation probe path already exists"* ]]; then
    printf '%s\n' '{"success":true,"path":"/fake/probe.php","errors":[]}'
  elif [[ "$body" == *"woopayments_i18n_probe_install.v1"* ]]; then
    printf '%s\n' '{"schema":"woopayments_i18n_probe_install.v1","success":true,"path":"/fake/probe.php","sha256":"fake"}'
  elif [[ "$body" == *"woopayments_i18n_probe_cleanup.v1"* ]]; then
    printf '%s\n' '{"schema":"woopayments_i18n_probe_cleanup.v1","success":true,"errors":[]}'
  else
    printf '%s\n' 'unexpected eval-file body' >&2
    exit 2
  fi
  exit 0
fi
printf 'unexpected fake wp args: %s\n' "$*" >&2
exit 2
""",
        )
        write_executable(
            fake_flow,
            """#!/usr/bin/env bash
printf '%s\n' '{"op":"charge","result":"fail","reason":"declined"}'
exit 1
""",
        )

        result = run_gate(
            "--target",
            str(fake_wp),
            "--out-dir",
            str(out_dir),
            extra_env={"I18N_NOTES_FLOW_DRIVE": str(fake_flow)},
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL: charge flow failed." in result.stderr
        assert "BLOCKED: charge flow did not complete." not in result.stderr
        assert json.loads((out_dir / "charge-flow.json").read_text(encoding="utf-8"))["reason"] == "declined"
        assert json.loads((out_dir / "i18n-probe-cleanup.json").read_text(encoding="utf-8"))["success"] is True
        assert json.loads((out_dir / "i18n-language-restore.json").read_text(encoding="utf-8"))["restored_snapshot_exact"] is True


def test_live_gate_reads_original_locale_with_a_notice_safe_marker() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "WPLANG:" in source
    assert "grep -oE 'WPLANG:[A-Za-z_]*'" in source


def test_live_gate_restores_the_exact_language_option_snapshot() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "snapshot_language_state" in source
    assert "restore_language_state" in source
    assert "restored_snapshot_exact" in source
    assert '$TARGET_WP_RUNTIME eval-file - "$payload_b64" --skip-plugins --skip-themes' in source
    assert 'restore_locale="en_US"' not in source


def test_live_gate_owns_translation_probe_and_fails_closed_on_cleanup() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "I18N_PROBE_TOKEN" in source
    assert "fopen( \\$plugin, 'x' )" in source
    assert "translation probe ownership mismatch" in source
    assert "trap 'handle_signal 129' HUP" in source
    assert "trap 'handle_signal 130' INT" in source
    assert "trap 'handle_signal 143' TERM" in source
    assert "exit 70" in source
