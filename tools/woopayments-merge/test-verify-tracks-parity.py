#!/usr/bin/env python3
"""Regression checks for verify.sh Tracks parity orchestration."""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
TRACKS_PARITY = REPO / "tools/woopayments-merge/tracks-parity.sh"
LOCAL_RUNNER_SAFETY = REPO / "tools/woopayments-merge/local-runner-safety.sh"
OWNED_ORDER_CLEANUP = REPO / "tools/woopayments-merge/verify-owned-order-cleanup.php"
TIMEOUT_RUNNER = REPO / "tools/woopayments-merge/run-command-with-timeout.py"


def run_verify(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def prepare_fake_verify_repo(tmp: Path) -> tuple[Path, Path, Path, Path]:
    repo = tmp / "repo"
    merge_dir = repo / "tools" / "woopayments-merge"
    merge_dir.mkdir(parents=True)

    verify_copy = merge_dir / "verify.sh"
    verify_copy.write_text(SCRIPT.read_text(encoding="utf-8"), encoding="utf-8")
    verify_copy.chmod(0o755)
    (merge_dir / "local-runner-safety.sh").write_text(
        LOCAL_RUNNER_SAFETY.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "verify-owned-order-cleanup.php").write_text(
        OWNED_ORDER_CLEANUP.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "run-command-with-timeout.py").write_text(
        TIMEOUT_RUNNER.read_text(encoding="utf-8"),
        encoding="utf-8",
    )

    fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
if [ "$name" = "flow-drive.sh" ]; then
	if [ "${FLOW_DRIVE_FAIL_WITHOUT_ID:-0}" = "1" ]; then
		exit 1
	fi
	if [[ " $* " = *" --native "* ]]; then
		printf '{"order_id":456}\n'
	else
		printf '{"order_id":123}\n'
	fi
fi
if [ "$name" = "i18n-notes-gate.sh" ] && [ "${I18N_GATE_EXIT_CODE:-0}" != "0" ]; then
	exit "$I18N_GATE_EXIT_CODE"
fi
if [ "$name" = "tracks-parity.sh" ]; then
	case "${1:-}" in
		mark)
			printf 'tracks capture marked\n'
			;;
		normalize)
			count_file="$TRACKS_NORMALIZE_COUNT_FILE"
			count=0
			if [ -f "$count_file" ]; then
				count="$(cat "$count_file")"
			fi
			count=$((count + 1))
			printf '%s\n' "$count" > "$count_file"
			if [ "${TRACKS_EMPTY_TARGET:-0}" = "1" ] && [ "$count" -eq 2 ]; then
				exit 0
			fi
			printf 'orders_edit_status_change | payment_method=str:woocommerce_payments\n'
			;;
		diff)
			printf 'PASS: zero Tracks contract drift\n'
			;;
	esac
fi
"""
    for script_name in (
        "bc-drift-gate.sh",
        "subsystem-disposition-gate.sh",
        "hook-shape-parity.sh",
        "rest-route-parity.sh",
        "i18n-notes-gate.sh",
        "flow-drive.sh",
        "parity-diff.sh",
        "perf-baseline.sh",
        "perf-surface-gate.sh",
        "financial-reconcile.sh",
        "tracks-parity.sh",
    ):
        write_executable(merge_dir / script_name, fake_gate)

    fake_wp_template = """#!/usr/bin/env bash
set -eu
role="{role}"
printf 'wp-%s|%s\n' "$role" "$*" >> "$INVOCATIONS_LOG"
if [ "${{1:-}}" = "eval-file" ]; then
	body="$(cat)"
	if printf '%s' "$body" | grep -q "WCPAY_VERIFY_OWNED_ORDER_CLEANUP"; then
		printf 'owned-order-cleanup|%s|%s\n' "$role" "${{*:3}}" >> "$INVOCATIONS_LOG"
		if [ "${{CLEANUP_HANG_ROLE:-}}" = "$role" ]; then
			sleep 5
		fi
		if [ "${{CLEANUP_FAIL_ROLE:-}}" = "$role" ]; then
			printf 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP:{{"success":false,"results":[{{"status":"delete_failed"}}]}}\n'
			exit 1
		fi
		printf 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP:{{"success":true}}\n'
	elif printf '%s' "$body" | grep -q "WCPAY_RUNTIME_IDENTITY"; then
		if [ "$role" = "reference" ]; then
			printf 'WCPAY_RUNTIME_IDENTITY:{{"runtime_owner":"plugin","site_url":"http://localhost:8082"}}\n'
		else
			printf 'WCPAY_RUNTIME_IDENTITY:{{"runtime_owner":"native","site_url":"http://store8889.localhost:8889"}}\n'
		fi
	elif printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
		if [ "$role" = "reference" ]; then
			printf 'HELPER_OPTION:enabled:MQ==\n'
			if [ "${{TRACKS_REFERENCE_EMPTY_HELPER_ENDPOINT:-0}}" = "1" ]; then
				printf 'HELPER_OPTION:sink_endpoint:\n'
			else
				printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
			fi
			printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
			printf 'HELPER_OPTION:capture_browser:MQ==\n'
			printf 'HELPER_OPTION:capture_server:MQ==\n'
		else
			printf 'HELPER_OPTION:enabled:MA==\n'
			printf 'HELPER_OPTION:sink_endpoint:X19NSVNTSU5HX18=\n'
			printf 'HELPER_OPTION:sink_token:X19NSVNTSU5HX18=\n'
			printf 'HELPER_OPTION:capture_browser:MQ==\n'
			printf 'HELPER_OPTION:capture_server:MQ==\n'
		fi
	elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
		printf 'RESTORE_HELPER_ENDPOINT_ARG:%s:%s\n' "$role" "${{4-__UNSET__}}" >> "$INVOCATIONS_LOG"
		printf 'HELPER_RESTORED\n'
	elif printf '%s' "$body" | grep -q "TRACKING:"; then
		if [ "$role" = "reference" ]; then
			printf 'TRACKING:yes\n'
		else
			printf 'TRACKING:no\n'
		fi
	elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
		printf 'TRACKING_SET:%s\n' "${{3:-}}"
	else
		printf 'ready\n'
	fi
elif [ "${{1:-}}" = "wpcom-local" ] && [ "${{2:-}}" = "tracks" ] && [ "${{3:-}}" = "enable" ]; then
	printf 'Success: Local Tracks capture enabled.\n'
else
	printf '{{}}\n'
fi
"""
    ref_wp = tmp / "reference" / "wp"
    target_wp = tmp / "target" / "wp"
    ref_wp.parent.mkdir()
    target_wp.parent.mkdir()
    write_executable(ref_wp, fake_wp_template.format(role="reference"))
    write_executable(target_wp, fake_wp_template.format(role="target"))

    fake_wpcom_local = tmp / "fake-wpcom-local"
    write_executable(
        fake_wpcom_local,
        """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
	printf 'Command: tracks path\n'
	printf 'Status: success\n'
	printf 'Context:\n'
	printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
	exit 0
fi
exit 1
""",
    )

    return verify_copy, ref_wp, target_wp, fake_wpcom_local


def run_fake_verify(
    verify_copy: Path,
    ref_wp: Path,
    target_wp: Path,
    env: dict[str, str],
) -> subprocess.CompletedProcess[str]:
    args, process_env = adapt_wp_runner_arguments(
        ["--ref", str(ref_wp), "--target", str(target_wp), "--with-tracks"],
        env,
    )
    return subprocess.run(
        ["bash", str(verify_copy), *args],
        cwd=verify_copy.parents[2],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
        check=False,
    )


def test_tracks_flags_are_documented_without_placeholder_language() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--with-tracks" in result.stderr
    assert "--tracks-ref-store-id" in result.stderr
    assert "--tracks-target-store-id" in result.stderr
    assert "placeholder" not in result.stderr.lower()


def test_with_tracks_uses_sink_parity_commands() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "tracks-parity.sh\" reset" not in source
    assert "tracks-parity.sh\" mark" in source
    assert "tracks-parity.sh\" normalize" in source
    assert "tracks-parity.sh\" diff" in source
    assert "TRACKS_REF_STORE_ID" in source
    assert "TRACKS_TARGET_STORE_ID" in source
    assert "tracks parity (run via HARNESS.md recipe)" not in source


def test_tracks_capture_marker_preserves_existing_sink_events() -> None:
    with tempfile.TemporaryDirectory(prefix="tracks-marker-") as tmp_name:
        tmp = Path(tmp_name)
        sink = tmp / "tracks-events.ndjson"
        marker = tmp / "capture-marker.json"
        fake_wpcom_local = tmp / "wpcom-local"
        sink.write_text(
            '{"event_name":"before","properties":{"store_id":"store-a"}}\n',
            encoding="utf-8",
        )
        write_executable(
            fake_wpcom_local,
            f"#!/usr/bin/env bash\nprintf '%s\\n' '- sink_path: {sink}'\n",
        )
        env = {"WLOCAL": str(fake_wpcom_local), "PATH": "/bin:/usr/bin:/usr/local/bin"}

        marked = subprocess.run(
            ["bash", str(TRACKS_PARITY), "mark", str(marker)],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert marked.returncode == 0, marked.stdout + marked.stderr

        with sink.open("a", encoding="utf-8") as stream:
            stream.write(
                '{"event_name":"after","properties":{"store_id":"store-a"}}\n'
            )

        normalized = subprocess.run(
            [
                "bash",
                str(TRACKS_PARITY),
                "normalize",
                "--mark",
                str(marker),
                "--store",
                "store-a",
            ],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert normalized.returncode == 0, normalized.stdout + normalized.stderr
        assert "after | store_id=str:store-a" in normalized.stdout
        assert "before |" not in normalized.stdout
        assert sink.read_text(encoding="utf-8").count("\n") == 2


def test_tracks_capture_marker_normalizes_without_optional_filters() -> None:
    with tempfile.TemporaryDirectory(prefix="tracks-marker-no-filters-") as tmp_name:
        tmp = Path(tmp_name)
        sink = tmp / "tracks-events.ndjson"
        marker = tmp / "capture-marker.json"
        fake_wpcom_local = tmp / "wpcom-local"
        sink.write_text("", encoding="utf-8")
        write_executable(
            fake_wpcom_local,
            f"#!/usr/bin/env bash\nprintf '%s\\n' '- sink_path: {sink}'\n",
        )
        env = {"WLOCAL": str(fake_wpcom_local), "PATH": "/bin:/usr/bin:/usr/local/bin"}

        marked = subprocess.run(
            ["bash", str(TRACKS_PARITY), "mark", str(marker)],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert marked.returncode == 0, marked.stdout + marked.stderr

        sink.write_text(
            '{"event_name":"after","properties":{"store_id":"store-a"}}\n',
            encoding="utf-8",
        )
        normalized = subprocess.run(
            ["bash", str(TRACKS_PARITY), "normalize", "--mark", str(marker)],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert normalized.returncode == 0, normalized.stdout + normalized.stderr
        assert "after | store_id=str:store-a" in normalized.stdout


def test_cleanup_exit_70_stops_before_deterministic_store_flows() -> None:
    with tempfile.TemporaryDirectory(prefix="tracks-cleanup-stop-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "tracks-normalize-count"
        fake_home = tmp / "wpcom-local-home"
        (fake_home / "secrets").mkdir(parents=True)
        (fake_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"test-token"}\n', encoding="utf-8"
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                **os.environ,
                "I18N_GATE_EXIT_CODE": "70",
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_home),
                "PATH": str(tmp) + ":/bin:/usr/bin:/usr/local/bin",
                "TMPDIR": str(tmp),
            },
        )

        assert result.returncode == 70, result.stdout + result.stderr
        invocation_log = invocations.read_text(encoding="utf-8")
        assert "i18n-notes-gate.sh|" in invocation_log
        assert "flow-drive.sh|" not in invocation_log
        assert "tracks-parity.sh|normalize" not in invocation_log


def test_tracks_capture_marker_refuses_replaced_sink() -> None:
    with tempfile.TemporaryDirectory(prefix="tracks-marker-rotation-") as tmp_name:
        tmp = Path(tmp_name)
        sink = tmp / "tracks-events.ndjson"
        marker = tmp / "capture-marker.json"
        fake_wpcom_local = tmp / "wpcom-local"
        sink.write_text("{}\n", encoding="utf-8")
        write_executable(
            fake_wpcom_local,
            f"#!/usr/bin/env bash\nprintf '%s\\n' '- sink_path: {sink}'\n",
        )
        env = {"WLOCAL": str(fake_wpcom_local), "PATH": "/bin:/usr/bin:/usr/local/bin"}
        marked = subprocess.run(
            ["bash", str(TRACKS_PARITY), "mark", str(marker)],
            cwd=REPO,
            env=env,
            check=False,
        )
        assert marked.returncode == 0

        replacement = tmp / "replacement.ndjson"
        replacement.write_text(
            '{"event_name":"unowned","properties":{"store_id":"store-a"}}\n',
            encoding="utf-8",
        )
        os.replace(replacement, sink)

        normalized = subprocess.run(
            ["bash", str(TRACKS_PARITY), "normalize", "--mark", str(marker)],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert normalized.returncode != 0
        assert "changed since the capture marker" in normalized.stderr


def test_with_tracks_stages_usage_tracking_and_restores_it() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-tracks-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert "reference usage tracking already enabled" in result.stdout
        assert "staged target usage tracking" in result.stdout

        invocation_log = invocations.read_text(encoding="utf-8")
        assert "wp-reference|wpcom-local tracks enable" in invocation_log
        assert "wp-target|wpcom-local tracks enable" in invocation_log
        assert f"wp-target|eval-file - yes" in invocation_log
        assert f"wp-target|eval-file - no" in invocation_log
        assert "tracks-parity.sh|diff" in invocation_log


def test_with_tracks_blocks_empty_target_capture_without_diff() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-tracks-empty-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TRACKS_EMPTY_TARGET": "1",
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "target Tracks normalization produced no events" in result.stdout
        assert "Tracks contract drift" not in result.stdout
        assert "tracks-parity.sh|diff" not in invocations.read_text(encoding="utf-8")


def test_with_tracks_cleans_flow_owned_orders_when_a_later_gate_blocks() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-tracks-cleanup-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TRACKS_EMPTY_TARGET": "1",
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        invocation_log = invocations.read_text(encoding="utf-8")
        assert re.search(
            r"owned-order-cleanup\|reference\|wcpay-verify-[a-f0-9]{32} 123",
            invocation_log,
        )
        assert re.search(
            r"owned-order-cleanup\|target\|wcpay-verify-[a-f0-9]{32} 456",
            invocation_log,
        )


def test_with_tracks_recovers_by_run_token_when_flow_emits_no_id() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-tracks-token-cleanup-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "FLOW_DRIVE_FAIL_WITHOUT_ID": "1",
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        invocation_log = invocations.read_text(encoding="utf-8")
        assert re.search(
            r"owned-order-cleanup\|reference\|wcpay-verify-[a-f0-9]{32}(?:\n|$)",
            invocation_log,
        )
        assert re.search(
            r"owned-order-cleanup\|target\|wcpay-verify-[a-f0-9]{32}(?:\n|$)",
            invocation_log,
        )


def test_missing_cleanup_helper_blocks_before_flow_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-missing-cleanup-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, _ = prepare_fake_verify_repo(tmp)
        (verify_copy.parent / "verify-owned-order-cleanup.php").unlink()
        invocations = tmp / "invocations.log"
        fake_tmp = tmp / "tmp"
        fake_tmp.mkdir()
        args, process_env = adapt_wp_runner_arguments(
            ["--ref", str(ref_wp), "--target", str(target_wp)],
            {
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        result = subprocess.run(
            ["bash", str(verify_copy), *args],
            cwd=verify_copy.parents[2],
            env=process_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2
        assert "cleanup helper is missing" in result.stderr
        assert not invocations.exists() or "flow-drive.sh" not in invocations.read_text(encoding="utf-8")


def test_cleanup_timeout_still_attempts_the_other_store() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-cleanup-timeout-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "CLEANUP_HANG_ROLE": "reference",
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TMPDIR": str(fake_tmp),
                "VERIFY_CLEANUP_TIMEOUT_SECONDS": "0.2",
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 70, result.stdout + result.stderr
        assert "TIMEOUT: command exceeded 0.2s" in result.stderr
        invocation_log = invocations.read_text(encoding="utf-8")
        assert "owned-order-cleanup|reference|" in invocation_log
        assert "owned-order-cleanup|target|" in invocation_log


def test_cleanup_failure_reports_result_and_still_attempts_the_other_store() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-cleanup-failure-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "CLEANUP_FAIL_ROLE": "reference",
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 70, result.stdout + result.stderr
        assert '"status":"delete_failed"' in result.stderr
        invocation_log = invocations.read_text(encoding="utf-8")
        assert "owned-order-cleanup|reference|" in invocation_log
        assert "owned-order-cleanup|target|" in invocation_log


def test_with_tracks_restores_existing_empty_helper_options() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-tracks-empty-helper-") as tmp_name:
        tmp = Path(tmp_name)
        verify_copy, ref_wp, target_wp, fake_wpcom_local = prepare_fake_verify_repo(tmp)
        invocations = tmp / "invocations.log"
        normalize_count = tmp / "normalize-count"
        fake_tmp = tmp / "tmp"
        fake_wpcom_home = tmp / "wpcom-local-home"
        fake_tmp.mkdir()
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"data":{"sink_token":"wpcom_local_tracks_test"}}\n',
            encoding="utf-8",
        )

        result = run_fake_verify(
            verify_copy,
            ref_wp,
            target_wp,
            {
                "INVOCATIONS_LOG": str(invocations),
                "TRACKS_NORMALIZE_COUNT_FILE": str(normalize_count),
                "TRACKS_REFERENCE_EMPTY_HELPER_ENDPOINT": "1",
                "TMPDIR": str(fake_tmp),
                "WLOCAL": str(fake_wpcom_local),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": "/bin:/usr/bin:/usr/local/bin",
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert (
            "RESTORE_HELPER_ENDPOINT_ARG:reference:__EMPTY_BASE64__"
            in invocations.read_text(encoding="utf-8")
        )


def main() -> None:
    tests = [
        test_tracks_flags_are_documented_without_placeholder_language,
        test_with_tracks_uses_sink_parity_commands,
        test_with_tracks_stages_usage_tracking_and_restores_it,
        test_with_tracks_blocks_empty_target_capture_without_diff,
        test_with_tracks_cleans_flow_owned_orders_when_a_later_gate_blocks,
        test_with_tracks_recovers_by_run_token_when_flow_emits_no_id,
        test_missing_cleanup_helper_blocks_before_flow_mutation,
        test_cleanup_timeout_still_attempts_the_other_store,
        test_cleanup_failure_reports_result_and_still_attempts_the_other_store,
        test_with_tracks_restores_existing_empty_helper_options,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
