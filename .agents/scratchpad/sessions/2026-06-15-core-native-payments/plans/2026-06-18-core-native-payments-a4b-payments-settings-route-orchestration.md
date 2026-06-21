---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 11:46
last_updated: 2026-06-18 12:12
target: A4b Core Payments Settings provider sub-route seam
reconciles:
  - ../analysis-a4b-admin-dashboard-surface.md
  - ../spec-conformance-baseline.md
  - ../supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4b Core Payments Settings Provider Sub-Route Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the minimal Core-owned Payments Settings sub-route seam needed for provider-owned pages, then register WooPayments through it instead of creating a plugin-era top-level `/payments/*` app.

**Architecture:** Add a small Settings Payments route registry in the existing `settings-embed` bundle. Core-owned/native provider code registers route descriptors through the registry, third-party extensions can add or alter descriptors through a WordPress hooks filter, and `SettingsPaymentsMainWrapper` renders those routes before the existing catch-all. Provider settings entry continues to flow through the existing `management._links.settings.href` contract consumed by the provider-list Manage button: `path=` URLs route in-app through the main Settings Payments wrapper, while `section=` and external URLs keep the current full-page behavior. WooPayments uses the registry as the first provider implementation; `/payments/*` remains a compatibility concern, not the canonical native owner path. Do not refactor the main Payments Settings providers list, suggestions, incentives, ordering, onboarding modal orchestration, or data stores in this slice.

**Tech Stack:** WooCommerce Settings Payments React bundle, `@wordpress/hooks`, React Router, existing `settings-embed` webpack workflow, WooCommerce Core PHP Payments Settings helpers, PHPUnit, Jest/RTL, ESLint, PHPStan, Chrome DevTools/Playwright browser smoke.

## Files and Responsibilities

- Create `plugins/woocommerce/client/admin/client/settings-payments/provider-routes.tsx`: provider-neutral route descriptor type, registry, filter seam, deterministic sort, duplicate path guard, and test reset helper.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/index.tsx`: render registry routes inside `SettingsPaymentsMainWrapper` before the catch-all main list route, while preserving existing offline route behavior and leaving the main providers list untouched.
- Create `plugins/woocommerce/client/admin/client/settings-payments/test/provider-routes.test.tsx`: registry/filter tests for native registrations, third-party filter additions, ordering, duplicate rejection, and route rendering.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/components/buttons/test/settings-button.test.tsx`: assert the existing Manage button navigates Reactified `path=` provider settings URLs through React Router and leaves non-Reactified provider settings URLs on full-page navigation.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`: WooPayments-specific route registrations through the generic Settings Payments route registry.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview.tsx`: first native WooPayments overview entry point, built as a lazy chunk and backed only by data contracts already native in this slice; do not expose fake money-movement or payout data.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: scoped overview route styling that follows Settings Payments density and does not bleed into generic pages.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`: assert WooPayments registers canonical Settings Payments sub-routes, uses lazy chunks, and does not register deprecated welcome or top-level `/payments/*` routes.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/index.tsx`: import the WooPayments route registration side effect only through the existing Settings Payments bundle, not the WC Admin app entry.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`: return the canonical native overview URL via `Utils::wc_payments_settings_url( '/woopayments/overview', ... )` for native onboarding/account contexts instead of the plugin-era `/payments/overview` adapter path.
- Test `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`: update/add RED assertions for native overview URLs and onboarding return URLs.
- Test `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/PaymentGatewayTest.php`: assert gateway-provided Reactified `admin.php?page=wc-settings&tab=checkout&path=...` settings URLs remain valid, get the Settings Payments `from` marker, and keep flowing through `management._links.settings.href`.
- Test `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsControllerTest.php`: assert the Core Payments menu remains the generic Settings Payments orchestration entry point when the legacy plugin runtime is not loaded; preserve the legacy-plugin-active duplicate-menu guard.
- Add one WooCommerce changelog entry for the Core Payments Settings route orchestration.

## Explicit Non-Scope

- Do not refactor `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-main.tsx`.
- Do not modify provider discovery, provider list rendering, suggestions, incentives, ordering, marketplace links, or payment-settings data stores except for tests proving the existing Manage/settings URL flow still works after the route seam lands.
- Do not migrate offline routes to the registry in this slice. Existing offline route behavior is evidence for the seam shape, not refactor scope.
- Do not introduce the full WooPayments dashboard data contract, `/wc/v3/payments/*` compatibility layer, `wcpaySettings`, payout widgets, transaction tables, dispute tables, or dashboard Tracks defaults in this slice.

## Task 1: Core Payments Settings Route Registry

**Files:**

- Create: `plugins/woocommerce/client/admin/client/settings-payments/provider-routes.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/index.tsx`
- Test: `plugins/woocommerce/client/admin/client/settings-payments/test/provider-routes.test.tsx`

- [ ] **Step 1: Add RED route registry tests**

Add tests that import `registerSettingsPaymentsProviderRoute`, `getSettingsPaymentsProviderRoutes`, `resetSettingsPaymentsProviderRoutesForTesting`, and `SETTINGS_PAYMENTS_PROVIDER_ROUTES_FILTER`. Assert that two native registrations are sorted by `order`, that an `addFilter( SETTINGS_PAYMENTS_PROVIDER_ROUTES_FILTER, ... )` callback can add a third-party route descriptor, that duplicate `path` values throw a descriptive error, and that route descriptors keep their lazy `element` untouched. Do not import or render the main provider-list component in these tests.

Run: `pnpm --filter=@woocommerce/admin-library test:js -- --runTestsByPath client/settings-payments/test/provider-routes.test.tsx`

Expected before implementation: FAIL because the module does not exist.

- [ ] **Step 2: Implement the registry**

Create `provider-routes.tsx` with this public shape:

```tsx
export const SETTINGS_PAYMENTS_PROVIDER_ROUTES_FILTER = 'woocommerce_admin_settings_payments_provider_routes';

export interface SettingsPaymentsProviderRoute {
	id: string;
	path: string;
	element: React.ReactNode;
	order?: number;
}

export function registerSettingsPaymentsProviderRoute( route: SettingsPaymentsProviderRoute ): void;
export function getSettingsPaymentsProviderRoutes(): SettingsPaymentsProviderRoute[];
export function resetSettingsPaymentsProviderRoutesForTesting(): void;
```

Use `applyFilters( SETTINGS_PAYMENTS_PROVIDER_ROUTES_FILTER, registeredRoutes )`, normalize leading slashes, sort by `order` then `id`, and throw if two descriptors resolve to the same path. Keep the registry small and free of WooPayments imports.

- [ ] **Step 3: Render registry routes**

Update `SettingsPaymentsMainWrapper` in `settings-payments/index.tsx` to call `getSettingsPaymentsProviderRoutes()` and render each descriptor as `<Route key={ route.id } path={ route.path } element={ route.element } />` before the catch-all `/*` route. Keep the existing offline routes working exactly as they do now in this slice; do not migrate offline routes and do not touch `settings-payments-main.tsx`.

- [ ] **Step 4: GREEN registry tests**

Run the focused route registry test again and keep it green.

## Task 2: WooPayments Route Registration Through Core Orchestration

**Files:**

- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/index.tsx`

- [ ] **Step 1: Add RED WooPayments route tests**

Add tests that import the WooPayments route module after resetting the generic registry and assert it registers one canonical route with `id: 'woopayments-overview'`, `path: '/woopayments/overview'`, and a lazy element. Assert no descriptor path starts with `/payments/` and no descriptor contains `wc-pay-welcome-page`.

Run: `pnpm --filter=@woocommerce/admin-library test:js -- --runTestsByPath client/woopayments/admin/test/routes.test.tsx`

Expected before implementation: FAIL because the route module does not exist.

- [ ] **Step 2: Register the WooPayments overview route**

Implement `routes.tsx` with a side-effect registration that calls `registerSettingsPaymentsProviderRoute( { id: 'woopayments-overview', path: '/woopayments/overview', order: 100, element: <WooPaymentsOverviewChunk /> } )`. Use `lazy( () => import( /* webpackChunkName: "settings-payments-woopayments-overview" */ './overview' ) )` so WooPayments dashboard code remains a separate chunk within the normal Core Settings Payments build.

- [ ] **Step 3: Implement a real but bounded overview entry point**

Implement `overview.tsx` as a Settings Payments page that uses the native WooPayments account summary endpoint already introduced in A4a. It should render loading, connected-account, setup-required, and error states with the same status vocabulary as `WooPaymentsAccountSettings`, and it should link to existing native settings/setup URLs. Keep it bounded to the already-native account/settings contract; do not render payout, transaction, dispute, balance, Capital, provider recommendation, or suggestion widgets until their proper contracts are ported in later A4 slices.

- [ ] **Step 4: Import route registration from Settings Payments**

Import `~/woopayments/admin/routes` from `settings-payments/index.tsx` so the route registration belongs to the Settings Payments bundle. Do not import this module from `client/admin/client/index.tsx` and do not use `woocommerce_admin_pages_list` for this canonical native route.

- [ ] **Step 5: GREEN WooPayments route tests**

Run the focused WooPayments route test again and keep it green.

## Task 3: Native WooPayments Overview URLs Stay Under Settings Payments

**Files:**

- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/PaymentGatewayTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsControllerTest.php`

- [ ] **Step 1: Add RED PHP URL tests**

Add or update tests asserting `WooPaymentsService::get_account_summary()['urls']['overview_page']` uses `admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview`, and the native onboarding return URL passed to the Transact onboarding request uses the same Settings Payments route. Add a `PaymentsControllerTest` assertion that when `WooPaymentsLegacyRuntime::is_loaded()` returns false, the top-level Payments menu points to `admin.php?page=wc-settings&tab=checkout&from=WCADMIN_PAYMENT_MENU_ITEM` even if a native account exists.

Add a `PaymentGatewayTest` assertion that a gateway-provided valid relative admin settings URL with `path=/third-party/settings` is normalized by the existing provider abstraction and returned as a valid Settings Payments URL with `path=%2Fthird-party%2Fsettings&from=...`. The encoded path is acceptable because WC Admin decodes the `path` query value before React Router matches it. This locks the third-party provider route funnel without changing provider discovery or the providers list.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter 'WooPaymentsServiceTest|PaymentsControllerTest'`

Expected before implementation: FAIL on the old `/payments/overview` URL expectations or missing native-menu assertion.

- [ ] **Step 2: Implement canonical native URL helper usage**

Update `WooPaymentsService` so native account/onboarding contexts use `Utils::wc_payments_settings_url( '/woopayments/overview' )` for Core-owned overview links. Keep the existing WooPayments extension adapter available for legacy-plugin compatibility paths that explicitly need the plugin URL, and do not modify the reference plugin.

- [ ] **Step 3: Preserve duplicate-menu safety**

Keep the existing guard that avoids duplicate top-level menus while the standalone WooPayments plugin runtime is loaded and owns the legacy dashboard. Do not let that guard suppress the Core Settings Payments menu when native Core owns the runtime.

- [ ] **Step 4: GREEN focused PHP**

Run the focused PHP tests again and keep them green, including the provider settings URL assertion.

## Task 3a: Manage Button Keeps Provider Settings Route Funnel

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/settings-payments/components/buttons/test/settings-button.test.tsx`

- [ ] **Step 1: Add RED/coverage tests for Reactified and non-Reactified settings URLs**

Mock `useNavigate` from `react-router-dom`, render `SettingsButton` with a provider settings href containing `admin.php?page=wc-settings&tab=checkout&path=/third-party/settings&from=settings-payments`, click Manage, and assert it records the existing Tracks event and calls `navigate()` with the origin-stripped URL instead of assigning `window.location.href`. Render a second button with `admin.php?page=wc-settings&tab=checkout&section=third_party&from=settings-payments`, click Manage, and assert it does not call `navigate()` because legacy/classic provider settings still use full-page navigation.

Run: `pnpm --filter=@woocommerce/admin-library test:js -- --runTestsByPath client/settings-payments/components/buttons/test/settings-button.test.tsx`

Expected before any necessary test-support changes: FAIL if the test harness does not currently expose the route choice clearly. If it already passes, treat it as a regression coverage lock for the existing abstraction and do not change production code.

## Task 4: Verification, Reviews, Logs, and Commit

**Files:**

- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Add: `plugins/woocommerce/changelog/<generated-entry>`

- [ ] **Step 1: Static gates**

Run focused JS tests, focused PHP tests, ESLint on changed TS/TSX/SCSS-adjacent files, PHP syntax for changed PHP, PHPStan for changed production PHP, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`. Do not lint scratchpad files.

- [ ] **Step 2: Build gate**

Run the Settings Payments build path that owns `settings-embed` and confirm the new WooPayments route chunk is emitted by the normal Core admin workflow. Do not create a WooPayments-specific build pipeline.

- [ ] **Step 3: Browser smoke**

Load `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview` and the generic provider list at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout`. Confirm the route loads through the Settings Payments UI, no PHP notices/warnings appear, no 4xx/5xx account endpoints appear unexpectedly, and the provider list still renders. Click or inspect the WooPayments provider Manage control and confirm it follows `management._links.settings.href` through the same Settings Payments route mechanism used for other providers; do not special-case WooPayments in the providers list. Compare the target overview route to the reference only for contracts already ported in this slice; record unported dashboard widgets as later A4 work rather than masking them.

- [ ] **Step 4: Review gates**

Run at least one architecture/integration review and one frontend/code review subagent. Ask them to verify that Core owns only the provider sub-route seam in this slice, third-party extension registration remains possible through a filter and the existing `settings_url`/Manage-button funnel, WooPayments is not special-cased in generic route code, the main providers list/suggestions/incentives were not refactored, lazy chunking is preserved, `/payments/*` is not the canonical native route, and A5 remains blocked.

- [ ] **Step 5: Update logs and commit**

Record deterministic, build, browser, and review evidence in the implementation and staging logs, add the changelog entry, commit one logical A4b change, and do not push.

## Task 5: N8 Advisory Re-Check

**Files:**

- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

- [ ] **Step 1: Re-check N8 after A4b closeout**

Read `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md`, re-confirm that A4b entered A4 without re-opening N7 gate-hardening, did not flip native admin readiness, did not advance A5, and did not weaken residual N7/N8 blockers. Record the result in the staging log before selecting the next A4 slice.

## Self-Review

Spec coverage: this plan advances A4 by moving the native WooPayments merchant route seam under Core-owned Settings Payments orchestration, while deliberately preserving the existing provider `settings_url` and Manage-button contract for native and third-party providers. It avoids a main Payments Settings refactor. It does not close full WooPayments dashboard parity, but it prevents the next dashboard slices from building on the wrong `/payments/*` ownership model. Placeholder scan: no TBD placeholders. Type consistency: route registry names and paths are stable across tasks.
