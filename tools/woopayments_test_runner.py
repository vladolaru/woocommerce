#!/usr/bin/env python3
"""Shared Docker-shaped runner transport for WooPayments harness tests."""

from __future__ import annotations

import os
import shlex
from pathlib import Path


REF_CONTAINER = "woopayments-test-reference-wp"
TARGET_CONTAINER = "woopayments-test-target-cli-1"
REF_PROJECT = "woopayments-test-reference"
TARGET_PROJECT = "woopayments-test-target"


def _argument_value(args: list[str], flag: str) -> tuple[int, str] | None:
    for index, value in enumerate(args):
        if value == flag and index + 1 < len(args):
            return index + 1, args[index + 1]
        if value.startswith(f"{flag}="):
            return index, value.partition("=")[2]
    return None


def _replace_argument_value(args: list[str], flag: str, index: int, value: str) -> None:
    if args[index].startswith(f"{flag}="):
        args[index] = f"{flag}={value}"
    else:
        args[index] = value


def _docker_container(command: str) -> str | None:
    try:
        tokens = shlex.split(command)
    except ValueError:
        return None
    if len(tokens) < 4 or os.path.basename(tokens[0]).lower() != "docker" or tokens[1] != "exec":
        return None

    option_flags = {"-d", "--detach", "-i", "--interactive", "--privileged", "-t", "--tty"}
    options_with_values = {"--detach-keys", "-e", "--env", "--env-file", "-u", "--user", "-w", "--workdir"}
    index = 2
    while index < len(tokens) and tokens[index].startswith("-"):
        option = tokens[index]
        if option == "--":
            index += 1
            break
        if option in option_flags:
            index += 1
            continue
        if option in options_with_values:
            index += 2
            continue
        if any(option.startswith(f"{name}=") for name in options_with_values if name.startswith("--")):
            index += 1
            continue
        return None
    return tokens[index] if index < len(tokens) else None


def _delegate_path(command: str) -> Path | None:
    try:
        tokens = shlex.split(command)
    except ValueError:
        return None
    if len(tokens) != 1:
        return None
    path = Path(tokens[0])
    return path if path.is_file() and os.access(path, os.X_OK) else None


def _write_fake_docker(
    path: Path,
    delegates: dict[str, Path],
    projects: dict[str, str],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    delegate_paths = {container: str(delegate) for container, delegate in delegates.items()}
    source = f"""#!/usr/bin/env python3
import json
import os
import sys

delegates = {delegate_paths!r}
projects = {projects!r}
args = sys.argv[1:]

if args[:2] == ["context", "show"]:
    print("default")
    raise SystemExit(0)
if args[:2] == ["context", "inspect"]:
    print(json.dumps([{{"Endpoints": {{"docker": {{"Host": "unix:///woopayments-test/docker.sock"}}}}}}]))
    raise SystemExit(0)
if args[:2] == ["inspect", "--format"]:
    template = args[2] if len(args) > 2 else ""
    container = args[-1]
    if "com.docker.compose.project" in template:
        print(projects.get(container, ""))
    else:
        print("{{}}")
    raise SystemExit(0)
if not args or args[0] != "exec":
    raise SystemExit(2)

option_flags = {{"-d", "--detach", "-i", "--interactive", "--privileged", "-t", "--tty"}}
options_with_values = {{"--detach-keys", "-e", "--env", "--env-file", "-u", "--user", "-w", "--workdir"}}
index = 1
while index < len(args) and args[index].startswith("-"):
    option = args[index]
    if option == "--":
        index += 1
        break
    if option in option_flags:
        index += 1
        continue
    if option in options_with_values:
        index += 2
        continue
    if any(option.startswith(name + "=") for name in options_with_values if name.startswith("--")):
        index += 1
        continue
    raise SystemExit(2)

container = args[index]
delegate = delegates.get(container)
if delegate is None or index + 1 >= len(args) or os.path.basename(args[index + 1]).lower() not in {{"wp", "wp-cli", "wp-cli.phar", "wp.phar"}}:
    raise SystemExit(2)
os.execv(delegate, [delegate, *args[index + 2:]])
"""
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def _common_test_root(delegates: list[Path]) -> Path:
    common = Path(os.path.commonpath([str(path.parent) for path in delegates]))
    return common if common.is_dir() else delegates[0].parent


def adapt_wp_runner_arguments(
    args: list[str],
    env: dict[str, str],
    *,
    ref_flag: str = "--ref",
    target_flag: str = "--target",
) -> tuple[list[str], dict[str, str]]:
    """Adapt temporary WP delegates to the same Docker contract used in production."""

    adapted_args = list(args)
    adapted_env = dict(env)
    roles = (
        ("ref", ref_flag, REF_CONTAINER, "WOOPAYMENTS_APPROVED_REF_CONTAINER"),
        ("target", target_flag, TARGET_CONTAINER, "WOOPAYMENTS_APPROVED_TARGET_CONTAINER"),
    )
    values: dict[str, tuple[int, str]] = {}
    delegates: dict[str, Path] = {}

    for role, flag, container, _approval_env in roles:
        argument = _argument_value(adapted_args, flag)
        if argument is None:
            continue
        values[role] = argument
        delegate = _delegate_path(argument[1])
        if delegate is not None:
            delegates[container] = delegate

    fake_docker: Path | None = None
    if delegates:
        fake_docker = _common_test_root(list(delegates.values())) / ".woopayments-test-bin" / "docker"
        _write_fake_docker(
            fake_docker,
            delegates,
            {
                REF_CONTAINER: adapted_env.get("REF_COMPOSE_PROJECT", REF_PROJECT),
                TARGET_CONTAINER: adapted_env.get("TARGET_COMPOSE_PROJECT", TARGET_PROJECT),
            },
        )

    for role, flag, container, approval_env in roles:
        argument = values.get(role)
        if argument is None:
            continue
        index, command = argument
        if container in delegates and fake_docker is not None:
            command = f"{fake_docker} exec -i {container} wp"
            _replace_argument_value(adapted_args, flag, index, command)
        approved_container = _docker_container(command)
        if approved_container:
            adapted_env[approval_env] = approved_container

    adapted_env.setdefault("REF_COMPOSE_PROJECT", REF_PROJECT)
    adapted_env.setdefault("TARGET_COMPOSE_PROJECT", TARGET_PROJECT)
    return adapted_args, adapted_env
