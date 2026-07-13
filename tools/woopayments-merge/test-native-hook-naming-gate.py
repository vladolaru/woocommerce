#!/usr/bin/env python3
"""Target-only regression checks for native WooPayments hook naming."""

from __future__ import annotations

import re
from collections import Counter
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
PLUGIN = REPO / "plugins/woocommerce"
HOOK_SHAPE_DRIVER = REPO / "tools/woopayments-merge/hook-shape-parity.php"

GENERIC_HOOKS = {
    "woocommerce_native_payments_enabled": 1,
    "woocommerce_native_payments_shadow_mode_enabled": 1,
    "woocommerce_native_payments_shadow_mode_log_full_surfaces": 1,
    "woocommerce_native_payments_shadow_mode_allow_live_reads": 1,
}

PROVIDER_HOOKS = {
    "woocommerce_woopayments_failed_webhook_events": 1,
    "woocommerce_woopayments_live_mode": 1,
    "woocommerce_woopayments_gateway_duplicate_payment_method_ids": 1,
    "woocommerce_woopayments_is_recurring_payment": 2,
    "woocommerce_woopayments_related_subscriptions_for_order": 1,
    "woocommerce_woopayments_subscriptions_for_renewal_order": 1,
    "woocommerce_woopayments_woopay_blog_id": 1,
    "woocommerce_woopayments_woopay_blog_token": 1,
    "woocommerce_woopayments_express_checkout_enabled_methods": 1,
    "woocommerce_woopayments_express_checkout_product_types": 1,
    "woocommerce_woopayments_express_checkout_is_product_supported": 1,
    "woocommerce_woopayments_express_checkout_product_data": 1,
    "woocommerce_woopayments_fraud_services_config": 1,
    "woocommerce_woopayments_soft_cutover_enabled": 1,
    "woocommerce_woopayments_mandatory_cutover_enabled": 1,
    "woocommerce_woopayments_cutover_transport_ready": 1,
    "woocommerce_woopayments_cutover_admin_surfaces_ready": 1,
    "woocommerce_woopayments_cutover_pending_event_types": 1,
    "woocommerce_woopayments_cutover_pending_operational_queue_hooks": 1,
    "woocommerce_woopayments_cutover_preflight_failures": 1,
}

STATE_KEYS = {
    "woocommerce_native_woopayments_cutover_normalization_version": (
        "src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverNormalizationRunner.php"
    ),
    "woocommerce_native_woopayments_last_webhook_fetch": (
        "src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityService.php"
    ),
    "woocommerce_native_woopayments_woopay_webhook_id": (
        "src/Internal/Payments/Providers/WooPayments/WooPay/WooPaymentsWooPayOrderStatusSync.php"
    ),
    "woocommerce_native_woopayments_woopay_webhook_lock": (
        "src/Internal/Payments/Providers/WooPayments/WooPay/WooPaymentsWooPayOrderStatusSync.php"
    ),
    "woocommerce_native_woopayments_file_purpose_": (
        "src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php"
    ),
    "woocommerce_woopayments_native_cutover_status": (
        "src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php"
    ),
}

CONSTANT_RE = re.compile(
    r"\bconst\s+(?P<name>[A-Z][A-Z0-9_]*)\s*=\s*"
    r"(?P<quote>['\"])(?P<value>woocommerce_[^'\"]+)(?P=quote)\s*;"
)
APPLY_FILTER_RE = re.compile(
    r"\bapply_filters\s*\(\s*(?:"
    r"(?P<quote>['\"])(?P<literal>woocommerce_[^'\"]+)(?P=quote)"
    r"|self::(?P<constant>[A-Z][A-Z0-9_]*))",
    re.DOTALL,
)
NATIVE_HOOK_PREFIXES = (
    "woocommerce_native_payments_",
    "woocommerce_native_woopayments_",
    "woocommerce_woopayments_",
)


def production_php_files() -> tuple[Path, ...]:
    return tuple(
        sorted(
            path
            for root in (PLUGIN / "src", PLUGIN / "includes")
            for path in root.rglob("*.php")
        )
    )


def native_filter_emissions() -> Counter[str]:
    emissions: Counter[str] = Counter()

    for path in production_php_files():
        source = path.read_text(encoding="utf-8")
        constants = {
            match.group("name"): match.group("value")
            for match in CONSTANT_RE.finditer(source)
        }
        for match in APPLY_FILTER_RE.finditer(source):
            hook_name = match.group("literal")
            if hook_name is None:
                hook_name = constants.get(match.group("constant"))
            if hook_name is not None and hook_name.startswith(NATIVE_HOOK_PREFIXES):
                emissions[hook_name] += 1

    return emissions


def obsolete_provider_hooks() -> set[str]:
    native_payments_provider = "woocommerce_" + "native_payments_" + "woopayments_"
    native_provider = "woocommerce_" + "native_" + "woopayments_"
    provider_native = "woocommerce_" + "woopayments_" + "native_"

    return {
        native_payments_provider + "failed_webhook_events",
        native_payments_provider + "live_mode",
        native_provider + "gateway_duplicate_payment_method_ids",
        native_provider + "is_recurring_payment",
        native_provider + "related_subscriptions_for_order",
        native_provider + "subscriptions_for_renewal_order",
        native_provider + "woopay_blog_id",
        native_provider + "woopay_blog_token",
        native_provider + "express_checkout_enabled_methods",
        native_provider + "express_checkout_product_types",
        native_provider + "express_checkout_is_product_supported",
        native_provider + "express_checkout_product_data",
        provider_native + "fraud_services_config",
        provider_native + "soft_cutover_enabled",
        provider_native + "mandatory_cutover_enabled",
        provider_native + "transport_ready",
        provider_native + "admin_surfaces_ready",
        provider_native + "cutover_pending_event_types",
        provider_native + "cutover_pending_operational_queue_hooks",
        provider_native + "cutover_preflight_failures",
    }


def old_name_scan_files() -> tuple[Path, ...]:
    plugin_files = (
        *production_php_files(),
        *(PLUGIN / "tests/php").rglob("*.php"),
    )
    tool_root = REPO / "tools/woopayments-merge"
    tool_files = (
        path
        for suffix in ("*.php", "*.py", "*.sh")
        for path in tool_root.glob(suffix)
    )
    return tuple(sorted({*plugin_files, *tool_files}))


def test_target_native_hook_inventory_has_24_unique_filters_at_25_emission_sites() -> None:
    expected = Counter({**GENERIC_HOOKS, **PROVIDER_HOOKS})
    actual = native_filter_emissions()

    assert len(GENERIC_HOOKS) == 4
    assert len(PROVIDER_HOOKS) == 20
    assert len(expected) == 24
    assert expected.total() == 25
    assert actual == expected, (
        "Target native hook inventory mismatch. "
        f"Missing or under-counted: {expected - actual}; "
        f"unexpected or over-counted: {actual - expected}"
    )


def test_obsolete_provider_hook_spellings_are_absent_from_owned_code() -> None:
    forbidden = obsolete_provider_hooks()
    offenders = {
        str(path.relative_to(REPO)): sorted(name for name in forbidden if name in source)
        for path in old_name_scan_files()
        if (source := path.read_text(encoding="utf-8"))
        if any(name in source for name in forbidden)
    }

    assert not offenders, f"Obsolete native provider hook spellings remain: {offenders}"


def test_six_non_hook_state_key_families_remain_at_their_production_owners() -> None:
    emissions = native_filter_emissions()

    assert len(STATE_KEYS) == 6
    for state_key, relative_path in STATE_KEYS.items():
        source = (PLUGIN / relative_path).read_text(encoding="utf-8")
        assert source.count(state_key) == 1, (
            f"Expected unchanged state key {state_key!r} exactly once in {relative_path}"
        )
        assert state_key not in emissions, f"State key {state_key!r} must not be emitted as a filter"


def test_target_only_native_hooks_stay_out_of_reference_hook_shape_parity() -> None:
    parity_source = HOOK_SHAPE_DRIVER.read_text(encoding="utf-8")

    for hook_name in (*GENERIC_HOOKS, *PROVIDER_HOOKS):
        assert hook_name not in parity_source
