---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 09:25
target: H31 Stripe Billing invoice provider event retirement
reconciles:
  - ../analysis-h31-stripe-billing-retirement.md
  - ../spec-conformance-baseline.md
  - ../review-agent-findings.md
status: closed
updated: 2026-06-18 09:57 EEST
---

# H31 Stripe Billing Retirement Plan

## Goal

Close the remaining invoice provider-event cutover blocker without importing the deprecated WCPay-native Stripe Billing subscriptions engine into Core. Preserve the standalone WooCommerce Subscriptions gateway integration, and fail closed at cutover when legacy WCPay/Stripe-Billing subscription markers are still present.

## Scope

- Native Core must stop advertising Stripe Billing-owned subscription scheduling (`gateway_scheduled_payments`) and must ignore `_wcpay_feature_subscriptions` / `_wcpay_feature_stripe_billing` for Core-owned gateway supports.
- Native Core must keep the preserved WooCommerce Subscriptions support contract: subscriptions, cancellation, multiple subscriptions, payment-method changes, amount changes, date changes, renewal handlers, and failed-renewal authentication emails.
- Cutover preflight must block when legacy WCPay/Stripe-Billing subscription markers exist, across all billing statuses and including migrated marker variants.
- `invoice.paid`, `invoice.payment_failed`, and `invoice.upcoming` must be removed from `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` only after the marker guard is in place.
- If a retired invoice event reaches native, native must log it as an alarm and return deliberately. It must not silently fall through as an ordinary unsupported/no-op event.
- This slice does not implement Stripe Billing invoice webhook handlers in Core and does not change WPCOM code.

## Superseded Direction

The earlier status-based guard direction is superseded by N9. Do not use a “live statuses only” guard, and do not treat cancelled or migrated legacy rows as harmless. The cutover safety condition is marker absence, not status interpretation.

## Implementation Steps

1. Add RED tests:
   - `NativeWooPaymentsGatewayTest` should assert Stripe Billing flags do not swap preserved WC Subscriptions supports to `gateway_scheduled_payments`.
   - `WooPaymentsEventIngestorTest` should assert the invoice events are no longer known-unhandled once data-safety is handled, and that a retired invoice event logs an alarm if it reaches native.
   - `WooPaymentsCutoverControllerTest` should assert legacy Stripe Billing markers yield `legacy_stripe_billing_subscriptions_present`, hide the soft cutover notice, refuse one-click disable, and block mandatory auto-deactivation.
2. Add a provider-owned guard service, tentatively `WooPaymentsLegacySubscriptionsGuard`, under `Internal\Payments\Providers\WooPayments\Subscriptions`.
   - Use source-backed markers `_wcpay_subscription_id`, `_migrated_wcpay_subscription_id`, `_wcpay_billing_invoice_id`, `_migrated_wcpay_billing_invoice_id`, `_wcpay_pending_invoice_id`, `_migrated_wcpay_pending_invoice_id`, `_wcpay_subscription_discount_ids`, `_migrated_wcpay_subscription_discount_ids`, and `_wcpay_subscription_migrated_during`.
   - Query `shop_subscription` and `shop_order` without status constraints.
   - Use `wcs_get_orders_with_meta_query()` and `wc_get_orders()` when available; preserve a bounded CPT fallback because the WooCommerce order API path can miss lightweight legacy subscription fixtures and old data shapes.
   - Cache the result for the current request.
   - Keep the query out of front-of-store runtime hooks; it is cutover preflight only.
3. Inject the guard into `WooPaymentsCutoverController` with an optional constructor/init parameter for tests and container compatibility.
4. Add the cutover preflight failure before the generic filter: `legacy_stripe_billing_subscriptions_present`.
5. Remove the native gateway Stripe Billing branch:
   - Remove the deprecated feature flag constants and helper methods if unused.
   - Always add `subscription_amount_changes` and `subscription_date_changes` when subscriptions support is available.
6. Remove invoice events from `KNOWN_UNHANDLED_EVENT_TYPES`, add the retired-event alarm branch, and update tests that intentionally asserted the previous fail-closed list.
7. Update scratchpad logs and changelog.
8. Run focused tests first, then the native payments PHP test set, syntax, PHPStan, changed-file lint, branch lint excluding scratchpad, changelog validation, and `git diff --check`.

## Measurement And Verification

This is not a browser performance slice. The guard's performance evidence should be structural: bounded lookups, ID-only return, no object hydration, no loops, request-local memoization, and no hot-path hooks. Any local timing would be noisy and should only be used as a smoke signal for unexpectedly large deltas, not as proof of performance preservation.

## Review Gates

- Architecture review: verify the slice honors Bucket C preserve versus Bucket D drop and does not move Stripe Billing engine behavior into generic payment lifecycle code.
- Performance review: verify the cutover guard query is bounded and not on hot paths.
- Reliability review: verify preflight failure is fail-closed and cannot be filtered into an invalid shape without existing invalid-filter protections.

## Closeout

Implemented and committed as `5ac34fdb62` (`fix(payments): retire legacy Stripe Billing events`). Reliability review found two blockers during closeout; both were fixed before commit and covered by regressions. Final focused and broad PHPUnit, PHPStan, PHP lint, changelog validation, branch lint, and `git diff --check` passed.
