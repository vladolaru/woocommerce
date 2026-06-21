---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 07:01
target: H28 N7c measured perf and bundle closeout
reconciles:
  - analysis-h21-n7c-perf-bundle-refresh.md
  - review-agent-findings.md
  - staging-log.md
  - spec-conformance-baseline.md
last_updated: 2026-06-18 07:34
status: final
---

# H28 N7c Measured Perf And Bundle Closeout

> **Prompt:** "Make sure you don't over-index on what is reliably measurable. Best to be honest about the possibilities and not chase unreliable numbers. Or at least use them honestly as stop gaps for big deltas in performance to inform that we may be doing something wrong, if their variability is high. But give it a good shot first."

## Orientation

H28 resumes N7c after H24-H27. H21 already fixed the native classic checkout card CSS gap and made the perf comparator more honest, but H21's capture/auth fixture blocker is now stale because H24 added deterministic manual-capture drivers and H27 added converted-currency fixtures. The current N7c work should refresh the measured gate with those newer fixtures, while keeping timing claims conservative and treating missing source-backed assets as blockers or tracked follow-up slices rather than budget-only exceptions.

## Source-Backed Current State

- `bundle-size-gate.sh` still records target `multi-currency-admin.css`, `multi-currency-analytics.js`, `multi-currency-switcher-block.js`, and `multi-currency-setup.css` as missing while H21's budget allows them missing. That means H21's bundle PASS is not enough for N7c; it was an explicit exception list.
- Core multi-currency settings has a native wp-admin script at `client/admin/client/wp-admin-scripts/multi-currency-settings`, and its TSX uses `woocommerce-multi-currency-settings*` class names. There is no SCSS file in that entry and `MultiCurrencySettingsProjectionService::get_admin_asset_manifest()` exposes only a script, so the native settings surface is source-backed as script-only.
- The reference extension has settings SCSS under `includes/multi-currency/client/settings/multi-currency/`, and the native surface is not a one-for-one markup copy, so H28 should implement Core-appropriate styling for the Core-owned settings UI instead of mechanically hoisting extension CSS.
- Core already has backend multi-currency analytics hooks in `MultiCurrencyAnalyticsController`, but I have not yet verified a native admin analytics UI that needs a separate `multi-currency-analytics.js` bundle. Until that source verification is done, the missing analytics JS remains an asset-disposition blocker, not a product-code target for this slice.
- Core has `MultiCurrencySwitcherBlockController` and server-side block rendering. The reference `multi-currency-switcher-block.js` is an editor script for the WooPayments block. The native runtime has not yet been source-verified for editor-side switcher parity, so this stays a tracked product/disposition blocker unless H28 proves otherwise.
- The reference `wcpay-multi-currency-setup` chunk comes from the WooPayments setup/task UI. Core's current native settings flow lives in WooCommerce settings, so setup CSS should not be budget-allowed as measured parity without a canonical A4/B3-style setup-surface decision.

## Subagent Findings

Newton the 2nd confirmed read-only that H18-H21 resolved the old shopper bundle blockers for classic card, classic express, Blocks express, and WooPay bundle mapping. Newton flagged the remaining source-backed/not-product-proven assets as multi-currency admin CSS, analytics JS, switcher editor JS, and setup CSS, and recommended refreshing bundle/perf captures with a fresh manual-capture fixture rather than reusing already captured H27 orders.

Hume the 2nd confirmed read-only that provider-event cutover remains blocked separately. `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` still contains account, refund, invoice, and notification event groups, and cutover still fails with `provider_events_undispositioned`. Hume recommended refund webhook migration as the next provider-event slice, but that is not H28 because the current active N7c blocker is measured perf/bundle coverage.

## Measurement Stance

The gate should favor stable structural facts: asset presence, raw/gzip size, conditional bundle separation, callback counts, duplicate gateway IDs, provider-boundary external-request counts, query counts, autoload bytes, and explicit incomplete classifications. Local `median_ms` values can be recorded and can fail on very large deltas, but they are not proof of exact latency parity. If a probe has high variance, H28 should say so and use the number only as a smoke signal for “something is probably wrong,” not as a precise benchmark.

## H28 Candidate Scope

H28 should refresh N7c as one coherent chunk: add the Core-owned multi-currency settings stylesheet and PHP asset registration if RED coverage confirms the gap, update the bundle gate mapping/budget so that CSS is measured rather than silently allowed missing, run fresh reference/target bundle captures, drive fresh disposable process/refund/capture fixtures on both stores, run the measured perf compare, and record any remaining missing assets or route-timing gaps honestly. If analytics, switcher editor, or setup assets are not product-proven within this slice, they remain tracked blockers for their canonical stage rather than being hidden inside a green N7c claim.

## Implementation Notes

- Added Core-owned `style.scss` for `client/admin/client/wp-admin-scripts/multi-currency-settings` and imported it from the wp-admin entry so the stylesheet is built through the normal WooCommerce admin webpack workflow.
- Extended `MultiCurrencySettingsProjectionService::get_admin_asset_manifest()` with a style manifest and updated `MultiCurrencySettingsController` to require, register, and enqueue the companion admin stylesheet from `assets/client/admin/multi-currency-settings/style.css`.
- Updated the ignored local bundle gate mapping and H28 budget so `multi-currency-admin.css` maps to the Core admin CSS output and is no longer treated as missing.
- While running the fresh perf gate, the manual-capture probe exposed a real product regression: native capture failure was applying the generic checkout failed lifecycle, moving authorized orders from `on-hold` to `failed`. Fixed `PaymentProcessingService` so failed captures apply metadata/notes without a status transition, and updated `WooPaymentsProviderGatewayAdapter` so failed native capture outcomes carry WooPayments-compatible `requires_capture` meta and a capture-failed order note.
- The refreshed perf gate then exposed a second source-backed regression: native Sift order tracking defaulted to enabled by filtering an empty config into `array( 'sift' => array() )`, causing extra Action Scheduler scheduling on capture failure. Fixed `WooPaymentsOrderTrackingService` so Sift tracking only schedules when an explicit fraud-services config contains `sift`, matching the reference gateway contract.

## Evidence

- Focused PHP RED/GREEN: `PaymentProcessingServiceTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsOrderTrackingServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencySettingsControllerTest` passed with 93 tests and 407 assertions after the capture and Sift fixes.
- Focused Jest: `pnpm test:js -- multi-currency-settings` passed with 4 suites and 18 tests.
- Admin build: `pnpm build:project:bundle` passed after the final SCSS formatting fix, with existing webpack cache serialization warnings from unrelated email-editor modules.
- Static checks: focused admin ESLint passed for `multi-currency-settings/index.tsx` and its test; focused stylelint passed for `multi-currency-settings/style.scss`; `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` passed; PHPStan passed for the touched production PHP; harness syntax checks passed; `git diff --check` passed.
- Bundle gate: final fresh capture `ref-bundle-h28-20260618-0721.json` vs `target-bundle-h28-20260618-0721.json` passed with explicit H28 budget. `multi-currency-admin.css` is now measured in Core at `2551` raw / `696` gzip bytes versus reference `22568` raw / `3888` gzip bytes. Remaining allowed missing assets are explicit product-disposition gaps: `multi-currency-analytics.js`, `multi-currency-switcher-block.js`, `multi-currency-setup.css`, and `settings-main.css`.
- Perf gate: final fresh capture `ref-perf-h28-20260618-0719.json` vs `target-perf-h28-20260618-0719.json` has no regression failures across process payment, refund, capture, gateway registration, autoload, and account-data autoload. Key structural deltas: process queries `111 -> 111`, refund queries `21 -> 7`, capture queries `30 -> 34`, gateway count `26 -> 4`, gateway callback count `6 -> 2`, autoload bytes `115748 -> 85349`, and `wcpay_account_data` remains `autoload=off`. Local timing medians are recorded only as smoke signals and are not exact latency proof.
- Perf incompleteness: the comparator exits `INCOMPLETE` because isolated REST route-registration timing is not measurable through this WP-CLI path. Both stores report `did_action( 'rest_api_init' ) === 1` at eval start and the captures record `route_registration_status=preinitialized`; route/controller counts are still useful snapshots (`28 -> 4` payment controller instances), but route timing remains explicitly unverified.
- Post-commit gates: branch lint passed with existing ignored-file JS warnings only; changelog validation passed after normalizing older branch-owned native-payments changelog entries from `Comment:` metadata into body text; `git diff --check` passed at `HEAD`.

## Remaining Dispositions

- N7c bundle/perf is materially stronger after H28, but not globally green because isolated REST route timing is still unverified and the remaining multi-currency analytics/switcher/setup assets still need canonical product disposition.
- Commits: `aeb4bbf162` (`fix(payments): close native measured perf gaps`), `26b23f75ad` (`chore(payments): add measured perf changelog`), plus `0d527ea115` (`chore(payments): normalize native payments changelogs`) for the accumulated branch changelog validation cleanup.
- No WPCOM code was changed or accessed beyond the already allowed local read-only context. Stripe CLI was not needed for H28.
