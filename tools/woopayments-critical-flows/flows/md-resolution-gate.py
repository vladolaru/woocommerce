#!/usr/bin/env python3
"""Drive one non-replayable MD-03/MD-04 dispute-resolution mutation."""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import shlex
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Callable


HERE = Path(__file__).resolve().parent
EVIDENCE_TOOL = HERE / "md-resolution-evidence.py"
MAX_STORE_ADAPTER_BYTES = 256 * 1024


class GateError(RuntimeError):
    """Raised when the runtime gate cannot establish trustworthy evidence."""


def _load_evidence():
    spec = importlib.util.spec_from_file_location("md_resolution_evidence_runtime", EVIDENCE_TOOL)
    if spec is None or spec.loader is None:
        raise GateError("resolution evidence core is unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def parse_wp_command(command: str) -> list[str]:
    """Parse a local WP runner without accepting shell evaluation syntax."""
    if not isinstance(command, str) or not command.strip():
        raise GateError("WP command is empty")
    if re.search(r"[;&|`$<>\r\n]", command):
        raise GateError("WP command contains shell control syntax")
    try:
        argv = shlex.split(command)
    except ValueError as error:
        raise GateError("WP command cannot be parsed") from error
    if not argv or Path(argv[0]).name not in {"docker", "pnpm", "npm", "npx"}:
        raise GateError("WP command is not an approved local runner")
    if not any(Path(token).name == "wp" for token in argv):
        raise GateError("WP command does not invoke wp")
    return argv


def _default_execute(
    argv: list[str],
    *,
    env: dict[str, str] | None = None,
    input_text: str | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        argv,
        env=env,
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=180,
    )


def _json_output(result: subprocess.CompletedProcess[str], label: str) -> dict[str, Any]:
    if result.returncode != 0:
        detail = result.stderr.strip().splitlines()[-1:] or result.stdout.strip().splitlines()[-1:]
        raise GateError(f"{label} failed{': ' + detail[0] if detail else ''}")
    raw = result.stdout.strip()
    candidates = [raw]
    if "{" in raw and "}" in raw:
        candidates.append(raw[raw.find("{") : raw.rfind("}") + 1])
    candidates.extend(reversed(raw.splitlines()))
    for candidate in candidates:
        try:
            payload = json.loads(candidate)
        except json.JSONDecodeError:
            continue
        if isinstance(payload, dict):
            return payload
    raise GateError(f"{label} emitted no JSON object")


def _write_json_exclusive(path: Path, payload: dict[str, Any]) -> None:
    with path.open("x", encoding="utf-8") as handle:
        json.dump(payload, handle, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())


def _append_journal(path: Path, record: dict[str, Any]) -> None:
    mode = "x" if not path.exists() else "a"
    with path.open(mode, encoding="utf-8") as handle:
        json.dump(record, handle, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())


def _normalize_drive(payload: dict[str, Any]) -> dict[str, Any]:
    fields = ("op", "order_id", "charge_id", "intent_id", "status", "order_currency")
    normalized = {field: payload.get(field) for field in fields}
    if (
        normalized["op"] != "dispute"
        or not isinstance(normalized["order_id"], int)
        or isinstance(normalized["order_id"], bool)
        or normalized["order_id"] <= 0
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(normalized["charge_id"])) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(normalized["intent_id"])) is None
        or not isinstance(normalized["status"], str)
        or not isinstance(normalized["order_currency"], str)
    ):
        raise GateError("deterministic flow output identity is invalid")
    return normalized


def _provider_id(value: Any) -> str:
    if isinstance(value, str):
        return value
    if isinstance(value, dict) and isinstance(value.get("id"), str):
        return value["id"]
    return ""


def _normalize_provider(
    raw: dict[str, Any],
    execute: Callable[..., subprocess.CompletedProcess[str]],
    stripe_argv: list[str],
    account_id: str,
) -> dict[str, Any]:
    dispute_id = str(raw.get("id", ""))
    charge_id = _provider_id(raw.get("charge"))
    intent_id = _provider_id(raw.get("payment_intent"))
    if not intent_id and isinstance(raw.get("charge"), dict):
        intent_id = _provider_id(raw["charge"].get("payment_intent"))
    transactions: list[dict[str, int | str]] = []
    raw_transactions = raw.get("balance_transactions")
    if not isinstance(raw_transactions, list):
        raise GateError("provider dispute has no balance transaction list")
    for item in raw_transactions:
        transaction = item
        if isinstance(item, str):
            transaction = _json_output(
                execute(
                    [
                        *stripe_argv,
                        "balance_transactions",
                        "retrieve",
                        item,
                        "--stripe-account",
                        account_id,
                        "--color",
                        "off",
                    ]
                ),
                "provider balance transaction retrieval",
            )
        if not isinstance(transaction, dict):
            raise GateError("provider balance transaction is invalid")
        transactions.append(
            {
                "id": transaction.get("id"),
                "amount": transaction.get("amount"),
                "fee": transaction.get("fee"),
                "net": transaction.get("net"),
            }
        )
    normalized = {
        "available": True,
        "livemode": raw.get("livemode"),
        "dispute_id": dispute_id,
        "dispute_status": raw.get("status"),
        "charge_id": charge_id,
        "intent_id": intent_id,
        "amount": raw.get("amount"),
        "currency": raw.get("currency"),
        "balance_transactions": transactions,
    }
    if normalized["livemode"] is not False:
        raise GateError("provider dispute is not test mode")
    return normalized


def _retrieve_provider(
    dispute_id: str,
    execute: Callable[..., subprocess.CompletedProcess[str]],
    stripe_argv: list[str],
    account_id: str,
) -> dict[str, Any]:
    raw = _json_output(
        execute(
            [
                *stripe_argv,
                "disputes",
                "retrieve",
                dispute_id,
                "--expand",
                "charge",
                "--expand",
                "balance_transactions",
                "--stripe-account",
                account_id,
                "--color",
                "off",
            ]
        ),
        "provider dispute retrieval",
    )
    return _normalize_provider(raw, execute, stripe_argv, account_id)


def _store_command(config: dict[str, Any], action: str, *arguments: str) -> list[str]:
    return [
        *config["wp_argv"],
        "--user=1",
        "eval-file",
        "-",
        action,
        *arguments,
    ]


def _load_store_adapter(path_value: Any) -> str:
    try:
        path = Path(path_value)
    except TypeError as error:
        raise GateError("store adapter is unavailable") from error
    if path.is_symlink() or not path.is_file():
        raise GateError("store adapter is unavailable")
    size = path.stat().st_size
    if size <= 0 or size > MAX_STORE_ADAPTER_BYTES:
        raise GateError("store adapter size is invalid")
    try:
        source = path.read_text(encoding="utf-8")
    except UnicodeDecodeError as error:
        raise GateError("store adapter is not UTF-8") from error
    if not source.startswith("<?php") or "WooPayments_Critical_Flows_MD_Resolution_Store" not in source:
        raise GateError("store adapter source is invalid")
    return source


def _execute_store(
    config: dict[str, Any],
    action: str,
    *arguments: str,
    execute: Callable[..., subprocess.CompletedProcess[str]],
) -> subprocess.CompletedProcess[str]:
    return execute(
        _store_command(config, action, *arguments),
        input_text=config["store_adapter_source"],
    )


def _probe_store(
    config: dict[str, Any],
    identity: dict[str, Any],
    execute: Callable[..., subprocess.CompletedProcess[str]],
) -> dict[str, Any]:
    return _json_output(
        _execute_store(
            config,
            "probe",
            identity["dispute_id"],
            str(identity["order_id"]),
            identity["charge_id"],
            identity["intent_id"],
            config["outcome"],
            execute=execute,
        ),
        "store resolution probe",
    )


def _connected_account(
    config: dict[str, Any],
    execute: Callable[..., subprocess.CompletedProcess[str]],
) -> str:
    payload = _json_output(
        _execute_store(
            config,
            "account",
            config["runtime_owner"],
            execute=execute,
        ),
        "store connected account probe",
    )
    if set(payload) != {"account_id", "site_locale"}:
        raise GateError("store connected account probe contains unexpected fields")
    site_locale = payload.get("site_locale")
    if not isinstance(site_locale, str) or re.fullmatch(r"en(?:_[A-Z]{2})?", site_locale) is None:
        raise GateError("an English site locale is required for lifecycle-note evidence")
    account_id = payload.get("account_id")
    if not isinstance(account_id, str) or re.fullmatch(r"acct_[A-Za-z0-9]{8,}", account_id) is None:
        raise GateError("store connected account is invalid")
    return account_id


def _assert_identity(
    identity: dict[str, Any], store_facts: dict[str, Any], provider_facts: dict[str, Any]
) -> None:
    if (
        identity["order_id"] != store_facts.get("order_id")
        or identity["charge_id"] != store_facts.get("charge_id")
        or identity["charge_id"] != provider_facts.get("charge_id")
        or identity["intent_id"] != store_facts.get("intent_id")
        or identity["intent_id"] != provider_facts.get("intent_id")
        or identity["dispute_id"] != store_facts.get("dispute_id")
        or identity["dispute_id"] != provider_facts.get("dispute_id")
    ):
        raise GateError("provider/store facts do not bind the fresh fixture identity")


def run_gate(
    config: dict[str, Any],
    *,
    execute: Callable[..., subprocess.CompletedProcess[str]] = _default_execute,
    sleep: Callable[[float], None] = time.sleep,
) -> int:
    """Drive exactly one fresh fixture and one evidence submission."""
    config = dict(config)
    evidence = _load_evidence()
    profile = evidence.outcome_profile(config["outcome"])
    store = config["store"]
    if store not in {"ref", "target"}:
        raise GateError("store role is invalid")
    if config.get("runtime_owner") != ("plugin" if store == "ref" else "native"):
        raise GateError("runtime owner binding is invalid")
    if not isinstance(config.get("wp_argv"), list) or not config["wp_argv"]:
        raise GateError("WP argv is unavailable")
    if not isinstance(config.get("stripe_argv"), list) or not config["stripe_argv"]:
        raise GateError("Stripe argv is unavailable")
    if any(token == "--live" or token.startswith("--live=") for token in config["stripe_argv"]):
        raise GateError("Stripe live mode is forbidden")
    if os.environ.get("STRIPE_API_KEY"):
        raise GateError("ambient Stripe API key configuration is forbidden")
    poll_tries = config.get("poll_tries")
    poll_delay = config.get("poll_delay")
    if (
        not isinstance(poll_tries, int)
        or isinstance(poll_tries, bool)
        or not 1 <= poll_tries <= 300
        or not isinstance(poll_delay, (int, float))
        or isinstance(poll_delay, bool)
        or not 0 <= poll_delay <= 30
    ):
        raise GateError("polling bounds are invalid")
    config["store_adapter_source"] = _load_store_adapter(config.get("store_adapter"))

    out_dir = Path(config["out_dir"])
    if out_dir.exists():
        raise GateError("evidence directory already exists; refusing to overwrite or replay")

    account_id = _connected_account(config, execute)

    _json_output(
        execute(
            [
                *config["stripe_argv"],
                "disputes",
                "list",
                "--limit",
                "1",
                "--stripe-account",
                account_id,
                "--color",
                "off",
            ]
        ),
        "provider preflight",
    )

    out_dir.mkdir()
    journal_path = out_dir / "mutation-journal.jsonl"
    first_record = evidence.build_journal_record(
        config["outcome"], store, config["run_stamp"], 1, "fresh_dispute_create_armed", {}
    )
    _append_journal(journal_path, first_record)

    drive_args = ["bash", str(config["flow_drive"]), "dispute", "--deterministic"]
    if store == "target":
        drive_args.append("--native")
    drive_env = {**os.environ, "WP": shlex.join(config["wp_argv"])}
    drive = _normalize_drive(_json_output(execute(drive_args, env=drive_env), "fresh dispute drive"))

    dispute_list = _json_output(
        execute(
            [
                *config["stripe_argv"],
                "disputes",
                "list",
                "--charge",
                drive["charge_id"],
                "--limit",
                "2",
                "--stripe-account",
                account_id,
                "--color",
                "off",
            ]
        ),
        "provider dispute discovery",
    )
    disputes = dispute_list.get("data")
    if (
        not isinstance(disputes, list)
        or len(disputes) != 1
        or dispute_list.get("has_more") is not False
    ):
        raise GateError("fresh charge does not bind exactly one provider dispute")
    dispute_id = str(disputes[0].get("id", "")) if isinstance(disputes[0], dict) else ""
    if re.fullmatch(r"(?:dp|du)_[A-Za-z0-9_]+", dispute_id) is None:
        raise GateError("provider dispute identity is invalid")
    identity = {
        "order_id": drive["order_id"],
        "charge_id": drive["charge_id"],
        "intent_id": drive["intent_id"],
        "dispute_id": dispute_id,
    }
    provider_facts = _retrieve_provider(
        dispute_id, execute, config["stripe_argv"], account_id
    )
    store_facts = _probe_store(config, identity, execute)
    _assert_identity(identity, store_facts, provider_facts)

    _append_journal(
        journal_path,
        evidence.build_journal_record(
            config["outcome"], store, config["run_stamp"], 2, "fresh_dispute_created", identity
        ),
    )
    _append_journal(
        journal_path,
        evidence.build_journal_record(
            config["outcome"],
            store,
            config["run_stamp"],
            3,
            "evidence_submit_armed",
            identity,
            marker=profile["evidence_marker"],
        ),
    )

    try:
        submission = _json_output(
            _execute_store(
                config,
                "submit",
                dispute_id,
                config["outcome"],
                profile["evidence_marker"],
                execute=execute,
            ),
            "evidence submission",
        )
    except (GateError, OSError, subprocess.TimeoutExpired):
        submission = {
            "trusted": False,
            "response_class": "unknown",
            "submit": True,
            "marker": profile["evidence_marker"],
            "http_status": 0,
            "dispute_id": dispute_id,
        }
    _append_journal(
        journal_path,
        evidence.build_journal_record(
            config["outcome"],
            store,
            config["run_stamp"],
            4,
            "evidence_submit_observed",
            identity,
            marker=profile["evidence_marker"],
            response_class=str(submission.get("response_class", "")),
        ),
    )

    blockers: list[str] = []
    if submission.get("response_class") == "trusted_success":
        terminal_seen = False
        for attempt in range(poll_tries):
            observation_blockers: list[str] = []
            try:
                provider_facts = _retrieve_provider(
                    dispute_id, execute, config["stripe_argv"], account_id
                )
            except (GateError, OSError, subprocess.TimeoutExpired):
                observation_blockers.append("provider_observation_unavailable")
            try:
                store_facts = _probe_store(config, identity, execute)
            except (GateError, OSError, subprocess.TimeoutExpired):
                observation_blockers.append("store_observation_unavailable")
            if observation_blockers:
                if attempt + 1 < poll_tries:
                    # Store observations cross the WPCOM API boundary and can be
                    # transiently rate-limited. Retry only these read-only probes;
                    # the evidence submission above remains single-attempt.
                    sleep(max(float(poll_delay), 5.0))
                    continue
                blockers.extend(observation_blockers)
                break
            _assert_identity(identity, store_facts, provider_facts)
            assertions = evidence.derive_outcome_assertions(
                config["outcome"], submission, store_facts, provider_facts
            )
            terminal_seen = (
                provider_facts.get("dispute_status") == profile["terminal_status"]
                and store_facts.get("dispute_status") == profile["terminal_status"]
            )
            if all(assertions.values()):
                break
            if attempt + 1 < poll_tries:
                sleep(float(poll_delay))
        else:
            if not terminal_seen:
                blockers.append("terminal_delivery_timeout")

    records = [json.loads(line) for line in journal_path.read_text(encoding="utf-8").splitlines()]
    packet = evidence.build_store_packet(
        outcome=config["outcome"],
        store=store,
        run_stamp=config["run_stamp"],
        runtime_owner=config["runtime_owner"],
        drive=drive,
        journal=records,
        submission=submission,
        store_facts=store_facts,
        provider_facts=provider_facts,
        blockers=blockers,
    )
    _write_json_exclusive(out_dir / f"{store}-store-packet.json", packet)
    return {"pass": 0, "fail": 1, "blocked": 3}[packet["status"]]


def _parse_executable_command(command: str, label: str) -> list[str]:
    if not command or re.search(r"[;&|`$<>\r\n]", command):
        raise GateError(f"{label} command contains shell control syntax")
    try:
        argv = shlex.split(command)
    except ValueError as error:
        raise GateError(f"{label} command cannot be parsed") from error
    if not argv:
        raise GateError(f"{label} command is empty")
    if label == "Stripe":
        if Path(argv[0]).name != "stripe":
            raise GateError("Stripe command must invoke the Stripe CLI directly")
        if any(token == "--live" or token.startswith("--live=") for token in argv):
            raise GateError("Stripe live mode is forbidden")
        if any(token == "--api-key" or token.startswith("--api-key=") for token in argv):
            raise GateError("Stripe API keys must not be supplied in argv")
    return argv


def parser() -> argparse.ArgumentParser:
    """Build the local runtime gate CLI."""
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--outcome", required=True, choices=("won", "lost"))
    result.add_argument("--store", required=True, choices=("ref", "target"))
    result.add_argument("--runtime-owner", required=True, choices=("plugin", "native"))
    result.add_argument("--run-stamp", required=True)
    result.add_argument("--out-dir", required=True)
    result.add_argument("--wp-command", required=True)
    result.add_argument("--stripe-command", default="stripe")
    result.add_argument("--flow-drive", required=True)
    result.add_argument("--store-adapter", required=True)
    result.add_argument("--poll-tries", type=int, default=120)
    result.add_argument("--poll-delay", type=float, default=1)
    return result


def main() -> int:
    """Parse local commands, validate dependencies, and run one gate."""
    args = parser().parse_args()
    try:
        wp_argv = parse_wp_command(args.wp_command)
        stripe_argv = _parse_executable_command(args.stripe_command, "Stripe")
        flow_drive = Path(args.flow_drive)
        store_adapter = Path(args.store_adapter)
        if flow_drive.is_symlink() or not flow_drive.is_file():
            raise GateError("deterministic flow driver is unavailable")
        if store_adapter.is_symlink() or not store_adapter.is_file():
            raise GateError("store adapter is unavailable")
        return run_gate(
            {
                "outcome": args.outcome,
                "store": args.store,
                "runtime_owner": args.runtime_owner,
                "run_stamp": args.run_stamp,
                "out_dir": Path(args.out_dir),
                "wp_argv": wp_argv,
                "stripe_argv": stripe_argv,
                "flow_drive": flow_drive,
                "store_adapter": store_adapter,
                "poll_tries": args.poll_tries,
                "poll_delay": args.poll_delay,
            }
        )
    except (GateError, OSError, subprocess.TimeoutExpired, ValueError) as error:
        print(f"BLOCKED: {error}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
