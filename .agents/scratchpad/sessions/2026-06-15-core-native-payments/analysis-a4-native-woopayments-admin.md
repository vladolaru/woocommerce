---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 10:10
last_updated: 2026-06-18 10:10
target: A4 native WooPayments admin surfaces
reconciles:
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4 Native WooPayments Admin Analysis

## Trigger

N8 declares N7 baseline-sufficient and explicitly says to stop widening N7 residuals before A4. The next canonical stage is A4: move the WooPayments provider admin app onto the WooCommerce Core build, eliminate duplicated JS/runtime dependencies, preserve provider-owned admin behavior, and keep A5 cutover blocked until these surfaces exist.

## Canonical Contract

The implementation plan defines A4 as "Admin surfaces + JS dedupe" with the admin React app living under `client/admin/client/woopayments/`, routed from the Settings Payments shell, built by the Core admin webpack, and checked by admin parity, bundle-size, single `@wordpress/data` registry, and unchanged server/mobile-inbound routes. The design spec names overview, transactions, disputes, deposits/payouts, reports, and card readers as the A4/A6 admin surface set. The manifest is explicit that Capital, deposits/payouts, and disputes are provider-owned WooPayments surfaces, not generic Core payments UI.

## Current Core State

Core currently has only `plugins/woocommerce/client/admin/client/woopayments/onboarding/index.ts`, which re-exports the Settings Payments onboarding flow. There is no native overview, transactions, disputes, payouts, reports, card-readers, documents, or payment-details app. The cutover controller correctly fails closed with `native_admin_surfaces_unavailable` by default, so this is not merchant-facing through A5 yet.

The existing Core admin seam for WooPayments is the Settings Payments root, not a standalone `/payments/*` shell yet. `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx` is still a placeholder rendering only "WooPayments settings". `settings-embed/index.tsx` already maps `experimental_wc_settings_payments_woocommerce_payments` to `SettingsPaymentsWooPaymentsWrapper`, but `WC_Settings_Payment_Gateways::get_reactified_sections()` does not include `woocommerce_payments`, so the placeholder root is not mounted by default. This explains the current local gap where WooCommerce > Settings > Payments can work generically while the WooPayments provider section is not a real native settings surface.

Existing native PHP support is stronger than the admin UI: `WooPaymentsApiClient` already supports account/onboarding, mobile/IPP routes, charge lookup, dispute summary lookup, transaction lookup, terminal readers/locations, and the runtime/event pieces needed by admin surfaces. `WooPaymentsAccountService` already preserves the defensive account cache/backoff behavior needed for provider status reads. `Internal\Admin\Settings\PaymentsProviders\WooPayments` already enriches provider metadata with native onboarding links and account/test-mode state. What is missing first is a provider-backed Settings Payments WooPayments account/settings shell; broader read/list routes for transactions, disputes, deposits/payouts, reports, documents, files, and capital can then hang from the same native boundary without pretending A4 is complete.

## Reference Surface Map

Reference WooPayments registers the admin SPA from `client/index.js`. It lazy-loads chunks for overview, payouts and payout details, transactions/payment-details/disputes as a shared money-movement chunk, onboarding, multi-currency setup, card readers, capital, documents, reports, and fraud-protection settings. Its admin PHP source registers menu routes in `includes/admin/class-wc-payments-admin.php` for `/payments/overview`, `/payments/transactions`, `/payments/transactions/details`, `/payments/disputes`, `/payments/disputes/details`, `/payments/disputes/challenge`, `/payments/payouts`, `/payments/payouts/details`, `/payments/reports`, and `/payments/card-readers`, plus redirects from legacy deposits paths to payouts.

Reference REST controllers are provider-owned under `includes/admin/class-wc-rest-payments-*-controller.php`, all in the preserved `wc/v3/payments/*` namespace. The admin app uses routes such as `/wc/v3/payments/accounts`, `/transactions`, `/transactions/{id}`, `/disputes`, `/disputes/{id}`, `/disputes/summary`, `/deposits`, `/deposits/{id}`, `/charges/{id}`, `/terminal/locations`, `/readers`, `/reports/*`, `/documents`, `/files`, `/capital`, `/settings`, `/tos`, and `/onboarding`. Core already preserves server/mobile-inbound subsets through `WooPaymentsMobileRestController` and other runtime controllers; A4 should add admin-client routes behind a provider-owned controller or controller group, not make them generic Core payments routes.

## Design Direction

The first A4 implementation chunk should make the existing Settings Payments WooPayments root real: reactify the `woocommerce_payments` settings section, add a read-only native account/settings summary endpoint under the existing `wc-admin/settings/payments/woopayments/*` provider namespace, and replace the placeholder component with a provider-backed account/settings view. The React implementation should live under `client/admin/client/woopayments/` for reusable WooPayments-owned account/settings UI, with `settings-payments-woopayments.tsx` acting as the mounted Settings Payments adapter.

This first chunk should not register the full `/payments/*` route list as shells or mark admin parity as done. Doing so would risk a false A4 signal and would hide the fact that the existing settings provider section is still the first user-facing admin gap. Later A4 chunks can add the dedicated WooPayments dashboard shell, route registration, and read/list money-movement routes after this account/settings foundation is real. `FILTER_NATIVE_ADMIN_SURFACES_READY` remains false until full admin surface parity and the N5 A4 exit gate pass.

## Verification Shape

The first A4 chunk needs deterministic PHP tests for the account/settings summary endpoint and the reactified section, JS render/data-helper tests for the WooPayments Settings Payments account surface, admin build verification through the existing Core settings-embed workflow, and browser smoke for `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` against the reference store. Browser parity should prove the section mounts, shows native account/test-mode/setup state, uses the Core settings bundle/chunk path, produces no PHP notices/warnings, and does not regress the generic provider list at `tab=checkout`. Full dashboard route parity remains for later A4 chunks.
