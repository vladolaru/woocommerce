---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 19:32
status: draft
last_updated: 2026-06-18 19:34
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-17-1311.md
  - analysis-a4g-remaining-admin-surfaces.md
---

# A4g Payout Details, Card Readers, and Capital Admin Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native WooPayments payout detail, Card readers, and Capital Loans merchant admin surfaces under WooCommerce > Settings > Payments provider sub-routes, preserving provider-owned capability boundaries while keeping Reports as the next heavier A4 slice.

**Architecture:** Payout details is a frontend route over the existing native deposits detail and transactions-summary contracts. Card readers is mostly a frontend route over the existing native IPP REST contract (`/wc/v3/payments/readers`). Capital needs a small provider-owned REST controller and API client methods for `/wc/v3/payments/capital/active_loan_summary` and `/wc/v3/payments/capital/loans`, then a native route that renders only when the account exposes Capital loan data. All surfaces stay in lazy admin chunks, use Core `apiFetch`, and do not introduce a plugin-style `@wordpress/data` registry.

**Tech Stack:** WooCommerce Core PHP DI/REST controllers, WooPayments native API client, React/TypeScript in `client/admin/client/woopayments/admin`, Jest/RTL, PHPUnit, PHPStan, PHPCS, Playwriter, restored `tools/woopayments-merge` gates.

---

## Source Mapping

- Reference plugin routes: `/payments/payouts/details`, `/payments/card-readers`, and `/payments/loans` are registered in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/index.js`.
- Reference payout details frontend: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/deposits/details/index.tsx` and `style.scss`.
- Reference Card readers frontend: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/card-readers/index.tsx`, `list/index.tsx`, `list/list-item.tsx`, and `style.scss`.
- Reference Capital frontend: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/capital/index.tsx` and `style.scss`.
- Reference data endpoints: `/wc/v3/payments/deposits/{deposit_id}`, `/wc/v3/payments/transactions/summary?deposit_id=...`, `/wc/v3/payments/readers`, `/wc/v3/payments/readers/charges/{transaction_id}`, `/wc/v3/payments/capital/active_loan_summary`, and `/wc/v3/payments/capital/loans`.
- Native payout details backend already exists through `WooPaymentsDepositsRestController::get_deposit()` and `WooPaymentsTransactionsRestController::get_transactions_summary()`, and the native data helper already exposes `getWooPaymentsDeposit()`.
- Native Card readers backend already exists in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMobileRestController.php`.
- Native Capital backend is missing and should be provider-owned, not generic payments code.
- Native routes currently cover settings, overview, payouts, transactions, disputes, dispute details, and dispute challenge in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`.
- Native file/evidence routes are present in `WooPaymentsRestController`; do not create a duplicate file controller in this slice.

## File Structure

- Modify `plugins/woocommerce/includes/class-woocommerce.php` to register the new native Capital REST controller.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` to add provider API methods for Capital endpoints.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php` for the preserved `/wc/v3/payments/capital/*` contract.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php` for Capital path tests.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php` for route registration, permission, API success, API errors, and arbiter gating.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` to register lazy `/woopayments/card-readers` and `/woopayments/loans` provider sub-routes.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/payouts/details.tsx` or `payouts/details-page.tsx` if the existing single-file payouts surface is not enough.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/*` for data, page, types, and route entry.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/capital/*` for data, page, types, and route entry.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` only to import surface styles; keep page-specific CSS in each route folder or in route-owned SCSS.
- Add focused Jest tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`.
- Add a WooCommerce changelog entry for the plugin package.

## Task 1: Backend Capital REST Contract

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php`

- [ ] **Step 1: Write RED PHP coverage**

Add API client tests asserting:

```php
$sut->get_capital_active_loan_summary();
$this->assertSame( '/sites/123/wcpay/capital/active_loan_summary?test_mode=1', $http_client->last_path );

$sut->get_capital_loans();
$this->assertSame( '/sites/123/wcpay/capital/loans?test_mode=1', $http_client->last_path );
```

Add controller tests asserting:

```php
$this->assertArrayHasKey( '/wc/v3/payments/capital/active_loan_summary', $this->server->get_routes() );
$this->assertArrayHasKey( '/wc/v3/payments/capital/loans', $this->server->get_routes() );
$this->assertSame( array( 'object' => 'capital.financing_summary' ), $response->get_data() );
$this->assertSame( array( array( 'stripe_loan_id' => 'loan_123' ) ), $response->get_data() );
```

- [ ] **Step 2: Run RED tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCapitalRestControllerTest|WooPaymentsApiClientTest'
```

Expected: fail because `WooPaymentsCapitalRestController` and `WooPaymentsApiClient::get_capital_*` methods are missing.

- [ ] **Step 3: Implement the native Capital controller**

Use the existing WooPayments controller shape: `RegisterHooksInterface`, `NativePaymentsRuntimeArbiter::should_native_register()`, `check_permission()` with `manage_woocommerce`, `WooPaymentsApiException` to `WP_Error`, and `WP_REST_Response` success bodies. Register only:

```php
register_rest_route( self::NAMESPACE, '/payments/capital/active_loan_summary', $this->get_readable_route( 'get_active_loan_summary' ) );
register_rest_route( self::NAMESPACE, '/payments/capital/loans', $this->get_readable_route( 'get_loans' ) );
```

- [ ] **Step 4: Wire Core registration**

Add the controller registration next to the other native WooPayments REST controllers:

```php
$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController::class )->register();
```

- [ ] **Step 5: Run GREEN backend gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCapitalRestControllerTest|WooPaymentsMobileRestControllerTest|WooPaymentsApiClientTest'
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php --memory-limit=2G
```

Expected: focused tests and static checks pass. Do not add to the PHPStan baseline.

## Task 2: Native Payout Details, Card Readers, and Capital Frontend Routes

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify or create: `plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/index.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/page.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/data.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/types.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/style.scss`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/index.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/page.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/data.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/types.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/card-readers-page.test.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/capital-page.test.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/payout-details-page.test.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`

- [ ] **Step 1: Write RED route and page tests**

Extend the route test to require:

```ts
expect( getRegisteredSettingsPaymentsProviderRoutes().map( ( route ) => route.path ) ).toContain( '/woopayments/card-readers' );
expect( getRegisteredSettingsPaymentsProviderRoutes().map( ( route ) => route.path ) ).toContain( '/woopayments/loans' );
expect( getRegisteredSettingsPaymentsProviderRoutes().map( ( route ) => route.path ) ).toContain( '/woopayments/payouts/details' );
```

Add page tests that mock `@wordpress/api-fetch` and assert loading, success, empty, and error states for payout details, the existing readers endpoint, and the new Capital endpoints.

- [ ] **Step 2: Run RED frontend tests**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js --runTestsByPath client/woopayments/admin/test/routes.test.tsx client/woopayments/admin/test/payout-details-page.test.tsx client/woopayments/admin/test/card-readers-page.test.tsx client/woopayments/admin/test/capital-page.test.tsx
```

Expected: fail because routes and components are missing.

- [ ] **Step 3: Implement data helpers**

Use plain Core `apiFetch`, matching existing A4 data helpers:

```ts
apiFetch< WooPaymentsDeposit >( { path: `/wc/v3/payments/deposits/${ encodeURIComponent( depositId ) }`, method: 'GET' } );
apiFetch< Record< string, unknown > >( { path: `/wc/v3/payments/transactions/summary?deposit_id=${ encodeURIComponent( depositId ) }`, method: 'GET' } );
apiFetch< WooPaymentsCardReader[] >( { path: '/wc/v3/payments/readers?limit=10', method: 'GET' } );
apiFetch< WooPaymentsCapitalSummary >( { path: '/wc/v3/payments/capital/active_loan_summary', method: 'GET' } );
apiFetch< WooPaymentsCapitalLoan[] >( { path: '/wc/v3/payments/capital/loans', method: 'GET' } );
```

- [ ] **Step 4: Implement payout details page**

Render `Payout details` with a back link to `/woopayments/payouts`, the payout ID, date, status, amount, bank reference/arrival fields when present, failure notice when `failure_code` is present, and a transaction summary section driven by the preserved `deposit_id` summary query. The payouts list should link each payout row to `/woopayments/payouts/details?id=<deposit_id>`.

- [ ] **Step 5: Implement Card readers page**

Render a compact provider-owned settings sub-page with title `Card readers`, description matching the reference meaning, a three-column readers list (`Reader ID`, `Model`, `Status`), active status labels, loading, empty, and error states. Use route-owned CSS and avoid nested cards.

- [ ] **Step 6: Implement Capital Loans page**

Render title `Capital Loans`, a test-mode notice if native settings expose test-drive account state, an active-loan summary section when the summary indicates an active loan, and an all-loans table with `Disbursed`, `Status`, `Amount`, `Fixed fee`, `Withhold rate`, and `First paydown`. Link loan rows to the native transactions route with `loan_id_is=<stripe_loan_id>`.

- [ ] **Step 7: Register lazy routes and chunks**

Add lazy chunks:

```ts
const WooPaymentsPayoutDetailsChunk = lazy( () => import( /* webpackChunkName: "settings-payments-woopayments-payouts" */ './payout-details' ) );
const WooPaymentsCardReadersChunk = lazy( () => import( /* webpackChunkName: "settings-payments-woopayments-card-readers" */ './card-readers' ) );
const WooPaymentsCapitalChunk = lazy( () => import( /* webpackChunkName: "settings-payments-woopayments-capital" */ './capital' ) );
```

Register:

```ts
registerSettingsPaymentsProviderRoute( { id: 'woopayments-payout-details', path: '/woopayments/payouts/details', order: 111, element: <Suspense fallback={ <LoadingFallback /> }><WooPaymentsPayoutDetailsChunk /></Suspense> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-card-readers', path: '/woopayments/card-readers', order: 125, element: <Suspense fallback={ <LoadingFallback /> }><WooPaymentsCardReadersChunk /></Suspense> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-capital', path: '/woopayments/loans', order: 126, element: <Suspense fallback={ <LoadingFallback /> }><WooPaymentsCapitalChunk /></Suspense> } );
```

- [ ] **Step 8: Run GREEN frontend gates**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js --runTestsByPath client/woopayments/admin/test/routes.test.tsx client/woopayments/admin/test/payout-details-page.test.tsx client/woopayments/admin/test/card-readers-page.test.tsx client/woopayments/admin/test/capital-page.test.tsx
pnpm --filter='@woocommerce/admin-library' ts:check
pnpm --filter='@woocommerce/admin-library' exec eslint client/woopayments/admin/routes.tsx client/woopayments/admin/payouts.tsx client/woopayments/admin/payout-details.tsx client/woopayments/admin/card-readers client/woopayments/admin/capital client/woopayments/admin/test/payout-details-page.test.tsx client/woopayments/admin/test/card-readers-page.test.tsx client/woopayments/admin/test/capital-page.test.tsx
pnpm --filter='@woocommerce/admin-library' exec stylelint client/woopayments/admin/card-readers/style.scss client/woopayments/admin/capital/style.scss client/woopayments/admin/style.scss
```

Expected: tests, type check, lint, and stylelint pass.

## Task 3: Browser, Harness, Bundle, and Review Gates

**Files:**
- Modify: `plugins/woocommerce/changelog/*` via the WooCommerce changelog command.
- Modify: `staging-log.md` and `implementation-log.md` only after source gates pass.

- [ ] **Step 1: Build admin assets**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' build
```

Expected: build succeeds and emits separate Card readers and Capital lazy chunks without moving WooPayments-specific code into the base settings bundle.

- [ ] **Step 2: Run restored harness**

Run:

```bash
tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Expected: deterministic cross-store gate passes. If it fails, fix product regressions rather than weakening the harness.

- [ ] **Step 3: Use Playwriter for target and reference smoke**

Open target routes:

```text
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts%2Fdetails&id=<existing-payout-id>
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fcard-readers
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Floans
```

Open reference routes:

```text
http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fpayouts%2Fdetails&id=<existing-payout-id>
http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fcard-readers
http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Floans
```

Expected: target routes render usable provider-owned surfaces with no 4xx/5xx network responses, no new PHP warnings/notices in local logs, and no obvious styling regression against the reference. If the local account has no Capital loan data or no card readers, record that the empty state is verified and use REST-level tests for populated rows.

- [ ] **Step 4: Dispatch review agents**

Run read-only reviews after the diff is stable:

```text
architecture-reviewer: provider-owned surfaces stay under Settings > Payments route registry, no generic Capital/Card-reader abstractions leaked.
api-contract-reviewer: preserved REST route names, response shape, permissions, and arbiter gating.
performance-reviewer: lazy chunks, CSS scoping, no base-bundle or registry duplication.
a11y-reviewer: tables/lists/loading/error states, links, and status labels are accessible.
```

Expected: all blocking findings fixed and re-reviewed.

- [ ] **Step 5: Final branch gates and changelog**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog add
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git diff --check
```

Expected: clean, apart from already-known repository warning noise that is recorded explicitly if it appears.

- [ ] **Step 6: Commit**

Commit source/tests and changelog as separate logical commits if appropriate:

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/client/admin/client/woopayments/admin
git commit
git add plugins/woocommerce/changelog
git commit
```

Do not push. Do not commit scratchpad files unless explicitly asked.

## Out of Scope

- Reports area (`/payments/reports`) remains the next heavier A4 slice because it needs multiple report REST contracts, export polling, custom date filtering, and a broader browser/Tracks comparison.
- Documents, multi-currency setup, and fraud-protection advanced settings remain explicit A4 disposition items; do not silently treat them as complete.
- Plugin-era `/payments/connect`, `/payments/onboarding`, and top-level menu semantics remain a route-architecture/compatibility-alias disposition, not direct route recreation in this slice.
- No WPCOM code changes, no WPCOM sandbox access, no WooPayments plugin changes.
- Do not flip `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY`; A4 remains open until Reports/Documents disposition and the standing A4 exit gate pass.
