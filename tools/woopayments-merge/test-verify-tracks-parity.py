#!/usr/bin/env python3
"""Regression checks for verify.sh Tracks parity orchestration."""

from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
LOCAL_RUNNER_SAFETY = REPO / "tools/woopayments-merge/local-runner-safety.sh"


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

    fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
if [ "$name" = "flow-drive.sh" ]; then
	printf '{"order_id":123}\n'
fi
if [ "$name" = "tracks-parity.sh" ]; then
	case "${1:-}" in
		reset)
			printf 'tracks sink cleared\n'
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
	if printf '%s' "$body" | grep -q "WCPAY_RUNTIME_IDENTITY"; then
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

    assert "tracks-parity.sh\" reset" in source
    assert "tracks-parity.sh\" normalize" in source
    assert "tracks-parity.sh\" diff" in source
    assert "TRACKS_REF_STORE_ID" in source
    assert "TRACKS_TARGET_STORE_ID" in source
    assert "tracks parity (run via HARNESS.md recipe)" not in source


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
        test_with_tracks_restores_existing_empty_helper_options,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
