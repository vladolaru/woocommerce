#!/usr/bin/env python3
"""Runtime safety regressions for the MD-03/MD-04 resolution gate."""

from __future__ import annotations

import importlib.util
import json
import os
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
GATE = ROOT / "tools/woopayments-critical-flows/flows/md-resolution-gate.py"
RUN_STAMP = "20260720T020000Z-4242"
CONTEXT_KEY = "43" * 32


def load_gate():
    spec = importlib.util.spec_from_file_location("md_resolution_gate", GATE)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def store_facts(status: str = "needs_response") -> dict:
    terminal = status == "won"
    return {
        "available": True,
        "dispute_id": "dp_ref_won",
        "dispute_status": status,
        "charge_id": "ch_ref_won",
        "intent_id": "pi_ref_won",
        "order_id": 101,
        "order_status": "processing",
        "order_total_minor": 5000,
        "currency": "usd",
        "notes": {
            "created": True,
            "evidence_submitted": terminal,
            "funds_reinstated": terminal,
            "fees_deducted": False,
        },
        "refunds": [],
    }


def provider_facts(status: str = "needs_response") -> dict:
    transactions = [
        {"id": "txn_debit", "amount": -5000, "fee": 1500, "net": -6500}
    ]
    if status == "won":
        transactions.append(
            {"id": "txn_reversal", "amount": 5000, "fee": -1500, "net": 6500}
        )
    return {
        "id": "dp_ref_won",
        "object": "dispute",
        "status": status,
        "amount": 5000,
        "currency": "usd",
        "livemode": False,
        "charge": {"id": "ch_ref_won", "payment_intent": "pi_ref_won"},
        "payment_intent": "pi_ref_won",
        "balance_transactions": transactions,
    }


def config(out_dir: Path, poll_tries: int = 2) -> dict:
    return {
        "outcome": "won",
        "store": "ref",
        "runtime_owner": "plugin",
        "run_stamp": RUN_STAMP,
        "out_dir": out_dir,
        "wp_argv": ["wp"],
        "stripe_argv": ["stripe"],
        "flow_drive": ROOT / "tools/woopayments-merge/flow-drive.sh",
        "store_adapter": ROOT
        / "tools/woopayments-critical-flows/flows/class-woopayments-critical-flows-md-resolution-store.php",
        "poll_tries": poll_tries,
        "poll_delay": 0,
    }


class FakeBoundary:
    def __init__(
        self,
        out_dir: Path,
        *,
        terminal: bool = True,
        ambiguous_dispute: bool = False,
        wrong_store_identity: bool = False,
        submission_class: str = "trusted_success",
        submission_transport_failure: bool = False,
        post_submit_provider_failure: bool = False,
        provider_preflight_failure: bool = False,
        list_has_more: bool = False,
        account_id: str = "acct_1234567890ref",
        site_locale: str = "en_US",
    ) -> None:
        self.out_dir = out_dir
        self.terminal = terminal
        self.ambiguous_dispute = ambiguous_dispute
        self.wrong_store_identity = wrong_store_identity
        self.submission_class = submission_class
        self.submission_transport_failure = submission_transport_failure
        self.post_submit_provider_failure = post_submit_provider_failure
        self.provider_preflight_failure = provider_preflight_failure
        self.list_has_more = list_has_more
        self.account_id = account_id
        self.site_locale = site_locale
        self.account_calls = 0
        self.preflight_calls = 0
        self.flow_calls = 0
        self.provider_retrieves = 0
        self.store_probes = 0
        self.submit_calls = 0
        self.adapter_stdin_calls = 0

    def __call__(
        self,
        argv: list[str],
        *,
        env: dict[str, str] | None = None,
        input_text: str | None = None,
    ):
        journal = self.out_dir / "mutation-journal.jsonl"
        if "eval-file" in argv:
            self.adapter_stdin_calls += 1
            adapter_index = argv.index("eval-file")
            assert argv[adapter_index + 1] == "-"
            assert input_text is not None
            assert input_text.startswith("<?php")
            assert "WooPayments_Critical_Flows_MD_Resolution_Store" in input_text
            assert str(config(self.out_dir)["store_adapter"]) not in argv
            action = argv[argv.index("eval-file") + 2]
            if action == "account":
                self.account_calls += 1
                assert not self.out_dir.exists()
                return subprocess.CompletedProcess(
                    argv,
                    0,
                    json.dumps(
                        {
                            "account_id": self.account_id,
                            "site_locale": self.site_locale,
                        }
                    ),
                    "",
                )
        if argv and argv[0] == "stripe":
            assert "--stripe-account" in argv
            account_index = argv.index("--stripe-account")
            assert argv[account_index + 1] == "acct_1234567890ref"
        if argv[:3] == ["stripe", "disputes", "list"] and "--charge" not in argv:
            self.preflight_calls += 1
            assert not self.out_dir.exists()
            if self.provider_preflight_failure:
                return subprocess.CompletedProcess(argv, 1, "", "provider unavailable")
            return subprocess.CompletedProcess(
                argv, 0, json.dumps({"object": "list", "data": [], "has_more": False}), ""
            )
        if argv[0] == "bash":
            self.flow_calls += 1
            assert journal.is_file()
            assert len(journal.read_text(encoding="utf-8").splitlines()) == 1
            return subprocess.CompletedProcess(
                argv,
                0,
                json.dumps(
                    {
                        "op": "dispute",
                        "order_id": 101,
                        "charge_id": "ch_ref_won",
                        "intent_id": "pi_ref_won",
                        "status": "processing",
                        "order_currency": "USD",
                    }
                ),
                "",
            )
        if argv[:3] == ["stripe", "disputes", "list"]:
            disputes = [{"id": "dp_ref_won", "charge": "ch_ref_won"}]
            if self.ambiguous_dispute:
                disputes.append({"id": "dp_other", "charge": "ch_ref_won"})
            return subprocess.CompletedProcess(
                argv,
                0,
                json.dumps(
                    {
                        "object": "list",
                        "data": disputes,
                        "has_more": self.list_has_more,
                    }
                ),
                "",
            )
        if argv[:3] == ["stripe", "disputes", "retrieve"]:
            self.provider_retrieves += 1
            if self.post_submit_provider_failure and self.submit_calls and self.provider_retrieves > 1:
                return subprocess.CompletedProcess(argv, 1, "", "provider unavailable")
            status = "won" if self.terminal and self.provider_retrieves > 1 else "needs_response"
            return subprocess.CompletedProcess(argv, 0, json.dumps(provider_facts(status)), "")
        if "eval-file" in argv:
            action = argv[argv.index("eval-file") + 2]
            if action == "probe":
                self.store_probes += 1
                status = "won" if self.terminal and self.store_probes > 1 else "needs_response"
                facts = store_facts(status)
                if self.wrong_store_identity:
                    facts["charge_id"] = "ch_other"
                return subprocess.CompletedProcess(argv, 0, json.dumps(facts), "")
            if action == "submit":
                self.submit_calls += 1
                assert len(journal.read_text(encoding="utf-8").splitlines()) == 3
                if self.submission_transport_failure:
                    return subprocess.CompletedProcess(argv, 124, "", "transport outcome unknown")
                if self.submission_class == "trusted_success":
                    trusted, http_status = True, 200
                elif self.submission_class == "trusted_failure":
                    trusted, http_status = True, 400
                else:
                    trusted, http_status = False, 0
                return subprocess.CompletedProcess(
                    argv,
                    0,
                    json.dumps(
                        {
                            "trusted": trusted,
                            "response_class": self.submission_class,
                            "submit": True,
                            "marker": "winning_evidence",
                            "http_status": http_status,
                            "dispute_id": "dp_ref_won",
                        }
                    ),
                    "",
                )
        raise AssertionError(f"unexpected command: {argv!r}")


def test_parse_wp_command_rejects_shell_control_and_non_wp_commands() -> None:
    module = load_gate()

    assert module.parse_wp_command("docker exec -i local-cli wp --allow-root") == [
        "docker",
        "exec",
        "-i",
        "local-cli",
        "wp",
        "--allow-root",
    ]
    for unsafe in (
        "docker exec local wp; curl remote.invalid",
        "docker exec local wp $(whoami)",
        "ssh remote wp",
        "docker exec local php",
    ):
        with pytest.raises(module.GateError):
            module.parse_wp_command(unsafe)


def test_gate_writes_ahead_each_mutation_and_seals_terminal_packet(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 0
    assert boundary.submit_calls == 1
    packet = json.loads((out_dir / "ref-store-packet.json").read_text(encoding="utf-8"))
    assert packet["status"] == "pass"
    records = [json.loads(line) for line in (out_dir / "mutation-journal.jsonl").read_text().splitlines()]
    assert [record["event"] for record in records] == [
        "fresh_dispute_create_armed",
        "fresh_dispute_created",
        "evidence_submit_armed",
        "evidence_submit_observed",
    ]


def test_gate_never_replays_trusted_submit_after_terminal_timeout(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, terminal=False)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 3
    assert boundary.submit_calls == 1
    packet = json.loads((out_dir / "ref-store-packet.json").read_text(encoding="utf-8"))
    assert packet["status"] == "blocked"
    assert "terminal_delivery_timeout" in packet["blockers"]


@pytest.mark.parametrize("failure", ["ambiguous_dispute", "wrong_store_identity"])
def test_gate_blocks_before_submit_when_fresh_fixture_cannot_be_bound(
    monkeypatch, tmp_path: Path, failure: str
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, **{failure: True})

    with pytest.raises(module.GateError):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.submit_calls == 0
    assert len((out_dir / "mutation-journal.jsonl").read_text().splitlines()) == 1
    assert not (out_dir / "ref-store-packet.json").exists()


@pytest.mark.parametrize(
    "submission_class,expected_status,expected_exit",
    [("trusted_failure", "fail", 1), ("unknown", "blocked", 3)],
)
def test_gate_archives_submit_failure_or_ambiguity_without_replay(
    monkeypatch,
    tmp_path: Path,
    submission_class: str,
    expected_status: str,
    expected_exit: int,
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, submission_class=submission_class)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == expected_exit
    assert boundary.submit_calls == 1
    assert boundary.provider_retrieves == 1
    packet = json.loads((out_dir / "ref-store-packet.json").read_text())
    assert packet["status"] == expected_status
    assert len(packet["journal"]) == 4


def test_gate_refuses_existing_archive_before_running_any_command(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    out_dir.mkdir()
    boundary = FakeBoundary(out_dir)

    with pytest.raises(module.GateError, match="refusing to overwrite or replay"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.submit_calls == 0
    assert boundary.provider_retrieves == 0


def test_gate_seals_unknown_witness_when_submit_transport_outcome_is_ambiguous(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, submission_transport_failure=True)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 3
    assert boundary.submit_calls == 1
    packet = json.loads((out_dir / "ref-store-packet.json").read_text())
    assert packet["submission"] == {
        "trusted": False,
        "response_class": "unknown",
        "submit": True,
        "marker": "winning_evidence",
        "http_status": 0,
        "dispute_id": "dp_ref_won",
    }
    assert packet["journal"][-1]["response_class"] == "unknown"


def test_gate_seals_last_projection_when_provider_becomes_unavailable_after_submit(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, post_submit_provider_failure=True)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 3
    assert boundary.submit_calls == 1
    packet = json.loads((out_dir / "ref-store-packet.json").read_text())
    assert packet["status"] == "blocked"
    assert "provider_observation_unavailable" in packet["blockers"]
    assert packet["provider_facts"]["dispute_status"] == "needs_response"


def test_cli_rejects_unsafe_wp_command_before_creating_archive(tmp_path: Path) -> None:
    out_dir = tmp_path / "unsafe"
    result = subprocess.run(
        [
            "python3",
            str(GATE),
            "--outcome",
            "won",
            "--store",
            "ref",
            "--runtime-owner",
            "plugin",
            "--run-stamp",
            RUN_STAMP,
            "--out-dir",
            str(out_dir),
            "--wp-command",
            "docker exec local wp; curl remote.invalid",
            "--flow-drive",
            str(ROOT / "tools/woopayments-merge/flow-drive.sh"),
            "--store-adapter",
            str(
                ROOT
                / "tools/woopayments-critical-flows/flows/class-woopayments-critical-flows-md-resolution-store.php"
            ),
        ],
        cwd=ROOT,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": CONTEXT_KEY},
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 3
    assert "shell control syntax" in result.stderr
    assert not out_dir.exists()


def test_gate_proves_test_provider_read_before_arming_fixture_creation(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, provider_preflight_failure=True)

    with pytest.raises(module.GateError, match="provider preflight"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.preflight_calls == 1
    assert boundary.flow_calls == 0
    assert not out_dir.exists()


def test_stripe_command_parser_rejects_live_mode_flag() -> None:
    module = load_gate()

    with pytest.raises(module.GateError, match="live mode"):
        module._parse_executable_command("stripe --live", "Stripe")


@pytest.mark.parametrize(
    "unsafe",
    ("ssh remote stripe", "python3 stripe", "stripe --api-key placeholder"),
)
def test_stripe_command_parser_rejects_non_stripe_or_secret_bearing_launchers(
    unsafe: str,
) -> None:
    module = load_gate()

    with pytest.raises(module.GateError):
        module._parse_executable_command(unsafe, "Stripe")


def test_gate_scopes_every_provider_read_to_the_exact_store_connected_account(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 0
    assert boundary.account_calls == 1
    assert boundary.preflight_calls == 1


def test_gate_streams_the_validated_adapter_to_each_isolated_container(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir)

    result = module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert result == 0
    assert boundary.adapter_stdin_calls == 4


def test_gate_blocks_before_runtime_reads_when_adapter_is_unavailable(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir)
    gate_config = config(out_dir)
    gate_config["store_adapter"] = tmp_path / "missing-adapter.php"

    with pytest.raises(module.GateError, match="store adapter"):
        module.run_gate(gate_config, execute=boundary, sleep=lambda _: None)

    assert boundary.account_calls == 0
    assert boundary.preflight_calls == 0
    assert boundary.flow_calls == 0
    assert not out_dir.exists()


def test_gate_blocks_before_provider_or_fixture_when_connected_account_is_invalid(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, account_id="")

    with pytest.raises(module.GateError, match="connected account"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.account_calls == 1
    assert boundary.preflight_calls == 0
    assert boundary.flow_calls == 0
    assert not out_dir.exists()


def test_gate_blocks_before_provider_or_fixture_when_site_locale_is_not_english(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, site_locale="fr_FR")

    with pytest.raises(module.GateError, match="English site locale"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.account_calls == 1
    assert boundary.preflight_calls == 0
    assert boundary.flow_calls == 0
    assert not out_dir.exists()


def test_gate_rejects_ambient_stripe_api_key_before_any_runtime_read(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    monkeypatch.setenv("STRIPE_API_KEY", "placeholder")
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir)

    with pytest.raises(module.GateError, match="ambient Stripe API key"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.account_calls == 0
    assert boundary.preflight_calls == 0
    assert boundary.flow_calls == 0
    assert not out_dir.exists()


def test_gate_rejects_truncated_dispute_discovery_before_submit(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_gate()
    out_dir = tmp_path / "ref"
    boundary = FakeBoundary(out_dir, list_has_more=True)

    with pytest.raises(module.GateError, match="exactly one"):
        module.run_gate(config(out_dir), execute=boundary, sleep=lambda _: None)

    assert boundary.submit_calls == 0
