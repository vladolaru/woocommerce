---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 12:16
target: A4ap WooPay checkout and accumulated gate parity
reconciles:
  - analysis-a4ap-accumulated-checkout-admin-gate.md
  - review-a4ao-blocks-express-checkout-element-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ap WooPay Checkout Gate Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the remaining source-backed WooPay/test-card checkout parity gaps and widen the accumulated A4/N12 gate so the final readiness claim is backed by shopper plus admin runtime evidence.

**Architecture:** Keep card checkout behavior in the card bundles, WooPay behavior in the split WooPay bundles, and protected admin route authorization in the Settings > Payments provider route seam. Add small provider-owned helpers where the reference behavior needs shared WooPay Connect or copy-feedback logic, but do not move WooPayments assets into generic WooCommerce checkout bundles.

**Tech Stack:** WooCommerce PHP unit tests/PHPStan/PHPCS, Jest for legacy and Blocks checkout bundles, React Testing Library for Blocks/admin, Playwriter harness scripts under `tools/woopayments-merge`, and existing WooCommerce build/lint commands.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionService.php` to compute `isWoopayFirstPartyAuthEnabled` from WooPay enablement, country availability, and context express availability.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionServiceTest.php` to prove the first-party flag is enabled only for US eligible WooPay express contexts.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` to add card test-number copy fallback, success feedback, and a short success class.
- Modify `plugins/woocommerce/client/legacy/css/woopayments-checkout.scss` and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/style.scss` to style the copy success state inside card checkout bundles only.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-woopay.js`, `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/index.js`, and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/style.scss` to restore WooPay first-party Connect, preferred-card rendering, and product-page preflight behavior without coupling to card/ECE code.
- Add or modify focused tests in `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-checkout.js`, `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js`, `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-woopay.js`, and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/test/index.js`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` and `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx` so protected routes fail closed when the route-availability preload is absent or does not name the route.
- Add `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs` and update `tools/woopayments-merge/HARNESS.md` if the current harness has no shopper checkout/browser gate. Keep progress output, reference/target evidence, failed-response/pageerror/console capture, and debug-log scan hooks.
- Add one WooCommerce changelog file after product implementation is green.

## Task 1: WooPay First-Party Config Predicate

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionService.php`

- [ ] **Step 1: Write the failing PHP test.** Add a focused test beside `test_builds_woopay_checkout_frontend_config()` asserting the default checkout config sets `isWoopayFirstPartyAuthEnabled` to `true`, while non-US account data, `platform_checkout_eligible => false`, `platform_checkout => no`, and `express_checkout_checkout_methods => array()` set it to `false`.

- [ ] **Step 2: Verify RED.** Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsWooPaySessionServiceTest::test_first_party_auth_flag_requires_available_woopay_express_checkout`. Expected: one assertion failure because the current production code returns `false` for the default US eligible checkout case.

- [ ] **Step 3: Implement the predicate.** Replace the hardcoded config value with `$is_woopay_enabled && $is_country_available && $this->is_woopay_express_checkout_enabled_at( $context )`. Do not broaden this to force network saved cards or save-user requirements; first-party auth is a WooPay express capability.

- [ ] **Step 4: Verify GREEN.** Rerun the same focused PHP method, then the full `WooPaymentsWooPaySessionServiceTest` class.

## Task 2: Card Test-Number Copy Feedback Parity

**Files:**
- Modify: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-checkout.js`
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js`
- Modify: `plugins/woocommerce/client/legacy/css/woopayments-checkout.scss`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/style.scss`

- [ ] **Step 1: Write RED tests for both card bundles.** Extend the existing copy-number tests so click handlers call `preventDefault()`, copy via `navigator.clipboard.writeText()` when available, fallback to `window.prompt( ..., testNumber )` when Clipboard API is unavailable, add `state--success` to the clicked button, and remove it after a timer. For Blocks, assert the existing payment response notice emitter or `@wordpress/a11y` announcement path if already available in the component; otherwise assert the DOM success state and fallback prompt without importing WooPay code.

- [ ] **Step 2: Verify RED.** Run `pnpm --dir plugins/woocommerce/client/legacy test:js -- woopayments-checkout.js --runInBand` and `pnpm --dir plugins/woocommerce/client/blocks test:js -- woopayments/test/index.js --runInBand`. Expected: failures on missing fallback/success behavior.

- [ ] **Step 3: Implement card-only copy feedback.** Add small local helpers in each card bundle. Use native `button` behavior, call `event.preventDefault()`, keep the icon decorative, and use `state--success` plus a scoped CSS style that survives forced colors through outline/currentColor rather than color alone.

- [ ] **Step 4: Verify GREEN.** Rerun both focused Jest commands and targeted style lint for the two SCSS files.

## Task 3: WooPay Connect, Preferred Card, and Product Preflight

**Files:**
- Modify: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-woopay.js`
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-woopay.js`
- Modify: `plugins/woocommerce/client/legacy/css/woopayments-woopay.scss`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/test/index.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/index.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/style.scss`

- [ ] **Step 1: Write RED tests for first-party auth.** In legacy and Blocks WooPay tests, set `isWoopayFirstPartyAuthEnabled: true`, click the WooPay button, assert the merchant request posts to `/?wc-ajax=wcpay_get_woopay_session`, assert the Connect iframe receives `{ action: 'setPreemptiveSessionData', value: response }`, and assert success redirects to the Connect response `redirect_url`. Also assert an `is_error` Connect response falls back to the existing `wcpay_init_woopay` OTP path.

- [ ] **Step 2: Write RED tests for preferred-card rendering.** Seed a cached preferred card and assert the button accessible name becomes `WooPay with Visa ending in 4242`, the last4 is visible, invalid cached data is ignored, and the default WooPay label remains when no card is present. For Blocks, keep the query/cache local to the WooPay bundle and avoid loading card/ECE bundles.

- [ ] **Step 3: Write RED tests for product-page preflight.** Add tests for product-page buttons where `.single_add_to_cart_button.disabled`, `.single_add_to_cart_button.wc-variation-selection-needed`, or `.single_add_to_cart_button.wc-variation-is-unavailable` prevents the `wcpay_add_to_cart` request and surfaces the configured confirmation error instead of opening WooPay. Keep server-side add-to-cart error handling intact.

- [ ] **Step 4: Verify RED.** Run `pnpm --dir plugins/woocommerce/client/legacy test:js -- woopayments-woopay.js --runInBand` and `pnpm --dir plugins/woocommerce/client/blocks test:js -- woopay/test/index.js --runInBand`. Expected: failures on missing first-party/preferred-card/preflight behavior.

- [ ] **Step 5: Implement WooPay helpers.** Add local WooPay Connect helpers for iframe injection, origin-checked postMessage listeners, timeout cleanup, `setPreemptiveSessionData`, and `getPreferredPaymentMethod`. Add preferred-card cache validation with brand aliases matching the reference (`american_express` to `amex`, `diners_club` to `diners`, `union_pay` to `unionpay`). Render first-party buttons as anchors with `href` to the WooPay host when enabled, keep existing buttons for OTP mode, show a loading state during first-party session exchange, and preserve the existing tracks events.

- [ ] **Step 6: Verify GREEN.** Rerun both WooPay Jest files, targeted ESLint for the touched JS files, and targeted Stylelint for the WooPay SCSS files.

## Task 4: Protected Admin Routes Fail Closed on Missing Matrix

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`

- [ ] **Step 1: Write RED tests.** Add tests showing `/woopayments/overview` renders `This WooPayments admin area is unavailable.` and does not lazy load when `adminRouteAvailability` is absent, and that a present matrix with no key for `/woopayments/documents` also denies that protected route. Keep settings, express checkout settings, and fraud-protection settings loadable.

- [ ] **Step 2: Verify RED.** Run `pnpm --dir plugins/woocommerce/client/admin test:js -- woopayments/admin/test/routes.test.tsx --runInBand`. Expected: failures because `isRouteAvailable()` currently returns `true` for missing matrix and missing route keys.

- [ ] **Step 3: Implement fail-closed protected route helper.** Change `isRouteAvailable()` so missing `allowedRoutes` and missing route keys return `false` for protected routes. Do not change the intentionally unprotected settings/express/fraud routes.

- [ ] **Step 4: Verify GREEN.** Rerun the focused admin route Jest file and exact-file ESLint for `routes.tsx` and the test.

## Task 5: Accumulated Checkout/Admin Gate

**Files:**
- Add or modify: `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs`
- Modify: `tools/woopayments-merge/HARNESS.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

- [ ] **Step 1: Add or widen the shopper checkout Playwriter gate.** Drive target and reference classic checkout card, Blocks checkout card, Blocks cart/checkout ECE, and WooPay product/cart/checkout surfaces where enabled. Capture screenshots, failed responses, page errors, console issues, split asset URLs, test-mode copy/test-card badge evidence, WooPay preferred-card/first-party availability evidence when deterministic, and Store API `extensions.wcpay.express_checkout_methods`.

- [ ] **Step 2: Include disabled-feature checks without masking bugs.** Where local settings can be toggled safely, assert disabled WooPay/ECE features do not show shopper buttons and do not load their dedicated split assets. If a state cannot be deterministic in the local account, record the source/Jest fallback explicitly rather than weakening the gate.

- [ ] **Step 3: Run accumulated gates.** Run focused PHP/Jest/lint/build gates from Tasks 1-4, `tools/woopayments-merge/bundle-size-gate.sh`, the existing admin browser/source gates, the new checkout browser gate, target/reference debug-log scans, target WooCommerce log scans, and `git diff --check -- . ':!.agents'`. Treat PHP notices/warnings as blockers unless source-verified unrelated.

- [ ] **Step 4: Review and close.** Dispatch spec/quality review agents over the final diff, fix source-backed findings, add a WooCommerce changelog, update staging/spec/implementation docs with exact evidence paths, commit locally in logical commits, and report the git range. Do not flip native admin readiness unless the final A4/N12 gate explicitly passes.

## Self-Review

- Spec coverage: This plan covers the source-backed WooPay first-party, preferred-card, product preflight, card copy-feedback, admin route fail-closed, and accumulated gate coverage gaps recorded in `analysis-a4ap-accumulated-checkout-admin-gate.md`.
- Boundaries: It does not touch WPCOM, does not edit generic WC checkout internals beyond the native provider route seam, and keeps card, WooPay, ECE, and admin assets split.
- Known caveat: If the local account cannot deterministically prove WooPay preferred-card data or a wallet availability state in-browser, the gate must record the limitation and rely on source/Jest for that narrow subclaim instead of changing the harness to pass.
