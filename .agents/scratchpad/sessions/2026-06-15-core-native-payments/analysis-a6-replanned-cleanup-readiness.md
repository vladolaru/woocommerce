---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 03:00
target: A6 replanned cleanup readiness
reconciles:
  - analysis-a5i-local-readiness-decision-rollup.md
  - staging-log.md
  - spec-conformance-baseline.md
last_updated: 2026-06-21 03:08
status: draft
---

# A6 Replanned Cleanup Readiness

## Prompt Trail

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A5i closed the local A4/A5 readiness decision line: local A4/A5 readiness is green under recorded limitations, while production/default-on, release sequencing, and A6 cleanup remain deferred and fail-closed. The next movement toward the end-state is not to execute the stale A6 plan mechanically. The old A6 cleanup assumption that `tools/woopayments-merge` is tracked scaffolding to delete is false: the directory is ignored local verification infrastructure with zero tracked files and must remain available for gates.

## Replan Questions

- What does canonical A6 require now, after A4/N12 parity and local A5 readiness were refreshed?
- Which deprecated or duplicated WooPayments surfaces are still tracked in WooCommerce Core and safe to remove without weakening BC, verification, or local gates?
- Which remaining cleanup items are product-code cleanup versus release/default-on decisions that should stay fail-closed?
- What is the first bounded A6 slice that moves the final objective forward while preserving local verification infrastructure?

## Canonical Constraints Read So Far

Implementation-plan A6 says cleanup should remove what the merge made dead: WC cross-version compat, one-shot migration runners after A5, `LegacyProxy`/`LegacyContainer` remnants, PRESERVE-AS-FACADE shims after internal callers migrate, and vendored/duplicated libraries. Bucket D explicitly includes the deprecated WCPay-native Stripe Billing subscriptions engine, `subscriptions-core`, `_wcpay_feature_subscriptions`, `_wcpay_feature_stripe_billing`, engine AS hooks, and product/price sync meta, while preserving the gateway-to-WC-Subscriptions integration as Bucket C.

The current local baseline changes the sequencing: production/default-on and A6 cleanup were deferred by A5i, and the ignored `tools/woopayments-merge` harness must not be treated as tracked scaffolding to delete.

## Local Source Findings

- The Stripe Billing runtime path is already mostly settled by H31: `NativeWooPaymentsGateway` no longer advertises `gateway_scheduled_payments`, `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is empty, retired `invoice.*` events are alarmed through `RETIRED_STRIPE_BILLING_INVOICE_EVENT_TYPES`, and `WooPaymentsLegacySubscriptionsGuard` blocks cutover on subscription/order invoice markers without deleting merchant data.
- The remaining source-backed Bucket-D settings surface is the deprecated bundled WooPayments subscriptions toggle. `WooPaymentsSettingsService` still defines `WCPAY_SUBSCRIPTIONS_FLAG_OPTION = '_wcpay_feature_subscriptions'`, returns `is_wcpay_subscriptions_enabled`, accepts `is_wcpay_subscriptions_enabled=false` to write the option to `0`, and the settings data store/UI still exposes `useWCPaySubscriptions()` plus a disabled `Enable Subscriptions with WooPayments` control with deprecation copy. This is not needed for preserved Bucket-C renewals, which are driven by `NativeWooPaymentsGateway::scheduled_subscription_payment()` and the gateway's WC Subscriptions hooks.
- `_wcpay_feature_stripe_billing` appears limited to tests that ensure native settings/gateway do not mutate or depend on it. This supports removing active `_wcpay_feature_subscriptions` settings reads/writes while keeping guard-only legacy marker detection and Bucket-C renewal coverage.

## First Slice Candidate

A6a should remove the deprecated bundled-subscriptions settings contract and UI from native WooPayments settings while preserving WC Subscriptions integration and the legacy Stripe Billing data-safety guard. This is a product-code cleanup slice, not a production/default-on decision and not harness deletion.

## Boundary Audit Update

`review-a6-boundary-audit.md` returned `HOLD_FOR_RELEASE_DECISION`. The deprecated bundled-subscriptions settings surface remains the source-backed future A6a cleanup candidate, but the boundary audit says not to begin A6 product-code cleanup until the release/default-on sequencing decision is settled. Product source remains untouched in this A6 replanning pass. The A6a plan is therefore marked held, not active.

## Subagent Reconciliation

- `review-a6-bucket-d-source-audit.md` returned `CLEAR_FIRST_SLICE` for the deprecated WooPayments Subscriptions settings surface around `_wcpay_feature_subscriptions`. It also found the Stripe Billing engine, `subscriptions-core`, product/price sync writers, migration runners, and vendored runtime duplicates already absent from tracked Core source. This supports the future A6a plan.
- `review-a6-facade-callers-audit.md` returned `NO_SAFE_CODE_CLEANUP` for facade/runtime cleanup. There is no tracked `LegacyContainer` or global production `WC_Payments`/`WCPay`/`wcpay_*` facade definition to delete, while `LegacyProxy`, `WooPaymentsLegacyRuntime`, request aliases, preserved hooks/options/meta/queues/telemetry, and guard surfaces remain live or externally meaningful. This rules out a facade cleanup slice before additional caller retirement and release/API decisions.
- `review-a6-boundary-audit.md` controls sequencing: despite the clear future Bucket-D first slice, A6 product-code cleanup should remain held until release/default-on sequencing is decided.
