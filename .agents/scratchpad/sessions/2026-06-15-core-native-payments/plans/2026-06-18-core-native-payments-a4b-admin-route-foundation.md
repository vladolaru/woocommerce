---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 11:38
target: A4b native WooPayments admin route foundation
reconciles:
  - ../analysis-a4b-admin-dashboard-surface.md
  - ../spec-conformance-baseline.md
  - ../supervisor-prompt-2026-06-17-1311.md
last_updated: 2026-06-18 11:40
status: superseded
superseded_by: 2026-06-18-core-native-payments-a4b-overview-dashboard.md
---

# A4b Native WooPayments Admin Route Foundation Implementation Plan

> **Superseded:** Explorer findings showed a route-only foundation would either expose a half-parity merchant surface or need to be hidden behind a guard that is too small for A4 throughput. Use `2026-06-18-core-native-payments-a4b-overview-dashboard.md` instead.

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the Core-owned WooPayments merchant admin app route/menu foundation for `/payments/overview` without claiming full overview parity or unblocking A5.

**Architecture:** Backend page/menu registration lives in the native provider namespace under `Internal\Payments\Providers\WooPayments`, because this is the provider admin app and not generic Settings Payments. Frontend route registration lives under `client/admin/client/woopayments/` and is imported by the normal WC Admin app entry so its `woocommerce_admin_pages_list` filter runs, while the actual overview shell remains lazy-loaded in a separate WooPayments chunk. The slice keeps `woocommerce_woopayments_native_admin_surfaces_ready` fail-closed.

**Tech Stack:** WooCommerce Core PHP DI services, `wc_admin_register_page()`, WC Admin `woocommerce_admin_pages_list` filter, React/TypeScript, normal WC Admin webpack lazy chunks, PHPUnit, Jest, ESLint, PHPStan, browser smoke through the local target store.

## Files and Responsibilities

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminPageController.php`: provider-owned WC Admin page registrar with an inspectable page definition list and an `admin_menu` registration hook.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the new provider admin page controller from the existing native WooPayments container registration block.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminPageControllerTest.php`: assert `/payments/overview` registration metadata and hook behavior without relying on global browser state.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/index.tsx`: eager route contribution module for WooPayments admin pages; it registers `/payments/overview` through `woocommerce_admin_pages_list` and lazy-loads the shell component.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview.tsx`: minimal native overview shell that proves the route is Core-owned while clearly avoiding full parity claims.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: route shell styling scoped to WooPayments admin pages.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`: assert route contribution shape, page ID, capability, breadcrumbs, lazy container, and no deprecated welcome route.
- Modify `plugins/woocommerce/client/admin/client/index.tsx`: eager import the native WooPayments admin route module so its filter is registered by the normal Core admin app bundle.
- Modify `plugins/woocommerce/client/admin/client/layout/test/controller.test.js` only if needed to guard the route at `getPages()` level after the eager import.
- Add one WooCommerce changelog entry for the Core-owned WooPayments admin route foundation.

## Task 1: PHP Admin Page Registrar

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminPageController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminPageControllerTest.php`

- [ ] **Step 1: Add RED PHP tests**

Add a test that instantiates `WooPaymentsAdminPageController`, calls `get_pages()`, and asserts the first page definition contains `id => wc-payments`, `title => WooPayments`, `path => /payments/overview`, `capability => manage_woocommerce`, `icon => dashicons-money-alt`, and a stable menu position near the existing reference payments top-level. Add a second test that calls `register()` and asserts the `admin_menu` hook contains the controller callback, then removes the callback during cleanup.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce run test:php:env -- --filter WooPaymentsAdminPageControllerTest`
Expected before implementation: fail because the class/test target does not exist.

- [ ] **Step 2: Implement the provider admin page controller**

Create the controller with `register()`, `register_pages()`, and `get_pages()` methods. `register()` should hook `admin_menu` to `register_pages()` at the normal page-registration priority. `register_pages()` should call `wc_admin_register_page()` for each definition only when the helper exists. The initial page list should register only the top-level WooPayments route for `/payments/overview`; later A4 slices can append transactions, disputes, payouts, reports, and card readers without changing the ownership boundary.

- [ ] **Step 3: Register the controller from WooCommerce bootstrap**

Add `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminPageController::class` to the existing native WooPayments registration block in `plugins/woocommerce/includes/class-woocommerce.php`. Keep it near the other provider admin/runtime registrations.

- [ ] **Step 4: GREEN focused PHP**

Run the focused PHP test again. Then run syntax and PHPStan on the new production PHP file.

## Task 2: Frontend Route Module and Overview Shell

**Files:**

- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/index.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/index.tsx`

- [ ] **Step 1: Add RED JS route tests**

Test the route module by applying the route contributor to an empty pages array and asserting it returns a `/payments/overview` page with `navArgs.id => wc-payments-overview`, `capability => manage_woocommerce`, `wpOpenMenu => toplevel_page_wc-admin-path--payments-overview`, and breadcrumbs rooted at Payments. Assert the container is lazy-loadable and that no `/wc-pay-welcome-page` route is added.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath client/admin/client/woopayments/admin/test/routes.test.tsx`
Expected before implementation: fail because the module does not exist.

- [ ] **Step 2: Implement the route module**

Use `addFilter( 'woocommerce_admin_pages_list', 'woocommerce/woopayments-admin', addWooPaymentsAdminPages )`. Export `addWooPaymentsAdminPages` for tests. Use `lazy( () => import( /* webpackChunkName: "woopayments-admin-overview" */ './overview' ) )` for the container, and keep the route module itself small enough to be an eager side-effect import in the main app.

- [ ] **Step 3: Implement the native overview shell**

Create a scoped WooPayments page shell with a page title, a short status callout explaining that native WooPayments admin is loading from Core, and deterministic test IDs for browser smoke. This is a temporary shell for route ownership only; it must not imply full dashboard parity or include provider business data that has not been ported yet.

- [ ] **Step 4: Import the route module through the normal WC Admin app**

Add `import './woopayments/admin';` to `plugins/woocommerce/client/admin/client/index.tsx` near other app-level side-effect imports. Do not create a separate WooPayments webpack entry.

- [ ] **Step 5: GREEN focused JS**

Run the focused JS test, then ESLint on the changed TS/TSX files.

## Task 3: Slice Verification and Reviews

**Files:**

- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Add: `plugins/woocommerce/changelog/<generated-entry>`

- [ ] **Step 1: Static and build gates**

Run focused PHP, focused JS, PHP syntax, PHPStan, changed-file ESLint, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, the relevant WC Admin build command for the app bundle, and `git diff --check`. Do not lint scratchpad files.

- [ ] **Step 2: Browser smoke**

Load `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-admin&path=/payments/overview` in the target store. Confirm the native shell renders, no 4xx/5xx account or admin endpoints appear, and no PHP notices/warnings appear. If Chrome DevTools MCP is unavailable, use Playwright.

- [ ] **Step 3: Review gates**

Run at least architecture/integration and frontend/code review subagents. Ask them to verify provider ownership, route parity with the reference route seam, lazy chunking, no deprecated welcome-page regression, and that A5 remains blocked.

- [ ] **Step 4: Update logs and commit**

Record deterministic and browser evidence in the implementation and staging logs, add the changelog entry, commit one logical A4b change, and do not push.

## Task 4: N8 Advisory Re-Check

**Files:**

- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

- [ ] **Step 1: Re-check N8 after A4b closeout**

Read `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md`, re-confirm that A4b did not flip native admin readiness, did not advance A5, and did not weaken residual N7/N8 blockers. Record the result in the staging log before selecting the next A4 slice.

## Self-Review

Spec coverage: this plan advances A4 admin surfaces by establishing the route/menu/build foundation shared by every later WooPayments admin screen. It does not close full A4 parity, does not port money movement tables, does not touch WPCOM, and does not change cutover readiness. Placeholder scan: no TBD steps. Type consistency: PHP controller and JS route names are stable across tasks.
