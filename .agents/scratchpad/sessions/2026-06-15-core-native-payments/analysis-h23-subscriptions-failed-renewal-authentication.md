---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 03:07
tool: source-map
target: H23 Bucket-C WC Subscriptions failed-renewal authentication parity
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - analysis-subscriptions-renewal-gate-scaffold.md
status: complete
last_updated: 2026-06-18 03:53
---

# H23 Subscriptions Failed-Renewal Authentication Analysis

> **Prompt:** "Make sure you don't over-index on what is reliably measurable. Best to be honest about the possibilities and not chase unreliable numbers. Or at least use them honestly as stop gaps for big deltas in performance to inform that we may be doing something wrong, if their variability is high. But give it a good shot first."

## Working Contract

H23 is the next Bucket-C slice after H22 because N7b is still fail-closed: successful-renewal scaffolding exists and live renewal comparisons have evidence, but the source-backed failed-renewal authentication path is not yet mapped or implemented for native WooPayments. The target is not to chase noisy runtime timing numbers for this slice; perf/bundle evidence stays tiered, with deterministic structural signals preferred and local timings treated only as smoke for large deltas when variability is high.

## Source Map Notes

- Start state: branch `exp/core-native-payments` is clean after H22.
- Baseline state: `spec-conformance-baseline.md` still marks Bucket-C renewal and failed-renewal conformance as not baseline-green, and explicitly warns that perf timing medians are smoke only rather than exact proof.
- Reference off-session SCA behavior is anchored in `woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:1811-1814`: when an intent returns `requires_action` for merchant-initiated payment information, the extension fires `woocommerce_woocommerce_payments_payment_requires_action` with order, intent ID, payment method, customer ID, charge ID, and currency, then marks the payment failed.
- Turing's source review confirmed the action-before-failure ordering is load-bearing: `mark_payment_failed()` runs after the custom action, so the email class has already installed retry filters before WC Subscriptions' failed-renewal notification path runs.
- Reference subscription hook registration is anchored in `woocommerce-payments/includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:278-285` and `:1126-1137`: the base WooPayments gateway registers the email classes through `woocommerce_email_classes`, then adds scheduled-renewal and failing-payment-method hooks for reusable WooPayments gateways.
- Reference customer SCA email contract is anchored in `woocommerce-payments/includes/compat/subscriptions/class-wc-payments-email-failed-renewal-authentication.php:30-53` and `:174-196`: email id `failed_renewal_authentication`, customer-facing template, action hook registration, removal of default WCS renewal notifications, and retry-rule filters that suppress customer retry emails while swapping admin retry email.
- Reference retry admin email contract is anchored in `woocommerce-payments/includes/compat/subscriptions/class-wc-payments-email-failed-authentication-retry.php:53-61` and `:148-196`: email id `failed_authentication_requested`, admin subject/heading, and retry-time placeholder sourced from `WCS_Retry_Manager`.
- Native subscription renewal code is anchored in `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php:262-300`: it sends a generic `PaymentContext` with `scheduled_subscription_payment => true`, but currently discards the `PaymentOutcome`, so it cannot emit the preserved action hook or branch on `requires_customer_action`.
- Native subscription hook registration is anchored in `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php:794-847`: it adds gateway support and scheduled/failing hooks, but has no email-class registration.
- Native generic payment lifecycle is anchored in `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php:82-103` and `:415-452`: `process_checkout()` applies an outcome then returns the checkout array, and `STATUS_REQUIRES_CUSTOMER_ACTION` currently maps to lifecycle `STATUS_STARTED`, not failed. That is correct for customer-present checkout but not sufficient for off-session subscription renewal parity.
- Ohm's source review confirmed `process_checkout()` must keep returning the checkout array because `NativeWooPaymentsGateway::process_payment()` returns it directly to WooCommerce checkout. H23 should add a separate internal outcome-returning path, not change the existing return contract.

## Architecture Pressure

The concrete pressure is that the generic payment service owns order lifecycle application while the WooPayments gateway owns WooPayments compatibility hooks and subscription-specific side effects. The smallest clean abstraction is to expose the already-existing checkout `PaymentOutcome` to trusted internal callers without changing the existing public checkout result shape, then let `NativeWooPaymentsGateway` handle the WooPayments-specific off-session action and failure transition after the generic lifecycle has recorded provider meta. This avoids embedding WooPayments hook names in `PaymentProcessingService` and avoids copying extension global classes into Core.

## Perf Evidence Policy

H23 should not claim broad perf proof from local timings. The meaningful perf checks for this slice are structural: no new frontend bundles, no new shopper assets, no new request loops, email classes instantiated only through `woocommerce_email_classes`, and one extra internal checkout-outcome method that reuses existing charge/lifecycle code. Any runtime timings, if gathered, are smoke signals only for large deltas and should be recorded with that caveat.

## Outcome

- 2026-06-18 03:39: Implemented the provider-neutral checkout outcome seam and kept WooPayments-specific failed-renewal authentication behavior in WooPayments-owned gateway/email classes. The generic service continues to return the existing checkout array from `process_checkout()`, while trusted internal scheduled-renewal callers can consume the neutral `PaymentOutcome`.
- 2026-06-18 03:39: Native scheduled renewals now preserve the reference off-session SCA ordering: provider meta is applied, the `woocommerce_woocommerce_payments_payment_requires_action` action fires with order, intent ID, payment method ID, customer ID, charge ID, and currency, and the renewal order is then marked failed. The customer email registers on that action and installs the retry-rule filters before the failed-renewal notification path runs.
- 2026-06-18 03:39: Added Core-owned templates and classes for the preserved customer/admin email IDs. Verification is deterministic at PHP/unit/harness-preflight level; no frontend bundle or timing claim is made for H23.
- 2026-06-18 03:39: Browser-created subscription compare mode remains unclaimed for this slice. The local stores have no existing subscription posts, Chrome DevTools MCP timed out on both `list_pages` and `new_page`, and Playwriter became unstable while filling the Stripe iframe. This is recorded as a remaining browser-gate limitation, not as a pass.
- 2026-06-18 03:53: Review fixes are incorporated. API-contract review kept legacy WooPayments template override names; reliability review drove hook-exception hardening and idempotent email registration; architecture review drove an explicit provider-level `charge_id` outcome field so the subscription hook no longer depends on generic lifecycle meta while preserving `_charge_id` order meta compatibility.
- 2026-06-18 03:53: Final H23 verification after review fixes passed at PHP/static/preflight level: targeted PHPUnit `PaymentProcessingServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest::test_charge_sets_card_display_meta_before_returning_requires_action` passed with 49 tests and 220 assertions; `lint:php:changes`, explicit PHPCS for the six new email/template files, PHP syntax, `git diff --check`, and Bucket-C preflight passed. PHPStan reported no touched-file errors but still exits nonzero because unrelated existing ignored-baseline patterns are unmatched.
