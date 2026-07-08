#!/usr/bin/env python3
"""Regression checks for merge-harness dependencies and source guards."""

from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
FINANCIAL_RECONCILE = REPO / "tools/woopayments-merge/financial-reconcile.sh"
NATIVE_CHARGE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-native-charge.php"

VERIFY_DEPENDENCIES = (
    "tools/woopayments-merge/bc-drift-gate.sh",
    "tools/woopayments-merge/bc-drift-baseline/endpoints.txt",
    "tools/woopayments-merge/bc-drift-baseline/hooks_filters.txt",
    "tools/woopayments-merge/bc-drift-baseline/persisted_data.txt",
    "tools/woopayments-merge/bc-drift-baseline/php_api.txt",
    "tools/woopayments-merge/bc-drift-baseline/scheduler.txt",
    "tools/woopayments-merge/bc-drift-baseline/tracks.txt",
    "tools/woopayments-merge/parity-diff.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.php",
    "tools/woopayments-merge/normalize-bucket-e-cross.py",
    "tools/woopayments-merge/perf-baseline.sh",
    "tools/woopayments-merge/perf-baseline.php",
    "tools/woopayments-merge/perf-baseline.json",
    "tools/woopayments-merge/financial-reconcile.sh",
    "tools/woopayments-merge/tracks-parity.sh",
    "tools/woopayments-merge/tracks-normalize.py",
    "tools/woopayments-merge/plugin-active-settings-gate.sh",
    "tools/woopayments-merge/plugin-active-settings.playwriter.mjs",
)


def is_tracked(path: str) -> bool:
    return (
        subprocess.run(
            ["git", "ls-files", "--error-unmatch", path],
            cwd=REPO,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        ).returncode
        == 0
    )


def test_verify_base_dependencies_are_tracked() -> None:
    missing = [path for path in VERIFY_DEPENDENCIES if not is_tracked(path)]

    assert missing == []


def test_financial_reconcile_dispute_id_extractor_requires_provider_id_shape() -> None:
    source = FINANCIAL_RECONCILE.read_text(encoding="utf-8")

    assert "$find_provider_id_meta_like" in source
    assert "'dispute_id'               => $find_provider_id_meta_like( array( 'dispute' ), array( 'du', 'dp' ) )," in source


def test_native_charge_driver_projects_multicurrency_orders_in_cli_context() -> None:
    source = NATIVE_CHARGE_DRIVER.read_text(encoding="utf-8")

    assert "MultiCurrencyFrontendCurrenciesController" in source
    assert "MultiCurrencyFrontendPricesController" in source
    assert "MultiCurrencyProjectionServiceFactory" in source
    assert "MultiCurrencyRequestContext" in source
    assert "should_register_frontend_hooks(): bool" in source
    assert "set_request_context( $frontend_request_context )" in source
    assert "set_frontend_projection_service" in source
    assert "create_frontend_projection_service" in source
    assert "set_price_projection_service" in source
    assert "create_price_projection_service" in source
    assert "$frontend_currencies_controller->register();" in source
    assert "$frontend_prices_controller->register();" in source
    assert "$order->set_currency( $selected_currency );" in source
    assert "$frontend_prices_controller->add_order_meta( $order->get_id(), $order );" in source


def test_native_charge_driver_forces_store_currency_when_no_currency_is_requested() -> None:
    source = NATIVE_CHARGE_DRIVER.read_text(encoding="utf-8")

    assert "$store_currency = strtoupper( (string) get_option( 'woocommerce_currency' ) );" in source
    assert "wcpay_multi_currency_should_return_store_currency" in source
    assert "wcpay_multi_currency_should_convert_product_price" in source
    assert "add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );" in source
    assert "add_filter( 'wcpay_multi_currency_should_convert_product_price', '__return_false' );" in source
    assert "$order->set_currency( $store_currency );" in source
    assert "$order->delete_meta_data( '_wcpay_multi_currency_order_exchange_rate' );" in source


def main() -> None:
    test_verify_base_dependencies_are_tracked()
    test_financial_reconcile_dispute_id_extractor_requires_provider_id_shape()
    test_native_charge_driver_projects_multicurrency_orders_in_cli_context()
    test_native_charge_driver_forces_store_currency_when_no_currency_is_requested()
    print("PASS test_verify_base_dependencies_are_tracked")


if __name__ == "__main__":
    main()
