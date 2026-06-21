---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 02:13
last_updated: 2026-06-18 02:44
target: N7c measured perf and bundle coverage after H20
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - staging-log.md
  - analysis-h20-product-page-express-checkout.md
status: complete
---

# H21 N7c Perf And Bundle Refresh

> **Prompt:** "Make sure you don't over-index on what is reliably measurable. Best to be honest about the possibilities and not chase unreliable numbers. Or at least use them honestly as stop gaps for big deltas in performance to inform that we may be doing something wrong, if their variability is high. But give it a good shot first."

## Initial Orientation

After H20, the source-backed shopper-facing express checkout gaps are materially smaller: cart/checkout, Amazon Pay, pay-for-order, and product-page ECE now have native owner code, split bundles, focused tests, browser evidence, and restored harness passes. The remaining pre-A4/A5 verification blocker most aligned with the latest instruction is N7c: measured bundle/perf coverage still needs to be refreshed against the accumulated runtime and represented honestly.

The existing N7c evidence is mixed. `bundle-size-gate.sh` and `perf-surface-gate.sh` exist in the ignored harness, and H17/H18/H19/H20 recorded useful signals. However, older bundle failures included assets that later slices intentionally implemented or changed, so the gate must be rerun against the current branch before acting on stale failures. Local browser and WP-CLI timings are variable and should be treated as large-delta smoke only unless repeatability is demonstrated. Deterministic evidence is stronger for asset presence, conditional loading, raw/gzip byte deltas, query counts, callback counts, autoload size, REST controller counts, and missing fixture classification.

## Source-Backed Current State

- `staging-log.md` says N7c remains not green after H17 because capture timing lacked a real `_intention_status=requires_capture` fixture, REST route-registration timing was preinitialized, bundle-size remained a separate fail, and money-path timing/query numbers are smoke only.
- H18 and H19 added split non-WooPay express checkout assets and budgeted bundle captures. H20 added product-page ECE within the existing classic ECE bundle and explicitly kept broad profile mapping as noisy.
- `review-agent-findings.md` records that the earlier bundle failures were a mix of true product gaps and budget/disposition issues. Some of those true product gaps, especially non-WooPay express checkout JS/CSS and card styling, have since had implementation work. Multi-currency admin/setup/analytics/switcher assets and settings CSS may still need fresh classification.
- The latest user guidance changes the evidentiary bar: run the measurements, but do not promote unstable local timings to exact performance proof. A fail-closed `INCOMPLETE` is more honest than a fake green when fixtures or stable measurements are unavailable.

## H21 Candidate Scope

H21 should refresh N7c rather than start A4/A5. The slice should:

1. Capture current reference and target bundle profiles with the existing harness.
2. Compare them with current budgets and classify every failure as fixed by H18-H20, true current code gap, intentional Core bundling difference needing budget/disposition, or unreliable mapping.
3. Capture current perf-surface profiles with available real fixture IDs and clearly report unavailable capture/REST timing fixtures as incomplete rather than pass.
4. If the refreshed gates expose source-backed asset loading or code regressions in current native WooPayments surfaces, fix those in Core with focused tests/build gates.
5. Update `spec-conformance-baseline.md`, `review-agent-findings.md`, `staging-log.md`, and `implementation-log.md` so future agents inherit the current N7c verdict, not stale pre-H18/H19/H20 classifications.

## Measurement Stance

Raw/gzip bytes, missing assets, unexpected loaded assets, duplicated callbacks, query-count deltas, autoload bytes, and route/controller counts are actionable if the harness inputs are current and the mapping is correct. Local wall-clock timings for checkout pages, WP-CLI process payment, refund, or REST boot are advisory smoke signals unless repeated enough to show a stable large delta. Capture/auth timing remains blocked until a real authorization fixture exists; the gate should state that directly.

## Fresh Bundle And Perf Refresh

The current H21 strict bundle compare still fails for `classic-card.css`, several multi-currency/admin assets, `settings-main.css`, the intentional split `blocks-express-checkout.*` target assets, and the `woopay-direct-checkout.js` logical mapping. With the historical H18 budget the same captures pass, but that pass only proves the budget allows the deltas. It does not prove every missing standalone plugin asset has equivalent Core styling or behavior.

The strongest source-backed H21 code gap is `classic-card.css`. The reference `dist/checkout.css` contains classic card payment method styling for test-mode instructions, the copy-test-card button, PaymentElement containers, brand logos, and payment method label layout. Core currently has classic WooPayments card JS and block payment method CSS, but no standalone classic card stylesheet mapped or enqueued for the classic checkout card surface. This lines up with the shopper-facing styling concern and should be fixed before treating the bundle gate as meaningful.

The current measured perf compare is favorable on structural numbers but remains incomplete: process payment and refund probes show lower query/external-request counts in target than reference, autoload bytes are lower, gateway/controller counts are lower, and `wcpay_account_data` remains non-autoloaded. Capture is still missing a real authorization fixture and REST route-registration timing is still preinitialized. Those incomplete surfaces stay blockers for a full measured perf PASS, while the observed timings remain smoke only.

## Subagent Findings

Aquinas confirmed read-only that H18-H20 resolved the stale non-WooPay express checkout missing-asset failures: classic and block express checkout assets now exist in target, and `blocks-express-checkout.*` is an expected Core split that the budget should allow as `allow_new`. Aquinas also confirmed `classic-card.css` is still a current disposition issue, while the multi-currency/admin assets and settings CSS need separate product classification rather than a blanket perf pass.

Kant confirmed read-only that N7c reporting should not describe `verify.sh` 7/7 as a measured perf pass. Bundle gates, measured perf gates, and the narrow deterministic verify harness need separate statuses. Kant also reinforced that local timing numbers should be reported as smoke only, and that missing capture fixtures or preinitialized REST route timing must leave the measured perf gate incomplete.

## H21 Implementation Result

H21 fixed the current source-backed classic checkout styling gap rather than treating it as a budget-only exception. Core now has a normal classic asset source at `plugins/woocommerce/client/legacy/css/woopayments-checkout.scss`, registered as `wc-woopayments-checkout` through `WC_Frontend_Scripts`, and enqueued by `WooPaymentsCheckoutBridge::render_payment_fields()`. The bridge test now asserts the Core-owned classic checkout style is registered and enqueued with the classic script. The stylesheet restores the reference classic card surface pieces that were missing in native: test-mode instruction spacing, copy-test-card affordance, PaymentElement container spacing, card brand logo row, the `+ N` count badge, and theme compatibility layout rules.

Browser verification on a target-only shortcode checkout page found a second same-surface style gap: the Core-rendered WooPay save-user phone input was using default browser sizing because only the WooPay express button path had been styled. H21 keeps that fix inside `woopayments-woopay.scss`, not the card stylesheet, so WooPay-specific CSS remains bundled with the WooPay surface. Post-fix computed styles showed the WooPay save-user container spacing and phone input full-width field styling applied through `woopayments-woopay.css`.

## H21 Gate Evidence

Bundle refresh: reference capture `data/n7-measured-gates/ref-bundle-h21-20260618-0224.json`, target capture `data/n7-measured-gates/target-bundle-h21-20260618-0245.json`, and budget `data/n7-measured-gates/h21-bundle-budget.json` passed. The current `classic-card.css` mapping is no longer missing: after trimming unused popover rules and avoiding inline SVG data in CSS, the mapped native classic card CSS delta is `+120` raw bytes and `+23` gzip bytes. The WooPay classic CSS mapping has headroom because the H21 gate compares the classic WooPay stylesheet used by the current browser surface, not an unrelated Blocks WooPay stylesheet.

Perf refresh: reference capture `data/n7-measured-gates/ref-perf-h21-20260618-0232.json` and target capture `data/n7-measured-gates/target-perf-h21-20260618-0232.json` remain `INCOMPLETE`, not green. Structural counts are favorable or within tolerance: process payment queries `31 -> 23`, process external requests `15 -> 2`, refund queries `6 -> 7`, refund external requests `3 -> 2`, gateway count `26 -> 4`, payment-gateway callbacks `6 -> 2`, REST controllers `28 -> 4`, autoload bytes `116319 -> 86567`, and `wcpay_account_data` remains non-autoloaded. Local timing numbers are recorded only as smoke: process median `33.38ms -> 23.76ms`, refund median `5.28ms -> 7.26ms`. Capture/auth still lacks a real `requires_capture` fixture and isolated REST route-registration timing is still preinitialized.

Browser evidence: `data/h21-classic-checkout-payment.png` captured the restored classic card CSS loading on target, and `data/h21-classic-checkout-payment-after-woopay-style.png` captured the follow-up WooPay save-user field styling. Playwriter evidence confirmed `woopayments-checkout.css` and `woopayments-woopay.css` loaded on the classic checkout surface, with no Blocks card stylesheet required for the classic card method. Browser console output showed only local Stripe Apple Pay/Google Pay domain/HTTP warnings and sandbox amount logs during this surface check; no WP notices or fatal errors were seen.

Verification evidence: the bridge regression test failed before the style enqueue implementation and passed after. Focused `WooPaymentsCheckoutBridgeTest` passed with 7 tests and 99 assertions. Focused classic Jest for `woopayments-woopay.js` and `woopayments-checkout.js` passed with 18 tests. Source-only PHPStan passed for `includes/class-wc-frontend-scripts.php` and `src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php`. `lint:php:changes`, branch `lint:changes:branch`, `git diff --check`, focused Stylelint for the two changed WooPayments SCSS files, and the normal classic CSS build passed. The full legacy CSS lint remains a pre-existing repo-wide SCSS/config failure, so H21 used focused changed-file Stylelint plus the normal asset build.

Commits: source/tests `67c49c4e29` (`fix(payments): restore native classic checkout styles`) and changelog `2d26bd5c27` (`chore(payments): add classic checkout styles changelog`). Git range: `f939e59102...2d26bd5c27`.

## Remaining N7c Disposition

H21 converts the current classic card CSS failure from a missing-asset product gap into a measured Core-owned asset with a small explicit budget. It does not make N7c fully green. The remaining measured perf status is fail-closed/incomplete for auth/capture fixtures and isolated REST route-registration timing. Multi-currency/admin/settings asset classifications remain future bundle-disposition work; they should not be hidden under the H21 pass because this slice verified shopper classic checkout card/WooPay surfaces and refreshed the post-H20 bundle mapping, not all admin or multi-currency frontend assets.
