---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 08:40
target: H30 account and notification provider event migration
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - staging-log.md
  - analysis-h29-provider-refund-webhooks.md
status: draft
---

# H30 Account and Notification Provider Events

## Source Findings

After H29, `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` contains exactly `account.deleted`, `account.updated`, `invoice.paid`, `invoice.payment_failed`, `invoice.upcoming`, and `wcpay.notification`. The cutover controller consumes this list, so all six still block native cutover fail-closed. `charge.refunded` and `charge.refund.updated` are no longer in that list because H29 migrated the refund side effects into `WooPaymentsRefundEventHandler`.

The reference WooPayments webhook processor handles `account.updated` by refreshing account data and clearing cached payment methods. It handles `account.deleted` by running onboarding account-reset cleanup, deleting the NOX profile and onboarding lock options, refreshing account data, and clearing cached payment methods. It handles `wcpay.notification` by passing the webhook `data` payload to the remote note service. The reference does not read `data.object` for `wcpay.notification`; native currently calls `get_event_object()` before all non-dispute/refund dispatch, so H30 must dispatch notification events before the generic `data.object` extractor.

The account-reset cleanup that matters for native parity is bounded and provider-owned: disable the WooPayments gateway, reset persisted test mode, reset enabled payment methods to `card`, clear `_wcpay_onboarding_stripe_connected`, clear `wcpay_onboarding_test_mode`, delete the onboarding success modal option, discard onboarding transients, delete the NOX profile and lock options, and then allow the account refresh to get the platform-driven next state. Native `WooPaymentsAccountService` already owns account cache refresh, gateway settings reads, onboarding test mode reads, and the preserved account cache, so adding account-reset cleanup there keeps this state mutation near the existing native account guardrails.

The reference token service clears old saved-payment caches by deleting all user meta keyed `_wcpay_payment_methods` and deleting legacy `wcpay_pm_%` option rows. Native `WooPaymentsTokenService` currently has no equivalent cache-clearing method. This is still needed even though native checkout stores current cards as WooCommerce tokens, because account lifecycle events are a rare and appropriate time to clear preserved plugin-era cached payment methods and avoid stale provider payment-method state after account replacement.

The reference remote note service is a small Woo Admin note adapter. It creates informational notes named `wc-payments-remote-notes-` plus either the provided `name` or `md5(title.content)`, source `woocommerce-payments`, empty object `content_data`, and optional actions. Actions require `label` and `url`, map `url=wcpay_settings` to the WooPayments settings URL, allow `url_is_admin` paths through `admin_url()`, and reject arbitrary URLs. Existing notes with the same name are deduped through the Woo Admin note data store.

## Slice Decision

H30 should migrate `account.updated`, `account.deleted`, and `wcpay.notification` together because they are the non-money account/admin side of the remaining provider-event blocker. The invoice events should stay out of H30. `invoice.upcoming`, `invoice.paid`, and `invoice.payment_failed` are subscription billing/order events and belong with the Bucket-C subscription/invoice gate so their renewal, failed-renewal, email, and Stripe Billing semantics can be tested as one coherent slice.

The implementation should add provider-level account and notification handlers under `Internal\Payments\Providers\WooPayments`, wire them through `WooPaymentsEventIngestor`, and remove only the three migrated event types from `KNOWN_UNHANDLED_EVENT_TYPES`. The generic payment lifecycle abstraction should not learn WooPayments account reset or Woo Admin remote note payload shapes.

## Verification Stance

H30 is backend provider-event behavior. Reliable evidence is focused PHPUnit over event dispatch, account reset state, payment-method cache clearing, remote note creation/dedupe/validation, plus broad native payment event gates, PHPStan, PHPCS, changed-file lint, changelog validation, and `git diff --check`. No frontend bundle or local timing claim should be made. If a restored harness run is useful after this backend slice, it is a smoke/regression check rather than the evidence that account and notification webhook semantics are correct.

## Current Cross-Checks

Linnaeus mapped the remaining provider event list and independently classified account/notification events as separable from subscription invoice events. Kierkegaard rechecked the multi-currency architecture blocker and found that production `src/Internal/MultiCurrency` no longer imports concrete WooPayments classes; the remaining concrete coupling is bootstrap ownership in `class-woocommerce.php` plus generic tests that still instantiate WooPayments provider pieces. Plato mapped the operational queue hooks and webhook logging source drift as separate blockers from H30.

## Outcome After Implementation

H30 migrated `account.updated`, `account.deleted`, and `wcpay.notification` out of the known-unhandled provider-event list. `invoice.paid`, `invoice.payment_failed`, and `invoice.upcoming` remain fail-closed and should be handled with the subscription/invoice slice, not hidden under this account/admin event work.

The final implementation includes strict account-refresh persistence verification, verified account-deletion pending marker writes/deletes, stale pending-marker protection when a different account is currently connected, bounded and verified preserved payment-method cache cleanup, and remote-note persistence verification. Euler's ecosystem integration review and Euclid's reliability review both approved the final H30 diff after those fixes.

Verification evidence as of 2026-06-18 09:15 EEST: focused H30 PHPUnit passed with 108 tests and 444 assertions; broad native payments PHPUnit passed with 194 tests and 844 assertions; PHP syntax passed for all changed PHP files; changed-file PHPCS passed; production PHPStan passed for the six changed WooPayments production classes; `git diff --check` passed. No frontend bundle, browser, e2e, or local timing claim is made for H30 because this slice changes backend provider-event/account-admin behavior only.
