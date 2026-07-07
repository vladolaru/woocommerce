#!/usr/bin/env python3
"""Compare WC money state against Stripe raw-source money state."""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Any


ZERO_DECIMAL_CURRENCIES = {
    "bif",
    "clp",
    "djf",
    "gnf",
    "jpy",
    "kmf",
    "krw",
    "mga",
    "pyg",
    "rwf",
    "ugx",
    "vnd",
    "vuv",
    "xaf",
    "xof",
    "xpf",
}


class Verdict:
    def __init__(self) -> None:
        self.failures: list[str] = []
        self.blockers: list[str] = []
        self.ok: list[str] = []

    def fail(self, message: str) -> None:
        self.failures.append(message)

    def block(self, message: str) -> None:
        self.blockers.append(message)

    def pass_(self, message: str) -> None:
        self.ok.append(message)

    def exit_code(self) -> int:
        if self.blockers:
            return 3
        if self.failures:
            return 1
        return 0

    def print(self) -> None:
        for message in self.ok:
            print(f"  ok: {message}")
        for message in self.failures:
            print(f"  FAIL: {message}")
        for message in self.blockers:
            print(f"  BLOCKED: {message}")


def load_json(path: str | None, *, required: bool, label: str, verdict: Verdict) -> Any:
    if not path:
        if required:
            verdict.block(f"{label} JSON was not provided")
        return {}

    file_path = Path(path)
    if not file_path.exists():
        if required:
            verdict.block(f"{label} JSON file is missing: {path}")
        return {}

    try:
        content = file_path.read_text()
    except OSError as exc:
        verdict.block(f"{label} JSON could not be read: {exc}")
        return {}

    if not content.strip():
        if required:
            verdict.block(f"{label} JSON is empty")
        return {}

    try:
        return json.loads(content)
    except json.JSONDecodeError as exc:
        verdict.block(f"{label} JSON is not parseable: {exc}")
        return {}


def string_value(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, bool):
        return "true" if value else "false"
    return str(value)


def lower(value: Any) -> str:
    return string_value(value).lower()


def is_present(value: Any) -> bool:
    return value is not None and string_value(value) != ""


def decimal_value(value: Any) -> Decimal | None:
    if value is None or string_value(value) == "":
        return None
    try:
        return Decimal(string_value(value))
    except (InvalidOperation, ValueError):
        return None


def amount_to_minor(value: Any, currency: str) -> int | None:
    amount = decimal_value(value)
    if amount is None:
        return None
    factor = minor_factor(currency)
    return int((amount * factor).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def minor_factor(currency: str) -> Decimal:
    return Decimal(1) if currency.lower() in ZERO_DECIMAL_CURRENCIES else Decimal(100)


def stripe_int(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def compare_equal(verdict: Verdict, label: str, wc_value: Any, provider_value: Any) -> None:
    wc_string = string_value(wc_value)
    provider_string = string_value(provider_value)
    if wc_string == provider_string:
        verdict.pass_(f"{label} matches ({wc_string})")
    else:
        verdict.fail(f"{label} mismatch WC={wc_string or '<empty>'} provider={provider_string or '<empty>'}")


def compare_minor(verdict: Verdict, label: str, wc_amount: Any, wc_currency: str, provider_minor: Any, provider_currency: str) -> None:
    wc_minor = amount_to_minor(wc_amount, wc_currency)
    provider_value = stripe_int(provider_minor)
    if wc_minor is None:
        verdict.block(f"{label} WC amount is not numeric: {wc_amount!r}")
        return
    if provider_value is None:
        verdict.block(f"{label} provider amount is not numeric: {provider_minor!r}")
        return
    if lower(wc_currency) != lower(provider_currency):
        verdict.fail(f"{label} currency mismatch WC={lower(wc_currency) or '<empty>'} provider={lower(provider_currency) or '<empty>'}")
        return
    if wc_minor == provider_value:
        verdict.pass_(f"{label} matches ({wc_minor} {lower(wc_currency)})")
    else:
        verdict.fail(f"{label} amount mismatch WC={wc_minor} {lower(wc_currency)} provider={provider_value} {lower(provider_currency)}")


def compare_converted_minor(verdict: Verdict, label: str, wc_amount: Any, wc_currency: str, provider_minor: Any, provider_currency: str, exchange_rate: Any) -> None:
    amount = decimal_value(wc_amount)
    provider_value = stripe_int(provider_minor)
    rate = decimal_value(exchange_rate)
    if amount is None:
        verdict.block(f"{label} WC amount is not numeric: {wc_amount!r}")
        return
    if provider_value is None:
        verdict.block(f"{label} provider amount is not numeric: {provider_minor!r}")
        return
    if rate is None:
        verdict.block(f"{label} needs exchange rate for {lower(wc_currency)}->{lower(provider_currency)} comparison")
        return
    converted = int((amount * rate * minor_factor(provider_currency)).quantize(Decimal("1"), rounding=ROUND_HALF_UP))
    if abs(converted - provider_value) <= 1:
        verdict.pass_(f"{label} matches after exchange-rate conversion ({converted} {lower(provider_currency)})")
    else:
        verdict.fail(f"{label} converted amount mismatch WC={converted} {lower(provider_currency)} provider={provider_value} {lower(provider_currency)} rate={rate}")


def list_data(raw: Any) -> list[dict[str, Any]]:
    if isinstance(raw, dict) and isinstance(raw.get("data"), list):
        return [item for item in raw["data"] if isinstance(item, dict)]
    if isinstance(raw, dict) and isinstance(raw.get("refunds"), dict):
        return list_data(raw["refunds"])
    return []


def first_string(*values: Any) -> str:
    for value in values:
        candidate = string_value(value)
        if candidate:
            return candidate
    return ""


def collect_provider_refunds(charge: dict[str, Any], refunds: dict[str, Any]) -> list[dict[str, Any]]:
    explicit = list_data(refunds)
    if explicit:
        return explicit
    return list_data(charge.get("refunds", {}))


def collect_provider_disputes(charge: dict[str, Any], disputes: dict[str, Any]) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    seen: set[str] = set()

    def add(item: dict[str, Any]) -> None:
        dispute_id = string_value(item.get("id"))
        if not dispute_id or dispute_id in seen:
            return
        seen.add(dispute_id)
        items.append(item)

    dispute = charge.get("dispute")
    charge_dispute_id = ""
    if isinstance(dispute, dict):
        add(dispute)
    elif is_present(dispute):
        charge_dispute_id = string_value(dispute)

    for item in list_data(disputes):
        add(item)

    if charge_dispute_id and charge_dispute_id not in seen:
        add({"id": charge_dispute_id})

    return items


def provider_dispute_charge_id(dispute: dict[str, Any]) -> str:
    charge = dispute.get("charge")
    if isinstance(charge, dict):
        return string_value(charge.get("id"))
    return string_value(charge)


def normalize_note_text(content: str) -> str:
    decoded = html.unescape(content).replace("\xa0", " ")
    decoded = decoded.replace("&#36;", "$")
    return re.sub(r"\s+", " ", decoded).strip().lower()


def dispute_reason_description(reason: str) -> str:
    descriptions = {
        "bank_cannot_process": "Bank cannot process",
        "check_returned": "Check returned",
        "credit_not_processed": "Credit not processed",
        "customer_initiated": "Customer initiated",
        "debit_not_authorized": "Debit not authorized",
        "duplicate": "Duplicate",
        "fraudulent": "Transaction unauthorized",
        "incorrect_account_details": "Incorrect account details",
        "insufficient_funds": "Insufficient funds",
        "product_not_received": "Product not received",
        "product_unacceptable": "Product unacceptable",
        "subscription_canceled": "Subscription canceled",
        "unrecognized": "Unrecognized",
        "noncompliant": "Non-compliant",
        "general": "General",
    }
    return descriptions.get(reason, descriptions["general"])


def dispute_reason_terms(reason: str) -> set[str]:
    if not reason:
        return set()
    return {
        normalize_note_text(reason.replace("_", " ")),
        normalize_note_text(dispute_reason_description(reason)),
    }


def dispute_amount_terms(dispute: dict[str, Any]) -> set[str]:
    amount = stripe_int(dispute.get("amount"))
    currency = lower(dispute.get("currency"))
    if amount is None:
        return set()

    factor = minor_factor(currency)
    major = Decimal(amount) / factor
    if factor == 1:
        major_text = str(amount)
    else:
        major_text = f"{major:.2f}"

    terms = {major_text, major_text.rstrip("0").rstrip(".")}
    if currency:
        terms.add(f"{major_text} {currency}")
    return {term for term in terms if term}


def note_contains_amount_term(note: str, amount_terms: set[str]) -> bool:
    for term in sorted(amount_terms, key=len, reverse=True):
        pattern = r"(?<![\d.])" + re.escape(term) + r"(?![\d.])"
        if re.search(pattern, note):
            return True
    return False


def note_matches_provider_dispute(content: str, dispute: dict[str, Any]) -> bool:
    note = normalize_note_text(content)
    if "dispute" not in note and "payment inquiry" not in note:
        return False

    status = lower(dispute.get("status"))
    if status.startswith("warning_") and "payment inquiry" not in note:
        return False

    amount_terms = dispute_amount_terms(dispute)
    if not amount_terms or not note_contains_amount_term(note, amount_terms):
        return False

    reason = lower(dispute.get("reason"))
    if reason and not any(term in note for term in dispute_reason_terms(reason)):
        return False

    return True


def note_contents(wc: dict[str, Any]) -> list[str]:
    notes = wc.get("notes")
    if not isinstance(notes, list):
        return []

    contents: list[str] = []
    for note in notes:
        if isinstance(note, dict):
            for key in ("content", "note", "message", "comment_content"):
                content = string_value(note.get(key))
                if content:
                    contents.append(content)
                    break
        elif is_present(note):
            contents.append(string_value(note))
    return contents


def dispute_side_effect_evidence(wc: dict[str, Any], provider_disputes: list[dict[str, Any]]) -> list[str]:
    evidence: list[str] = []
    for content in note_contents(wc):
        for dispute in provider_disputes:
            if note_matches_provider_dispute(content, dispute):
                dispute_id = string_value(dispute.get("id")) or "<unknown>"
                evidence.append(f"order note matches provider dispute {dispute_id} amount/reason")
                break
        if evidence:
            break

    refunds = wc.get("refunds") if isinstance(wc.get("refunds"), list) else []
    for refund in refunds:
        if not isinstance(refund, dict):
            continue
        for key in ("reason", "description", "provider_refund_status"):
            if "dispute" in lower(refund.get(key)):
                evidence.append("refund evidence mentions dispute")
                return evidence

    return evidence


def compare_refunds(verdict: Verdict, wc: dict[str, Any], charge: dict[str, Any], refunds: dict[str, Any]) -> None:
    wc_currency = lower(wc.get("currency"))
    wc_refunds = wc.get("refunds") if isinstance(wc.get("refunds"), list) else []
    wc_total = sum(amount_to_minor(refund.get("amount"), lower(refund.get("currency") or wc_currency)) or 0 for refund in wc_refunds if isinstance(refund, dict))
    provider_amount_refunded = stripe_int(charge.get("amount_refunded"))
    if provider_amount_refunded is None:
        verdict.block("charge amount_refunded is missing from provider charge")
        return
    if wc_total == provider_amount_refunded:
        verdict.pass_(f"refund total matches ({wc_total} {wc_currency})")
    else:
        verdict.fail(f"refund total mismatch WC={wc_total} {wc_currency} provider={provider_amount_refunded} {lower(charge.get('currency'))}")

    provider_refunds = collect_provider_refunds(charge, refunds)
    provider_refund_total = sum(stripe_int(refund.get("amount")) or 0 for refund in provider_refunds)
    if provider_refund_total == provider_amount_refunded:
        verdict.pass_(f"provider refund rows sum to charge amount_refunded ({provider_refund_total})")
    else:
        verdict.fail(f"provider refund rows mismatch rows={provider_refund_total} charge.amount_refunded={provider_amount_refunded}")

    provider_refund_ids = {string_value(refund.get("id")) for refund in provider_refunds if is_present(refund.get("id"))}
    for refund in wc_refunds:
        if not isinstance(refund, dict):
            continue
        refund_id = first_string(refund.get("provider_refund_id"), refund.get("refund_id"), refund.get("wcpay_refund_id"))
        if not refund_id:
            if provider_refund_ids:
                verdict.fail(f"WC refund {string_value(refund.get('id')) or '<unknown>'} is missing provider refund id")
            continue
        if refund_id in provider_refund_ids:
            verdict.pass_(f"refund id {refund_id} exists at provider")
        else:
            verdict.fail(f"refund id {refund_id} missing from provider refunds")


def compare_fee_and_net(verdict: Verdict, wc: dict[str, Any], balance: dict[str, Any]) -> None:
    provider_currency = lower(first_string(balance.get("currency"), wc.get("currency")))
    wc_currency = lower(wc.get("currency"))
    exchange_rate = balance.get("exchange_rate")
    if not balance:
        if is_present(wc.get("transaction_fee")) or is_present(wc.get("net")) or is_present(wc.get("payment_transaction_id")):
            verdict.block("WC has fee/net/balance transaction state but provider balance transaction was not supplied")
        return

    if is_present(wc.get("payment_transaction_id")):
        compare_equal(verdict, "balance transaction id", wc.get("payment_transaction_id"), balance.get("id"))

    provider_fee = stripe_int(balance.get("fee"))
    wc_fee = wc.get("transaction_fee")
    if is_present(wc_fee) or (provider_fee is not None and provider_fee != 0):
        if provider_currency and wc_currency and provider_currency != wc_currency:
            compare_converted_minor(verdict, "transaction fee", wc_fee, wc_currency, provider_fee, provider_currency, exchange_rate)
        else:
            compare_minor(verdict, "transaction fee", wc_fee, provider_currency, provider_fee, provider_currency)

    provider_net = stripe_int(balance.get("net"))
    wc_net = wc.get("net")
    if is_present(wc_net) or provider_net is not None:
        if provider_currency and wc_currency and provider_currency != wc_currency:
            compare_converted_minor(verdict, "net amount", wc_net, wc_currency, provider_net, provider_currency, exchange_rate)
        else:
            compare_minor(verdict, "net amount", wc_net, provider_currency, provider_net, provider_currency)


def compare_multi_currency(verdict: Verdict, wc: dict[str, Any], balance: dict[str, Any], charge: dict[str, Any]) -> None:
    multi = wc.get("multi_currency") if isinstance(wc.get("multi_currency"), dict) else {}
    wc_currency = lower(wc.get("currency"))
    store_currency = lower(wc.get("store_currency"))
    charge_currency = lower(charge.get("currency"))
    balance_currency = lower(balance.get("currency")) if balance else ""
    intent_currency = lower(wc.get("intent_currency"))
    is_converted_order = bool(wc_currency and store_currency and wc_currency != store_currency)

    if is_converted_order:
        order_rate = decimal_value(multi.get("order_exchange_rate"))
        order_default_currency = lower(multi.get("order_default_currency"))
        if order_rate is None:
            verdict.fail("converted order is missing _wcpay_multi_currency_order_exchange_rate")
        else:
            verdict.pass_(f"converted order exchange rate is present ({order_rate})")
        if order_default_currency != store_currency:
            verdict.fail(
                f"converted order default-currency meta mismatch WC={order_default_currency or '<empty>'} store={store_currency or '<empty>'}"
            )
        else:
            verdict.pass_(f"converted order default-currency meta matches ({store_currency})")
        if not intent_currency:
            verdict.fail("converted order is missing _wcpay_intent_currency")
        elif charge_currency and intent_currency != charge_currency:
            verdict.fail(
                f"converted order intent currency mismatch WC={intent_currency} provider charge={charge_currency}"
            )
        else:
            verdict.pass_(f"converted order intent currency is present ({intent_currency})")

    wc_rate = decimal_value(multi.get("stripe_exchange_rate"))
    provider_rate = decimal_value(balance.get("exchange_rate")) if balance else None
    if wc_rate is None and provider_rate is None:
        if is_converted_order and balance and balance_currency and wc_currency and balance_currency != wc_currency:
            verdict.block(
                f"converted order provider balance currency {balance_currency} differs from WC currency {wc_currency} but has no exchange rate"
            )
            return
        verdict.pass_("multi-currency exchange rate absent on both sides")
        return
    if wc_rate is None or provider_rate is None:
        verdict.fail(f"multi-currency exchange-rate presence mismatch WC={wc_rate} provider={provider_rate}")
        return
    if abs(wc_rate - provider_rate) <= Decimal("0.000001"):
        verdict.pass_(f"multi-currency exchange rate matches ({wc_rate})")
    else:
        verdict.fail(f"multi-currency exchange-rate mismatch WC={wc_rate} provider={provider_rate}")


def compare_dispute(verdict: Verdict, wc: dict[str, Any], charge: dict[str, Any], disputes: dict[str, Any]) -> None:
    wc_dispute = first_string(wc.get("dispute_id"), wc.get("dispute"))
    provider_items = collect_provider_disputes(charge, disputes)
    provider_ids = {string_value(item.get("id")) for item in provider_items if is_present(item.get("id"))}
    charge_id = string_value(charge.get("id"))
    for item in provider_items:
        dispute_charge_id = provider_dispute_charge_id(item)
        if dispute_charge_id and charge_id and dispute_charge_id != charge_id:
            verdict.fail(
                f"provider dispute {string_value(item.get('id')) or '<unknown>'} points to charge {dispute_charge_id}, expected {charge_id}"
            )
    if not wc_dispute and not provider_ids:
        verdict.pass_("dispute state absent on both sides")
        return
    if wc_dispute and wc_dispute in provider_ids:
        verdict.pass_(f"dispute id {wc_dispute} matches provider")
        return
    if not wc_dispute and provider_ids:
        evidence = dispute_side_effect_evidence(wc, provider_items)
        if evidence:
            verdict.pass_(f"provider dispute {','.join(sorted(provider_ids))} has WC side-effect evidence without persisted dispute id ({'; '.join(evidence)})")
            return
        verdict.fail(f"provider dispute {','.join(sorted(provider_ids))} has no WC dispute id or dispute side-effect evidence")
        return
    verdict.fail(f"dispute mismatch WC={wc_dispute or '<empty>'} provider={','.join(sorted(provider_ids)) or '<empty>'}")


def compare_payout(verdict: Verdict, wc: dict[str, Any], balance: dict[str, Any], payout: dict[str, Any]) -> None:
    wc_payout = first_string(wc.get("payout_id"), wc.get("payout"))
    provider_payout = first_string(balance.get("payout") if balance else "", payout.get("id") if payout else "")
    if wc_payout:
        compare_equal(verdict, "payout id", wc_payout, provider_payout)
    elif provider_payout and payout:
        verdict.pass_(f"provider payout {provider_payout} is readable")
    else:
        verdict.pass_("payout state absent or not yet linked")


def compare_authorization(verdict: Verdict, wc: dict[str, Any], charge: dict[str, Any], intent: dict[str, Any]) -> None:
    currency = lower(first_string(charge.get("currency"), intent.get("currency"), wc.get("currency")))
    captured = bool(charge.get("captured"))
    expected_captured = wc.get("total") if captured else "0"
    compare_minor(verdict, "captured amount", expected_captured, currency, charge.get("amount_captured"), currency)

    if intent:
        status = lower(intent.get("status"))
        if captured and status in {"succeeded", "processing"}:
            verdict.pass_(f"intent status is compatible with captured charge ({status})")
        elif not captured and status in {"requires_capture", "requires_payment_method", "requires_confirmation", "requires_action"}:
            verdict.pass_(f"intent status is compatible with uncaptured authorization ({status})")
        elif status:
            verdict.fail(f"intent status {status} is not compatible with charge captured={captured}")


def compare(args: argparse.Namespace) -> Verdict:
    verdict = Verdict()
    wc = load_json(args.wc, required=True, label="WC order", verdict=verdict)
    charge = load_json(args.charge, required=True, label="Stripe charge", verdict=verdict)
    intent = load_json(args.intent, required=False, label="Stripe payment intent", verdict=verdict)
    balance = load_json(args.balance_transaction, required=False, label="Stripe balance transaction", verdict=verdict)
    refunds = load_json(args.refunds, required=False, label="Stripe refunds", verdict=verdict)
    disputes = load_json(args.disputes, required=False, label="Stripe disputes", verdict=verdict)
    payout = load_json(args.payout, required=False, label="Stripe payout", verdict=verdict)

    if verdict.blockers:
        return verdict
    if not isinstance(wc, dict) or not isinstance(charge, dict):
        verdict.block("WC order and Stripe charge inputs must be JSON objects")
        return verdict

    wc_currency = lower(wc.get("currency"))
    charge_currency = lower(charge.get("currency"))
    compare_equal(verdict, "charge id", wc.get("charge_id"), charge.get("id"))
    if is_present(wc.get("intent_id")) or is_present(charge.get("payment_intent")):
        compare_equal(verdict, "payment intent id", wc.get("intent_id"), first_string(charge.get("payment_intent"), intent.get("id") if isinstance(intent, dict) else ""))
    compare_minor(verdict, "charge amount", wc.get("total"), wc_currency, charge.get("amount"), charge_currency)
    compare_authorization(verdict, wc, charge, intent if isinstance(intent, dict) else {})
    compare_refunds(verdict, wc, charge, refunds if isinstance(refunds, dict) else {})
    compare_fee_and_net(verdict, wc, balance if isinstance(balance, dict) else {})
    compare_multi_currency(verdict, wc, balance if isinstance(balance, dict) else {}, charge)
    compare_dispute(verdict, wc, charge, disputes if isinstance(disputes, dict) else {})
    compare_payout(verdict, wc, balance if isinstance(balance, dict) else {}, payout if isinstance(payout, dict) else {})

    return verdict


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wc", required=True, help="WC order money-state JSON")
    parser.add_argument("--charge", required=True, help="Stripe charge JSON")
    parser.add_argument("--intent", help="Stripe payment intent JSON")
    parser.add_argument("--balance-transaction", help="Stripe balance transaction JSON")
    parser.add_argument("--refunds", help="Stripe refunds list JSON")
    parser.add_argument("--disputes", help="Stripe disputes list JSON")
    parser.add_argument("--payout", help="Stripe payout JSON")
    args = parser.parse_args()

    verdict = compare(args)
    verdict.print()
    if verdict.exit_code() == 0:
        print("PASS: WC money state matches Stripe raw-source state for the widened matrix.")
    elif verdict.exit_code() == 1:
        print("FAIL: WC money state diverges from Stripe raw-source state.")
    else:
        print("BLOCKED: financial reconciliation could not read required source data.")
    return verdict.exit_code()


if __name__ == "__main__":
    sys.exit(main())
