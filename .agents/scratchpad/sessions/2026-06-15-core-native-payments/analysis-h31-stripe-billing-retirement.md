---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 09:25
target: H31 Stripe Billing invoice provider event retirement
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - analysis-h30-account-notification-provider-events.md
status: closed
updated: 2026-06-18 09:57 EEST
---

# H31 Stripe Billing Retirement Analysis

## Question

After H30, the only remaining native provider-event cutover blockers are `invoice.paid`, `invoice.payment_failed`, and `invoice.upcoming`. The decision point is whether to migrate these invoice handlers into Core or retire them as part of the deprecated WCPay-native Stripe Billing subscriptions engine, while preserving the standalone WooCommerce Subscriptions gateway integration.

## Contract Anchors

The canonical docs draw a hard boundary between two subscription concepts. Workstream C preserves the standalone WooCommerce Subscriptions gateway integration: `supports[]`, token renewals, change-payment-method, and failed-renewal/auth emails. Workstream D drops the WCPay-native subscriptions engine: `includes/subscriptions/`, vendored `subscriptions-core`, `_wcpay_feature_subscriptions`, `_wcpay_feature_stripe_billing`, engine Action Scheduler hooks, and product/price sync metadata. The Workstream D gate is data-safety: do not strand merchants that still have live WCPay/Stripe-Billing subscription data.

Reference WooPayments routes the three remaining invoice events through `WC_Payments_Webhook_Processing_Service::process_webhook_stripe_billing_invoice()`, which returns early if `WC_Payments_Subscriptions` is absent and otherwise calls `WC_Payments_Subscriptions::get_event_handler()->handle_invoice_*()`. Those handlers live under `includes/subscriptions/class-wc-payments-subscriptions-event-handler.php`, use `_wcpay_subscription_id`, `_wcpay_billing_invoice_id`, and `_wcpay_pending_invoice_id`, and create/update Stripe Billing renewal orders. That is the engine being dropped, not the standalone WooCommerce Subscriptions token-renewal integration Core must preserve.

Native Core currently still contains a drift from that boundary. `NativeWooPaymentsGateway` has `_wcpay_feature_subscriptions` and `_wcpay_feature_stripe_billing` constants, `should_use_stripe_billing()`, and a support branch that advertises `gateway_scheduled_payments` instead of `subscription_amount_changes` and `subscription_date_changes`. That mirrored the reference gateway for provider-list parity, but it carries a deprecated engine semantic into Core without the engine that fulfills it. This should be corrected in the same slice as the invoice event retirement, otherwise cutover could stop blocking invoice webhooks while the gateway still tells WC Subscriptions that Stripe Billing owns scheduling.

## Source Findings

- Native `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` now contains only `invoice.paid`, `invoice.payment_failed`, and `invoice.upcoming`. The cutover controller treats any non-empty pending event list as `provider_events_undispositioned`.
- Reference `WC_Payments_Subscription_Service::SUBSCRIPTION_ID_META_KEY` is `_wcpay_subscription_id`; a subscription is a WCPay/Stripe Billing subscription when it uses gateway `woocommerce_payments` and has that meta key.
- Reference `WC_Payments_Subscriptions_Migrator::get_stripe_billing_subscription_count()` uses `wcs_get_orders_with_meta_query()` for `type => shop_subscription` plus `_wcpay_subscription_id EXISTS`. That is a useful source-backed detection pattern, but Core should bound the guard to live subscription statuses for cutover rather than counting all historical cancelled/expired data.
- Reference `WC_Payments_Subscription_Service::store_has_active_wcpay_subscriptions()` is narrower: it checks active subscriptions with `_wcpay_subscription_id EXISTS`. For native cutover data-safety, a broader live-status set is safer: active, pending, on-hold, and pending-cancel.
- If WC Subscriptions helper functions are absent, the most compatible fallback is a bounded `wc_get_orders()` query over `type => shop_subscription`, live statuses, and `_wcpay_subscription_id EXISTS`. This is a cutover preflight query, not a hot-path request query, and should return IDs with `limit => 1`.

## Explorer Finding Reconciliation

Boyle the 2nd mapped the full reference invoice-event behavior. `invoice.upcoming` updates subscription notes/dates and validates/repairs WCPay invoice items and discounts. `invoice.paid` reuses or creates renewal orders, stores `_wcpay_billing_invoice_id`, calls `payment_complete()`, attaches payment intent metadata, clears `_wcpay_pending_invoice_id`, records invoice context, and writes charge/transaction metadata. `invoice.payment_failed` reuses or creates renewal orders, adds failure notes, calls WCS failure transitions, stores `_wcpay_pending_invoice_id`, and records invoice context. The report correctly notes that preserving these events in Core would require a provider-scoped subscription invoice handler, invoice meta store, WCS compatibility calls, duplicate-site guard, and additional API client endpoints for invoice/subscription/charge/transaction write paths.

That report is strong evidence that these events are not simple payment lifecycle events. It also strengthens the decision not to migrate them as part of native Core: carrying those behaviors would transplant a substantial slice of `includes/subscriptions/` and its Stripe Billing remote-control model into Core, while the manifest explicitly says that engine is Bucket D DROP. The source-backed implementation boundary for H31 is therefore not "migrate all invoice handlers"; it is "block cutover when live engine-owned data exists, and otherwise retire the engine event surface."

## N9 Correction

The initial H31 implementation plan was too narrow because it only blocked cutover for live-status `_wcpay_subscription_id` subscriptions. N9 tightened the definition of done: the guard must be airtight before invoice events are de-dispositioned, detect `_wcpay_subscription_id` plus the migrator’s `_migrated_*` variants across billing statuses, signpost the migration path, and log any retired invoice event that reaches native as an alarm instead of letting it fall through as a generic no-op.

The corrected source-backed marker set is `_wcpay_subscription_id`, `_migrated_wcpay_subscription_id`, `_wcpay_billing_invoice_id`, `_migrated_wcpay_billing_invoice_id`, `_wcpay_pending_invoice_id`, `_migrated_wcpay_pending_invoice_id`, `_wcpay_subscription_discount_ids`, `_migrated_wcpay_subscription_discount_ids`, and `_wcpay_subscription_migrated_during`. The `_wcpay_subscription_item_id` constant exists in the reference subscription service, but the source-verified migrator does not move it as part of the subscription/order meta migration, so it remains a legacy data disposition concern rather than the authoritative cutover marker for invoice-event retirement.

The first status-based guard is superseded. A clean H31/N9 implementation must block on any of those markers on `shop_subscription` or `shop_order`, regardless of billing status, and must use a bounded lookup plus request-local memoization so the preflight stays structurally safe.

## Decision

H31 should retire the Stripe Billing invoice provider events from native cutover blockers by moving the fail-closed condition from "invoice events are unimplemented" to "legacy Stripe Billing subscription markers exist." That means Core should not migrate the `includes/subscriptions/` invoice handlers. Instead:

1. Add a provider-owned guard service that detects legacy WCPay/Stripe-Billing subscription markers, including migrated marker variants and order-level invoice markers, across all statuses.
2. Add a cutover preflight failure such as `legacy_stripe_billing_subscriptions_present` when that guard detects markers.
3. Remove the three invoice events from `KNOWN_UNHANDLED_EVENT_TYPES`, because their emitter surface belongs to the dropped engine once the data-safety guard is in place.
4. Add a dedicated retired-invoice-event branch that logs an error if `invoice.paid`, `invoice.payment_failed`, or `invoice.upcoming` reaches native. This is an alarm that the guard or rollout sequence failed, not a native implementation of Stripe Billing.
5. Remove the native gateway Stripe Billing support branch and ignore the deprecated `_wcpay_feature_subscriptions` / `_wcpay_feature_stripe_billing` options for Core-owned supports. Native Core should always advertise the preserved WC Subscriptions token-renewal capabilities when standalone WooCommerce Subscriptions support is available.

Dirac the 2nd architecture review returned STAND on this direction. The review specifically called out that `gateway_scheduled_payments` is the deprecated Stripe Billing/WCPay-native engine path, that migrating the `invoice.*` handlers would pull Bucket D into Core, and that the cutover guard should be based on legacy data state rather than remaining coupled to `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES`.

## Measurement Note

This slice should not claim meaningful browser or payment-flow performance results. The guard adds a bounded admin/cutover preflight lookup, so the useful performance checks are structural: `limit => 1`, ID-only return, no object hydration, no repeated query loop, and no front-of-store runtime hook. Broader local timing remains useful only as a smoke signal for large regressions; noisy deltas should be recorded honestly rather than treated as proof.

## Open Checks Before Implementation

- Confirm the fallback query works in the WooCommerce test harness without the standalone WC Subscriptions plugin loaded; if not, keep the guard fail-closed by preserving the bounded CPT fallback rather than treating WC order API emptiness as authoritative.
- Confirm tests cover both sides: legacy marker data blocks cutover even after invoice events retire, while no marker data leaves provider-event blockers empty.
- Re-run the existing Settings Payments/provider-list support tests because B3ae intentionally matched the reference `gateway_scheduled_payments` behavior and those assertions must be updated to the Core-owned contract.

## Interim Verification

The corrected marker-based implementation passed the focused H31 suite on 2026-06-18: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter 'WooPaymentsCutoverControllerTest|NativeWooPaymentsGatewayTest|WooPaymentsEventIngestorTest'` returned OK with 126 tests and 440 assertions. The first run failed on the weak “WC order API empty is authoritative” shortcut, proving the test harness would have caught the non-airtight guard; that shortcut was removed and replaced with the bounded direct marker fallback.

The broad native payments filter also passed after the correction: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter 'Payments|WooPayments|NativePayments|PaymentProcessing|OrderPayment'` returned OK with 1983 tests and 30579 assertions.

## Final Verification

After reliability review, H31 received two additional fixes: the legacy marker blocker is enforced after generic preflight-filter normalization, and retired invoice events log before livemode mismatch can return. Final gates passed: focused H31 PHPUnit returned OK with 127 tests and 444 assertions; broad native payments PHPUnit returned OK with 1984 tests and 30583 assertions; production PHPStan, changed-file PHP lint, changelog validation, branch lint, and `git diff --check` passed. The committed H31 range is `aa4dc99edb...5ac34fdb62`.
