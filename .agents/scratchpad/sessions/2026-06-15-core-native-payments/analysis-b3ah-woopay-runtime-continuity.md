---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 08:10
last_updated: 2026-06-17 08:51
status: draft
---

# B3ah WooPay Runtime Continuity Analysis

## Prompt Context

> **Prompt:** "Continue"

> **Prompt:** "Make sure you don't concel bugs in your implementation by changing the harness to mask them."

> **Prompt:** "Both local env were working e2e before you started working. So if anything breaks is because of your changes. Do not ignore WP notices or warnings because they may point to something off."

> **Prompt:** "Don't forget you have the reference local store environment that uses WC on trunk and WooPayments on develop branches. That reference store local env is there for you to compare against, for any relevant aspects: technical, behavioral, UI, UX, BC, etc."

## Current State

- B3ag is committed as `4e1addc0809f6cda60fe6624e939ce63f88030df` and logged in both implementation and staging logs.
- The tracked worktree is clean on `exp/core-native-payments`; WPCOM remains off limits and no push has occurred.
- B3ag selection analysis promoted WooPay runtime continuity as the next hard external contract after the admin/provider entrypoints.

## Findings

- Core currently has no native `payments/woopay/session` REST route and no native registrations for the WooPay AJAX hooks `wc_ajax_wcpay_init_woopay`, `wc_ajax_wcpay_get_woopay_session`, `wc_ajax_wcpay_set_woopay_phone_number`, `wc_ajax_wcpay_get_woopay_signature`, `wc_ajax_wcpay_get_woopay_minimum_session_data`, `wp_ajax_wcpay_admin_set_woopay_appearance`, or `wc_ajax_wcpay_shopper_set_woopay_appearance`.
- The reference plugin registers `payments/woopay/session` from `includes/admin/class-wc-rest-woopay-session-controller.php`, requires `User-Agent: WooPay`, and validates the request signature through `Rest_Authentication::is_signed_with_blog_token()` behind the `wcpay_woopay_is_signed_with_blog_token` filter.
- The reference plugin registers the AJAX hooks from `WC_Payments::maybe_register_woopay_hooks()` only when WooPay is eligible and `platform_checkout` is enabled; in native Core this should be expressed through native runtime ownership plus native WooPayments gateway settings rather than standalone plugin runtime.
- `WooPay_Session` builds encrypted/signed session payloads from WooCommerce Store API cart/checkout data, the WooPayments account ID, blog ID, checkout/shop URLs, test mode, capture method, subscriptions state, optional appearance/font rules, and customer/session email state. The full payload is large, but the first native preservation chunk can cover route/hook registration, nonce/signature behavior, minimum session payload shape, phone/save-user session persistence, signature generation, and init-session forwarding with test-preemptable transport.
- Existing Core native WooPayments infrastructure already provides the patterns needed for this chunk: `NativePaymentsRuntimeArbiter` for ownership, `WooPaymentsAccountService` for gateway settings/test mode/account ID, `WooPaymentsApiClient`/HTTP patterns for transport seams, `WooPaymentsCheckoutBridge` for checkout config, and `WooPaymentsMobileRestController` for REST registration/testing style.
- This chunk should not move WooPay frontend/button rendering wholesale. Direct checkout, express button iframe UI, adapted-extension order restoration, Store API custom session handlers, and WooPay appearance extraction/cache parity are larger follow-ups. B3ah should expose the server-side contracts without pretending those downstream surfaces are complete.
- Browser comparison after the first B3ah implementation proved the server/config slice is necessary but not sufficient for shopper parity. Target guest Blocks/classic checkout now has the card method, test-card details, Stripe iframe, and reference-compatible `initWooPayNonce` session config, but reference guest Blocks/classic checkout also loads WooPay express/save frontend globals and surfaces (`wcpayConfig`, `wcpayAssets`, `wcpayExpressCheckoutParams`, WooPay express iframe/button, save-my-info phone UI). Target does not yet register/load those frontend assets/surfaces and target Blocks retains a stale offscreen "There are no payment methods available" live-region announcement that reference guest checkout does not show. The next implementation chunk should treat WooPay frontend asset registration and express/save UI as Core-owned WooCommerce asset workflow work rather than standalone-plugin special casing.

## Decision

B3ah should be the native WooPay session/server contract package: a Core-owned WooPay session controller/service registered under native runtime ownership, preserving the public route/action names and core payload semantics needed by existing WooPay frontend/external callers. The scope is intentionally larger than a single endpoint because these routes/actions share nonces, session keys, encryption/signature helpers, and runtime gating.
