---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 04:00
tool: source-map
target: H24 N7a auth/capture financial matrix closure
reconciles:
  - staging-log.md
  - review-agent-findings.md
  - plans/2026-06-17-core-native-payments-n7-verification-coverage.md
status: draft
last_updated: 2026-06-18 04:19
---

# H24 N7a Auth/Capture Driver Analysis

> **Prompt:** "Continue working toward the active thread goal."

## Source-Backed Findings

- N7a remains fail-closed after H23. `staging-log.md` records that the financial reconciler compares charge amount/currency, capture state, refund totals and ids, fee/net, multi-currency exchange rates, disputes, and payouts, but deterministic coverage is still incomplete for auth/capture, dispute outcome ingestion, payout linkage, and target multi-currency money paths.
- The existing comparator is structurally wider than the original refund-only gate: `tools/woopayments-merge/financial-reconcile-normalize.py` compares captured amount, compatible PaymentIntent status, refund rows, fee/net, multi-currency exchange rate, dispute ids, and payout readability/linkage. The blocker is fixture production and runtime coverage, not the comparator shape alone.
- The current deterministic flow layer only supports deterministic native `charge` and deterministic `refund`; `tools/woopayments-merge/flow-drive.sh` rejects deterministic `dispute` and `payout`, and has no deterministic auth/capture/cancel operation.
- Native WooPayments capture support exists at provider/service level: `PaymentProcessingService::capture()`, `PaymentProcessingService::cancel()`, `WooPaymentsProviderGatewayAdapter::capture()`, and `WooPaymentsProviderGatewayAdapter::cancel()` are implemented and unit-tested. This is sufficient for N7a service/provider money-path verification but does not prove A4 merchant admin capture UI.
- A product gap blocks authentic native auth/capture coverage: reference WooPayments sets `capture_method` from the gateway manual-capture setting when creating and confirming a PaymentIntent (`woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:1734`), while `WooPaymentsProviderGatewayAdapter::build_native_charge_request_data()` currently sends amount, currency, customer, metadata, payment method, off-session/setup flags, and method types but no `capture_method`. Native can normalize `requires_capture` when the provider returns it, but it is not asking the provider to create manual authorizations.

## H24 Direction

H24 should first fix native manual-capture request creation with RED/GREEN unit coverage, then widen the ignored local harness so it can create a manual authorization and drive capture through the real provider/service path on both stores. The plan must record this as N7a money-path coverage only; A4 admin capture controls and dispute/payout UI/event flows remain separate blockers unless the new drivers expose source-backed product drift.

## Implementation Findings

- RED/GREEN confirmed the native `capture_method` gap and fixed it in `WooPaymentsProviderGatewayAdapter::build_native_charge_request_data()` using the existing provider-owned `manual_capture` setting. The request remains provider-specific and does not leak into `PaymentProcessingService`. Review follow-up verified the reference renewal contract and forced scheduled renewals to `automatic` even when manual capture is enabled for customer checkout.
- Live target order 197 exposed a second product gap: authorized orders could have `_intent_id` and `_intention_status=requires_capture` without a WooCommerce transaction id, while provider capture/cancel used only the transaction id. H24 fixed this at lifecycle level for new authorized orders and added `_intent_id` fallback for already-created authorizations.
- The deterministic reference driver initially failed to create manual authorizations because the WooPayments gateway instance was already loaded before the harness updated the option row. The harness now also updates the live gateway object's `manual_capture` option for the simulated checkout and restores the persisted option afterward. This exposed the real reference behavior instead of masking it.
- Pre/post-capture financial reconciliation passed for target native order 198 and reference order 501. This closes auth/capture provider/service money-path coverage for the baseline, but it does not close dispute ingestion, payout linkage, target multi-currency money fixtures, or A4 merchant capture UI parity.
