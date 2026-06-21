---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 21:04 EEST
last_updated: 2026-06-18 21:55 EEST
status: draft
reconciles:
  - supervisor-prompt-2026-06-17-1311.md
  - spec-conformance-baseline.md
  - staging-log.md
---

# A4h Exit Gate Analysis

## Prompt Trail

> **Prompt:** "When identifying bigger chunks of implementation (including more mechanical ones), consider using subagent implementors, including parallel ones where they don't risk trampling on each other, to conserve your own context and focus."

## Scope

A4h is the A4 exit/N10 gate, not another feature surface by default. The gate must prove the accumulated native WooPayments admin work sits under the intended Settings > Payments provider-route architecture, preserves lazy per-surface bundling, avoids a second WordPress data registry, and records measured bundle evidence plus the route-architecture decision into the baseline before A5 proceeds.

## Source-Backed Findings

Native WooPayments admin routes are now registered through `plugins/woocommerce/client/admin/client/settings-payments/provider-routes.tsx` and bootstrapped by `plugins/woocommerce/client/admin/client/settings-payments/register-provider-routes.ts`. The WooPayments route module registers eleven Settings > Payments sub-routes: settings, overview, payouts, payout details, transactions, transaction details, disputes, dispute details, dispute challenge, card readers, and Capital loans. The registered paths are all `/woopayments/*`, not plugin-era `/payments/*`, matching the user’s architecture direction.

The route module keeps surfaces separately lazy-loaded through named chunks: settings, overview, payouts, money movement, card readers, and Capital. Payout details intentionally shares the payouts chunk, and transactions/disputes/evidence intentionally share the money-movement chunk. This is consistent with the performance/adaptability requirement because WooPayments-specific admin UI is not bundled as a single eager surface.

The provider-route registry imports `@wordpress/hooks` and stores route descriptors in the existing WC Admin runtime. Source search over `client/admin/client/woopayments` and `client/admin/client/settings-payments` finds normal `@wordpress/data` consumers and a WooPayments settings store registered with `createReduxStore`, but no `createRegistry` or `RegistryProvider`. That supports the single-registry claim at the source level; the A4h gate should still record it explicitly and, if practical, add a harness/source assertion so this remains cheap to check later.

The existing bundle gate is broad and first-pass. `tools/woopayments-merge/bundle-size-gate.sh` captures logical WooPayments and multi-currency assets, but it maps all Settings Payments admin work to `plugins/woocommerce/assets/client/admin/chunks/settings-payments-main.js`. That proves broad admin bundle size but not the A4-specific chunk split or aggregate admin dedupe called out by N10. A4h should add a focused admin-route bundle capture or at minimum record concrete chunk filenames and sizes for the native A4 admin chunks.

The current JS bootstrap test `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx` still expects one registered route even though the route module now registers eleven. That is stale test coverage and would fail when run directly. A4h should fix it so the test asserts the full provider-route seam, no `/payments/*` paths, and deterministic ordering without masking implementation bugs.

The native onboarding adapter fallback in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php` still builds `admin.php?page=wc-admin&path=/payments/overview` when the legacy runtime cannot provide an overview URL. This conflicts with the native route-ownership decision. The fallback should use `Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, array( 'from' => WooPaymentsService::FROM_NOX_IN_CONTEXT ) )`, while preserving legacy-runtime URLs when the separate extension is still the active owner.

`WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` still defaults to false, so A5 remains fail-closed. A4h should not flip this casually. If the exit gate is made green and no A4 blockers remain, the readiness decision should be made explicitly with a focused preflight test or recorded as the remaining A5-unblocker.

## Working Decision

Proceed with A4h as a single coherent exit-gate slice: patch the stale route bootstrap test and native overview fallback, add focused source/bundle measurement for native admin chunks and single-registry assertions, update `spec-conformance-baseline.md` and `staging-log.md` with the route architecture decision and evidence, then run the targeted JS/PHP tests, harness bundle gate, browser smoke, and focused review before deciding whether the admin readiness filter can be flipped.

## Exit Gate Result

A4h fixed the route drift found by source review. Native order-note and fallback admin URLs in `WooPaymentsProviderGatewayAdapter`, `WooPaymentsCheckoutAjaxController`, `WooPaymentsDisputeEventHandler`, and `WooPaymentsOnboardingAdapter` now use `Utils::wc_payments_settings_url()` with `/woopayments/*` Settings > Payments paths instead of plugin-era `wc-admin&path=/payments/*` links. The bootstrap Jest test now asserts all eleven native provider sub-routes and rejects registered `/payments/*` paths.

The browser smoke initially surfaced a real native fatal on deposits routes: `WooPaymentsDepositsListRequest::set_param()` was `protected` while `WooPaymentsPaginatedListRequest::set_param()` is public. That broke route loading at class-compatibility time and was not a local-platform parity issue. The fix makes the child method public and extends the legacy `wcpay_list_deposits_request` filter test to prove a third-party callback can still call `set_param()` on the preserved request object.

The remaining missing-id failures in the browser probe are intentionally recorded but not treated as A4 route-smoke failures: `po_missing` returns HTTP 500 because both native and reference request-object endpoints wrap provider `API_Exception` as a `WP_Error` without a REST `status` field, while `txn_missing` and `dp_missing` return 404. The Playwriter evidence now stores both `failedResponses` and `unexpectedFailedResponses`; the final run has `unexpectedFailedResponses: []`, `unmatched: []`, and `routesWithPluginDistScripts: []`.

The focused admin surface gate passed against current source and built chunks. It asserts the expected native admin chunks, rejects plugin-era `/payments/*` route paths in native/settings source, rejects private-registry tokens (`createRegistry`, `RegistryProvider`, `useRegistry`), verifies the built Core chunks exist, and records native/admin reference sizes in `data/a4-admin-surface-gate-final.json`. Native A4 admin chunks measure `69174` raw bytes and `20267` gzip bytes across settings, overview, payouts, money movement, card readers, and Capital after the transaction-details compatibility additions. The reference plugin admin baseline measured by the same gate is `1185920` raw bytes and `281792` gzip bytes.

Verification evidence so far: focused PHP `WooPaymentsDepositsRestControllerTest|WooPaymentsOnboardingAdapterTest|WooPaymentsEventIngestorTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsCheckoutAjaxControllerTest` passed with 150 tests and 621 assertions; changed-file PHP lint passed; production PHPStan passed for the touched WooPayments production classes; focused admin-library Jest passed with 3 suites and 14 tests; targeted ESLint passed; Playwriter A4 route smoke passed with no unexpected failed responses; A4 admin surface gate passed; bundle byte compare passed with the explicit H28 budget; branch lint passed with the existing ignored-file JS warnings only and PHP clean.

A4h does not flip `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY`. The route/bundle/single-registry evidence is baseline-sufficient for closing the A4 admin-route architecture gap, but A5 cutover must still make the readiness decision explicitly alongside the standing queue, legacy Stripe Billing, and full stage-boundary checks.

## Reliability Review Blocker: Transaction Details ID Contract

The focused reliability review after the first A4h implementation found a real recovery-path blocker: native order/dispute links now pointed at the native `/woopayments/transactions/details` route, but that route treated the primary `id` query parameter as a Stripe balance transaction ID and always called `/wc/v3/payments/transactions/{id}`. Reference WooPayments uses the same transaction details route as a payment-details route: `id` is normally a PaymentIntent (`pi_*`), accepts charge fallbacks (`ch_*`/`py_*`), and carries a balance transaction as supplemental `transaction_id=txn_*` when available. The full source trail is preserved in `analysis-a4h-transaction-details-link-contract.md`.

The fix keeps native `txn_*` detail support but restores the reference-compatible contract. Native now exposes read-only `/wc/v3/payments/charges/{charge_id}` and `/wc/v3/payments/payment_intents/{payment_intent_id}` routes behind the native runtime arbiter; the transaction details page branches `pi_*` to payment-intent details, `ch_*`/`py_*` to charge details with canonical redirect to the related PaymentIntent, and `txn_*` or transaction-only URLs to the existing ledger endpoint. Native transaction/dispute list links now prefer `payment_intent_id || charge_id` as the primary `id` and append `transaction_id` separately. Backend-generated payment/dispute notes continue to display the PaymentIntent/charge ID but now include `transaction_id=txn_*` when native already has the balance transaction ID, so recovery links work without narrowing the route contract.

Additional focused evidence after the fix: PHP `WooPaymentsMoneyMovementRestControllerTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsCheckoutAjaxControllerTest|WooPaymentsEventIngestorTest` passed with 146 tests and 686 assertions; admin-library Jest for provider routes, account settings, routes, and money-movement pages passed with 4 suites and 22 tests; targeted ESLint passed; changed-file PHPCS passed after formatting; PHPStan passed for the touched production WooPayments classes; the admin bundle rebuilt successfully with only existing webpack cache serialization warnings. The refreshed Playwriter gate covered 13 routes, including `transaction-details-intent` and `transaction-details-charge`, and confirmed those sentinel URLs call `/wc/v3/payments/payment_intents/{id}` and `/wc/v3/payments/charges/{id}` rather than the balance-transaction endpoint. Final `git diff --check`, changelog validation, branch lint, and the narrowed target-container PHP/WP warning scan passed; branch JS lint still emits only existing ignored-file warnings from the broad branch diff.
