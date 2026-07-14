#!/usr/bin/env python3
"""Regression checks for Tracks sink normalization."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
NORMALIZER = REPO / "tools/woopayments-merge/tracks-normalize.py"


def normalize(*records: dict) -> list[str]:
    result = subprocess.run(
        ["python3", str(NORMALIZER)],
        cwd=REPO,
        input="\n".join(json.dumps(record) for record in records) + "\n",
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    return [line for line in result.stdout.splitlines() if line]


def order_status_record(**props: object) -> dict:
    base = {
        "event_name": "wcadmin_orders_edit_status_change",
        "source": "server_pixel",
        "properties": {
            "order_id": "123",
            "next_status": "processing",
            "previous_status": "pending",
            "date_created": "2026-07-08",
            "payment_method": "woocommerce_payments",
            "order_total": "50.00",
        },
    }
    base["properties"].update(props)
    return base


def test_global_context_props_do_not_create_contract_drift() -> None:
    lines = normalize(
        order_status_record(coming_soon="no", role="customer"),
        order_status_record(coming_soon="store", role="administrator"),
    )

    assert len(lines) == 1
    assert "coming_soon" not in lines[0]
    assert "role=" not in lines[0]
    assert "payment_method=str:woocommerce_payments" in lines[0]


def test_payment_enum_drift_remains_visible() -> None:
    lines = normalize(
        order_status_record(payment_method="woocommerce_payments"),
        order_status_record(payment_method="cod"),
    )

    assert len(lines) == 2
    assert any("payment_method=str:woocommerce_payments" in line for line in lines)
    assert any("payment_method=str:cod" in line for line in lines)


def test_binary_string_flag_flip_remains_visible() -> None:
    # "0"/"1" are PHP-serialized booleans (feature flags); masking them to str:<n>
    # made a woopay_enabled 1 -> 0 flip invisible to the contract diff.
    lines = normalize(
        order_status_record(woopay_enabled="1"),
        order_status_record(woopay_enabled="0"),
    )

    assert len(lines) == 2
    assert any("woopay_enabled=str:1" in line for line in lines)
    assert any("woopay_enabled=str:0" in line for line in lines)


def test_multi_digit_numeric_strings_remain_masked() -> None:
    lines = normalize(
        order_status_record(order_id="123"),
        order_status_record(order_id="456"),
    )

    assert len(lines) == 1
    assert "order_id=str:<n>" in lines[0]


def test_duplicate_event_cardinality_remains_visible() -> None:
    # An event fired twice per flow (or once instead of per-item) is a regression a
    # set-dedup silently collapsed; identical signatures now carry an occurrence count.
    single = normalize(order_status_record())
    double = normalize(order_status_record(), order_status_record())

    assert len(single) == 1
    assert "occurrences=" not in single[0]
    assert len(double) == 1
    assert double[0].endswith("| occurrences=2")
    assert single != double

