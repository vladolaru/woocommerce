#!/usr/bin/env python3
"""
A4 native WooPayments admin surface gate.

This verifies the native Settings > Payments WooPayments route chunks,
single-registry source claim, and admin shell parity guardrails for the A4
merge slice without changing the broader measured bundle-size gate semantics.
"""

from __future__ import annotations

import argparse
import gzip
import json
import re
import sys
from pathlib import Path
from typing import Any


EXPECTED_CHUNKS = {
    "settings": "settings-payments-woopayments-settings",
    "overview": "settings-payments-woopayments-overview",
    "payouts": "settings-payments-woopayments-payouts",
    "money-movement": "settings-payments-woopayments-money-movement",
    "card-readers": "settings-payments-woopayments-card-readers",
    "capital": "settings-payments-woopayments-capital",
    "documents": "settings-payments-woopayments-documents",
    "reports": "settings-payments-woopayments-reports",
}

PLUGIN_BASELINE_FILES = {
    "admin-index-js": "dist/index.js",
    "admin-index-css": "dist/index.css",
    "settings-js": "dist/settings.js",
    "settings-css": "dist/settings.css",
    "overview-js": "dist/chunks/wcpay-overview.js",
    "payouts-js": "dist/chunks/wcpay-payouts.js",
    "money-movement-js": "dist/chunks/wcpay-money-movement.js",
    "card-readers-js": "dist/chunks/wcpay-card-readers.js",
    "capital-js": "dist/chunks/wcpay-capital.js",
}

SOURCE_EXTENSIONS = {".js", ".jsx", ".ts", ".tsx"}
FORBIDDEN_REGISTRY_TOKENS = ("createRegistry", "RegistryProvider", "useRegistry")
PLUGIN_ROUTE_RE = re.compile(r"path\s*:\s*(['\"])(/payments(?:/|$)[^'\"]*)\1")
PLUGIN_ADMIN_NAVIGATION_ROUTE_RE = re.compile(r"(['\"])(/payments(?:/|$)[^'\"]*)\1")
EXPECTED_ADMIN_NAVIGATION_ROUTES = {
    "onboarding": "/woopayments/onboarding",
    "overview": "/woopayments/overview",
    "payouts": "/woopayments/payouts",
    "transactions": "/woopayments/transactions",
    "reports": "/woopayments/reports",
    "disputes": "/woopayments/disputes",
    "card-readers": "/woopayments/card-readers",
    "capital": "/woopayments/loans",
    "documents": "/woopayments/documents",
    "settings": "/woopayments/settings",
}
EXPECTED_LEGACY_REDIRECT_ROUTES = {
    "/payments/connect",
    "/payments/onboarding",
    "/payments/onboarding/kyc",
    "/payments/overview",
    "/payments/deposits",
    "/payments/deposits/details",
    "/payments/payouts",
    "/payments/payouts/details",
    "/payments/transactions",
    "/payments/transactions/details",
    "/payments/reports",
    "/payments/disputes",
    "/payments/disputes/details",
    "/payments/disputes/challenge",
    "/payments/fraud-protection",
    "/payments/multi-currency-setup",
    "/payments/additional-payment-methods",
    "/payments/card-readers",
    "/payments/loans",
    "/payments/documents",
    "/payments/settings",
}
FORBIDDEN_LEGACY_REDIRECT_ROUTES = set()
ACCOUNT_SERVICE_ADMIN_STATE_METHODS = (
    "is_gateway_enabled",
    "is_account_rejected",
    "is_account_under_review",
    "is_details_submitted",
    "has_valid_account_for_admin_navigation",
    "is_card_present_eligible",
    "has_card_readers_available",
    "has_previous_capital_loans",
    "is_documents_enabled",
    "is_reports_enabled",
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Verify A4 native WooPayments admin source and chunk split."
    )
    parser.add_argument(
        "--repo",
        required=True,
        help="Path to a WooCommerce core repository checkout.",
    )
    parser.add_argument(
        "--out",
        help="Optional path for JSON evidence output.",
    )
    parser.add_argument(
        "--plugin-repo",
        help="Optional WooPayments plugin repo used to capture the reference admin baseline.",
    )
    return parser.parse_args()


def progress(message: str) -> None:
    print(f"[a4-admin] {message}")


def rel(path: Path, repo: Path) -> str:
    return path.relative_to(repo).as_posix()


def source_files(*roots: Path) -> list[Path]:
    files: list[Path] = []
    for root in roots:
        if not root.is_dir():
            continue
        files.extend(
            path
            for path in root.rglob("*")
            if path.is_file() and path.suffix in SOURCE_EXTENSIONS
        )
    return sorted(files)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def measure_chunk(path: Path) -> dict[str, Any]:
    data = path.read_bytes()
    return {
        "status": "present",
        "path": path.as_posix(),
        "raw_bytes": len(data),
        "gzip_bytes": len(gzip.compress(data, compresslevel=9)),
    }


def add_failure(failures: list[str], message: str) -> None:
    failures.append(message)
    print(f"FAIL: {message}")


def summarize_measurements(entries: dict[str, Any]) -> dict[str, int]:
    present = [
        entry
        for entry in entries.values()
        if isinstance(entry, dict) and entry.get("status") == "present"
    ]

    return {
        "file_count": len(present),
        "raw_bytes": sum(int(entry.get("raw_bytes", 0)) for entry in present),
        "gzip_bytes": sum(int(entry.get("gzip_bytes", 0)) for entry in present),
    }


def check_required_paths(repo: Path, failures: list[str]) -> dict[str, Path]:
    paths = {
        "woocommerce": repo / "plugins/woocommerce",
        "routes": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx",
        "settings_payments": repo
        / "plugins/woocommerce/client/admin/client/settings-payments",
        "woopayments": repo / "plugins/woocommerce/client/admin/client/woopayments",
        "chunks": repo / "plugins/woocommerce/assets/client/admin/chunks",
        "admin_navigation_controller": repo
        / "plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php",
        "account_service": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php",
        "api_client": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php",
        "pm_promotions_get_request": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsGetPmPromotionsRequest.php",
        "pm_promotions_activate_request": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsActivatePmPromotionRequest.php",
        "badge_service": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeService.php",
        "pm_promotions_service": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsService.php",
        "rest_controller": repo
        / "plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php",
        "settings_service": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php",
        "pm_promotions_store_name": repo
        / "plugins/woocommerce/client/admin/client/woopayments/promotions/data/store-name.ts",
        "pm_promotions_actions": repo
        / "plugins/woocommerce/client/admin/client/woopayments/promotions/data/actions.ts",
        "pm_promotions_spotlight": repo
        / "plugins/woocommerce/client/admin/client/woopayments/promotions/spotlight.tsx",
        "payment_methods_list": repo
        / "plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx",
        "settings_page": repo
        / "plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx",
        "overview_page": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx",
        "overview_data": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts",
        "overview_utils": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/overview/utils.ts",
        "overview_account_balances": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx",
        "overview_payouts_card": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/payouts-overview-card.tsx",
        "payouts_page": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx",
        "payout_details": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx",
        "transactions_page": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transactions-page.tsx",
        "transaction_details": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx",
        "transaction_card_reader_fee_details": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-card-reader-fee-details.tsx",
        "transaction_detail_sections": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx",
        "transaction_timeline": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-timeline.tsx",
        "money_movement_data": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts",
        "test_mode_notice": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/test-mode-notice.tsx",
        "disputes_page": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx",
        "dispute_details": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-details.tsx",
        "dispute_challenge": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx",
        "dispute_evidence_form": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx",
        "dispute_evidence_fields": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts",
        "dispute_evidence_cover_letter": repo
        / "plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts",
        "payment_details_rest_controller": repo
        / "plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php",
        "bootstrap": repo / "plugins/woocommerce/includes/class-woocommerce.php",
    }

    for label, path in paths.items():
        if label in {"settings_payments", "woopayments", "chunks", "woocommerce"}:
            if not path.is_dir():
                add_failure(failures, f"required directory missing: {rel(path, repo)}")
        elif not path.is_file():
            add_failure(failures, f"required file missing: {rel(path, repo)}")

    return paths


def check_tokens(
    repo: Path,
    path: Path,
    required_tokens: dict[str, str],
    failures: list[str],
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "path": rel(path, repo),
        "required_tokens": {},
    }

    if not path.is_file():
        add_failure(failures, f"required file missing: {rel(path, repo)}")
        return result

    source = read_text(path)
    for label, token in required_tokens.items():
        present = token in source
        result["required_tokens"][label] = present
        if not present:
            add_failure(failures, f"{rel(path, repo)} missing token {label}: {token}")

    return result


def check_absent_tokens(
    repo: Path,
    path: Path,
    forbidden_tokens: dict[str, str],
    failures: list[str],
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "path": rel(path, repo),
        "forbidden_tokens_absent": {},
    }

    if not path.is_file():
        add_failure(failures, f"required file missing: {rel(path, repo)}")
        return result

    source = read_text(path)
    for label, token in forbidden_tokens.items():
        absent = token not in source
        result["forbidden_tokens_absent"][label] = absent
        if not absent:
            add_failure(
                failures,
                f"{rel(path, repo)} contains forbidden token {label}: {token}",
            )

    return result


def check_pm_promotions_source(repo: Path, paths: dict[str, Path], failures: list[str]) -> dict[str, Any]:
    progress("Checking native PM promotions source contract")
    result: dict[str, Any] = {
        "api_client": check_tokens(
            repo,
            paths["api_client"],
            {
                "get-method": "function get_pm_promotions",
                "activate-method": "function activate_pm_promotion",
                "get-hook": "wcpay_get_pm_promotions_request",
                "activate-hook": "wcpay_activate_pm_promotion_request",
            },
            failures,
        ),
        "get_request": check_tokens(
            repo,
            paths["pm_promotions_get_request"],
            {
                "class": "class WooPaymentsGetPmPromotionsRequest",
                "platform-api": "API = 'payment_method_promotions'",
                "legacy-alias": "WCPay\\Core\\Server\\Request\\Get_PM_Promotions",
                "store-context": "function set_store_context_params",
            },
            failures,
        ),
        "activate_request": check_tokens(
            repo,
            paths["pm_promotions_activate_request"],
            {
                "class": "class WooPaymentsActivatePmPromotionRequest",
                "platform-api": "API = 'payment_method_promotions'",
                "activate-path": "rawurlencode( $id ) . '/activate'",
                "legacy-alias": "WCPay\\Core\\Server\\Request\\Activate_PM_Promotion",
                "promotion-id": "function get_id",
            },
            failures,
        ),
        "service": check_tokens(
            repo,
            paths["pm_promotions_service"],
            {
                "class": "class WooPaymentsPmPromotionsService",
                "cache-key": "PROMOTIONS_CACHE_KEY = 'wcpay_pm_promotions'",
                "dismissals-option": "PROMOTION_DISMISSALS_OPTION = '_wcpay_pm_promotion_dismissals'",
                "visible-promotions": "function get_visible_promotions",
                "activate": "function activate_promotion",
                "dismiss": "function dismiss_promotion",
                "settings-save-activation": "function maybe_activate_promotion_for_payment_method",
                "manage-woocommerce-gate": "current_user_can( 'manage_woocommerce' )",
                "active-discount-filter": "payment_method_has_active_discount",
            },
            failures,
        ),
        "rest_controller": check_tokens(
            repo,
            paths["rest_controller"],
            {
                "route-get": "/payments/pm-promotions",
                "route-activate": "/payments/pm-promotions/(?P<promotion_id>[^/]+)/activate",
                "route-dismiss": "/payments/pm-promotions/(?P<promotion_id>[^/]+)/dismiss",
                "get-callback": "get_native_pm_promotions",
                "activate-callback": "activate_native_pm_promotion",
                "dismiss-callback": "dismiss_native_pm_promotion",
                "id-validation": "validate_pm_promotion_id",
            },
            failures,
        ),
        "settings_service": check_tokens(
            repo,
            paths["settings_service"],
            {
                "source-backed-settings-field": "'pm_promotions'",
                "service-injection": "WooPaymentsPmPromotionsService",
                "settings-save-activation": "maybe_activate_promotion_for_payment_method",
            },
            failures,
        ),
        "frontend_store": check_tokens(
            repo,
            paths["pm_promotions_store_name"],
            {
                "store-name": "wc/payments/pmPromotions",
            },
            failures,
        ),
        "frontend_actions": check_tokens(
            repo,
            paths["pm_promotions_actions"],
            {
                "activate-endpoint": "/activate",
                "dismiss-endpoint": "/dismiss",
                "success-activation-notice": "Promotion activated successfully.",
                "success-dismissal-notice": "Promotion dismissed.",
                "invalidate-resolution": "invalidateResolution( 'getPmPromotions'",
            },
            failures,
        ),
        "spotlight": check_tokens(
            repo,
            paths["pm_promotions_spotlight"],
            {
                "component": "SpotlightPromotion",
                "view-track": "wcpay_payment_method_promotion_view",
                "activate-track": "wcpay_payment_method_promotion_activate_click",
                "dismiss-track": "wcpay_payment_method_promotion_dismiss_click",
                "native-route-source": "getNativeRoutePath",
                "safe-url": "getSafeUrl",
            },
            failures,
        ),
        "row_badge": check_tokens(
            repo,
            paths["payment_methods_list"],
            {
                "component": "PmPromotionBadge",
                "discount-precedence": "discountBadgeText",
                "badge-filter": "promotion.type === 'badge'",
                "promotion-prop": "pmPromotions",
            },
            failures,
        ),
        "spotlight_mounts": {},
    }

    mount_paths = {
        "settings": paths["settings_page"],
        "overview": paths["overview_page"],
        "payouts": paths["payouts_page"],
        "transactions": paths["transactions_page"],
        "disputes": paths["disputes_page"],
    }
    for label, path in mount_paths.items():
        result["spotlight_mounts"][label] = check_tokens(
            repo,
            path,
            {
                "import": "SpotlightPromotion",
                "mount": "<SpotlightPromotion />",
            },
            failures,
        )

    return result


def check_overview_financial_summary_source(repo: Path, paths: dict[str, Path], failures: list[str]) -> dict[str, Any]:
    progress("Checking native Overview financial summary source contract")
    return {
        "account_balances": check_tokens(
            repo,
            paths["overview_account_balances"],
            {
                "currency-selector": "Balance currency",
                "total-balance": "Total balance",
                "available-funds": "Available funds",
                "instant-payout-notice": "Get %1$s via instant payout.",
                "instant-payout-button": "Get %s now",
                "instant-payout-modal": "Pay out %s now",
                "instant-payout-success": "Instant payout for %s in transit.",
                "instant-payout-submit-prop": "onInstantPayoutSubmit",
            },
            failures,
        ),
        "overview_data": check_tokens(
            repo,
            paths["overview_data"],
            {
                "instant-payout-submit": "submitWooPaymentsInstantDeposit",
                "instant-payout-type": "type: 'instant'",
            },
            failures,
        ),
        "payouts_card": check_tokens(
            repo,
            paths["overview_payouts_card"],
            {
                "selected-currency": "getSelectedBalanceCurrency",
                "schedule-copy": "Available funds are automatically dispatched",
                "schedule-docs": "payout-schedule/",
                "suspended-copy": "Your payouts are temporarily suspended.",
                "pending-copy": "You have no funds available.",
                "failed-payout-copy": "Payouts are currently paused because a recent payout failed. Please",
                "payout-details-link": "View payout %s details",
                "status-chip": "woocommerce-woopayments-overview__status-chip",
                "change-schedule": "Change payout schedule",
            },
            failures,
        ),
        "overview_page": check_tokens(
            repo,
            paths["overview_page"],
            {
                "selected-currency-state": "selectedCurrency",
                "selected-currency-helper": "getSelectedBalanceCurrency",
                "recent-deposits-call": "getWooPaymentsRecentDeposits",
                "balance-currency-handler": "onCurrencyChange",
                "instant-payout-submit": "submitWooPaymentsInstantDeposit",
                "instant-payout-refresh": "reloadOverviewAndPayouts",
                "payout-selected-currency-prop": "selectedCurrency={ selectedCurrency || undefined }",
            },
            failures,
        ),
        "utils": check_tokens(
            repo,
            paths["overview_utils"],
            {
                "currency-options": "getBalanceCurrencyOptions",
                "selected-currency": "getSelectedBalanceCurrency",
                "instant-balance": "getInstantBalanceForCurrency",
                "monthly-anchor": "getMonthlyAnchorLabel",
                "status-class": "getPayoutStatusClassName",
            },
            failures,
        ),
    }


def check_money_detail_source(repo: Path, paths: dict[str, Path], failures: list[str]) -> dict[str, Any]:
    progress("Checking native money-detail source contract")
    return {
        "payout_details": check_tokens(
            repo,
            paths["payout_details"],
            {
                "bank-reference-copy": "Copy bank reference ID to clipboard",
                "copy-announcement": "Bank reference ID copied.",
                "instant-payout-detection": "payout?.automatic === false",
                "instant-payout-no-history": "We're unable to show transaction history on instant payouts.",
                "instant-payout-doc-anchor": "instant-payouts/#transactions",
                "history-handoff": "View all transactions in this %s",
                "deposit-filter": "deposit_id",
            },
            failures,
        ),
        "payment_details_rest_controller": check_tokens(
            repo,
            paths["payment_details_rest_controller"],
            {
                "timeline-route": "/payments/timeline/(?P<intention_id>\\w+)",
                "timeline-callback": "get_timeline",
                "timeline-api-client": "api_client->get_timeline",
                "manual-fraud-outcome": "_wcpay_fraud_outcome_manual_entry",
                "fraud-review-event": "fraud_outcome_review",
            },
            failures,
        ),
        "money_movement_data": check_tokens(
            repo,
            paths["money_movement_data"],
            {
                "timeline-helper": "getWooPaymentsTimeline",
                "timeline-path": "/timeline/",
                "reader-charge-helper": "getWooPaymentsReaderChargeSummary",
                "reader-charge-path": "/readers/charges/",
                "charge-helper": "getWooPaymentsCharge",
                "payment-intent-helper": "getWooPaymentsPaymentIntent",
            },
            failures,
        ),
        "transaction_details": check_tokens(
            repo,
            paths["transaction_details"],
            {
                "timeline-helper": "getWooPaymentsTimeline",
                "payment-heading": "Payment details",
                "summary-section": "WooPaymentsPaymentSummarySection",
                "missing-order-notice": "WooPaymentsMissingOrderNotice",
                "identifiers-section": "WooPaymentsPaymentIdentifiersSection",
                "payment-method-card": "WooPaymentsPaymentMethodDetailsSection",
                "reader-fee-route": "isCardReaderFeeRoute",
                "reader-fee-component": "WooPaymentsCardReaderFeeDetails",
                "timeline-component": "WooPaymentsTransactionTimeline",
                "detail-test-mode-notice": "isDetailsView",
                "payment-intent-id-path": "isPaymentIntentId",
                "charge-id-path": "isChargeId",
                "transaction-id-path": "isTransactionId",
            },
            failures,
        ),
        "transaction_card_reader_fee_details": check_tokens(
            repo,
            paths["transaction_card_reader_fee_details"],
            {
                "reader-helper": "getWooPaymentsReaderChargeSummary",
                "heading": "Card readers",
                "reader-id": "Reader id",
                "status": "Status",
                "transactions": "Transactions",
                "fee": "Fee",
                "error-copy": "Readers details not loaded",
                "download-button": "Download",
            },
            failures,
        ),
        "transaction_timeline": check_tokens(
            repo,
            paths["transaction_timeline"],
            {
                "timeline-heading": "Timeline",
                "captured-status": "Payment status changed to Paid.",
                "captured-copy": "A payment of %s was successfully charged.",
                "refund-copy": "A payment of %s was successfully refunded.",
                "dispute-opened": "A dispute was opened for %s.",
                "manual-approve": "Payment was approved by %s",
                "manual-block": "Payment was blocked by %s",
            },
            failures,
        ),
        "transaction_detail_sections": check_tokens(
            repo,
            paths["transaction_detail_sections"],
            {
                "summary-heading": "Summary",
                "sales-channel": "Sales channel",
                "missing-order-copy": "This payment is not linked to a WooCommerce order.",
                "identifiers-heading": "Identifiers",
                "payment-id": "Payment ID",
                "charge-id": "Charge ID",
                "payment-method": "Payment method",
                "risk-evaluation": "Risk evaluation",
                "net-amount": "Net amount",
                "payment-method-card": "WooPaymentsPaymentMethodDetailsSection",
                "customer-link": "transaction.order?.customer_url",
                "order-link": "order?.url",
                "subscriptions": "transaction.order.subscriptions",
                "formatted-address-sanitizer": "replace( /<[^>]*>/g, '' )",
                "non-card-bank-name": "Bank name",
                "non-card-iban": "IBAN",
                "non-card-verified-name": "Verified name",
            },
            failures,
        ),
        "test_mode_notice": check_tokens(
            repo,
            paths["test_mode_notice"],
            {
                "payments-page": "payments:",
                "detail-test-mode-prop": "isDetailsView",
                "detail-test-mode-copy": "account is currently in test mode",
                "settings-route": "getSettingsPaymentsProviderRouteUrl",
            },
            failures,
        ),
        "dispute_details": check_tokens(
            repo,
            paths["dispute_details"],
            {
                "redirect-component": "WooPaymentsDisputeDetailsRedirect",
                "dispute-fetch": "getWooPaymentsDispute",
                "transaction-route": "getTransactionDetailsRoute",
                "settings-route": "getSettingsPaymentsProviderRouteUrl",
                "no-standalone-render": "return null",
            },
            failures,
        ),
        "dispute_challenge": check_tokens(
            repo,
            paths["dispute_challenge"],
            {
                "challenge-component": "WooPaymentsDisputeChallengePage",
                "file-details": "getWooPaymentsDisputeFileDetails",
                "file-limit-copy": "full 4.5 MB evidence limit",
                "loaded-message": "Dispute evidence form loaded.",
            },
            failures,
        ),
        "dispute_evidence_form": check_tokens(
            repo,
            paths["dispute_evidence_form"],
            {
                "basics-step": "Let's gather the basics",
                "shipping-step": "Add your shipping details",
                "review-step": "Review your cover letter",
                "recommended-documents": "Recommended documents",
                "autosave-continue": "handleContinue",
                "save-draft": "Save draft",
                "submit-evidence": "Submit evidence",
                "upload-wait": "Please wait until file upload is finished",
                "final-submit-confirm": "Are you sure you’re ready to submit this evidence? Evidence submissions are final.",
                "success-confirmation": "Thanks for sharing your response!",
                "visa-heading": "Dispute information",
                "visa-textarea": "Why do you disagree with this dispute?",
            },
            failures,
        ),
        "dispute_evidence_fields": check_tokens(
            repo,
            paths["dispute_evidence_fields"],
            {
                "recommended-helper": "getRecommendedDocumentFields",
                "shipping-helper": "needsShipping",
                "visa-helper": "isVisaComplianceDispute",
                "refund-receipt-supported-key": "duplicate_charge_documentation",
            },
            failures,
        ),
        "dispute_evidence_fields_absent": check_absent_tokens(
            repo,
            paths["dispute_evidence_fields"],
            {
                "unsupported-refund-receipt-key": "refund_receipt_documentation",
            },
            failures,
        ),
        "dispute_evidence_cover_letter": check_tokens(
            repo,
            paths["dispute_evidence_cover_letter"],
            {
                "cover-letter-generator": "generateDisputeCoverLetter",
                "formal-subject": "Subject: Chargeback Dispute",
                "formal-greeting": "Dear Dispute Resolution Team",
                "formal-attachments": "To support our case",
                "shipping-tracking": "Tracking number",
            },
            failures,
        ),
    }


def check_admin_navigation_source(repo: Path, paths: dict[str, Path], failures: list[str]) -> dict[str, Any]:
    progress("Checking native admin navigation source contract")
    result: dict[str, Any] = {
        "controller": {
            "path": rel(paths["admin_navigation_controller"], repo),
            "required_tokens": {},
            "expected_routes": {},
            "legacy_redirect_routes": [],
            "unexpected_plugin_era_routes": [],
            "forbidden_legacy_redirect_routes": [],
        },
        "account_service": {
            "path": rel(paths["account_service"], repo),
            "required_methods": {},
        },
        "api_client": {
            "path": rel(paths["api_client"], repo),
            "required_methods": {},
        },
        "badge_service": {
            "path": rel(paths["badge_service"], repo),
            "required_tokens": {},
        },
        "bootstrap": {
            "path": rel(paths["bootstrap"], repo),
            "registered": False,
        },
    }

    controller = paths["admin_navigation_controller"]
    if controller.is_file():
        source = read_text(controller)
        required_tokens = {
            "class": "class WooPaymentsAdminNavigationController",
            "hook-interface": "implements RegisterHooksInterface",
            "runtime-arbiter": "NativePaymentsRuntimeArbiter",
            "account-service": "WooPaymentsAccountService",
            "admin-menu-hook": "admin_menu",
            "native-runtime-gate": "should_native_register()",
            "capability-gate": "current_user_can( self::CAPABILITY )",
            "settings-url-helper": "Utils::wc_payments_settings_url",
            "payments-menu-from": "Payments::FROM_PAYMENTS_MENU_ITEM",
            "provider-enabled-menu-gate": "is_gateway_enabled()",
            "badge-service": "WooPaymentsAdminMenuBadgeService",
            "badge-format": "wcpay-menu-badge awaiting-mod",
            "disputes-filter": "'filter' => 'awaiting_response'",
            "reports-menu-gate": "is_reports_enabled()",
            "documents-menu-gate": "is_documents_enabled()",
        }
        for label, token in required_tokens.items():
            present = token in source
            result["controller"]["required_tokens"][label] = present
            if not present:
                add_failure(
                    failures,
                    f"native admin navigation controller missing token {label}: {token}",
                )

        for label, route in EXPECTED_ADMIN_NAVIGATION_ROUTES.items():
            present = route in source
            result["controller"]["expected_routes"][label] = {
                "route": route,
                "present": present,
            }
            if not present:
                add_failure(
                    failures,
                    f"native admin navigation controller missing route {label}: {route}",
                )

        for line_number, line in enumerate(source.splitlines(), start=1):
            for match in PLUGIN_ADMIN_NAVIGATION_ROUTE_RE.finditer(line):
                route = match.group(2)
                found = {
                    "path": rel(controller, repo),
                    "line": line_number,
                    "route": route,
                }
                if route in EXPECTED_LEGACY_REDIRECT_ROUTES:
                    result["controller"]["legacy_redirect_routes"].append(found)
                    continue

                if route in FORBIDDEN_LEGACY_REDIRECT_ROUTES:
                    result["controller"]["forbidden_legacy_redirect_routes"].append(found)
                    add_failure(
                        failures,
                        "Reports legacy redirect found before native surface exists: "
                        f"{found['path']}:{line_number} {found['route']}",
                    )
                    continue

                result["controller"]["unexpected_plugin_era_routes"].append(found)
                add_failure(
                    failures,
                    "unexpected plugin-era admin navigation route found: "
                    f"{found['path']}:{line_number} {found['route']}",
                )
    else:
        add_failure(
            failures,
            f"native WooPayments admin navigation controller missing: {rel(controller, repo)}",
        )

    account_service = paths["account_service"]
    if account_service.is_file():
        source = read_text(account_service)
        for method in ACCOUNT_SERVICE_ADMIN_STATE_METHODS:
            token = f"function {method}"
            present = token in source
            result["account_service"]["required_methods"][method] = present
            if not present:
                add_failure(
                    failures,
                    f"native account service missing admin-navigation method: {method}",
                )
    else:
        add_failure(
            failures,
            f"native account service missing: {rel(account_service, repo)}",
        )

    api_client = paths["api_client"]
    if api_client.is_file():
        source = read_text(api_client)
        for method in ("get_dispute_status_counts", "get_authorizations_summary"):
            token = f"function {method}"
            present = token in source
            result["api_client"]["required_methods"][method] = present
            if not present:
                add_failure(
                    failures,
                    f"native WooPayments API client missing admin badge method: {method}",
                )
    else:
        add_failure(
            failures,
            f"native WooPayments API client missing: {rel(api_client, repo)}",
        )

    badge_service = paths["badge_service"]
    if badge_service.is_file():
        source = read_text(badge_service)
        required_tokens = {
            "class": "class WooPaymentsAdminMenuBadgeService",
            "dispute-live-cache-key": "wcpay_dispute_status_counts_cache",
            "dispute-test-cache-key": "wcpay_test_dispute_status_counts_cache",
            "authorization-live-cache-key": "wcpay_authorization_summary_cache",
            "authorization-test-cache-key": "wcpay_test_authorization_summary_cache",
            "stale-fallback-helper": "get_or_add_cached_array",
            "manual-capture-gate": "manual_capture",
        }
        for label, token in required_tokens.items():
            present = token in source
            result["badge_service"]["required_tokens"][label] = present
            if not present:
                add_failure(
                    failures,
                    f"native admin badge service missing token {label}: {token}",
                )
    else:
        add_failure(
            failures,
            f"native admin badge service missing: {rel(badge_service, repo)}",
        )

    bootstrap = paths["bootstrap"]
    if bootstrap.is_file():
        source = read_text(bootstrap)
        registered = "WooPaymentsAdminNavigationController::class" in source
        result["bootstrap"]["registered"] = registered
        if not registered:
            add_failure(
                failures,
                "native admin navigation controller is not registered in WooCommerce bootstrap",
            )
    else:
        add_failure(failures, f"WooCommerce bootstrap missing: {rel(bootstrap, repo)}")

    return result


def check_source_chunks(repo: Path, routes_file: Path, failures: list[str]) -> dict[str, Any]:
    progress("Checking expected source chunk names")
    if not routes_file.is_file():
        return {"status": "missing", "expected": EXPECTED_CHUNKS}

    routes_source = read_text(routes_file)
    result: dict[str, Any] = {}
    for logical_name, chunk_name in EXPECTED_CHUNKS.items():
        present = chunk_name in routes_source
        result[logical_name] = {"chunk_name": chunk_name, "present": present}
        if not present:
            add_failure(
                failures,
                f"expected source chunk name absent: {chunk_name}",
            )

    return result


def check_route_paths(repo: Path, files: list[Path], failures: list[str]) -> list[dict[str, Any]]:
    progress("Checking native route paths")
    matches: list[dict[str, Any]] = []
    for path in files:
        for line_number, line in enumerate(read_text(path).splitlines(), start=1):
            for match in PLUGIN_ROUTE_RE.finditer(line):
                found = {
                    "path": rel(path, repo),
                    "line": line_number,
                    "route": match.group(2),
                }
                matches.append(found)
                add_failure(
                    failures,
                    "plugin-era /payments route path found: "
                    f"{found['path']}:{line_number} {found['route']}",
                )
    return matches


def check_registry_tokens(repo: Path, files: list[Path], failures: list[str]) -> list[dict[str, Any]]:
    progress("Checking native/settings-payments source for private registries")
    token_patterns = {
        token: re.compile(rf"\b{re.escape(token)}\b")
        for token in FORBIDDEN_REGISTRY_TOKENS
    }
    matches: list[dict[str, Any]] = []
    for path in files:
        for line_number, line in enumerate(read_text(path).splitlines(), start=1):
            for token, pattern in token_patterns.items():
                if pattern.search(line):
                    found = {
                        "path": rel(path, repo),
                        "line": line_number,
                        "token": token,
                    }
                    matches.append(found)
                    add_failure(
                        failures,
                        f"forbidden registry token {token} found: "
                        f"{found['path']}:{line_number}",
                    )
    return matches


def check_chunks(repo: Path, chunks_dir: Path, failures: list[str]) -> dict[str, Any]:
    progress("Checking built native admin chunks")
    chunks: dict[str, Any] = {}
    for logical_name, chunk_name in EXPECTED_CHUNKS.items():
        path = chunks_dir / f"{chunk_name}.js"
        if not path.is_file():
            chunks[logical_name] = {
                "status": "missing",
                "path": rel(path, repo),
                "chunk_name": chunk_name,
            }
            add_failure(
                failures,
                f"missing required native admin chunk {logical_name}: {rel(path, repo)}",
            )
            continue

        measurement = measure_chunk(path)
        measurement["path"] = rel(path, repo)
        measurement["chunk_name"] = chunk_name
        chunks[logical_name] = measurement
        progress(
            f"Measured {logical_name}: "
            f"{measurement['raw_bytes']} raw bytes, {measurement['gzip_bytes']} gzip bytes"
        )

    return chunks


def capture_plugin_baseline(plugin_repo: Path, failures: list[str]) -> dict[str, Any]:
    progress(f"Checking reference plugin admin baseline at {plugin_repo}")
    baseline: dict[str, Any] = {}

    for logical_name, relative_path in PLUGIN_BASELINE_FILES.items():
        path = plugin_repo / relative_path
        if not path.is_file():
            baseline[logical_name] = {
                "status": "missing",
                "path": relative_path,
            }
            add_failure(
                failures,
                f"missing reference plugin admin baseline file {logical_name}: {relative_path}",
            )
            continue

        measurement = measure_chunk(path)
        measurement["path"] = relative_path
        baseline[logical_name] = measurement
        progress(
            f"Measured reference {logical_name}: "
            f"{measurement['raw_bytes']} raw bytes, {measurement['gzip_bytes']} gzip bytes"
        )

    return baseline


def write_json(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    progress(f"Wrote JSON evidence to {path}")


def main() -> int:
    args = parse_args()
    repo = Path(args.repo).expanduser().resolve()
    failures: list[str] = []

    progress(f"Using repo {repo}")
    paths = check_required_paths(repo, failures)

    scan_files = source_files(paths["settings_payments"], paths["woopayments"])
    progress(f"Scanning {len(scan_files)} native admin source files")

    source_chunk_checks = check_source_chunks(repo, paths["routes"], failures)
    plugin_routes = check_route_paths(repo, scan_files, failures)
    registry_tokens = check_registry_tokens(repo, scan_files, failures)
    admin_navigation = check_admin_navigation_source(repo, paths, failures)
    pm_promotions = check_pm_promotions_source(repo, paths, failures)
    overview_financial_summary = check_overview_financial_summary_source(repo, paths, failures)
    money_details = check_money_detail_source(repo, paths, failures)
    chunks = check_chunks(repo, paths["chunks"], failures)

    plugin_baseline: dict[str, Any] | None = None
    plugin_repo = None
    if args.plugin_repo:
        plugin_repo = Path(args.plugin_repo).expanduser().resolve()
        if not plugin_repo.is_dir():
            add_failure(failures, f"plugin repo not found: {plugin_repo}")
            plugin_baseline = {}
        else:
            plugin_baseline = capture_plugin_baseline(plugin_repo, failures)

    result = {
        "schema": "woopayments_a4_admin_surface_gate.v1",
        "repo": str(repo),
        "result": "fail" if failures else "pass",
        "failures": failures,
        "source": {
            "scanned_files": [rel(path, repo) for path in scan_files],
            "expected_chunks": source_chunk_checks,
            "plugin_era_routes": plugin_routes,
            "registry_tokens": registry_tokens,
            "admin_navigation": admin_navigation,
            "pm_promotions": pm_promotions,
            "overview_financial_summary": overview_financial_summary,
            "money_details": money_details,
        },
        "chunks": chunks,
        "summary": {
            "native_admin_chunks": summarize_measurements(chunks),
            "reference_plugin_admin_baseline": summarize_measurements(plugin_baseline or {}),
        },
        "reference_plugin": {
            "repo": str(plugin_repo) if plugin_repo else None,
            "baseline_files": plugin_baseline,
        },
    }

    if args.out:
        write_json(Path(args.out).expanduser(), result)

    if failures:
        print("RESULT: FAIL A4 admin surface gate")
        return 1

    print("RESULT: PASS A4 admin surface gate")
    return 0


if __name__ == "__main__":
    sys.exit(main())
