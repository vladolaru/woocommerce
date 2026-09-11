#!/usr/bin/env python3
"""Validate, compare, and seal MD-03/MD-04 dispute-resolution evidence."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


PROFILES: dict[str, dict[str, str]] = {
    "won": {
        "outcome": "won",
        "flow": "MD-03-winning-dispute",
        "evidence_marker": "winning_evidence",
        "terminal_status": "won",
    },
    "lost": {
        "outcome": "lost",
        "flow": "MD-04-losing-dispute",
        "evidence_marker": "losing_evidence",
        "terminal_status": "lost",
    },
}
RUN_STAMP_RE = re.compile(r"^[0-9]{8}T[0-9]{6}Z-[0-9]+$")
ID_RE = re.compile(r"^(?:ch|py|pi|dp|du)_[A-Za-z0-9_]+$")
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
HMAC_RE = re.compile(r"^hmac-sha256:[0-9a-f]{64}$")
JOURNAL_DOMAIN = b"woopayments-md-resolution-journal-context-v1\0"
PACKET_DOMAIN = b"woopayments-md-resolution-store-packet-context-v1\0"
COMPARISON_DOMAIN = b"woopayments-md-resolution-comparison-context-v1\0"
LOG_DOMAIN = b"woopayments-md-resolution-log-context-v1\0"
EXECUTION_DOMAIN = b"woopayments-md-resolution-execution-context-v1\0"
MANIFEST_DOMAIN = b"woopayments-md-resolution-manifest-context-v1\0"
JOURNAL_EVENTS = (
    "fresh_dispute_create_armed",
    "fresh_dispute_created",
    "evidence_submit_armed",
    "evidence_submit_observed",
)


class EvidenceError(ValueError):
    """Raised when resolution evidence cannot be trusted."""


def canonical(payload: Any) -> bytes:
    """Return canonical UTF-8 JSON for digests and context seals."""
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _digest_payload(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    unsigned.pop("context_hmac", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def _context_key() -> bytes:
    encoded = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if re.fullmatch(r"[0-9a-f]{64}", encoded) is None:
        raise EvidenceError("runner evidence context key is unavailable")
    return bytes.fromhex(encoded)


def _seal(payload: dict[str, Any], domain: bytes) -> dict[str, Any]:
    sealed = dict(payload)
    sealed["payload_sha256"] = _digest_payload(sealed)
    material = dict(sealed)
    material.pop("context_hmac", None)
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(
        _context_key(), domain + canonical(material), hashlib.sha256
    ).hexdigest()
    return sealed


def _validate_seal(payload: dict[str, Any], domain: bytes) -> None:
    digest = payload.get("payload_sha256")
    context_hmac = payload.get("context_hmac")
    if not isinstance(digest, str) or DIGEST_RE.fullmatch(digest) is None:
        raise EvidenceError("payload digest is malformed")
    if not isinstance(context_hmac, str) or HMAC_RE.fullmatch(context_hmac) is None:
        raise EvidenceError("context HMAC is malformed")
    if not hmac.compare_digest(digest, _digest_payload(payload)):
        raise EvidenceError("payload digest does not match")
    material = dict(payload)
    material.pop("context_hmac", None)
    expected = "hmac-sha256:" + hmac.new(
        _context_key(), domain + canonical(material), hashlib.sha256
    ).hexdigest()
    if not hmac.compare_digest(context_hmac, expected):
        raise EvidenceError("context HMAC does not match")


def outcome_profile(outcome: str) -> dict[str, Any]:
    """Return the immutable contract profile for one resolution outcome."""
    try:
        return dict(PROFILES[outcome])
    except KeyError as error:
        raise EvidenceError("resolution outcome is unsupported") from error


def _marker_digest(marker: str) -> str:
    return "sha256:" + hashlib.sha256(marker.encode("utf-8")).hexdigest()


def build_journal_record(
    outcome: str,
    store: str,
    run_stamp: str,
    sequence: int,
    event: str,
    identity: dict[str, Any],
    *,
    marker: str = "",
    response_class: str = "",
) -> dict[str, Any]:
    """Build one independently sealed write-ahead mutation record."""
    profile = outcome_profile(outcome)
    return _seal(
        {
            "schema": "woopayments_md_resolution_journal_record.v1",
            "flow": profile["flow"],
            "outcome": outcome,
            "store": store,
            "run_stamp": run_stamp,
            "sequence": sequence,
            "event": event,
            "identity": dict(identity),
            "marker_sha256": _marker_digest(marker) if marker else "",
            "response_class": response_class,
        },
        JOURNAL_DOMAIN,
    )


def _valid_identity(identity: Any, *, complete: bool) -> bool:
    if not isinstance(identity, dict):
        return False
    if not complete:
        return identity == {}
    if set(identity) != {"order_id", "charge_id", "intent_id", "dispute_id"}:
        return False
    return (
        _is_int(identity.get("order_id"))
        and identity["order_id"] > 0
        and isinstance(identity.get("charge_id"), str)
        and re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", identity["charge_id"]) is not None
        and isinstance(identity.get("intent_id"), str)
        and re.fullmatch(r"pi_[A-Za-z0-9_]+", identity["intent_id"]) is not None
        and isinstance(identity.get("dispute_id"), str)
        and re.fullmatch(r"(?:dp|du)_[A-Za-z0-9_]+", identity["dispute_id"]) is not None
    )


def validate_journal(
    records: Any,
    *,
    outcome: str,
    store: str,
    run_stamp: str,
) -> None:
    """Validate exact mutation ordering and the armed-before-submit boundary."""
    profile = outcome_profile(outcome)
    if not isinstance(records, list) or len(records) != len(JOURNAL_EVENTS):
        raise EvidenceError("mutation journal is incomplete")
    complete_identity: dict[str, Any] | None = None
    fields = {
        "schema",
        "flow",
        "outcome",
        "store",
        "run_stamp",
        "sequence",
        "event",
        "identity",
        "marker_sha256",
        "response_class",
        "payload_sha256",
        "context_hmac",
    }
    for index, (record, event) in enumerate(zip(records, JOURNAL_EVENTS, strict=True), start=1):
        if not isinstance(record, dict) or set(record) != fields:
            raise EvidenceError("mutation journal record fields are invalid")
        _validate_seal(record, JOURNAL_DOMAIN)
        if (
            record.get("schema") != "woopayments_md_resolution_journal_record.v1"
            or record.get("flow") != profile["flow"]
            or record.get("outcome") != outcome
            or record.get("store") != store
            or record.get("run_stamp") != run_stamp
            or record.get("sequence") != index
            or record.get("event") != event
        ):
            raise EvidenceError("mutation journal binding or order is invalid")
        if index == 1:
            if not _valid_identity(record.get("identity"), complete=False):
                raise EvidenceError("pre-drive journal record contains an identity")
        else:
            if not _valid_identity(record.get("identity"), complete=True):
                raise EvidenceError("mutation journal identity is invalid")
            if complete_identity is None:
                complete_identity = record["identity"]
            elif record["identity"] != complete_identity:
                raise EvidenceError("mutation journal identity changed")
        if index < 3 and (record.get("marker_sha256") or record.get("response_class")):
            raise EvidenceError("mutation journal exposes submit facts before arming")
        if index >= 3 and record.get("marker_sha256") != _marker_digest(profile["evidence_marker"]):
            raise EvidenceError("mutation journal marker binding is invalid")
        if index == 3 and record.get("response_class"):
            raise EvidenceError("armed submit record already has a response")
        if index == 4 and record.get("response_class") not in {
            "trusted_success",
            "trusted_failure",
            "unknown",
        }:
            raise EvidenceError("submit response classification is invalid")


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _dispute_refunds(store: dict[str, Any]) -> list[dict[str, Any]]:
    refunds = store.get("refunds")
    if not isinstance(refunds, list):
        return []
    return [
        refund
        for refund in refunds
        if isinstance(refund, dict) and refund.get("reason_family") == "dispute"
    ]


def _balance_transactions(provider: dict[str, Any]) -> list[dict[str, Any]]:
    transactions = provider.get("balance_transactions")
    if not isinstance(transactions, list):
        return []
    return [transaction for transaction in transactions if isinstance(transaction, dict)]


def derive_outcome_assertions(
    outcome: str,
    submission: dict[str, Any],
    store: dict[str, Any],
    provider: dict[str, Any],
) -> dict[str, bool]:
    """Derive hard lifecycle assertions from normalized boundary facts."""
    profile = outcome_profile(outcome)
    amount = provider.get("amount")
    transactions = _balance_transactions(provider)
    transaction_amounts = [
        transaction.get("amount")
        for transaction in transactions
        if _is_int(transaction.get("amount"))
    ]
    notes = store.get("notes") if isinstance(store.get("notes"), dict) else {}
    refunds = _dispute_refunds(store)

    exact_identity = (
        isinstance(submission.get("dispute_id"), str)
        and submission.get("dispute_id") == store.get("dispute_id") == provider.get("dispute_id")
        and isinstance(store.get("charge_id"), str)
        and store.get("charge_id") == provider.get("charge_id")
        and isinstance(store.get("intent_id"), str)
        and store.get("intent_id") == provider.get("intent_id")
    )
    fee_shape_recorded = (
        bool(transactions)
        and all(
            _is_int(transaction.get("amount"))
            and _is_int(transaction.get("fee"))
            and _is_int(transaction.get("net"))
            and transaction["net"] == transaction["amount"] - transaction["fee"]
            for transaction in transactions
        )
        and any(transaction.get("fee") != 0 for transaction in transactions)
    )
    original_debit = _is_int(amount) and amount > 0 and -amount in transaction_amounts

    if outcome == "won":
        outcome_note = notes.get("funds_reinstated") is True
        opposite_note_absent = notes.get("fees_deducted") is False
        refund_shape = not refunds
        outcome_amount_movement = (
            original_debit
            and amount in transaction_amounts
            and sum(transaction_amounts) == 0
        )
    else:
        outcome_note = notes.get("fees_deducted") is True
        opposite_note_absent = notes.get("funds_reinstated") is False
        refund_shape = (
            _is_int(amount)
            and len(refunds) == 1
            and refunds[0].get("amount_minor") == amount
        )
        outcome_amount_movement = (
            original_debit
            and all(transaction_amount <= 0 for transaction_amount in transaction_amounts)
            and sum(transaction_amounts) == -amount
        )

    return {
        "trusted_exact_submission": (
            submission.get("trusted") is True
            and submission.get("submit") is True
            and submission.get("marker") == profile["evidence_marker"]
            and _is_int(submission.get("http_status"))
            and 200 <= submission["http_status"] < 300
        ),
        "provider_test_mode": provider.get("available") is True and provider.get("livemode") is False,
        "exact_identity": exact_identity,
        "terminal_status": (
            store.get("available") is True
            and provider.get("available") is True
            and store.get("dispute_status") == profile["terminal_status"]
            and provider.get("dispute_status") == profile["terminal_status"]
        ),
        "created_note": notes.get("created") is True,
        "evidence_submitted_note": notes.get("evidence_submitted") is True,
        "outcome_note": outcome_note,
        "opposite_outcome_note_absent": opposite_note_absent,
        "refund_shape": refund_shape,
        "original_dispute_debit": original_debit,
        "outcome_amount_movement": outcome_amount_movement,
        "fee_shape_recorded": fee_shape_recorded,
        "amount_currency_binding": (
            _is_int(amount)
            and amount > 0
            and store.get("order_total_minor") == amount
            and isinstance(provider.get("currency"), str)
            and provider.get("currency") == store.get("currency")
        ),
    }


def derive_verdict(
    submission: dict[str, Any],
    store: dict[str, Any],
    provider: dict[str, Any],
    assertions: dict[str, bool],
) -> tuple[str, list[str]]:
    """Classify unavailable evidence separately from observable contract drift."""
    blockers: list[str] = []
    if submission.get("response_class") == "unknown":
        blockers.append("submission_response_unknown")
    if store.get("available") is not True:
        blockers.append("store_observation_unavailable")
    if provider.get("available") is not True:
        blockers.append("provider_observation_unavailable")
    if blockers:
        return "blocked", blockers

    failures = [name for name, passed in assertions.items() if passed is not True]
    if failures:
        return "fail", failures
    return "pass", []


def portable_projection(
    outcome: str,
    store: dict[str, Any],
    provider: dict[str, Any],
) -> dict[str, Any]:
    """Project only cross-store lifecycle semantics, excluding local identifiers."""
    notes = store.get("notes") if isinstance(store.get("notes"), dict) else {}
    movements = [
        {
            "amount": transaction.get("amount"),
            "fee": transaction.get("fee"),
            "net": transaction.get("net"),
        }
        for transaction in _balance_transactions(provider)
    ]
    movements.sort(key=lambda item: (item["amount"], item["fee"], item["net"]))
    refunds = [
        {
            "amount_minor": refund.get("amount_minor"),
            "reason_family": refund.get("reason_family"),
        }
        for refund in store.get("refunds", [])
        if isinstance(refund, dict)
    ]
    refunds.sort(key=lambda item: (item["amount_minor"], item["reason_family"]))
    return {
        "outcome": outcome,
        "store_dispute_status": store.get("dispute_status"),
        "provider_dispute_status": provider.get("dispute_status"),
        "order_status": store.get("order_status"),
        "order_total_minor": store.get("order_total_minor"),
        "store_currency": store.get("currency"),
        "note_families": {
            "created": notes.get("created"),
            "evidence_submitted": notes.get("evidence_submitted"),
            "funds_reinstated": notes.get("funds_reinstated"),
            "fees_deducted": notes.get("fees_deducted"),
        },
        "refunds": refunds,
        "provider_amount": provider.get("amount"),
        "provider_currency": provider.get("currency"),
        "provider_movements": movements,
    }


def compare_facts(
    outcome: str,
    reference_store: dict[str, Any],
    reference_provider: dict[str, Any],
    target_store: dict[str, Any],
    target_provider: dict[str, Any],
) -> dict[str, Any]:
    """Compare reference and target portable resolution behavior."""
    reference = portable_projection(outcome, reference_store, reference_provider)
    target = portable_projection(outcome, target_store, target_provider)
    differences = [key for key in reference if reference[key] != target.get(key)]
    return {
        "status": "fail" if differences else "pass",
        "differences": differences,
        "reference": reference,
        "target": target,
    }


def _validate_drive(drive: Any, store_facts: dict[str, Any], provider_facts: dict[str, Any]) -> None:
    fields = {
        "op",
        "order_id",
        "charge_id",
        "intent_id",
        "status",
        "order_currency",
    }
    if not isinstance(drive, dict) or set(drive) != fields:
        raise EvidenceError("drive evidence fields are invalid")
    if (
        drive.get("op") != "dispute"
        or not _is_int(drive.get("order_id"))
        or drive["order_id"] <= 0
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(drive.get("charge_id", ""))) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(drive.get("intent_id", ""))) is None
        or not isinstance(drive.get("status"), str)
        or not isinstance(drive.get("order_currency"), str)
        or drive.get("order_id") != store_facts.get("order_id")
        or drive.get("charge_id") != store_facts.get("charge_id")
        or drive.get("charge_id") != provider_facts.get("charge_id")
        or drive.get("intent_id") != store_facts.get("intent_id")
        or drive.get("intent_id") != provider_facts.get("intent_id")
    ):
        raise EvidenceError("drive identity does not bind final facts")


def _validate_boundary_facts(
    outcome: str,
    submission: Any,
    store_facts: Any,
    provider_facts: Any,
) -> None:
    """Enforce the archive allowlist before any boundary payload is sealed."""
    profile = outcome_profile(outcome)
    submission_fields = {
        "trusted",
        "response_class",
        "submit",
        "marker",
        "http_status",
        "dispute_id",
    }
    if not isinstance(submission, dict) or set(submission) != submission_fields:
        raise EvidenceError("submission witness contains unallowlisted fields")
    response_class = submission.get("response_class")
    http_status = submission.get("http_status")
    if (
        response_class not in {"trusted_success", "trusted_failure", "unknown"}
        or not _is_int(http_status)
        or http_status < 0
        or http_status > 599
        or submission.get("submit") is not True
        or submission.get("marker") != profile["evidence_marker"]
        or re.fullmatch(r"(?:dp|du)_[A-Za-z0-9_]+", str(submission.get("dispute_id", ""))) is None
        or (response_class == "trusted_success" and (submission.get("trusted") is not True or not 200 <= http_status < 300))
        or (response_class == "trusted_failure" and (submission.get("trusted") is not True or http_status < 400))
        or (response_class == "unknown" and (submission.get("trusted") is not False or http_status != 0))
    ):
        raise EvidenceError("submission witness semantics are invalid")

    store_fields = {
        "available",
        "dispute_id",
        "dispute_status",
        "charge_id",
        "intent_id",
        "order_id",
        "order_status",
        "order_total_minor",
        "currency",
        "notes",
        "refunds",
    }
    if not isinstance(store_facts, dict) or set(store_facts) != store_fields:
        raise EvidenceError("store projection contains unallowlisted fields")
    notes = store_facts.get("notes")
    refunds = store_facts.get("refunds")
    if (
        not isinstance(store_facts.get("available"), bool)
        or re.fullmatch(r"(?:dp|du)_[A-Za-z0-9_]+", str(store_facts.get("dispute_id", ""))) is None
        or not isinstance(store_facts.get("dispute_status"), str)
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(store_facts.get("charge_id", ""))) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(store_facts.get("intent_id", ""))) is None
        or not _is_int(store_facts.get("order_id"))
        or store_facts["order_id"] <= 0
        or not isinstance(store_facts.get("order_status"), str)
        or not _is_int(store_facts.get("order_total_minor"))
        or store_facts["order_total_minor"] <= 0
        or re.fullmatch(r"[a-z]{3}", str(store_facts.get("currency", ""))) is None
        or not isinstance(notes, dict)
        or set(notes) != {"created", "evidence_submitted", "funds_reinstated", "fees_deducted"}
        or not all(isinstance(value, bool) for value in notes.values())
        or not isinstance(refunds, list)
    ):
        raise EvidenceError("store projection semantics are invalid")
    for refund in refunds:
        if (
            not isinstance(refund, dict)
            or set(refund) != {"amount_minor", "reason_family"}
            or not _is_int(refund.get("amount_minor"))
            or refund["amount_minor"] <= 0
            or refund.get("reason_family") not in {"dispute", "other"}
        ):
            raise EvidenceError("store refund projection is invalid")

    provider_fields = {
        "available",
        "livemode",
        "dispute_id",
        "dispute_status",
        "charge_id",
        "intent_id",
        "amount",
        "currency",
        "balance_transactions",
    }
    if not isinstance(provider_facts, dict) or set(provider_facts) != provider_fields:
        raise EvidenceError("provider projection contains unallowlisted fields")
    transactions = provider_facts.get("balance_transactions")
    if (
        not isinstance(provider_facts.get("available"), bool)
        or not isinstance(provider_facts.get("livemode"), bool)
        or re.fullmatch(r"(?:dp|du)_[A-Za-z0-9_]+", str(provider_facts.get("dispute_id", ""))) is None
        or not isinstance(provider_facts.get("dispute_status"), str)
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(provider_facts.get("charge_id", ""))) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(provider_facts.get("intent_id", ""))) is None
        or not _is_int(provider_facts.get("amount"))
        or provider_facts["amount"] <= 0
        or re.fullmatch(r"[a-z]{3}", str(provider_facts.get("currency", ""))) is None
        or not isinstance(transactions, list)
    ):
        raise EvidenceError("provider projection semantics are invalid")
    for transaction in transactions:
        if (
            not isinstance(transaction, dict)
            or set(transaction) != {"id", "amount", "fee", "net"}
            or re.fullmatch(r"txn_[A-Za-z0-9_]+", str(transaction.get("id", ""))) is None
            or not _is_int(transaction.get("amount"))
            or not _is_int(transaction.get("fee"))
            or not _is_int(transaction.get("net"))
        ):
            raise EvidenceError("provider balance transaction projection is invalid")


def build_store_packet(
    *,
    outcome: str,
    store: str,
    run_stamp: str,
    runtime_owner: str,
    drive: dict[str, Any],
    journal: list[dict[str, Any]],
    submission: dict[str, Any],
    store_facts: dict[str, Any],
    provider_facts: dict[str, Any],
    blockers: list[str],
) -> dict[str, Any]:
    """Build one sealed store packet and derive its verdict from facts."""
    profile = outcome_profile(outcome)
    if (
        store not in {"ref", "target"}
        or RUN_STAMP_RE.fullmatch(run_stamp) is None
        or runtime_owner != ("plugin" if store == "ref" else "native")
        or not isinstance(blockers, list)
        or not all(isinstance(reason, str) and reason for reason in blockers)
    ):
        raise EvidenceError("store packet runner context is invalid")
    validate_journal(journal, outcome=outcome, store=store, run_stamp=run_stamp)
    _validate_boundary_facts(outcome, submission, store_facts, provider_facts)
    _validate_drive(drive, store_facts, provider_facts)
    assertions = derive_outcome_assertions(outcome, submission, store_facts, provider_facts)
    status, reasons = derive_verdict(submission, store_facts, provider_facts, assertions)
    if blockers:
        terminal_statuses = {"won", "lost"}
        terminal_observed = (
            store_facts.get("available") is True
            and provider_facts.get("available") is True
            and store_facts.get("dispute_status") in terminal_statuses
            and provider_facts.get("dispute_status") in terminal_statuses
        )
        if status != "fail" or not terminal_observed:
            status = "blocked"
        reasons = list(dict.fromkeys([*blockers, *reasons]))
    return _seal(
        {
            "schema": "woopayments_md_resolution_store_packet.v1",
            "flow": profile["flow"],
            "outcome": outcome,
            "store": store,
            "run_stamp": run_stamp,
            "runtime_owner": runtime_owner,
            "drive": drive,
            "journal": journal,
            "submission": submission,
            "store_facts": store_facts,
            "provider_facts": provider_facts,
            "assertions": assertions,
            "status": status,
            "reasons": reasons,
            "blockers": blockers,
        },
        PACKET_DOMAIN,
    )


def validate_store_packet(
    payload: Any,
    *,
    outcome: str,
    store: str,
    run_stamp: str,
) -> None:
    """Validate one packet's seal, exact context, identity, and derived semantics."""
    profile = outcome_profile(outcome)
    fields = {
        "schema",
        "flow",
        "outcome",
        "store",
        "run_stamp",
        "runtime_owner",
        "drive",
        "journal",
        "submission",
        "store_facts",
        "provider_facts",
        "assertions",
        "status",
        "reasons",
        "blockers",
        "payload_sha256",
        "context_hmac",
    }
    if not isinstance(payload, dict) or set(payload) != fields:
        raise EvidenceError("store packet fields are invalid")
    _validate_seal(payload, PACKET_DOMAIN)
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        payload.get("schema") != "woopayments_md_resolution_store_packet.v1"
        or payload.get("flow") != profile["flow"]
        or payload.get("outcome") != outcome
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("runtime_owner") != expected_owner
        or RUN_STAMP_RE.fullmatch(run_stamp) is None
        or not isinstance(payload.get("submission"), dict)
        or not isinstance(payload.get("store_facts"), dict)
        or not isinstance(payload.get("provider_facts"), dict)
        or not isinstance(payload.get("blockers"), list)
        or not all(isinstance(reason, str) and reason for reason in payload["blockers"])
    ):
        raise EvidenceError("store packet context binding is invalid")
    validate_journal(payload["journal"], outcome=outcome, store=store, run_stamp=run_stamp)
    _validate_drive(payload["drive"], payload["store_facts"], payload["provider_facts"])
    journal_identity = payload["journal"][1]["identity"]
    if (
        journal_identity.get("order_id") != payload["drive"].get("order_id")
        or journal_identity.get("charge_id") != payload["drive"].get("charge_id")
        or journal_identity.get("intent_id") != payload["drive"].get("intent_id")
        or journal_identity.get("dispute_id") != payload["submission"].get("dispute_id")
        or payload["journal"][3].get("response_class") != payload["submission"].get("response_class")
    ):
        raise EvidenceError("mutation journal does not bind the packet facts")
    rebuilt = build_store_packet(
        outcome=outcome,
        store=store,
        run_stamp=run_stamp,
        runtime_owner=expected_owner,
        drive=payload["drive"],
        journal=payload["journal"],
        submission=payload["submission"],
        store_facts=payload["store_facts"],
        provider_facts=payload["provider_facts"],
        blockers=payload["blockers"],
    )
    if payload != rebuilt:
        raise EvidenceError("store packet semantics do not match its facts")


def build_comparison(
    reference: dict[str, Any],
    target: dict[str, Any],
    *,
    outcome: str,
    run_stamp: str,
) -> dict[str, Any]:
    """Build a sealed same-run portable reference/target comparison."""
    validate_store_packet(reference, outcome=outcome, store="ref", run_stamp=run_stamp)
    validate_store_packet(target, outcome=outcome, store="target", run_stamp=run_stamp)
    facts = compare_facts(
        outcome,
        reference["store_facts"],
        reference["provider_facts"],
        target["store_facts"],
        target["provider_facts"],
    )
    return _seal(
        {
            "schema": "woopayments_md_resolution_comparison.v1",
            "flow": outcome_profile(outcome)["flow"],
            "outcome": outcome,
            "run_stamp": run_stamp,
            "status": facts["status"],
            "differences": facts["differences"],
            "reference_packet_sha256": reference["payload_sha256"],
            "target_packet_sha256": target["payload_sha256"],
            "reference": facts["reference"],
            "target": facts["target"],
        },
        COMPARISON_DOMAIN,
    )


def validate_comparison(payload: Any, *, outcome: str, run_stamp: str) -> None:
    """Validate a comparison's exact context and internally derived result."""
    fields = {
        "schema",
        "flow",
        "outcome",
        "run_stamp",
        "status",
        "differences",
        "reference_packet_sha256",
        "target_packet_sha256",
        "reference",
        "target",
        "payload_sha256",
        "context_hmac",
    }
    if not isinstance(payload, dict) or set(payload) != fields:
        raise EvidenceError("comparison fields are invalid")
    _validate_seal(payload, COMPARISON_DOMAIN)
    differences = payload.get("differences")
    if (
        payload.get("schema") != "woopayments_md_resolution_comparison.v1"
        or payload.get("flow") != outcome_profile(outcome)["flow"]
        or payload.get("outcome") != outcome
        or payload.get("run_stamp") != run_stamp
        or not isinstance(differences, list)
        or not all(isinstance(name, str) and name for name in differences)
        or payload.get("status") != ("fail" if differences else "pass")
        or DIGEST_RE.fullmatch(str(payload.get("reference_packet_sha256", ""))) is None
        or DIGEST_RE.fullmatch(str(payload.get("target_packet_sha256", ""))) is None
        or not isinstance(payload.get("reference"), dict)
        or not isinstance(payload.get("target"), dict)
    ):
        raise EvidenceError("comparison context or verdict is invalid")
    expected_differences = [
        key
        for key in payload["reference"]
        if payload["reference"][key] != payload["target"].get(key)
    ]
    if differences != expected_differences:
        raise EvidenceError("comparison differences do not match its projections")


def build_log_scan(
    raw: dict[str, Any],
    *,
    outcome: str,
    store: str,
    run_stamp: str,
    expected_exit_code: int,
) -> dict[str, Any]:
    """Normalize the generic debug-log observer result into a sealed flow artifact."""
    profile = outcome_profile(outcome)
    scan = raw.get("scan") if isinstance(raw, dict) else None
    if (
        raw.get("schema") != "woopayments_debug_log_scan.v6"
        or raw.get("store") != store
        or not isinstance(scan, dict)
        or scan.get("run_stamp") != run_stamp
        or scan.get("store") != store
        or scan.get("flow_id") != profile["flow"]
        or scan.get("purpose") != "clean-debug-log"
    ):
        raise EvidenceError("generic log scan binding is invalid")
    status = scan.get("status")
    exit_map = {"pass": 0, "fail": 1, "blocked": 3}
    if status not in exit_map or exit_map[status] != expected_exit_code:
        raise EvidenceError("generic log scan status contradicts its exit code")
    matches = scan.get("matches")
    if not isinstance(matches, list):
        raise EvidenceError("generic log scan matches are invalid")
    return _seal(
        {
            "schema": "woopayments_md_resolution_log_scan.v1",
            "flow": profile["flow"],
            "outcome": outcome,
            "store": store,
            "run_stamp": run_stamp,
            "status": status,
            "exit_code": expected_exit_code,
            "scan_observed": True,
            "match_count": len(matches),
            "blocker_code": str(scan.get("blocker_code", "")),
            "source_payload_sha256": "sha256:" + hashlib.sha256(canonical(raw)).hexdigest(),
        },
        LOG_DOMAIN,
    )


def validate_log_scan(payload: Any, *, outcome: str, store: str, run_stamp: str) -> None:
    """Validate a normalized exact-flow log artifact."""
    fields = {
        "schema",
        "flow",
        "outcome",
        "store",
        "run_stamp",
        "status",
        "exit_code",
        "scan_observed",
        "match_count",
        "blocker_code",
        "source_payload_sha256",
        "payload_sha256",
        "context_hmac",
    }
    if not isinstance(payload, dict) or set(payload) != fields:
        raise EvidenceError("log artifact fields are invalid")
    _validate_seal(payload, LOG_DOMAIN)
    exit_map = {"pass": 0, "fail": 1, "blocked": 3}
    if (
        payload.get("schema") != "woopayments_md_resolution_log_scan.v1"
        or payload.get("flow") != outcome_profile(outcome)["flow"]
        or payload.get("outcome") != outcome
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("status") not in exit_map
        or payload.get("exit_code") != exit_map[payload["status"]]
        or payload.get("scan_observed") is not True
        or not _is_int(payload.get("match_count"))
        or payload["match_count"] < 0
        or (payload["status"] == "pass" and payload["match_count"] != 0)
        or not isinstance(payload.get("blocker_code"), str)
        or DIGEST_RE.fullmatch(str(payload.get("source_payload_sha256", ""))) is None
    ):
        raise EvidenceError("log artifact semantics are invalid")


def build_execution(
    packet: dict[str, Any],
    log_scan: dict[str, Any],
    *,
    comparison: dict[str, Any] | None,
    outcome: str,
    store: str,
    run_stamp: str,
) -> dict[str, Any]:
    """Build the final per-store verdict without weakening any component."""
    validate_store_packet(packet, outcome=outcome, store=store, run_stamp=run_stamp)
    validate_log_scan(log_scan, outcome=outcome, store=store, run_stamp=run_stamp)
    if store == "target":
        if comparison is None:
            raise EvidenceError("target execution requires a same-run comparison")
        validate_comparison(comparison, outcome=outcome, run_stamp=run_stamp)
    elif comparison is not None:
        raise EvidenceError("reference execution cannot contain a comparison")

    statuses = [packet["status"], log_scan["status"]]
    verdict_sources: list[str] = []
    if packet["status"] != "pass":
        verdict_sources.append(
            f"store_packet_{'failed' if packet['status'] == 'fail' else packet['status']}"
        )
    if log_scan["status"] != "pass":
        verdict_sources.append(
            f"log_scan_{'failed' if log_scan['status'] == 'fail' else log_scan['status']}"
        )
    comparison_sha256 = ""
    if comparison is not None:
        statuses.append(comparison["status"])
        comparison_sha256 = comparison["payload_sha256"]
        if comparison["status"] != "pass":
            verdict_sources.append(
                f"comparison_{'failed' if comparison['status'] == 'fail' else comparison['status']}"
            )
    if "fail" in statuses:
        status = "fail"
    elif "blocked" in statuses:
        status = "blocked"
    else:
        status = "pass"
    exit_code = {"pass": 0, "fail": 1, "blocked": 3}[status]
    return _seal(
        {
            "schema": "woopayments_md_resolution_execution.v1",
            "flow": outcome_profile(outcome)["flow"],
            "outcome": outcome,
            "store": store,
            "run_stamp": run_stamp,
            "status": status,
            "exit_code": exit_code,
            "verdict_sources": verdict_sources,
            "store_packet_sha256": packet["payload_sha256"],
            "log_scan_sha256": log_scan["payload_sha256"],
            "comparison_sha256": comparison_sha256,
        },
        EXECUTION_DOMAIN,
    )


def validate_execution(payload: Any, *, outcome: str, store: str, run_stamp: str) -> None:
    """Validate execution context, status, and component bindings."""
    fields = {
        "schema",
        "flow",
        "outcome",
        "store",
        "run_stamp",
        "status",
        "exit_code",
        "verdict_sources",
        "store_packet_sha256",
        "log_scan_sha256",
        "comparison_sha256",
        "payload_sha256",
        "context_hmac",
    }
    if not isinstance(payload, dict) or set(payload) != fields:
        raise EvidenceError("execution fields are invalid")
    _validate_seal(payload, EXECUTION_DOMAIN)
    exit_map = {"pass": 0, "fail": 1, "blocked": 3}
    verdict_sources = payload.get("verdict_sources")
    if (
        payload.get("schema") != "woopayments_md_resolution_execution.v1"
        or payload.get("flow") != outcome_profile(outcome)["flow"]
        or payload.get("outcome") != outcome
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("status") not in exit_map
        or payload.get("exit_code") != exit_map[payload["status"]]
        or not isinstance(verdict_sources, list)
        or not all(isinstance(source, str) and source for source in verdict_sources)
        or (payload["status"] == "pass" and verdict_sources)
        or DIGEST_RE.fullmatch(str(payload.get("store_packet_sha256", ""))) is None
        or DIGEST_RE.fullmatch(str(payload.get("log_scan_sha256", ""))) is None
        or (
            store == "target"
            and DIGEST_RE.fullmatch(str(payload.get("comparison_sha256", ""))) is None
        )
        or (store == "ref" and payload.get("comparison_sha256") != "")
    ):
        raise EvidenceError("execution semantics are invalid")


def _required_artifact_names(store: str) -> set[str]:
    names = {
        f"{store}-store-packet.json",
        f"{store}-log-scan.json",
        f"{store}-execution.json",
    }
    if store == "target":
        names.add("comparison.json")
    return names


def _load_artifact(path: Path) -> tuple[dict[str, Any], bytes]:
    if path.is_symlink() or not path.is_file():
        raise EvidenceError("manifest artifact is not a regular non-symlinked file")
    try:
        raw = path.read_bytes()
    except OSError as error:
        raise EvidenceError("manifest artifact is unreadable") from error
    if not raw or len(raw) > 2 * 1024 * 1024:
        raise EvidenceError("manifest artifact size is invalid")
    try:
        payload = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise EvidenceError("manifest artifact is not valid JSON") from error
    if not isinstance(payload, dict):
        raise EvidenceError("manifest artifact is not an object")
    return payload, raw


def _load_reference_oracle(
    target_dir: Path,
    *,
    outcome: str,
    run_stamp: str,
    run_scope: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Load and revalidate the exact passing reference archive beside target."""
    target_dir = target_dir.resolve()
    reference_dir = target_dir.parent / "ref"
    if target_dir.name != "target" or reference_dir.is_symlink() or not reference_dir.is_dir():
        raise EvidenceError("passing same-run reference archive is unavailable")
    reference_manifest_path = reference_dir / "ref-manifest.json"
    reference_manifest, _ = _load_artifact(reference_manifest_path)
    validate_manifest(
        reference_manifest,
        artifact_dir=reference_dir,
        outcome=outcome,
        store="ref",
        run_stamp=run_stamp,
        run_scope=run_scope,
        status="pass",
        exit_code=0,
    )
    reference_packet, _ = _load_artifact(reference_dir / "ref-store-packet.json")
    validate_store_packet(
        reference_packet,
        outcome=outcome,
        store="ref",
        run_stamp=run_stamp,
    )
    if (
        reference_manifest.get("files", {})
        .get("ref-store-packet.json", {})
        .get("payload_sha256")
        != reference_packet.get("payload_sha256")
    ):
        raise EvidenceError("reference manifest does not bind its store packet")
    return reference_manifest, reference_packet


def _validate_artifact_set(
    artifacts: dict[str, dict[str, Any]],
    *,
    outcome: str,
    store: str,
    run_stamp: str,
    status: str,
    exit_code: int,
) -> dict[str, Any]:
    required = _required_artifact_names(store)
    if set(artifacts) != required:
        raise EvidenceError("manifest artifact set is incomplete or unexpected")
    packet = artifacts[f"{store}-store-packet.json"]
    log_scan = artifacts[f"{store}-log-scan.json"]
    execution = artifacts[f"{store}-execution.json"]
    validate_store_packet(packet, outcome=outcome, store=store, run_stamp=run_stamp)
    validate_log_scan(log_scan, outcome=outcome, store=store, run_stamp=run_stamp)
    validate_execution(execution, outcome=outcome, store=store, run_stamp=run_stamp)
    if (
        execution.get("status") != status
        or execution.get("exit_code") != exit_code
        or execution.get("store_packet_sha256") != packet.get("payload_sha256")
        or execution.get("log_scan_sha256") != log_scan.get("payload_sha256")
    ):
        raise EvidenceError("execution does not bind the manifest verdict artifacts")
    if store == "target":
        comparison = artifacts["comparison.json"]
        validate_comparison(comparison, outcome=outcome, run_stamp=run_stamp)
        if (
            execution.get("comparison_sha256") != comparison.get("payload_sha256")
            or comparison.get("target_packet_sha256") != packet.get("payload_sha256")
        ):
            raise EvidenceError("target comparison does not bind the execution packet")
    return execution


def build_manifest(
    paths: list[Path],
    *,
    outcome: str,
    store: str,
    run_stamp: str,
    run_scope: str,
    status: str,
    exit_code: int,
) -> dict[str, Any]:
    """Build a context-bound manifest over the complete per-store artifact set."""
    if run_scope not in {"partial", "full"} or status not in {"pass", "fail", "blocked"}:
        raise EvidenceError("manifest runner verdict binding is invalid")
    if {"pass": 0, "fail": 1, "blocked": 3}[status] != exit_code:
        raise EvidenceError("manifest status contradicts its exit code")
    if not paths:
        raise EvidenceError("manifest has no artifacts")
    parents = {path.parent.resolve() for path in paths}
    if len(parents) != 1:
        raise EvidenceError("manifest artifacts do not share one archive directory")
    parent = next(iter(parents))
    artifacts: dict[str, dict[str, Any]] = {}
    file_bindings: dict[str, dict[str, str]] = {}
    for path in paths:
        if path.name in artifacts or path.name != Path(path.name).name or path.parent.resolve() != parent:
            raise EvidenceError("manifest artifact path or name is invalid")
        payload, raw = _load_artifact(path)
        artifacts[path.name] = payload
        payload_sha256 = payload.get("payload_sha256")
        if not isinstance(payload_sha256, str) or DIGEST_RE.fullmatch(payload_sha256) is None:
            raise EvidenceError("manifest artifact payload digest is invalid")
        file_bindings[path.name] = {
            "sha256": "sha256:" + hashlib.sha256(raw).hexdigest(),
            "payload_sha256": payload_sha256,
        }
    execution = _validate_artifact_set(
        artifacts,
        outcome=outcome,
        store=store,
        run_stamp=run_stamp,
        status=status,
        exit_code=exit_code,
    )
    reference_manifest_sha256 = ""
    if store == "target":
        reference_manifest, reference_packet = _load_reference_oracle(
            parent,
            outcome=outcome,
            run_stamp=run_stamp,
            run_scope=run_scope,
        )
        rebuilt_comparison = build_comparison(
            reference_packet,
            artifacts["target-store-packet.json"],
            outcome=outcome,
            run_stamp=run_stamp,
        )
        if artifacts["comparison.json"] != rebuilt_comparison:
            raise EvidenceError("target comparison does not match the current reference archive")
        reference_manifest_sha256 = reference_manifest["payload_sha256"]
    return _seal(
        {
            "schema": "woopayments_md_resolution_manifest.v1",
            "flow": outcome_profile(outcome)["flow"],
            "outcome": outcome,
            "store": store,
            "run_stamp": run_stamp,
            "run_scope": run_scope,
            "status": status,
            "exit_code": exit_code,
            "verdict_sources": execution["verdict_sources"],
            "reference_manifest_sha256": reference_manifest_sha256,
            "files": dict(sorted(file_bindings.items())),
        },
        MANIFEST_DOMAIN,
    )


def validate_manifest(
    payload: Any,
    *,
    artifact_dir: Path,
    outcome: str,
    store: str,
    run_stamp: str,
    run_scope: str,
    status: str,
    exit_code: int,
) -> None:
    """Re-read every manifested file and verify structural and semantic bindings."""
    fields = {
        "schema",
        "flow",
        "outcome",
        "store",
        "run_stamp",
        "run_scope",
        "status",
        "exit_code",
        "verdict_sources",
        "reference_manifest_sha256",
        "files",
        "payload_sha256",
        "context_hmac",
    }
    if not isinstance(payload, dict) or set(payload) != fields:
        raise EvidenceError("manifest fields are invalid")
    _validate_seal(payload, MANIFEST_DOMAIN)
    if (
        payload.get("schema") != "woopayments_md_resolution_manifest.v1"
        or payload.get("flow") != outcome_profile(outcome)["flow"]
        or payload.get("outcome") != outcome
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("run_scope") != run_scope
        or payload.get("status") != status
        or payload.get("exit_code") != exit_code
        or not isinstance(payload.get("files"), dict)
        or not isinstance(payload.get("verdict_sources"), list)
        or (
            store == "target"
            and DIGEST_RE.fullmatch(str(payload.get("reference_manifest_sha256", ""))) is None
        )
        or (store == "ref" and payload.get("reference_manifest_sha256") != "")
    ):
        raise EvidenceError("manifest runner context is invalid")
    if set(payload["files"]) != _required_artifact_names(store):
        raise EvidenceError("manifest file set is incomplete or unexpected")
    artifact_dir = artifact_dir.resolve()
    paths: list[Path] = []
    for filename, binding in payload["files"].items():
        if filename != Path(filename).name or not isinstance(binding, dict) or set(binding) != {
            "sha256",
            "payload_sha256",
        }:
            raise EvidenceError("manifest file binding is malformed")
        path = artifact_dir / filename
        if path.parent.resolve() != artifact_dir:
            raise EvidenceError("manifest artifact escapes the archive")
        artifact, raw = _load_artifact(path)
        if (
            binding.get("sha256") != "sha256:" + hashlib.sha256(raw).hexdigest()
            or binding.get("payload_sha256") != artifact.get("payload_sha256")
        ):
            raise EvidenceError("manifest artifact digest does not match")
        paths.append(path)
    rebuilt = build_manifest(
        paths,
        outcome=outcome,
        store=store,
        run_stamp=run_stamp,
        run_scope=run_scope,
        status=status,
        exit_code=exit_code,
    )
    if payload != rebuilt:
        raise EvidenceError("manifest semantics do not match its artifacts")


def _json_path(path: str) -> dict[str, Any]:
    payload, _ = _load_artifact(Path(path))
    return payload


def _emit(payload: dict[str, Any], exit_code: int = 0) -> int:
    print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
    return exit_code


def parser() -> argparse.ArgumentParser:
    """Build the evidence CLI consumed by the shell flow contract."""
    root = argparse.ArgumentParser(description=__doc__)
    commands = root.add_subparsers(dest="command", required=True)

    compare = commands.add_parser("compare")
    compare.add_argument("--reference", required=True)
    compare.add_argument("--target", required=True)
    compare.add_argument("--outcome", required=True, choices=tuple(PROFILES))
    compare.add_argument("--run-stamp", required=True)

    log_scan = commands.add_parser("normalize-log-scan")
    log_scan.add_argument("--input", required=True)
    log_scan.add_argument("--outcome", required=True, choices=tuple(PROFILES))
    log_scan.add_argument("--store", required=True, choices=("ref", "target"))
    log_scan.add_argument("--run-stamp", required=True)
    log_scan.add_argument("--expected-exit-code", required=True, type=int)

    execution = commands.add_parser("execution")
    execution.add_argument("--packet", required=True)
    execution.add_argument("--log-scan", required=True)
    execution.add_argument("--comparison")
    execution.add_argument("--outcome", required=True, choices=tuple(PROFILES))
    execution.add_argument("--store", required=True, choices=("ref", "target"))
    execution.add_argument("--run-stamp", required=True)

    manifest = commands.add_parser("manifest")
    manifest.add_argument("--file", action="append", required=True)
    manifest.add_argument("--outcome", required=True, choices=tuple(PROFILES))
    manifest.add_argument("--store", required=True, choices=("ref", "target"))
    manifest.add_argument("--run-stamp", required=True)
    manifest.add_argument("--run-scope", required=True, choices=("partial", "full"))
    manifest.add_argument("--status", required=True, choices=("pass", "fail", "blocked"))
    manifest.add_argument("--exit-code", required=True, type=int)

    bound = commands.add_parser("validate-bound-manifest")
    bound.add_argument("--manifest", required=True)
    bound.add_argument("--outcome", required=True, choices=tuple(PROFILES))
    bound.add_argument("--store", required=True, choices=("ref", "target"))
    bound.add_argument("--run-stamp", required=True)
    bound.add_argument("--run-scope", required=True, choices=("partial", "full"))
    bound.add_argument("--status", required=True, choices=("pass", "fail", "blocked"))
    bound.add_argument("--exit-code", required=True, type=int)
    return root


def main() -> int:
    """Run one evidence command and preserve PASS/FAIL/BLOCKED exit semantics."""
    args = parser().parse_args()
    try:
        if args.command == "compare":
            payload = build_comparison(
                _json_path(args.reference),
                _json_path(args.target),
                outcome=args.outcome,
                run_stamp=args.run_stamp,
            )
            return _emit(payload, 0 if payload["status"] == "pass" else 1)
        if args.command == "normalize-log-scan":
            payload = build_log_scan(
                _json_path(args.input),
                outcome=args.outcome,
                store=args.store,
                run_stamp=args.run_stamp,
                expected_exit_code=args.expected_exit_code,
            )
            return _emit(payload, args.expected_exit_code)
        if args.command == "execution":
            payload = build_execution(
                _json_path(args.packet),
                _json_path(args.log_scan),
                comparison=_json_path(args.comparison) if args.comparison else None,
                outcome=args.outcome,
                store=args.store,
                run_stamp=args.run_stamp,
            )
            return _emit(payload, payload["exit_code"])
        if args.command == "manifest":
            payload = build_manifest(
                [Path(path) for path in args.file],
                outcome=args.outcome,
                store=args.store,
                run_stamp=args.run_stamp,
                run_scope=args.run_scope,
                status=args.status,
                exit_code=args.exit_code,
            )
            return _emit(payload)
        if args.command == "validate-bound-manifest":
            manifest_path = Path(args.manifest)
            payload = _json_path(args.manifest)
            validate_manifest(
                payload,
                artifact_dir=manifest_path.parent,
                outcome=args.outcome,
                store=args.store,
                run_stamp=args.run_stamp,
                run_scope=args.run_scope,
                status=args.status,
                exit_code=args.exit_code,
            )
            print(payload["payload_sha256"])
            return 0
    except EvidenceError as error:
        print(f"BLOCKED: {error}", file=sys.stderr)
        return 3
    raise AssertionError("unreachable evidence command")


if __name__ == "__main__":
    raise SystemExit(main())
