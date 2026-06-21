---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 07:32
tool: writing-plans
target: B3ag native WooPayments admin onboarding entrypoints
reconciles:
  - ../analysis-b3ag-selection.md
status: draft
---

# B3ag Native WooPayments Admin Entry Points Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Core-owned WooPayments admin entrypoints route to the native Settings Payments onboarding flow instead of installing or depending on the legacy WooPayments plugin runtime.

**Architecture:** Treat WooPayments as a native provider in WooCommerce admin surfaces. Existing extension install flows remain available for real extensions, but WooPayments task-list, Launch Your Store, and `/wc-admin/plugins/connect-wcpay` no longer require `installAndActivatePlugins( [ 'woocommerce-payments' ] )` or `WooPaymentsLegacyRuntime::is_loaded()` to proceed. Existing Settings Payments onboarding routes and WooCommerce admin bundles remain the source of truth.

**Tech Stack:** WooCommerce admin React/TypeScript, Jest/RTL, WooCommerce admin PHP REST controllers, PHPUnit via wp-env, existing WooCommerce admin build/lint workflows.

---

## Files

- Modify `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/components/WCPay/utils.js`: replace legacy `connect-wcpay` REST usage with native Settings Payments onboarding URL helpers; keep exports compatible for existing imports.
- Modify `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/components/WCPay/Suggestion.tsx`: use native onboarding callback and avoid plugin-install button semantics for WooPayments.
- Modify `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/utils.js`: identify WooPayments by provider/suggestion IDs, not by `plugins: [ 'woocommerce-payments' ]`; keep BNPL detection.
- Modify `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/test/index.js` and `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/test/utils.js`: prove WooPayments task-list does not call plugin-install semantics and navigates to native onboarding.
- Modify `plugins/woocommerce/client/admin/client/launch-your-store/data/setup-payments-context.tsx`: derive native WooPayments availability from `paymentSettingsStore` provider data, not only `pluginsStore` active/installed lists.
- Modify `plugins/woocommerce/client/admin/client/launch-your-store/hub/main-content/pages/payments-content.tsx`: make the WooPayments intro step start native onboarding directly when native provider data is available; keep install/activate flow only for non-native legacy fallback.
- Add `plugins/woocommerce/client/admin/client/launch-your-store/hub/main-content/pages/payments-content.test.tsx`: prove native Launch Your Store does not call `installAndActivatePlugins`.
- Modify `plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/payments-sidebar.tsx` and related tests only if the visible first step still says Install/Enable under native mode.
- Modify `plugins/woocommerce/src/Admin/API/Plugins.php`: make `/wc-admin/plugins/connect-wcpay` return a native Settings Payments onboarding URL when the legacy WooPayments runtime is absent, while preserving the legacy URL when the old runtime is loaded.
- Modify `plugins/woocommerce/src/Admin/Features/PaymentGatewaySuggestions/DefaultPaymentGateways.php`: remove WooPayments plugin-install metadata from current WooPayments suggestions so source metadata stops advertising native WooPayments as an extension install target.
- Add or modify PHP tests under `plugins/woocommerce/tests/legacy/unit-tests/woocommerce-admin/api/plugins.php` and/or `plugins/woocommerce/tests/php/src/Admin/API/PaymentGatewaySuggestionsTest.php`: prove the native connect fallback and no `plugins => [ 'woocommerce-payments' ]` metadata for WooPayments suggestions.
- Add `plugins/woocommerce/changelog/fix-native-payments-b3ag-admin-entrypoints`.

## Tasks

### Task 1: Red Tests For Native Admin Entrypoints

- [ ] Add a Jest test in `PaymentGatewaySuggestions/test/index.js` that renders a WooPayments suggestion, clicks `Get started`, and expects `window.location.href` to contain `page=wc-settings`, `tab=checkout`, `path=/woopayments/onboarding`, and `from=WCADMIN_PAYMENT_TASK`; the same test must assert the plugin installer is not called.
- [ ] Add a Jest test in `PaymentGatewaySuggestions/test/utils.js` that `getIsGatewayWCPay()` returns true for `woocommerce_payments`, `woocommerce_payments:with-in-person-payments`, `woocommerce_payments:without-in-person-payments`, and `woocommerce_payments:bnpl` even when `plugins` is empty.
- [ ] Add a Jest test for Launch Your Store payments content that supplies native WooPayments provider data through mocked selectors, clicks the intro button, and asserts `installAndActivatePlugins` is not called while onboarding is rendered/refreshed.
- [ ] Add a PHP test for `Plugins::connect_wcpay()` that asserts the response contains a native Settings Payments onboarding URL when `WooPaymentsLegacyRuntime` is unavailable.
- [ ] Add a PHP test around default payment gateway suggestions that asserts current WooPayments suggestions do not include `plugins => [ 'woocommerce-payments' ]`.
- [ ] Run the new focused tests and confirm they fail for the expected reasons:
  - `cd plugins/woocommerce/client/admin && pnpm run test:js -- PaymentGatewaySuggestions payments-content`
  - `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WC_Admin_Tests_API_Plugins|PaymentGatewaySuggestionsTest'`

### Task 2: Native Task-List WooPayments Entry

- [ ] Change `connectWcpay()` in `components/WCPay/utils.js` to navigate to the native Settings Payments onboarding URL instead of POSTing to `/wc-admin/plugins/connect-wcpay`.
- [ ] Keep `installActivateAndConnectWcpay()` exported for compatibility, but make it avoid installing `woocommerce-payments`; it should record the historical install event only if an actual install path is still invoked by a caller, then call `connectWcpay()`.
- [ ] Update `WCPay/Suggestion.tsx` so the action uses native onboarding for WooPayments and passes `hasPlugins={ false }`.
- [ ] Update `PaymentGatewaySuggestions/utils.js` so WooPayments detection relies on IDs rather than plugin slugs.
- [ ] Run focused task-list Jest tests:
  - `cd plugins/woocommerce/client/admin && pnpm run test:js -- PaymentGatewaySuggestions`

### Task 3: Native Launch Your Store Payments Entry

- [ ] Update `setup-payments-context.tsx` so WooPayments is considered available for onboarding when `paymentSettingsStore.getPaymentProviders()` contains a WooPayments provider with native onboarding data, even if `pluginsStore` does not list `woocommerce-payments` as active.
- [ ] Update `payments-content.tsx` so native WooPayments uses the existing intro surface with a `Get started` button that preloads onboarding if available, records the same settings-payments event family, refreshes store data, and opens/renders `WooPaymentsOnboarding` without calling `installAndActivatePlugins`.
- [ ] Keep the legacy install/activate fallback guarded for a provider that is not native, so this change does not rewrite generic extension install behavior.
- [ ] Update sidebar labels/tests if the native first step would otherwise say Install or Enable. Under native mode, the first step should read as a setup/start step, not a plugin install step.
- [ ] Run focused LYS Jest tests:
  - `cd plugins/woocommerce/client/admin && pnpm run test:js -- payments-content payments-sidebar`

### Task 4: Backend Native Fallback And Suggestion Metadata

- [ ] Update `Plugins::connect_wcpay()` to return `Utils::wc_payments_settings_url( '/woopayments/onboarding', array( 'from' => Payments::FROM_PAYMENTS_TASK ) )` when legacy runtime is absent or unloaded.
- [ ] Preserve the existing legacy connect URL when `WooPaymentsLegacyRuntime::is_loaded()` is true.
- [ ] Remove `plugins => array( 'woocommerce-payments' )` from the active WooPayments suggestion rows in `DefaultPaymentGateways.php`; leave old backwards-compatibility comments/rows only if a test proves they are required for historic specs, and document the reason inline.
- [ ] Run focused PHP tests:
  - `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WC_Admin_Tests_API_Plugins|PaymentGatewaySuggestionsTest'`

### Task 5: Verification, Browser Gates, Review, Commit

- [ ] Run syntax and lint checks on touched PHP files:
  - `php -l plugins/woocommerce/src/Admin/API/Plugins.php`
  - `php -l plugins/woocommerce/src/Admin/Features/PaymentGatewaySuggestions/DefaultPaymentGateways.php`
  - `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`
- [ ] Run ESLint only on changed JS/TS files from `plugins/woocommerce/client/admin` with `npx eslint --fix <files>` and then `npx eslint <files>`.
- [ ] Run TypeScript/Jest focused gates:
  - `cd plugins/woocommerce/client/admin && pnpm run test:js -- PaymentGatewaySuggestions payments-content payments-sidebar`
  - `cd plugins/woocommerce/client/admin && pnpm run ts:check`
- [ ] Run the harness:
  - `tools/woopayments-merge/verify.sh --ref "docker exec -i wcpay_wp_default wp --allow-root" --target "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root"`
- [ ] Run browser gates on target and compare against reference where useful:
  - Settings Payments provider list at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout`
  - Payments task or Launch Your Store payments entrypoint on target; verify WooPayments setup enters native onboarding and does not surface Install/Enable WooPayments plugin copy.
  - Block checkout test-mode details and classic checkout test-card details remain present.
- [ ] Scan target logs for fresh PHP notices/warnings/fatals after browser gates.
- [ ] Request focused code review on the admin/frontend wiring and API fallback. Fix critical/high/medium findings.
- [ ] Add changelog, run staged checks, commit with a conventional message, and update `implementation-log.md` plus `staging-log.md`.

## Self-Review

- Spec coverage: Covers the user’s Settings Payments generic UX concern, the frontend asset/workflow concern, explorer-identified admin entrypoints, backend fallback, tests, harness, browser checks, and review gate.
- Placeholder scan: No TBD/TODO placeholders remain.
- Scope check: WooPay runtime continuity, lifecycle email hooks, and provider-business admin screens are intentionally not in B3ag; they remain separate hard preserve chunks because they involve different runtime surfaces.
