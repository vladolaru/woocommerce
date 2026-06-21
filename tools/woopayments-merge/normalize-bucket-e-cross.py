#!/usr/bin/env python3
"""Normalize Bucket-E dumps for cross-store parity.

Same-store shadow parity can be byte-identical because both runtimes inspect the
same order. Cross-store parity drives corresponding orders on different stores,
so local order IDs and provider object IDs necessarily differ. This normalizer
masks those volatile identifiers while preserving the shape, status, amounts,
currency, meta keys, notes, refund records, payment method, and title fields that
make up the Bucket-E behavior contract.
"""

from __future__ import annotations

import json
import re
import sys
from typing import Any


ID_PATTERNS = (
    (re.compile(r"\b(ch|pi|seti|pm|cus|acct|evt|req|po|src|tok|txn)_[A-Za-z0-9_]+\b"), r"\1_<id>"),
    (re.compile(r"\bwc_order_[A-Za-z0-9]+\b"), "wc_order_<id>"),
    (re.compile(r"\b[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\b", re.IGNORECASE), "<uuid>"),
)

VOLATILE_JSON_KEYS = {
    "authorization_code",
    "network_transaction_id",
}

IGNORED_META_KEYS = {
    "_wcpay_raw_payment_method_details",  # Core-owned PaymentInfo cache, not a WooPayments compatibility surface.
    "_wcpay_test_lab",  # Reference-store harness provenance.
    "_wcpay_test_lab_account",  # Reference-store harness provenance.
}

ADMIN_URL_PATTERN = re.compile(r"https?://[^\"< ]+/wp-admin/admin\.php")
FEE_DETAILS_FX_RATE_PATTERN = re.compile(
    r"(1\.00 [A-Z]{3} \u2192 )[-+]?\d+(?:\.\d+)?( [A-Z]{3}:)"
)


def mask_string(value: str) -> str:
    masked = ADMIN_URL_PATTERN.sub("http://<store>/wp-admin/admin.php", value)
    masked = FEE_DETAILS_FX_RATE_PATTERN.sub(r"\1<rate>\2", masked)
    for pattern, replacement in ID_PATTERNS:
        masked = pattern.sub(replacement, masked)
    return masked


def normalize_json_string(value: str) -> str:
    stripped = value.strip()
    if not stripped or stripped[0] not in "[{":
        return mask_string(value)

    try:
        parsed = json.loads(value)
    except json.JSONDecodeError:
        return mask_string(value)

    normalized = normalize(parsed)
    return json.dumps(normalized, sort_keys=True, separators=(",", ":"))


def normalize(value: Any) -> Any:
    if isinstance(value, dict):
        return {
            key: "<volatile>" if key in VOLATILE_JSON_KEYS else normalize(inner)
            for key, inner in sorted(value.items())
        }
    if isinstance(value, list):
        return [normalize(inner) for inner in value]
    if isinstance(value, str):
        return normalize_json_string(value)
    return value


def normalize_record(record: dict[str, Any], index: int) -> dict[str, Any]:
    normalized = normalize(record)
    if isinstance(normalized.get("meta"), dict):
        normalized["meta"] = {
            key: value
            for key, value in normalized["meta"].items()
            if key not in IGNORED_META_KEYS
        }
    normalized["order_id"] = "<order>"
    normalized["_sequence"] = index
    return normalized


for index, line in enumerate(sys.stdin, start=1):
    line = line.strip()
    if not line:
        continue
    record = json.loads(line)
    print(json.dumps(normalize_record(record, index), sort_keys=True, separators=(",", ":")))
