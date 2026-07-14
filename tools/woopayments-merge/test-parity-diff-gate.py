from __future__ import annotations

import json
import os
import shutil
import stat
import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/parity-diff.sh"
NORMALIZER = REPO / "tools/woopayments-merge/normalize-bucket-e-cross.py"

FAKE_DUMP = """#!/usr/bin/env bash
# Test stand-in for dump-bucket-e-surface.sh: emits one canned JSON line per order id,
# read from $PARITY_FIXTURE_DIR/$WP/<id>.json; missing fixtures emit the same
# {"order_id":N,"error":"not_found"} record the real dump emits for missing orders.
# PARITY_FAKE_DROP_MISSING=1 instead emits nothing for missing fixtures (simulates a
# dump that silently lost records).
set -euo pipefail
if [ "$#" -eq 0 ]; then
    echo "usage: WP='<wp runner>' dump-bucket-e-surface.sh <order_id> [<order_id>...]" >&2
    exit 2
fi
for id in "$@"; do
    fixture="$PARITY_FIXTURE_DIR/$WP/$id.json"
    if [ -f "$fixture" ]; then
        cat "$fixture"
    elif [ "${PARITY_FAKE_DROP_MISSING:-0}" = "1" ]; then
        continue
    else
        printf '{"order_id":%s,"error":"not_found"}\\n' "$id"
    fi
done
"""


def order_record(
    order_id: int,
    status: str = "processing",
    charge_suffix: str = "AbCdEf123456",
) -> str:
    return json.dumps(
        {
            "order_id": order_id,
            "status": status,
            "total": "50.00",
            "currency": "USD",
            "payment_method": "woocommerce_payments",
            "meta": {
                "_intent_id": [f"pi_{charge_suffix}"],
                "_charge_id": [f"ch_{charge_suffix}"],
            },
            "notes": ["Payment of 50.00 USD completed"],
            "refunds": [],
        },
        sort_keys=True,
        separators=(",", ":"),
    )


def make_harness(tmp_path: Path) -> tuple[Path, Path, dict[str, str]]:
    harness = tmp_path / "harness"
    harness.mkdir()
    gate = harness / "parity-diff.sh"
    shutil.copy2(GATE, gate)
    shutil.copy2(NORMALIZER, harness / "normalize-bucket-e-cross.py")
    fake_dump = harness / "dump-bucket-e-surface.sh"
    fake_dump.write_text(FAKE_DUMP, encoding="utf-8")
    fake_dump.chmod(fake_dump.stat().st_mode | stat.S_IXUSR)

    fixtures = tmp_path / "fixtures"
    fixtures.mkdir()
    env = os.environ.copy()
    env["PARITY_FIXTURE_DIR"] = str(fixtures)
    # Keep the cross-store settle loop from burning wall time on intended diffs.
    env["CROSS_SETTLE_TRIES"] = "1"
    env["CROSS_SETTLE_SLEEP_SECONDS"] = "0"
    return gate, fixtures, env


def write_fixture(fixtures: Path, store: str, order_id: int, record: str) -> None:
    store_dir = fixtures / store
    store_dir.mkdir(parents=True, exist_ok=True)
    (store_dir / f"{order_id}.json").write_text(record + "\n", encoding="utf-8")


def run_gate(gate: Path, env: dict[str, str], *args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(gate), *args],
        cwd=gate.parent,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def test_cross_store_matching_orders_pass(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_fixture(fixtures, "refstore", 10, order_record(10, charge_suffix="RefAbc123456"))
    write_fixture(fixtures, "targetstore", 20, order_record(20, charge_suffix="TgtXyz654321"))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 0, result.stdout
    assert "PASS: zero Bucket-E parity diff" in result.stdout


def test_cross_store_real_divergence_fails(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_fixture(fixtures, "refstore", 10, order_record(10, status="processing"))
    write_fixture(fixtures, "targetstore", 20, order_record(20, status="failed"))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 1, result.stdout
    assert "RULE 0 regression" in result.stdout


def test_self_check_missing_order_is_blocked_not_pass(tmp_path: Path) -> None:
    gate, _fixtures, env = make_harness(tmp_path)

    result = run_gate(gate, env, "--self-check", "selfstore", "999999")

    assert result.returncode == 2, result.stdout
    assert "PASS" not in result.stdout
    assert "999999" in result.stdout
    assert "not_found" in result.stdout


def test_cross_store_missing_on_both_sides_is_blocked_not_pass(tmp_path: Path) -> None:
    gate, _fixtures, env = make_harness(tmp_path)

    result = run_gate(
        gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "888888", "999999"
    )

    assert result.returncode == 2, result.stdout
    assert "PASS" not in result.stdout


def test_cross_store_missing_on_one_side_is_blocked(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_fixture(fixtures, "refstore", 10, order_record(10))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 2, result.stdout
    assert "PASS" not in result.stdout


def test_empty_vs_real_transaction_id_is_a_divergence(tmp_path: Path) -> None:
    # transaction_id is masked as volatile, but the presence/emptiness class must be
    # preserved: a native store failing to persist the transaction id at all is a
    # merchant-facing regression, not volatile noise.
    gate, fixtures, env = make_harness(tmp_path)
    ref = json.loads(order_record(10))
    ref["transaction_id"] = "pi_RefAbc1234567890"
    target = json.loads(order_record(20))
    target["transaction_id"] = ""
    write_fixture(fixtures, "refstore", 10, json.dumps(ref, sort_keys=True, separators=(",", ":")))
    write_fixture(fixtures, "targetstore", 20, json.dumps(target, sort_keys=True, separators=(",", ":")))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 1, result.stdout
    assert "RULE 0 regression" in result.stdout


def test_note_wording_change_behind_english_prefix_is_a_divergence(tmp_path: Path) -> None:
    # "re_authorization" vs "re_authentication" must not both collapse to re_<id>:
    # the id mask requires a Stripe-shaped suffix (contains a digit, 8+ chars).
    gate, fixtures, env = make_harness(tmp_path)
    ref = json.loads(order_record(10))
    ref["notes"] = ["Payment re_authorization required"]
    target = json.loads(order_record(20))
    target["notes"] = ["Payment re_authentication required"]
    write_fixture(fixtures, "refstore", 10, json.dumps(ref, sort_keys=True, separators=(",", ":")))
    write_fixture(fixtures, "targetstore", 20, json.dumps(target, sort_keys=True, separators=(",", ":")))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 1, result.stdout


def test_dispute_ids_in_notes_do_not_false_fail(tmp_path: Path) -> None:
    # Cross-store dispute ids legitimately differ; dp_ is now masked like the other
    # Stripe prefixes so a dispute note does not produce false parity noise.
    gate, fixtures, env = make_harness(tmp_path)
    ref = json.loads(order_record(10))
    ref["notes"] = ["Payment disputed (dp_1RefAbc123456789)"]
    target = json.loads(order_record(20))
    target["notes"] = ["Payment disputed (dp_1TgtXyz987654321)"]
    write_fixture(fixtures, "refstore", 10, json.dumps(ref, sort_keys=True, separators=(",", ":")))
    write_fixture(fixtures, "targetstore", 20, json.dumps(target, sort_keys=True, separators=(",", ":")))

    result = run_gate(gate, env, "--ref", "refstore", "--target", "targetstore", "--target-ids", "20", "10")

    assert result.returncode == 0, result.stdout


def test_record_count_mismatch_is_blocked(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_fixture(fixtures, "selfstore", 10, order_record(10))
    env["PARITY_FAKE_DROP_MISSING"] = "1"

    result = run_gate(gate, env, "--self-check", "selfstore", "10", "11")

    assert result.returncode == 2, result.stdout
    assert "PASS" not in result.stdout
