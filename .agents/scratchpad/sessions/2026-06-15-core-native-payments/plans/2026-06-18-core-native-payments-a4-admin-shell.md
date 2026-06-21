---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 10:10
last_updated: 2026-06-18 10:10
target: A4 native WooPayments settings account surface
reconciles:
  - ../analysis-a4-native-woopayments-admin.md
  - ../spec-conformance-baseline.md
  - ../supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4a Native WooPayments Settings Account Surface Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing WooCommerce > Settings > Payments > WooPayments section a real Core-owned native WooPayments settings/account surface, backed by native account/provider data and built through the existing Core settings-embed workflow.

**Architecture:** Keep WooPayments business/admin features provider-owned. Backend data comes from `Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService` and `Internal\Payments\Providers\WooPayments\WooPaymentsAccountService`, exposed through the existing `wc-admin/settings/payments/woopayments/*` provider namespace. Frontend UI lives under `client/admin/client/woopayments/` and is mounted from `settings-payments-woopayments.tsx` as the Settings Payments adapter. Do not register broad `/payments/*` dashboard shells in this slice and do not flip `woocommerce_woopayments_native_admin_surfaces_ready`.

**Tech Stack:** WooCommerce Core PHP DI services, WordPress REST API, existing Settings Payments reactification, existing settings-embed Core webpack entry, React/TypeScript, `@wordpress/data`/`@wordpress/components`, Jest/RTL, PHPUnit, Chrome/Playwright browser smoke.

## Files and Responsibilities

- Modify `plugins/woocommerce/includes/admin/settings/class-wc-settings-payment-gateways.php`: include `woocommerce_payments` in the optional reactified sections by default so the existing `experimental_wc_settings_payments_woocommerce_payments` root mounts without relying on the extension.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`: add a read-only account/settings summary method that composes existing account service data, provider details links/state, and onboarding context without triggering mutating onboarding actions.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`: add a read-only `GET /wc-admin/settings/payments/woopayments/account` route.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/*`: reusable native WooPayments settings/account UI, data helper, and styles.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx`: mount the native WooPayments settings/account component instead of the placeholder.
- Tests: add/extend PHPUnit for the reactified section and account route/service; add Jest/RTL tests for the native WooPayments settings/account UI and API helper; add browser smoke after deterministic tests pass.

## Task 1: PHP Settings Account Foundation

**Files:**

- Modify: `plugins/woocommerce/includes/admin/settings/class-wc-settings-payment-gateways.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- Test: `plugins/woocommerce/tests/php/includes/settings/class-wc-settings-payment-gateways-test.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`

- [ ] **Step 1: Add RED tests for the native WooPayments settings section**

Add tests asserting `WC_Settings_Payment_Gateways::should_render_react_section( 'woocommerce_payments' )` is true by default while the existing filter can still remove optional sections, and asserting the rendered root ID is `experimental_wc_settings_payments_woocommerce_payments`.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter 'WC_Settings_Payment_Gateways_Test'`
Expected before implementation: failures for the missing default reactified WooPayments section.

- [ ] **Step 2: Add RED account summary route/service tests**

Add tests for `WooPaymentsService::get_account_summary()` and `GET /wc-admin/settings/payments/woopayments/account`. The summary should expose only safe account/settings data: account ID, mode, booleans for account connected/working/test-drive/sandbox/live/test mode/can process payments, default currency, onboarding overview URL, and setup/action links already present in provider metadata. It must not expose secret keys. Route tests should assert `manage_woocommerce` is required and service exceptions return the existing REST error shape.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter 'WooPaymentsServiceTest|WooPaymentsRestControllerTest'`
Expected before implementation: failures for missing method/route.

- [ ] **Step 3: Implement the PHP account foundation**

Add `woocommerce_payments` to the optional reactified sections default list. Implement `WooPaymentsService::get_account_summary()` by composing existing defensive reads from `WooPaymentsAccountService`, existing provider details where safe, and `get_overview_page_url()` for the future dashboard link. Add the read-only route to `WooPaymentsRestController` using the existing permission and exception wrapper conventions.

- [ ] **Step 4: GREEN focused PHP**

Run both focused PHP filters from steps 1 and 2. Expected: PASS.

## Task 2: Native WooPayments Settings Frontend

**Files:**

- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/api.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/account-settings.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/types.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`
- Create: focused tests under `plugins/woocommerce/client/admin/client/woopayments/settings/test/`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.scss`

- [ ] **Step 1: Add RED frontend tests**

Mock `@wordpress/api-fetch` and test that the helper requests `/wc-admin/settings/payments/woopayments/account`. Test the component renders loading, connected test account, live account, no account/setup, and error states with semantic headings/buttons/links.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath client/admin/client/woopayments/settings/test/account-settings.test.tsx`
Expected before implementation: missing file/failing imports.

- [ ] **Step 2: Implement the data helper and component**

Use native buttons/links for actions. Show a status summary for mode/test-drive/live/sandbox, account readiness, default currency, and actions surfaced by backend links. Keep visual styling restrained and Settings Payments-consistent. Do not introduce global data stores or plugin aliases.

- [ ] **Step 3: Replace the placeholder adapter**

Update `settings-payments-woopayments.tsx` to mount the reusable WooPayments settings component and import its styles. Keep the Settings Payments wrapper/header behavior unchanged.

- [ ] **Step 4: GREEN focused JS**

Run the focused WooPayments settings tests. Expected: PASS.

## Task 3: Verification, Review, and Commit

**Files:**

- Update `staging-log.md`, `implementation-log.md`, and `spec-conformance-baseline.md` after verification.
- Add a WooCommerce changelog entry under `plugins/woocommerce/changelog/`.

- [ ] **Step 1: Static gates**

Run PHP syntax for touched PHP, PHPStan for touched production PHP, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, focused JS lint/test commands for new admin files, and `git diff --check`.

- [ ] **Step 2: Build gate**

Run the Core admin build path that owns `settings-embed` and confirm the WooPayments settings component is built through Core admin chunks, not a WooPayments plugin webpack island.

- [ ] **Step 3: Browser smoke**

Use Chrome DevTools MCP or Playwright to load target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments`. Also re-check the generic target provider list at `tab=checkout`. Capture console/network/PHP notices and screenshots or snapshots in session data.

- [ ] **Step 4: Review gates**

Run at least one architecture/integration review and one frontend/code review. Ask specifically whether the slice correctly uses the Settings Payments seam, preserves provider-owned boundaries, avoids secret exposure, keeps the broad A4 readiness gate closed, and does not regress the generic provider list.

- [ ] **Step 5: Commit**

Commit the full A4a source/tests/changelog as one logical change after the gates pass. Do not push.

## Self-Review

Spec coverage: this plan covers the first A4 admin-surface foundation, not full A4 exit. It deliberately leaves the cutover admin readiness preflight false until all screens are functional and the A4 N5 gate passes. The scope is meaningful because it turns the existing WooPayments Settings Payments section from a placeholder/unmounted root into a real provider-backed native surface, while preserving the broader dashboard and money-movement route parity for the next A4 chunk.
