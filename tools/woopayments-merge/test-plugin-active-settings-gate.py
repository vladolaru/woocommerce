#!/usr/bin/env python3
"""Focused regression checks for the plugin-active settings browser gate."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/plugin-active-settings-gate.sh"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
TARGET_URL = "http://store8889.localhost:8889"
SETTINGS_URL = f"{TARGET_URL}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, *, active: bool = True, home_url: str = TARGET_URL, stageable: bool = False) -> None:
    active_exit = 0 if active else 1
    initial_state = "active" if active else "inactive"
    stageable_literal = "1" if stageable else "0"
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
if [ -n "${{FAKE_WP_INVOCATIONS:-}}" ]; then
\tprintf '%s\\n' "$*" >> "$FAKE_WP_INVOCATIONS"
fi
stageable={stageable_literal}
state_file="${{FAKE_WP_STATE:-}}"
current_active() {{
\tif [ "$stageable" = "1" ] && [ -n "$state_file" ]; then
\t\tif [ ! -f "$state_file" ]; then
\t\t\tprintf '%s\\n' {json.dumps(initial_state)} > "$state_file"
\t\tfi
\t\t[ "$(cat "$state_file")" = "active" ]
\t\treturn
\tfi
\treturn {active_exit}
}}
if [ "$1" = "plugin" ] && [ "$2" = "is-active" ] && [ "$3" = "woocommerce-payments" ]; then
\tcurrent_active
\texit $?
fi
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
\tprintf '%s\\n' {json.dumps(home_url)}
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "stage-plugin-active" ]; then
\tcat >/dev/null
\tprintf 'active\\n' > "$state_file"
\tprintf '{{"success":true,"mode":"stage-plugin-active","was_plugin_active":false,"disabled_mu_plugins":[{{"path":"/wp-content/mu-plugins/native-payments-enable.php","disabled_path":"/wp-content/mu-plugins/native-payments-enable.php.disabled-by-woopayments-merge"}}],"errors":[]}}\\n'
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "restore-plugin-active" ]; then
\tcat >/dev/null
\tprintf 'inactive\\n' > "$state_file"
\tprintf '{{"success":true,"mode":"restore-plugin-active","errors":[]}}\\n'
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_playwriter(path: Path, *, duplicate_store_error: bool = False) -> None:
    duplicate_errors = (
        '[{"type":"error","text":"Store \\"wc/payments/settings\\" is already registered"}]'
        if duplicate_store_error
        else "[]"
    )
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import pathlib
import sys

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({{
        "argv": sys.argv[1:],
        "env": {{
            "target_url": os.environ.get("PLUGIN_SETTINGS_TARGET_URL", ""),
            "settings_url": os.environ.get("PLUGIN_SETTINGS_SETTINGS_URL", ""),
            "evidence_path": os.environ.get("PLUGIN_SETTINGS_EVIDENCE_PATH", ""),
        }},
    }}, sort_keys=True) + "\\n")

if "-e" in sys.argv:
    sys.exit(0)

payload = {{
    "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
    "status": "pass",
    "target_url": os.environ["PLUGIN_SETTINGS_TARGET_URL"],
    "settings_url": os.environ["PLUGIN_SETTINGS_SETTINGS_URL"],
    "plugin_active": True,
    "authenticated_wp_admin": True,
    "settings_screen_present": True,
    "duplicate_store_errors": {duplicate_errors},
    "fatal_console_errors": [],
    "failed_responses": [],
    "failures": [],
}}

evidence_path = pathlib.Path(os.environ["PLUGIN_SETTINGS_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
""",
    )


def test_usage_requires_target_and_target_url() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--target-url" in result.stderr


def test_print_plan_describes_settings_regression_gate() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--target-url",
        TARGET_URL,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_plugin_active_settings_gate_plan.v1"
    assert payload["target_wp"] == TARGET_WP
    assert payload["target_url"] == TARGET_URL
    assert payload["settings_url"] == SETTINGS_URL
    assert payload["browser_driver"].endswith("plugin-active-settings.playwriter.mjs")
    assert payload["checks"] == [
        "woocommerce-payments plugin is active before browser run",
        "authenticated wp-admin settings page renders",
        "WooPayments settings screen is present",
        "no duplicate wc/payments/settings store registration error",
    ]


def test_full_gate_invokes_playwriter_driver_and_validates_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
        }

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 2
        assert "-e" in invocations[0]["argv"]
        assert "state.pluginActiveSettingsConfig" in " ".join(invocations[0]["argv"])
        assert TARGET_URL in " ".join(invocations[0]["argv"])
        assert SETTINGS_URL in " ".join(invocations[0]["argv"])
        assert "-s" in invocations[1]["argv"]
        assert "unit" in invocations[1]["argv"]
        assert str(REPO / "tools/woopayments-merge/plugin-active-settings.playwriter.mjs") in invocations[1]["argv"]
        assert invocations[1]["env"]["target_url"] == TARGET_URL
        assert invocations[1]["env"]["settings_url"] == SETTINGS_URL

        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_plugin_active_settings_gate_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["evidence"]["settings_screen_present"] is True
        assert rollup["evidence"]["duplicate_store_errors"] == []


def test_full_gate_can_stage_and_restore_plugin_active_fixture() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        wp_state = tmp_path / "wp-state.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp, active=False, stageable=True)
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_WP_STATE": str(wp_state),
        }

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--stage-plugin-active-fixture",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - stage-plugin-active" in wp_log
        assert "plugin is-active woocommerce-payments" in wp_log
        assert "eval-file - restore-plugin-active" in wp_log
        assert wp_state.read_text(encoding="utf-8").strip() == "inactive"

        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["evidence"]["settings_screen_present"] is True


def test_gate_blocks_when_woopayments_plugin_is_not_active() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"

        make_fake_wp(fake_wp, active=False)
        make_fake_playwriter(fake_playwriter)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(tmp_path / "evidence"),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            },
        )

        assert result.returncode == 3
        assert "WooPayments plugin is not active on the target store" in result.stderr
        assert not invocations_path.exists()


def test_gate_fails_duplicate_settings_store_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter, duplicate_store_error=True)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "duplicate wc/payments/settings store registration error" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("duplicate wc/payments/settings store registration error" in item for item in rollup["failures"])


def main() -> None:
    tests = [
        test_usage_requires_target_and_target_url,
        test_print_plan_describes_settings_regression_gate,
        test_full_gate_invokes_playwriter_driver_and_validates_evidence,
        test_full_gate_can_stage_and_restore_plugin_active_fixture,
        test_gate_blocks_when_woopayments_plugin_is_not_active,
        test_gate_fails_duplicate_settings_store_evidence,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
