---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 14:08
last_updated: 2026-06-20 15:53
target: A4aq final accumulated A4/N12 gate
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4ap-accumulated-checkout-admin-gate.md
  - spec-conformance-baseline.md
  - staging-log.md
  - implementation-log.md
status: draft
---

# A4aq Final Accumulated Gate Analysis

> **Prompt:** "."

## Baseline

A4ap is now committed locally as source/tests `746469525d` plus changelog `6cbb4314c2`, with git range `c3b737269f...6cbb4314c2`. Product status is clean. Native admin readiness remains fail-closed and `spec-conformance-baseline.md` now includes A4ap.

The next coherent slice is not another single-feature parity patch by default. The remaining source-backed work is the final accumulated A4/N12 gate: drive the already-restored admin and checkout surfaces through a single evidence-producing matrix, cover disabled-feature/no-chunk/no-protected-REST behavior, record bundle/perf evidence honestly, scan target/reference logs, and only then make a fail-closed readiness decision. If the gate exposes product regressions, those regressions become product fixes inside A4aq before any readiness flip is considered.

## Local Harness Findings

`tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs` already provides a useful admin browser substrate: target/reference routes, desktop/mobile viewports, token assertions, resource timing, failed response and console/pageerror capture, expected target lazy chunks, unavailable-route forbidden asset checks, screenshot capture, progress JSON after every check, and an admin navigation assertion. It is still admin-only and token-oriented. It does not cover shopper checkout/cart/product/order-pay/my-account surfaces, disabled WooPay/express settings on shopper pages, or bundle/perf JSON capture.

`tools/woopayments-merge/a4-admin-surface-gate.py` remains the source/chunk/static admin gate. It measures route chunks and reference plugin admin baselines, rejects plugin-era route ownership, and checks single-registry guardrails. It is not a browser parity matrix and does not prove shopper checkout behavior.

`tools/woopayments-merge/bundle-size-gate.sh` can capture plugin/reference and core/target asset sizes for settings, classic card, Blocks card, express checkout, WooPay, and multi-currency assets. It records missing assets explicitly and compares via `compare-measured-gates.py`. This can support A4aq bundle evidence but needs an A4aq-specific budget/disposition because native Core owns split assets differently from the plugin.

`tools/woopayments-merge/HARNESS.md` still treats browser checkout matrix, admin screens, broad perf, full refund/payout/capture/multi-currency financial matrix, dispute lifecycle, and subscription renewals as judged runbooks. For A4aq, the goal should be to mechanize the accumulated admin/checkout browser matrix enough to fail closed on obvious parity regressions while recording honest limits for flows that cannot be deterministic locally.

## A4aq Working Shape

A4aq should add a new orchestrated Playwriter gate, rather than overloading the existing admin script. The script should reuse the admin browser gate patterns but define a combined matrix with separate surface groups: admin route reachability, admin disabled/unavailable protected-route behavior, checkout Blocks card, classic card, cart/checkout express checkout, WooPay express, and add-payment-method/order-pay/my-account payment method surfaces where deterministic local setup exists. The output should be one JSON evidence file under the session data directory with per-check progress, screenshots, failed responses, console/page errors, resource assets, expected/missing tokens, and explicit caveats.

The first implementation should not chase pixel-perfect automated visual diff. It should assert structural and asset parity at high signal: presence/absence of specific merchant/shopper controls, reference-style copy tokens, Stripe iframe mounting, card-brand asset paths, WooPay split asset loading, no standard saved-card checkbox when WooPay owns save-my-info, no WooPay asset when WooPay is disabled, no ECE asset when express checkout is disabled, protected admin chunks absent for unavailable protected routes, and clean logs. Screenshots remain evidence for human inspection and future visual-diff hardening.

The final gate should run source/chunk, browser matrix, bundle capture/compare, a measured perf capture or explicit perf limitation note, and log scans. Native admin readiness must remain fail-closed unless the gate passes without undispositioned product findings and the readiness flip is implemented as its own explicit final step with tests.

## Pending Subagent Findings

Three read-only explorer agents are running:

- Avicenna the 6th: current harness capabilities and minimal A4aq orchestrator shape.
- Gauss the 6th: shopper surface selectors, settings keys, assets, and remaining source-backed checkout caveats.
- Mencius the 6th: admin/account-state and disabled-feature matrix, route availability, settings keys, and deterministic limits.

Their findings must be reconciled into the A4aq plan before implementation.

## Subagent Findings Reconciled

Avicenna the 6th confirmed the harness direction. The current admin browser gate already has the right primitive shape: state-configured surface/viewport/store filters, progress JSON after every check, token and asset assertions, unavailable-route forbidden-chunk assertions, failed response/console/pageerror capture, and screenshots. The source/chunk gate, bundle gate, and perf gate are separate working CLIs, but no orchestrator currently runs them together, and no reusable checkout browser gate or log-scan gate exists. The recommended A4aq implementation is a local-only `a4aq-accumulated-gate.py` that runs admin source, admin browser, a new checkout Playwriter gate, deterministic disabled-feature checks where safe, bundle capture/compare with explicit budgets, perf capture/compare with honest incomplete handling, and target/reference log scans into one JSON rollup with pass/fail/incomplete status.

Gauss the 6th mapped the shopper gate selectors and caveats. Blocks card checkout should assert `woocommerce_payments_data` / `getPaymentMethodData( 'woocommerce_payments' )`, `paymentMethodsConfig.card`, native bridge flags, `isSavedCardsEnabled`, `#wcpay-core-blocks-payment-element`, `.wcpay-core-test-mode-instructions`, `[data-testid="payment-methods-logos"]`, `.payment-methods--logos-count`, and `#wcpay-core-payment-methods-popover`. Blocks cart/checkout express should assert `.wcpay-core-express-checkout`, `.wcpay-core-express-checkout__element`, `expressCheckoutParams.enabled_methods`, `expressCheckoutParams.payment_method_types`, `expressCheckoutParams.flags.isEceUsingConfirmationTokens`, and Store API `cart.extensions.wcpay.express_checkout_methods`. Classic checkout/add-payment should assert `window.wcpay_core_checkout_config`, `#wcpay-core-checkout-form[data-wcpay-config]`, `#wcpay-core-payment-element`, `#wcpay-core-payment-errors[role="alert"]`, `input[name="payment_method"][value="woocommerce_payments"]`, `#add_payment_method`, hidden `wcpay-setup-intent`, and negative add-payment assertions for no WooPay/ECE buttons. Product/cart/order-pay express and WooPay should assert `window.wcpayExpressCheckoutParams`, `.wcpay-express-checkout-wrapper`, `#wcpay-express-checkout-element`, `#wcpay-express-checkout-button-separator`, `window.wcpay_core_woopay_config`, `#wcpay-woopay-button`, `#wcpay-woopay-button[data-product_page="1"]`, `.woopay-express-button[aria-label="WooPay"]`, and `#woopay-connect-iframe` only when a click actually initializes Connect.

Gauss also flagged caveats that should not become false failures: native checkout is card-only right now, so BNPL/local payment-method fields should not gate A4aq; reference ECE has `methods_enabled_at_location` while Core uses Store API `extensions.wcpay.express_checkout_methods`; reference combined express output includes `#wcpay-express-checkout__order-attribution-inputs` but Core split controllers do not; WooPay order-pay derives only product/cart/checkout context and should stay caveated; Blocks cart/checkout should assert `.wcpay-core-express-checkout__element`, not the classic `#wcpay-express-checkout-element`.

Mencius the 6th mapped the admin/account-state and disabled-feature source. Admin routes are registered through `client/admin/client/settings-payments/register-provider-routes.ts` and `provider-routes.tsx`, with native WooPayments routes in `client/admin/client/woopayments/admin/routes.tsx`. Settings, express checkout settings, and fraud settings are unprotected; protected admin surfaces use `WooPaymentsProtectedRoute()`. PHP account-state and persistent navigation are owned by `WooPaymentsAdminNavigationController::preload_shared_settings()`, `get_admin_route_availability()`, `get_admin_route_account_state()`, `add_menu_items()`, `get_menu_items()`, `get_full_menu_items()`, `get_reduced_menu_items()`, and `redirect_legacy_payment_paths()`, backed by `WooPaymentsAccountService` predicates such as `is_gateway_enabled()`, `has_account()`, `is_details_submitted()`, `has_valid_account_for_admin_navigation()`, `is_account_rejected()`, `is_account_under_review()`, `is_reports_enabled()`, `is_card_present_eligible()`, `has_card_readers_available()`, `has_previous_capital_loans()`, and `is_documents_enabled()`.

The deterministic account-state gate should cover gateway disabled, no account, details not submitted, details submitted but card payments unavailable/unrequested, rejected/under-review reduced access, full minimal account, and optional reports/card readers/capital/documents route/menu openings. Optional route real payloads can be source/Jest fallback if local account data cannot produce live payloads, but route/menu/chunk behavior is deterministic. The disabled-feature gate should cover `payment_request=no` plus empty express arrays suppressing ECE shopper assets/buttons, per-context express arrays for product/cart/checkout, `platform_checkout=no` suppressing WooPay frontend hooks/assets, context-excluded WooPay suppressing express buttons while recording save-user asset caveats, Blocks contexts suppressing classic assets, Link-enabled WooPay admin toggle disablement as source/Jest fallback, and admin Stripe preview HTTPS/preview-config behavior as source/Jest fallback.

## A4aq Implementation Evidence

The local harness now has `a4-checkout-browser-gate.playwriter.mjs`, `a4aq-accumulated-gate.py`, and `a4aq-bundle-budget.json` under ignored `tools/woopayments-merge`. The checkout gate writes progress after every route, captures screenshots and JSON evidence, and covers Blocks card checkout, Blocks checkout express, Blocks cart express, classic checkout card, My Account add-payment-method, and product express/WooPay surfaces for target and reference across desktop and mobile. It records exact ignored local third-party/browser noise rather than dropping it silently.

Target/reference fixture corrections made during implementation: the correct target WP-CLI is `docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1`; a broad `grep -- '-cli-1'` can select wpcom-local and is unsafe. Target product fixture is `h20-ece-probe-product` ID `189`; reference fixture is ID `492`; classic checkout pages are `codex-classic-checkout` on target and `codex-reference-classic-checkout` on reference. Playwriter state is now reset through the orchestrator before browser gates so stale `state.gateSlug`, `surfaceIds`, `viewportIds`, or `storeIds` cannot misfile or narrow a run.

Checkout browser evidence is green for the full deterministic matrix: `data/a4aq-checkout-full-smoke-2/a4aq-checkout-full-smoke-2-checkout-browser-gate.json` passed 24/24, and the post-review full A4aq orchestrator reran the checkout browser gate successfully with evidence at `data/a4aq/a4aq-checkout-browser-gate.json`. The gate validates target and reference structural/asset parity for the bounded surfaces, including native selectors/assets, reference plugin selectors/assets, card test-mode copy, brand-overflow behavior, WooPay/ECE split assets, direct Store API cart extension reads, and viewport-aware card-logo tokens. It now treats checkout console warnings as fail-by-default unless they match explicit expected-warning metadata keyed by store, surface, source URL/stack owner, and count. It still does not claim 3DS/SCA, redirect, saved-method mutation, order-pay WooPay, or pixel-perfect visual diff.

Admin browser evidence is fail-closed `incomplete`, not green parity, after review hardening. The browser route checks still render correctly for available routes and the exact reference-only local preview exception remains scoped to `settings-express-*` reference surfaces, but the aggregate gate now classifies six target protected route checks as incomplete because the current local account state makes them unavailable. This prevents unavailable-route guard evidence from being counted as available-route merchant parity.

Bundle evidence is green only under an explicit A4aq budget. `a4aq-bundle-budget.json` names the intentional split-asset/presence differences and adds a narrow allowance for `classic-card.css` growth after source verification that the added bytes are checkout parity styling. The default remains strict for unmentioned assets; there is no broad allow-new budget.

The full accumulated run at `data/a4aq/a4aq-accumulated-gate.json` is fail-closed `incomplete`, not pass, with zero failures and two incomplete reasons. First, `admin-browser` records six target protected route checks as unavailable-guard coverage rather than available-route parity. Second, `perf-compare` exits `3` because `process_payment`, `refund`, and `capture` probes on both stores returned `requires_fixture`, and `rest_boot` route-registration status was `preinitialized` on both stores. The measured perf signals that did run were green: gateway count `26 -> 4`, action callback count `6 -> 2`, external requests `0 -> 0`, gateway registration median `0 -> 0`, controller instantiation count `31 -> 13`, autoload bytes `115833 -> 85363`, and `wcpay_account_data` autoload `off` on both sides. These are coverage gaps, not source-backed product regressions, but they block treating A4aq as the final green N12 readiness gate.

Target log scanning is green after fixing the orchestrator to judge status from target diagnostics instead of letting prior reference noise contaminate the target pass/fail decision. Reference diagnostics are recorded as limitations. The full A4aq run's `log-scan` check passed with no target PHP/WP notices, warnings, deprecations, fatals, uncaught exceptions, database errors, stack traces, or actual 5xx markers in the gate window.

Review hardening is complete. The final focused code-review pass first found three harness false-green risks: unavailable target admin routes passing as browser parity, broad target checkout warning ignores, and insufficient local-only WP-CLI validation. The harness now marks unavailable target protected routes as aggregate incomplete, uses explicit expected console-warning rules with `expectedRuleId` evidence and count limits, rejects remote/shell-expanded WP-CLI forms in both the orchestrator and standalone perf capture, and passed the reviewer recheck with critical/high/medium counts all zero.

Current readiness disposition: native admin readiness remains fail-closed. A4aq establishes useful accumulated checkout/browser/bundle/log evidence and a safer fail-closed harness, but the final A4/N12 exit gate is incomplete until account-state protected-route coverage and deterministic perf money fixtures are supplied or explicitly resolved through later gates.
