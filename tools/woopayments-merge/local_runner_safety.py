#!/usr/bin/env python3
"""Shared local-only Docker WP-CLI runner validation for merge harnesses."""

from __future__ import annotations

import json
import os
import shlex
from subprocess import PIPE, TimeoutExpired, run as run_process
from urllib.parse import urlparse


LOCAL_ONLY_FORBIDDEN = ("wpcom.com", "wordpress.com", "a8c.com")
LOCAL_URL_HOSTS = {"localhost", "127.0.0.1", "store8889.localhost", "woopay.localhost"}
REF_CONTAINER_ENV = "WOOPAYMENTS_APPROVED_REF_CONTAINER"
TARGET_CONTAINER_ENV = "WOOPAYMENTS_APPROVED_TARGET_CONTAINER"


class LocalRunnerError(ValueError):
    """Raised when a WP runner leaves the approved local Docker boundary."""


def _role_for_label(label: str) -> str:
    normalized = label.lower().replace("_", "-")
    if normalized in {"ref", "reference", "ref-wp", "reference-wp"}:
        return "ref"
    if normalized in {"target", "target-wp"}:
        return "target"
    raise LocalRunnerError(f"unknown local WP runner role: {label}")


def _validate_local_docker_context() -> None:
    docker_host = os.environ.get("DOCKER_HOST", "")
    docker_context = os.environ.get("DOCKER_CONTEXT", "")
    if docker_host and not docker_host.startswith("unix://"):
        raise LocalRunnerError("DOCKER_HOST must use a local Unix socket")
    if docker_context not in {"", "default"}:
        raise LocalRunnerError("DOCKER_CONTEXT must be empty or default")

    try:
        context_result = run_process(
            ["docker", "context", "show"],
            text=True,
            stdout=PIPE,
            stderr=PIPE,
            timeout=10,
            check=False,
        )
    except (OSError, TimeoutExpired) as exc:
        raise LocalRunnerError("Docker effective context could not be resolved") from exc
    context = context_result.stdout.strip()
    if context_result.returncode != 0 or not context:
        raise LocalRunnerError("Docker effective context could not be resolved")

    try:
        inspect_result = run_process(
            ["docker", "context", "inspect", context],
            text=True,
            stdout=PIPE,
            stderr=PIPE,
            timeout=10,
            check=False,
        )
    except (OSError, TimeoutExpired) as exc:
        raise LocalRunnerError("Docker effective context could not be inspected") from exc
    if inspect_result.returncode != 0:
        raise LocalRunnerError("Docker effective context could not be inspected")

    try:
        endpoint = json.loads(inspect_result.stdout)[0]["Endpoints"]["docker"]["Host"]
    except (IndexError, KeyError, TypeError, json.JSONDecodeError) as exc:
        raise LocalRunnerError("Docker effective endpoint could not be resolved") from exc
    if not isinstance(endpoint, str) or not endpoint.startswith("unix://"):
        raise LocalRunnerError("Docker effective context must use a local Unix socket")


def validate_local_wp_command(label: str, value: str) -> list[str]:
    """Validate and split an exact local Docker WP-CLI command.

    The top-level verifier sets the approved-container environment variables only
    after checking each container's Compose project label. Direct callers retain
    only the established primary-reference default; target callers must export
    the exact approved target container.
    """

    lowered = value.lower()
    if any(token in lowered for token in LOCAL_ONLY_FORBIDDEN):
        raise LocalRunnerError(f"refusing non-local WPCOM/Automattic host in {label}")
    if any(token in value for token in ("\n", "\r", ";", "&", "|", "<", ">", "`", "$(")):
        raise LocalRunnerError(f"refusing shell metacharacter in {label}; pass a plain local WP-CLI command")
    try:
        parts = shlex.split(value)
    except ValueError as exc:
        raise LocalRunnerError(f"invalid {label} command: {exc}") from exc
    if len(parts) < 5 or parts[:3] != ["docker", "exec", "-i"] or parts[4] != "wp":
        raise LocalRunnerError(f"{label} must use the approved local Docker WP-CLI form")

    for part in parts:
        if part == "--ssh" or part.startswith("--ssh=") or part == "--http" or part.startswith("--http="):
            raise LocalRunnerError(f"refusing remote WP-CLI transport in {label}: {part}")
        parsed = urlparse(part)
        if parsed.scheme in {"http", "https", "ssh"} and parsed.hostname not in LOCAL_URL_HOSTS:
            raise LocalRunnerError(f"refusing non-local URL in {label}: {part}")

    _validate_local_docker_context()

    role = _role_for_label(label)
    container = parts[3]
    if role == "ref":
        expected = os.environ.get(REF_CONTAINER_ENV, "wcpay_wp_default")
        if container != expected:
            raise LocalRunnerError(f"ref-wp must target approved local reference container {expected}, got {container}")
    else:
        expected = os.environ.get(TARGET_CONTAINER_ENV, "")
        if not expected:
            raise LocalRunnerError(
                f"target-wp requires an approved target container via {TARGET_CONTAINER_ENV}"
            )
        if container != expected:
            raise LocalRunnerError(f"target-wp must target approved local target container {expected}, got {container}")

    return parts
