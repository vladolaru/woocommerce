---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 12:05
last_updated: 2026-06-20 14:07
target: A4ap accumulated checkout/admin gate after A4ao
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - spec-conformance-baseline.md
  - staging-log.md
  - implementation-log.md
  - review-a4ao-blocks-express-checkout-element-parity.md
status: verified
---

# A4ap Accumulated Checkout/Admin Gate Analysis

> **Prompt:** "ok. continue"

## Current Baseline

A4ao is committed locally as source/tests `e34b3f96f2` plus changelog `c3b737269f`, with git range `bdc86d6ea1...c3b737269f`. Product status was clean before this analysis, and `spec-conformance-baseline.md` has now been backfilled through A4ao so later agents do not treat A4ak-A4ao as undocumented residuals.

Native admin readiness remains intentionally fail-closed: `WooPaymentsCutoverController::get_preflight_failures()` still calls `apply_filters( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, false )`, and `WooPaymentsCutoverControllerTest::test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes()` asserts the default blocker.

The next coherent slice should not reopen the already-closed single-surface parity work unless new source or browser evidence proves a regression. The current highest-leverage gap is that the accumulated A4/N12 evidence still rests on a mix of per-slice focused browser proofs, source gates, and runbook notes rather than one widened final gate that drives the reference and target through the accumulated merchant/shopper surfaces.

## Local Source Findings

- `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs` is an admin-only browser gate. It drives target/reference admin routes, checks token presence, screenshots, failed responses, console issues, page errors, expected target lazy chunks, unavailable-route no-chunk behavior, and target WP-admin navigation links. It writes progress JSON after each result, which satisfies the recent progress-output ask for this script.
- The admin browser gate does not cover shopper checkout, product, cart, order-pay, or my-account payment-method surfaces. HARNESS.md still lists the browser checkout matrix as a judged runbook rather than an automated gate, and explicitly says `flow-drive.sh` does not cover browser/Stripe.js flows.
- `tools/woopayments-merge/bundle-size-gate.sh` captures checkout asset byte sizes for classic card, Blocks card, classic express checkout, Blocks express checkout, WooPay, and multi-currency assets. It can support the A4ap evidence, but by itself it does not prove conditional loading only when a merchant enables a feature.
- The existing admin source/browser gates are useful guardrails, but they are not enough for the final N12 claim because they do not assert reference-vs-target visual/copy parity beyond selected text tokens, do not drive account-state variants in-browser, and do not drive disabled-feature no-chunk/no-REST behavior across shopper checkout assets.
- Native admin route availability is source-gated by account state in `WooPaymentsAdminNavigationController`: settings is always available when native registers, onboarding is available for onboarding state, overview/transactions/disputes are available for protected full/restricted states, and payouts/reports/card readers/capital/documents are full-account/feature gated. Submenus are registered only when the native runtime should register, the user has `manage_woocommerce`, and the gateway is enabled.

## Subagent Findings Reconciled

Sagan the 6th source-verified the admin readiness contract. Native readiness still defaults fail-closed in `WooPaymentsCutoverController`; route availability and submenus are account-state-aware; protected React routes are gated before lazy chunk import; and settings/express/fraud routes remain intentionally loadable when protected money-movement/admin routes are denied. Two caveats need final-gate treatment: the client protected-route helper currently defaults open if `adminRouteAvailability` is missing, and some optional backend endpoints such as mobile/card-reader routes are capability/runtime-gated but not uniformly account-state gated. A4ap should either make missing route availability fail closed or prove the preload contract, and should not claim API-level account-state parity without explicitly checking or dispositioning optional REST routes.

Huygens the 6th source-verified the gate coverage gaps. The current gates do not yet automate the account-state browser matrix, require every conditional persistent menu route, assert no protected REST calls on unavailable surfaces, compare semantic visual/copy/grouping parity beyond token smoke, or enforce an aggregate admin bundle budget. The recommended harness direction is an A4/N12 orchestrator that runs source/chunk gates, state-matrix browser checks, semantic parity, unavailable no-chunk/no-REST assertions, log scans, bundle budgets, and perf evidence with progress output. This should complement, not replace, the current admin source/chunk and browser smoke gates.

Hilbert the 6th source-compared checkout frontend/assets and found no new concrete drift in the A4am/A4ao classic card, Blocks card, or Blocks Express Checkout Element source. The remaining checkout parity gaps are WooPay-specific plus shared test-card copy feedback:

- Native hardcodes `isWoopayFirstPartyAuthEnabled` to `false` in `WooPaymentsWooPaySessionService::get_woopay_frontend_config()`, while the reference enables the flag when WooPay express checkout is available for the account/country and uses WooPay Connect for preemptive session and preferred-card flows. Local source verification confirms native already has the encrypted WooPay session AJAX route and nonce; the missing pieces are the frontend Connect/preemptive-session path, preferred-card query/cache/rendering, and the config predicate.
- Native WooPay product-page click handling serializes the product form and posts `wcpay_add_to_cart` without the reference disabled/unavailable add-to-cart preflight. Server validation still exists, so this is a shopper-facing UX parity gap rather than a data-safety gap.
- Native classic and Blocks test-card copy handlers only call `navigator.clipboard.writeText()` and return when Clipboard API is unavailable. The reference prevents default, falls back to `prompt()`, emits the checkout snackbar, and toggles a success state on the copy button.

The explorer accidentally wrote its standalone artifact to `.agents/scratchpad/sessions/2026-06-20-native-woopayments-checkout-coverage/analysis.md`. These findings are now centralized here under the required active session.

## Provisional A4ap Shape

A4ap should be a broad gate-hardening and accumulated verification slice, not a narrow product implementation by default. The slice should add a shopper checkout browser gate alongside the existing admin gate, run it against both local stores, fold the results into the A4/N12 closeout evidence, and fix any real product regressions it surfaces.

The gate should cover at least:

- Classic checkout card row: test-mode badge/copy, test-card number copy control, Payment Element container, brand-logo row/overflow behavior, and split WooPayments classic card assets.
- Blocks checkout card row: test-mode badge/copy, Payment Element iframe, card asset loading, and no cross-coupling with WooPay/ECE.
- Blocks cart and checkout express surfaces: WooPay button, Stripe Express Checkout iframe, Store API `extensions.wcpay.express_checkout_methods`, split WooPay/ECE assets, and failed-response/console cleanliness.
- Product/cart WooPay or express button surfaces where enabled in the current local account state; if a surface is not deterministically available over local HTTP, record the source/Jest fallback explicitly.
- Disabled-feature checks for WooPay and express checkout where the local store can toggle the setting without destructive account changes: disabled features should not load their dedicated chunks or show merchant/shopper buttons.
- Reference-vs-target screenshots and structured evidence files for every driven surface, plus target/reference debug-log and WooCommerce-log scans after the browser pass.

The product work that should fold into A4ap before the accumulated gate runs is the source-backed WooPay/test-card residual cluster: first-party auth/preferred-card runtime parity, product-page disabled/unavailable variation preflight, and test-card copy fallback/snackbar/success state for classic and Blocks card surfaces. These gaps are at the same checkout/WooPay abstraction level and will otherwise make a checkout matrix gate fail.

The slice should not flip `FILTER_NATIVE_ADMIN_SURFACES_READY` unless the widened accumulated gate actually passes and the remaining N12 account-state/browser/log/bundle conditions are explicitly recorded as green or honestly limited. If the gate exposes additional product issues, those issues become A4ap product fixes before any final readiness decision.

## Open Inputs

The first Playwriter reconnaissance attempt failed at the Playwriter relay with `Error: fetch failed` before page navigation evidence was collected. Do not treat that as a product failure; rerun with a fresh Playwriter session or use the existing harness scripts once A4ap implementation starts.

## Source Decisions Before Implementation

Local source checks confirm that the WooPay first-party-auth backend gap is narrow: `WooPaymentsWooPaySessionService::get_woopay_frontend_config()` already computes `$is_woopay_enabled`, `$is_country_available`, `$should_show_woopay`, and `$woopay_express_available`; only `isWoopayFirstPartyAuthEnabled` is still hardcoded to `false`. The existing test fixture defaults to a US, `platform_checkout_eligible` account with WooPay enabled for product/cart/checkout methods, so the RED test can assert the flag is true in the normal checkout config and false for non-US, ineligible, disabled platform checkout, or context-disabled express methods without inventing new account mocks.

Native first-party session plumbing is partially present: `WooPaymentsWooPaySessionController` registers `wcpay_get_woopay_session`, and `WooPaymentsWooPaySessionService` returns the encrypted `blog_id` / `data.session` / `data.iv` / `data.hash` payload that WooPay Connect expects. The missing frontend runtime pieces are the merchant AJAX request to `wcpay_get_woopay_session`, a WooPay Connect iframe/postMessage helper for `setPreemptiveSessionData` and `getPreferredPaymentMethod`, preferred-card caching/validation/rendering, and fallback to the existing OTP/minimum-session flow when WooPay Connect fails or reports `is_error`.

The product-page WooPay preflight should stay shopper-facing and not replace server validation. The reference blocks disabled add-to-cart buttons and unavailable variation states before posting `wcpay_add_to_cart`, while native currently serializes whatever form data is present and waits for the server response. A4ap should add the same preflight guard to native WooPay button handlers and cover it in the existing WooPay unit tests.

The test-card copy gap is shared by classic and Blocks card checkout: both handlers currently require `navigator.clipboard` and do not prevent default, fallback to `prompt()`, announce success, or add a short success state. A4ap should implement the same behavior in both split card bundles, with CSS scoped to the existing `js-woopayments-copy-test-number` control and without loading WooPay-specific code from card surfaces.

The admin route helper caveat is source-backed and small enough to fold into A4ap: protected routes currently default open when `adminRouteAvailability` is missing or a route key is absent. The PHP preload contract makes the full matrix available during normal native boot, but a missing client-side matrix should fail closed for protected routes because A4/N12 readiness depends on no protected chunk loading before account-state authorization is known.

## Closeout Findings

A4ap became an accumulated checkout/admin parity implementation slice rather than only a harness-hardening slice because the widened proof exposed source-backed shopper-facing drift. The implemented fixes stay at the existing card/WooPay/admin abstractions: protected admin routes now fail closed when route availability is missing or denies a protected route, while settings/express/fraud settings remain loadable through the Core Settings > Payments seam; WooPay first-party auth now follows actual WooPay express availability and country eligibility; WooPay express frontend runtime now includes Connect preemptive session, preferred-card retrieval/cache/rendering, OTP fallback, product disabled/unavailable preflight, and scoped styling; classic and Blocks card checkout now share the reference copy fallback/success behavior for the test-card number; card Elements options now restore Link/wallet/terms behavior at the card surface rather than tying it to WooPay; Blocks appearance extraction now preserves the floating-label decision and stops clamping `fontSizeBase` to the payment-method label; saved-card controls now follow backend `isSavedCardsEnabled` and card `showSaveOption` so logged-in WooPay subscription checkouts show only the WooPay save-my-info control; WooPayments card-brand assets now use the reference-style Core-owned artwork with WooPayments-only JCB/UnionPay SVGs.

Fresh focused gates after the final card-brand and saved-card fixes are green: Blocks card Jest 29 tests, classic card Jest 21 tests, Blocks WooPay Jest 8 tests, classic WooPay Jest 11 tests, admin routes Jest 31 tests, `WooPaymentsCheckoutBridgeTest` 10 tests / 112 assertions, `WooPaymentsWooPaySessionServiceTest` 27 tests / 121 assertions, scoped frontend ESLint, Blocks stylelint, changed-file PHP lint, production PHPStan for the touched WooPayments PHP classes, changelog validation, and `git diff --check`. Previous A4ap bundle gates also passed for Blocks, classic assets, and admin assets with only existing build warnings. The broad legacy `lint:lang:css` command remains unusable as a slice gate because it reports thousands of existing repository/stylelint-SCSS configuration findings; scoped direct stylelint for the touched legacy files exits 0 but prints the known custom-syntax warning, while the classic asset build is the meaningful CSS build proof.

Playwriter compared native and reference checkout at the same viewport. Both render the compact one-row Stripe card field layout in that width, so the difference from the earlier wide reference screenshot is viewport-responsive Stripe behavior, not native drift. Native now renders the test-mode badge/instructions, copy button, reference-style Visa/Mastercard/Amex/Discover artwork plus the `+ 2` overflow dialog with JCB/UnionPay, mounted Stripe PaymentElement, subscription future-payment terms, and no standard saved-payment checkbox when WooPay owns the save-my-info surface. WooPay express renders from its split WooPay bundle as a 48px purple branded button with `aria-label="WooPay"`. Screenshots: `$TMPDIR/native-checkout-payment-a4ap-final.png`, `$TMPDIR/native-checkout-express-a4ap-final.png`, and the prior same-width reference comparison `$TMPDIR/reference-checkout-payment-a4ap-after-save-option-fix.png`.

Runtime log scans after the browser proof found no native PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, or actual 5xx markers in the target Docker output or target `debug.log`. The reference store still has older unrelated stack notices, including Zoho MU-plugin warnings and an early `woocommerce-payments` textdomain notice; those are comparison-store context, not target regressions. A4ap is committed locally as source/tests `746469525d` plus changelog `6cbb4314c2`, with git range `c3b737269f...6cbb4314c2`. Native admin readiness remains fail-closed and A4/N12 is not complete until the final accumulated gate is explicitly widened and passed.
