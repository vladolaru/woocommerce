---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 11:34
last_updated: 2026-06-18 12:12
status: draft
---

# A4b Admin Dashboard Surface Analysis

> **Prompt:** "Continue working toward the active thread goal."

## Scope

This artifact records the source-backed selection and implementation notes for the next A4 merchant/admin surface after A4a. It must stay aligned with the canonical A4 obligation: native Core must own WooPayments merchant admin surfaces before A5 cutover can be unblocked, but partial A4 slices must not flip `woocommerce_woopayments_native_admin_surfaces_ready`.

## Initial Anchors

- A4 in `implementation-plan.md` is "Admin surfaces + JS dedupe" with subagents per admin screen: overview, transactions, disputes, deposits, reports, and card readers.
- The design spec records the full WooPayments admin app as `client/admin/client/woopayments/` in Core, routed from the Settings Payments shell, with provider-owned admin features kept out of generic Core payments.
- A4a committed only the Settings > Payments > WooPayments account/settings section. It intentionally left broad `/payments/*` dashboard routes out of scope and left cutover readiness fail-closed.
- N8 remains the sequencing rule: enter A4, keep residual N7 limitations tracked, and keep A5 blocked until native admin surfaces and operational cutover blockers are closed.

## Open Questions

- Which native admin surface should come next to maximize A4 progress without over-broadening the slice?
- What Core admin route/build seam should own the first dashboard route?
- Which plugin admin REST and Tracks contracts must be preserved for the selected surface?

## Source-Backed Findings

- The reference WooPayments plugin registers its merchant admin app through the existing `woocommerce_admin_pages_list` JS filter in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/index.js`. It contributes `/payments/connect`, `/payments/onboarding`, `/payments/onboarding/kyc`, `/payments/overview`, `/payments/payouts`, payout details, transactions, transaction details, disputes, dispute redirect/details, dispute challenge, multi-currency setup, card readers, Capital, documents, and fraud-protection settings.
- The reference route entry intentionally lazy-loads separate chunks: `wcpay-payouts`, `wcpay-money-movement`, `wcpay-onboarding`, `wcpay-overview`, `wcpay-multi-currency-setup`, `wcpay-card-readers`, `wcpay-capital`, `wcpay-documents`, `wcpay-reports`, and `wcpay-fraud-protection`. A4 native work should preserve that separability; a single monolithic WooPayments admin import would violate the performance/adaptability direction.
- Core WC Admin has the matching route seam already. `plugins/woocommerce/client/admin/client/layout/controller.js` exports `PAGES_FILTER = 'woocommerce_admin_pages_list'`, `getPages()` applies that filter, and `Controller` renders the selected page container under React `Suspense`.
- Core PHP has the matching admin page/menu seam already. `plugins/woocommerce/includes/react-admin/page-controller-functions.php` exposes `wc_admin_register_page()`, which delegates to `Automattic\WooCommerce\Admin\PageController::register_page()`. That method maps app paths into `wc-admin&path=...`, registers WP admin menu/submenu entries, and connects the page for breadcrumb/current-page behavior.
- Core build tooling already supports `~/woopayments/...` imports through the `~` alias in `plugins/woocommerce/client/admin/webpack.config.js`, and async chunks through normal dynamic imports. A4b does not need a new build pipeline or special asset workflow; native WooPayments admin code should be bundled by the same WC Admin webpack pipeline as other Core admin code.
- Current Core native WooPayments client files are limited to `client/admin/client/woopayments/onboarding/index.ts` and the A4a settings module under `client/admin/client/woopayments/settings/`. There is no native `/payments/overview` route contribution yet.
- The backend ownership boundary for a full provider admin app should live in the native provider area (`Automattic\WooCommerce\Internal\Payments\Providers\WooPayments`) rather than the existing settings-specific namespace (`Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments`). The latter should remain focused on WooCommerce > Settings > Payments provider configuration.

## A4b Slice Selection

A4b should establish the native WooPayments admin app route foundation: a provider-owned PHP registrar for WooPayments WC Admin page/menu entries, a provider-owned JS route module under `client/admin/client/woopayments/`, and a small `/payments/overview` native shell that proves the page loads through the Core-owned route without claiming full overview parity. This is deliberately broader than a tiny file move but narrower than porting the full overview business dashboard, because every later A4 admin screen depends on the route/menu/chunk seam.

The route foundation must keep `woocommerce_woopayments_native_admin_surfaces_ready` false. It creates infrastructure and a visible native shell only; it does not close A4 or unblock A5.

## Goodall Admin Contract Findings

- Goodall confirmed the overview surface is still a WooPayments plugin app contract at `wc-admin&path=/payments/overview`, registered from `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php` and initialized by `WCPAY_DASH_APP` plus `window.wcpaySettings`.
- Reference menu setup is capability-gated by `manage_woocommerce`; connected accounts get Overview, Payouts, Transactions, and Disputes children, while rejected/under-review accounts get a reduced menu. The disconnected case uses `/payments/connect` as the top-level landing.
- The overview dashboard data contract is not Core's A4a `wc-admin/settings/payments/woopayments/*` REST namespace. The reference app uses `/wc/v3/payments/*`, including settings, deposits overview/list/instant-deposit POST, disputes, dispute readiness, PM promotions, and optional Capital active loan summary.
- `wcpaySettings` is a compatibility surface for overview rendering and Tracks defaults. It includes account status/details, feature flags, test/dev mode, Jetpack state, default/store currency, task visibility, dismissal flags, tracking defaults, lifetime TPV, and formatted store address.
- Dashboard Tracks continuity must preserve `wcpay_*` events and their default props, separate from the A4a Settings Payments `settings_payments_woopayments_*` prefix.

## Course Correction

The A4b route foundation must not put a merchant-visible placeholder at `/payments/overview` unless it is explicitly guarded away from production merchant flows. A visible shell without `wcpaySettings`, `/wc/v3/payments/*` compatibility, and Tracks/default-prop continuity would be a half-baked surface. Therefore A4b should focus on registering the provider-owned route/menu definitions and JS route contribution behind a native admin surfaces readiness/development guard, proving the seam without claiming or displaying dashboard parity. The next A4 slice should port the real overview data/settings/Tracks contract and then remove or relax the guard for that route only.

## Mendel Route/Build Findings

- Mendel confirmed the WC Admin server seam is `PageController::register_page()` via `wc_admin_register_page()`, and the client seam is `getPages()`/`woocommerce_admin_pages_list` rendered by the WC Admin layout. Settings Payments embed is explicitly the wrong mounting seam for `/payments/overview`.
- Existing Core references already expect `/payments/overview`: `WooPaymentsOnboardingAdapter` and `WooPaymentsService` return native overview URLs for post-onboarding returns.
- The build pattern should not add a new webpack entry. The normal WC Admin app entry can register the route, and async chunks should carry the WooPayments overview/payout UI.
- Mendel independently flagged the need for an explicit runtime/feature guard so Core does not show an empty dashboard before native data is ready.

## Confucius Surface Map

- Confucius confirmed A4a only covered the native settings/account surface.
- Plugin admin surfaces are registered around `/payments/overview`, with route chunks split into Overview, Payouts, Money Movement, Card Readers, Reports, Capital, Documents, and Fraud Protection. This reinforces the requirement to preserve separate chunks.
- Overview consumes localized `wcpaySettings`, `/wc/v3/payments/settings`, `/wc/v3/payments/disputes`, `/wc/v3/payments/deposits/overview-all`, `/wc/v3/payments/deposits`, `/wc/v3/payments/dispute-readiness`, `/wc/v3/payments/capital/active_loan_summary`, settings option POSTs, and WooCommerce inbox notes.
- Confucius recommends the smallest useful next slice as Core-owned Payments dashboard shell plus `/payments/overview` plus the overview data contract, with a real `/payments/payouts` list target for the Overview payout-history CTA. This is broader than the guarded route-foundation plan and better aligned with the user’s high-throughput guidance.

## Earlier A4b Scope Decision (Superseded)

The route-only A4b plan was superseded once the explorer agents showed that a visible `/payments/overview` placeholder would be a merchant-facing regression. The next decision was to implement the native overview dashboard contract at a first real parity level. That decision is itself superseded by the later architecture correction below: native provider routes should live under Core Payments Settings, and the first slice should establish the route seam there before porting the full overview dashboard.

## Architecture Correction: Core Payments Settings Orchestration

The user clarified that WooPayments-specific merchant routes should be sub-routes of the WC Core Payments Settings surface now that WooPayments is native, analogous to offline payment methods, and that the same high-level abstraction should be usable by future native providers such as PayPal and eventually by third-party payments extensions. This changes the canonical native route target for A4b: WooPayments should not recreate the plugin-era top-level `/payments/*` dashboard as the native owner path. Core should own the Payments Settings orchestration, expose a provider-neutral sub-route registration seam, and let WooPayments register provider-specific pages through that seam.

Source check supports this direction. `plugins/woocommerce/client/admin/client/settings-payments/index.tsx` already uses a route-aware `HistoryRouter` and sub-routes such as `/offline`, `/offline/bacs`, `/offline/cod`, and `/offline/cheque`. `plugins/woocommerce/src/Internal/Admin/Settings/Utils::wc_payments_settings_url()` already builds `admin.php?page=wc-settings&tab=checkout&path=...` URLs. `plugins/woocommerce/client/admin/client/wp-admin-scripts/settings-embed/index.tsx` mounts React wrappers into section roots rendered by `WC_Settings_Payment_Gateways`, including the native WooPayments section root `experimental_wc_settings_payments_woocommerce_payments`. Therefore the first A4b implementation seam should be a provider-neutral Payments Settings route registry/filter in the settings-embed bundle, not a separate WC Admin `woocommerce_admin_pages_list` route.

The old `/payments/overview` plugin URL remains a compatibility concern because the reference plugin and some existing native methods currently return it. Native Core should add or preserve aliases/redirects only after the canonical Settings Payments route exists, so merchants and extensions get a stable Core-owned path such as `admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview`. Any compatibility alias must be explicit and tested; it should not define the ownership boundary.

This also reframes `PaymentsController::add_menu()`. Its current behavior skips the generic Core Payments menu when an onboarded WooPayments account is detected, because the plugin-owned dashboard used to own that menu. Under native Core ownership, the top-level Payments menu should remain Core's orchestration entry point and route to the Payments Settings surface; WooPayments should contribute provider sub-routes within that surface. A4b should add RED tests that make this boundary explicit before changing the controller behavior.

Scope guard added by the user: this does not authorize a broad refactor of the main Payments Settings abstractions. A4b should not dive into the provider list, suggestions, provider data stores, incentives, ordering, onboarding modal orchestration, or marketplace recommendation flows beyond the minimum seams needed to mount provider sub-routes and prove the existing main page still renders. The registry should be a thin route seam that can support future native or extension routes without reshaping the existing providers-list architecture.

## Provider List Manage URL Contract

The user clarified that individual provider settings routes must funnel through the existing main Settings > Payments UI controls, especially provider Manage buttons, for both native providers and third-party providers. Source check confirms the correct contract already exists: `PaymentGateway::get_details()` exposes `management._links.settings.href`, `PaymentGatewayListItem` and offline list items pass that href into `SettingsButton`, and `SettingsButton` chooses in-app navigation when the URL carries a `path` query arg. This means native providers and third-party providers can both use `Utils::wc_payments_settings_url( '/provider/path', ... )` or their own valid `admin.php?page=wc-settings&tab=checkout&path=...` settings URL without changing the provider list.

The PHP renderer also supports this split. `WC_Settings_Payment_Gateways::output()` renders the main React root when no `section` is present, so `path=/provider/...` loads the main Settings Payments wrapper and the new route registry. Existing `section=<gateway>` URLs remain valid for classic or section-specific provider settings and should not be forced through the new route registry. WordPress URL helpers may encode supplied paths as `path=%2Fprovider%2F...`; this is compatible with WC Admin because `getHistory()` parses the query with `qs.parse()` and exposes the decoded path to React Router. The implementation should therefore preserve the provider list and button abstractions, add regression coverage for encoded and unencoded `path` URLs, and avoid refactoring the broader providers list/suggestions orchestration.

## Verification Targets

- Unit-test the PHP Payments Settings URL/menu behavior so native WooPayments overview links resolve to `admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview`, the Core Payments menu remains present for onboarded WooPayments accounts, and no deprecated `/wc-pay-welcome-page` regression returns.
- Unit-test the provider settings URL contract so gateway-provided `path=` URLs keep the existing `management._links.settings.href` and Manage button flow, while non-Reactified `section=` URLs still leave the button on full-page navigation.
- Unit-test the JS Payments Settings route registry so Core-owned route descriptors can be registered by first-party/native code today and by third-party extensions through a filter later, preserving deterministic ordering, duplicate-path protection, lazy chunks, and existing offline route behavior without touching provider discovery, suggestions, ordering, or incentives.
- Unit-test the WooPayments route contribution so `/woopayments/overview` loads through the Payments Settings registry rather than the WC Admin top-level `woocommerce_admin_pages_list` filter.
- Unit-test the bounded overview entry point only against the native account-summary contract already present from A4a. Defer `/wc/v3/payments/*`, `wcpaySettings`, full overview widgets, and dashboard Tracks-default parity to later A4 slices.
- Run targeted JS tests for the route module and layout controller, targeted PHP test for the registrar, targeted ESLint on changed admin client files, PHP lint/PHPStan for changed PHP, and `git diff --check`.
- Browser-smoke `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview` and the generic provider list at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout`. Confirm the WooPayments provider Manage action still follows `management._links.settings.href` and that unrelated third-party/classic provider settings URLs are not affected. Compare meaningful visible states and network contracts to the reference store, and record any remaining parity gaps as next A4 slices before A5.
