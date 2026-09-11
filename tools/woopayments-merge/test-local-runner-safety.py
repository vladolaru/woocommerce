#!/usr/bin/env python3
"""Regression checks for shared local Docker WP runner validation."""

from __future__ import annotations

import importlib.util
import os
import subprocess
from pathlib import Path

import pytest

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/local_runner_safety.py"
SHELL_LIBRARY_PATH = REPO / "tools/woopayments-merge/local-runner-safety.sh"


@pytest.fixture(scope="module")
def module():
    spec = importlib.util.spec_from_file_location("woopayments_local_runner_safety", MODULE_PATH)
    assert spec and spec.loader
    loaded = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(loaded)
    return loaded


def run_shell_validator(
    function_name: str,
    command: str,
    role: str = "",
    *,
    env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            "bash",
            "-c",
            'source "$1"; "$2" "$3" "$4"',
            "bash",
            str(SHELL_LIBRARY_PATH),
            function_name,
            command,
            role,
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def test_test_transport_adapts_delegates_to_exact_approved_docker_runners(tmp_path: Path) -> None:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    write_executable(ref_wp, "#!/usr/bin/env bash\nprintf 'ref:%s\\n' \"$*\"\n")
    write_executable(target_wp, "#!/usr/bin/env bash\nprintf 'target:%s\\n' \"$*\"\n")

    args, env = adapt_wp_runner_arguments(
        ["--ref", str(ref_wp), "--target", str(target_wp)],
        os.environ.copy(),
    )

    ref_runner = args[args.index("--ref") + 1]
    target_runner = args[args.index("--target") + 1]
    assert " exec -i woopayments-test-reference-wp wp" in ref_runner
    assert " exec -i woopayments-test-target-cli-1 wp" in target_runner
    assert env["WOOPAYMENTS_APPROVED_REF_CONTAINER"] == "woopayments-test-reference-wp"
    assert env["WOOPAYMENTS_APPROVED_TARGET_CONTAINER"] == "woopayments-test-target-cli-1"

    target_result = subprocess.run(
        [*target_runner.split(), "option", "get", "home"],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        check=False,
    )
    assert target_result.returncode == 0, target_result.stderr
    assert target_result.stdout == "target:option get home\n"


def test_test_transport_keeps_unsafe_runner_visible_to_the_gate(tmp_path: Path) -> None:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    write_executable(ref_wp, "#!/usr/bin/env bash\nexit 0\n")
    write_executable(target_wp, "#!/usr/bin/env bash\nexit 0\n")
    unsafe_target = f"{target_wp} --ssh=merchant@example.test"

    args, _env = adapt_wp_runner_arguments(
        ["--ref", str(ref_wp), "--target", unsafe_target],
        os.environ.copy(),
    )

    assert not args[args.index("--ref") + 1].startswith("docker ")
    assert " exec -i woopayments-test-reference-wp wp" in args[args.index("--ref") + 1]
    assert args[args.index("--target") + 1] == unsafe_target


def test_reference_defaults_to_the_primary_local_container(module, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("WOOPAYMENTS_APPROVED_REF_CONTAINER", raising=False)

    assert module.validate_local_wp_command(
        "ref-wp",
        "docker exec -i wcpay_wp_default wp --allow-root --user=1",
    )[3] == "wcpay_wp_default"

    with pytest.raises(module.LocalRunnerError, match="wcpay_wp_default"):
        module.validate_local_wp_command(
            "ref-wp",
            "docker exec -i wcpay_wp_codex_oracle_10_8 wp --allow-root --user=1",
        )


def test_orchestrator_can_authorize_one_exact_reference_container(module, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_REF_CONTAINER", "wcpay_wp_codex_oracle_10_8")

    assert module.validate_local_wp_command(
        "ref-wp",
        "docker exec -i wcpay_wp_codex_oracle_10_8 wp --allow-root --user=1",
    )[3] == "wcpay_wp_codex_oracle_10_8"

    with pytest.raises(module.LocalRunnerError, match="wcpay_wp_codex_oracle_10_8"):
        module.validate_local_wp_command(
            "ref-wp",
            "docker exec -i wcpay_wp_other_oracle wp --allow-root --user=1",
        )


def test_orchestrator_can_authorize_one_exact_target_container(module, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", "target-cli-custom")

    assert module.validate_local_wp_command(
        "target-wp",
        "docker exec -i target-cli-custom wp --allow-root --user=1",
    )[3] == "target-cli-custom"

    with pytest.raises(module.LocalRunnerError, match="target-cli-custom"):
        module.validate_local_wp_command(
            "target-wp",
            "docker exec -i sibling-cli-1 wp --allow-root --user=1",
        )


def test_target_requires_an_explicit_exact_container_approval(module, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", raising=False)

    with pytest.raises(module.LocalRunnerError, match="approved target container"):
        module.validate_local_wp_command(
            "target-wp",
            "docker exec -i arbitrary-cli-1 wp --allow-root --user=1",
        )


def test_python_validator_rejects_remote_docker_host(module, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", "target-cli-1")
    monkeypatch.setenv("DOCKER_HOST", "ssh://remote.example.test")

    with pytest.raises(module.LocalRunnerError, match="DOCKER_HOST"):
        module.validate_local_wp_command(
            "target-wp",
            "docker exec -i target-cli-1 wp --allow-root --user=1",
        )


def test_python_validator_rejects_remote_effective_context(
    module,
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
) -> None:
    fake_bin = tmp_path / "bin"
    fake_docker = fake_bin / "docker"
    write_executable(
        fake_docker,
        """#!/usr/bin/env bash
set -eu
if [ "$1" = "context" ] && [ "$2" = "show" ]; then
    printf 'remote-ci\n'
    exit 0
fi
if [ "$1" = "context" ] && [ "$2" = "inspect" ]; then
    printf '[{"Endpoints":{"docker":{"Host":"tcp://remote.example.test:2376"}}}]\n'
    exit 0
fi
exit 2
""",
    )
    monkeypatch.setenv("PATH", f"{fake_bin}{os.pathsep}{os.environ['PATH']}")
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", "target-cli-1")
    monkeypatch.delenv("DOCKER_HOST", raising=False)
    monkeypatch.delenv("DOCKER_CONTEXT", raising=False)

    with pytest.raises(module.LocalRunnerError, match="Unix socket"):
        module.validate_local_wp_command(
            "target-wp",
            "docker exec -i target-cli-1 wp --allow-root --user=1",
        )


def test_shell_syntax_boundary_rejects_bare_wp_runner() -> None:
    result = run_shell_validator(
        "woopayments_validate_local_wp_runner",
        "wp --allow-root --user=1",
    )

    assert result.returncode != 0
    assert "Docker" in result.stdout or "Docker" in result.stderr


def test_shell_target_approval_rejects_implicit_cli_container() -> None:
    env = {
        **os.environ,
        "WOOPAYMENTS_APPROVED_REF_CONTAINER": "wcpay_wp_default",
    }
    env.pop("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", None)

    result = run_shell_validator(
        "woopayments_validate_approved_docker_runner",
        "docker exec -i arbitrary-cli-1 wp --allow-root --user=1",
        "target",
        env=env,
    )

    assert result.returncode != 0
    assert "approved target container" in result.stdout


def test_shell_target_approval_accepts_exported_exact_container() -> None:
    env = {
        **os.environ,
        "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "verified-target-cli-1",
    }

    result = run_shell_validator(
        "woopayments_validate_approved_docker_runner",
        "docker exec -i verified-target-cli-1 wp --allow-root --user=1",
        "target",
        env=env,
    )

    assert result.returncode == 0, result.stdout + result.stderr


@pytest.mark.parametrize(
    "command",
    (
        "docker exec -i target-cli-1 wp option get home; rm -rf .",
        "wp --http=https://example.com option get home",
        "ssh example wp option get home",
    ),
)
def test_remote_and_shell_commands_remain_rejected(module, command: str) -> None:
    with pytest.raises(module.LocalRunnerError):
        module.validate_local_wp_command("target-wp", command)
