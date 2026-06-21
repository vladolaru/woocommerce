---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 07:37
target: H29 provider refund webhook migration
reconciles:
  - analysis-h29-provider-refund-webhooks.md
  - spec-conformance-baseline.md
  - review-agent-findings.md
status: draft
---

# H29 Provider Refund Webhooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Native WooPayments should process the `charge.refunded` and `charge.refund.updated` provider webhook group with reference-compatible order/refund side effects, so those two events no longer block cutover while unrelated provider events remain fail-closed.

**Architecture:** Add a provider-specific `WooPaymentsRefundEventHandler` alongside the existing dispute handler. Keep Stripe/WooPayments refund payload parsing, note/meta wording, WC refund creation, and failed/canceled refund update behavior out of the generic lifecycle abstraction. Dispatch refund events from `WooPaymentsEventIngestor` before neutral lifecycle handling and remove only the migrated refund event types from `KNOWN_UNHANDLED_EVENT_TYPES`.

**Tech Stack:** WooCommerce Core PHP, `WC_Order`, `WC_Order_Refund`, `wc_create_refund()`, WooPayments preserved Bucket-E meta, PHPUnit via `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env`.

---

## Files

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandler.php` for provider-specific refund webhook side effects.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php` to construct/dispatch the refund handler and shrink `KNOWN_UNHANDLED_EVENT_TYPES`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php` with RED/GREEN regressions for the migrated refund event group and cutover-list behavior.
- Create: `plugins/woocommerce/changelog/fix-native-payments-h29-provider-refund-webhooks` after verification.
- Update scratchpad: `analysis-h29-provider-refund-webhooks.md`, `staging-log.md`, `implementation-log.md`, `spec-conformance-baseline.md`, and `review-agent-findings.md`.

## Task 1: RED Refund Webhook Coverage

- [ ] Add failing tests in `WooPaymentsEventIngestorTest` for `charge.refunded` full and partial external refunds, duplicate `_wcpay_refund_id` dedupe, ignored uncaptured/succeeded-missing cases, missing order fail-closed, invalid amount fail-closed, and `charge.refund.updated` failed/canceled/succeeded/invalid status behavior.
- [ ] Assert `charge.refunded` and `charge.refund.updated` no longer belong in the known-unhandled event test once implementation lands, while at least one remaining non-refund known-unhandled type still throws.
- [ ] Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsEventIngestorTest --stop-on-failure` and confirm the new tests fail for missing native behavior before implementation.

## Task 2: Provider Refund Event Handler

- [ ] Create `WooPaymentsRefundEventHandler` with `is_supported_event()` and `process()` methods for `charge.refunded` and `charge.refund.updated`.
- [ ] Implement `charge.refunded` parity: require charge status `succeeded`, require captured charge, require charge ID/currency/amount/refund fields, resolve a WooPayments order by `_charge_id`, dedupe existing refunds by `_wcpay_refund_id`, validate refund amount and charge amount, create local refunds with full-refund line items only when the provider refund equals the charge amount, and write `_wcpay_refund_status`, `_wcpay_refund_id`, `_wcpay_refund_transaction_id`, plus a WooPayments-compatible order note.
- [ ] Implement `charge.refund.updated` parity: resolve by `charge`, match existing refund by `_wcpay_refund_id`, write failed/canceled notes and `_wcpay_refund_status=failed`, delete matched WC refunds for failed/canceled updates, restore fully refunded order status to failed when appropriate, and on succeeded updates write success note/meta only for matched WC refunds.
- [ ] Fail closed with `RuntimeException` before side effects when required fields are malformed, the order cannot be resolved, amounts are invalid, or the update status is unknown. Log local refund creation/deletion failures through `WooPaymentsLegacyRuntime::get_logger()` when possible.

## Task 3: Ingestor Wiring And Cutover List

- [ ] Add `WooPaymentsRefundEventHandler` as a private dependency constructed in `WooPaymentsEventIngestor::init()`, using the same runtime/logger seam as the dispute handler.
- [ ] Dispatch refund events after `get_event_object()` and before lifecycle-event construction, firing before/after delivery hooks only around successful or ignored processing, matching existing ingestor flow.
- [ ] Remove `charge.refunded` and `charge.refund.updated` from `KNOWN_UNHANDLED_EVENT_TYPES` and keep `account.deleted`, `account.updated`, invoice events, and `wcpay.notification` present so cutover remains fail-closed for unmigrated families.

## Task 4: Verification, Review, Docs, Commit

- [ ] Run focused PHPUnit for `WooPaymentsEventIngestorTest` and the broader native payment event/payment set likely touched by refund handling.
- [ ] Run PHP syntax, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, production PHPStan for touched production PHP, `git diff --check`, and relevant harness syntax checks if helper files are touched.
- [ ] Dispatch at least two review agents: one source/parity review against the reference extension and one reliability/security/architecture review focused on fail-closed behavior and side effects.
- [ ] Create the changelog entry, commit source/tests, commit changelog separately, update scratchpad evidence, and record the git range.

## Guardrails

Do not touch WPCOM code or sandbox state. Do not clear the provider-event cutover blocker entirely. Do not move WooPayments refund payload parsing into `Internal\Payments` generic services. Do not claim broad N7a financial-matrix completion from unit coverage alone; this closes the source-backed provider-event refund side effect and should later be exercised with local webhook/Test Lab flow where available.

