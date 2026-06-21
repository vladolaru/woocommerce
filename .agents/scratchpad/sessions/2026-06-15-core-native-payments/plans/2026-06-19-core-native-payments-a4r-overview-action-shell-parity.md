---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 09:43
target: exp/core-native-payments A4r Overview action shell parity
reconciles:
  - ../analysis-a4r-overview-action-shell-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4r Overview Action Shell Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments Overview action shell around the A4q financial cards without reopening A5 readiness.

**Architecture:** Add a small Core-owned Overview projection behind the Settings > Payments WooPayments admin route, then consume it in the Overview chunk with scoped React components for notices, tasks, and modals. Reuse existing settings option persistence, disputes APIs, financial-card APIs, and the native account-mode activation event; do not reintroduce `window.wcpaySettings`, plugin stores, embedded Connect, inbox, active-loan, or account-details surfaces in this slice.

**Tech Stack:** WooCommerce PHP services and REST controller tests, WordPress REST API, React/TypeScript admin client, `@wordpress/components`, `@wordpress/data` notices, Jest/React Testing Library, scoped SCSS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php`: admin projection over the native account cache and existing WooPayments admin URLs.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`: register `GET /wc-admin/settings/payments/woopayments/overview`, inject/lazily resolve the projection service, and return permission/error-safe responses.
- Create `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewServiceTest.php`: RED/GREEN coverage for projection shape and safe account-cache behavior.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`: RED/GREEN route success, capability denial, and exception handling.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/types.ts`: add Overview shell, task, account status, and dispute-task types.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts`: add `getWooPaymentsOverviewShell()` and `getWooPaymentsOverviewDisputes()` while keeping existing deposits paths unchanged.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-notices.tsx`: query-param notices and connection-success modal host.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-task-list.tsx`: reference-compatible task visibility, dismiss/delete/snooze, undo notices, and task rendering.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-tasks.tsx`: pure task builders for update details, reconnect, dispute, and go-live tasks.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/update-business-details-modal.tsx`: scoped modal for multiple requirement errors.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/connection-success-modal.tsx`: modal with persisted dismissal.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`: load the shell projection and disputes task data, render notices/tasks before financial cards, and fail closed to the existing A4q cards when shell data is unavailable.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: add scoped task/modal styles under the existing Overview namespace.
- Modify or add focused Jest files under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`: data-client path tests, pure task tests, task list behavior tests, and page integration tests.
- Add a WooCommerce changelog entry for the source change.

## Task 1: Backend Overview Projection

**Files:**
- Create: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`

- [ ] **Step 1: Write RED projection tests**

Add tests that set `wcpay_account_data`, `woocommerce_woocommerce_payments_settings`, `woocommerce_dismissed_todo_tasks`, `woocommerce_deleted_todo_tasks`, `woocommerce_remind_me_later_todo_tasks`, and `wcpay_connection_success_modal_dismissed`, then assert the Overview projection returns a safe shape:

```php
$overview = $this->sut->get_overview();

$this->assertSame( 'acct_native_test', $overview['account']['id'] );
$this->assertTrue( $overview['account']['details_submitted'] );
$this->assertSame( 'restricted_soon', $overview['account_status']['status'] );
$this->assertSame( 1781740800, $overview['account_status']['current_deadline'] );
$this->assertSame( array( 'verification_document_missing_front' ), $overview['account_status']['requirements']['errors'][0]['code'] ? array( $overview['account_status']['requirements']['errors'][0]['code'] ) : array() );
$this->assertTrue( $overview['show_update_details_task'] );
$this->assertSame( array( 'old-task' ), $overview['overview_tasks_visibility']['dismissed_todo_tasks'] );
$this->assertTrue( $overview['is_connection_success_modal_dismissed'] );
$this->assertSame( '', $overview['wpcom_reconnect_url'] );
$this->assertArrayNotHasKey( 'test_publishable_key', $overview['account'] );
$this->assertArrayNotHasKey( 'live_publishable_key', $overview['account'] );
```

Add a no-account test that asserts `connected` and `show_update_details_task` are false and the projection does not invent readiness or action URLs.

- [ ] **Step 2: Run RED projection tests**

Run:

```bash
pnpm run test:php:env -- --filter WooPaymentsOverviewServiceTest
```

Expected: FAIL because `WooPaymentsOverviewService`/`get_overview()` does not exist yet, or because the projection route is absent.

- [ ] **Step 3: Implement the projection service**

Create `WooPaymentsOverviewService` with an `init( WooPaymentsService $woopayments, WooPaymentsAccountService $account_service ): void` method. Implement `get_overview(): array` using `WooPaymentsService::get_account_summary()` for existing URLs and high-level account booleans, `WooPaymentsAccountService::get_cached_account_data()` for account-status fields, and `get_option()` for task/modal visibility. Project only scalar/array fields needed by the UI, normalize booleans with `filter_var( ..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false`, and keep `wpcom_reconnect_url` as an empty string until native has a real reconnect URL seam.

Projection contract:

```php
return array(
	'account'                               => array(
		'id'                   => $summary['account']['id'] ?? '',
		'mode'                 => $summary['account']['mode'] ?? 'live',
		'connected'            => (bool) ( $summary['account']['connected'] ?? false ),
		'working'              => (bool) ( $summary['account']['working'] ?? false ),
		'can_process_payments' => (bool) ( $summary['account']['can_process_payments'] ?? false ),
		'details_submitted'    => $this->is_truthy( $account_data['details_submitted'] ?? false ),
		'test_mode'            => (bool) ( $summary['account']['test_mode'] ?? false ),
		'test_mode_onboarding' => in_array( get_option( 'wcpay_onboarding_test_mode', 'no' ), array( 'yes', '1' ), true ),
		'dev_mode'             => $this->account_service->is_dev_mode_enabled(),
		'test_drive'           => (bool) ( $summary['account']['test_drive'] ?? false ),
		'sandbox'              => (bool) ( $summary['account']['sandbox'] ?? false ),
		'live'                 => (bool) ( $summary['account']['live'] ?? false ),
	),
	'account_status'                        => array(
		'status'              => $this->get_account_status( $account_data ),
		'current_deadline'    => $this->get_current_deadline( $account_data ),
		'past_due'            => $this->has_past_due_requirements( $account_data ),
		'account_link'        => $this->get_scalar( $account_data['account_link'] ?? $account_data['accountLink'] ?? '' ),
		'requirements'        => array( 'errors' => $this->get_requirement_errors( $account_data ) ),
		'details_submitted'   => $this->is_truthy( $account_data['details_submitted'] ?? false ),
		'payments_enabled'    => $this->is_truthy( $account_data['payments_enabled'] ?? false ),
		'deposits_enabled'    => $this->is_truthy( $account_data['deposits_enabled'] ?? $account_data['payouts_enabled'] ?? false ),
	),
	'show_update_details_task'              => $this->should_show_update_business_details_task( $account_data ),
	'overview_tasks_visibility'             => $this->get_overview_tasks_visibility(),
	'is_connection_success_modal_dismissed' => (bool) get_option( 'wcpay_connection_success_modal_dismissed', false ),
	'wpcom_reconnect_url'                   => '',
	'urls'                                  => array(
		'overview_page' => $summary['urls']['overview_page'] ?? '',
		'settings'      => Utils::wc_payments_settings_url( '/woopayments/settings' ),
		'onboarding'    => Utils::wc_payments_settings_url( '/woopayments/onboarding' ),
		'setup'         => $summary['urls']['setup'] ?? '',
	),
);
```

Implement `get_account_status()` to follow the reference Stripe status logic from local account cache: explicit scalar `status` wins; `requirements.disabled_reason` maps `requirements.pending_verification` to `pending_verification`, `requirements.fields_needed` to `restricted_partially`, `rejected*` to the disabled reason, any other non-empty disabled reason to `restricted`; non-empty `requirements.past_due` or top-level `has_overdue_requirements` maps to `restricted`; non-empty `currently_due` with a current deadline maps to `restricted_soon`; non-empty `eventually_due` without a current deadline maps to `enabled`; otherwise connected accounts map to `complete` and no-account stores map to `not_connected`.

- [ ] **Step 4: Wire REST route RED/GREEN**

Add `WooPaymentsOverviewService` as an optional sixth dependency on `WooPaymentsRestController::init()`, a lazy `get_overview_service()` resolver, a `GET /wc-admin/settings/payments/woopayments/overview` route near the existing `/account` route, and `protected function get_overview(): WP_REST_Response`.

Update `WooPaymentsRestControllerTest` to assert:

```php
$request  = new WP_REST_Request( 'GET', self::ENDPOINT . '/overview' );
$response = $this->server->dispatch( $request );
$this->assertSame( 200, $response->get_status() );
$this->assertSame( $overview, $response->get_data() );
```

Also add capability denial and thrown exception tests mirroring the account summary tests.

- [ ] **Step 5: Run GREEN backend tests**

Run:

```bash
pnpm run test:php:env -- --filter 'WooPaymentsOverviewServiceTest|WooPaymentsRestControllerTest::test_get_overview'
```

Expected: PASS.

## Task 2: Frontend Data, Types, And Pure Task Builders

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-tasks.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-data.test.ts`
- Create or modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-tasks.test.tsx`

- [ ] **Step 1: Write RED frontend data tests**

Extend `overview-data.test.ts` with tests for:

```ts
await getWooPaymentsOverviewShell();
expect( mockApiFetch ).toHaveBeenCalledWith( {
	path: '/wc-admin/settings/payments/woopayments/overview',
	method: 'GET',
} );

await getWooPaymentsOverviewDisputes();
expect( mockApiFetch ).toHaveBeenCalledWith( {
	path: '/wc/v3/payments/disputes?page=1&pagesize=50&filter=awaiting_response',
	method: 'GET',
} );
```

- [ ] **Step 2: Write RED pure task tests**

Add tests for task construction without rendering the full page:

```ts
expect(
	buildOverviewTasks( {
		shell: incompleteSetupShell,
		disputes: [],
		onOpenUpdateBusinessDetails: jest.fn(),
		onActivatePayments: jest.fn(),
	} )[ 0 ]
).toMatchObject( {
	key: 'complete-setup',
	title: 'Finish setting up WooPayments',
	actionLabel: 'Finish setup',
} );

expect(
	buildOverviewTasks( {
		shell: restrictedSoonShell,
		disputes: [],
		onOpenUpdateBusinessDetails: jest.fn(),
		onActivatePayments: jest.fn(),
	} )[ 0 ].content
).toContain( 'Update by' );

expect(
	buildOverviewTasks( {
		shell: liveShell,
		disputes: [ disputeDueTomorrow ],
		onOpenUpdateBusinessDetails: jest.fn(),
		onActivatePayments: jest.fn(),
	} )[ 0 ]
).toMatchObject( {
	actionLabel: 'Respond now',
	title: 'Respond to a dispute for $10.00',
} );
```

- [ ] **Step 3: Run RED frontend tests**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/admin/client/woopayments/admin/test/overview-data.test.ts client/admin/client/woopayments/admin/test/overview-tasks.test.tsx
```

Expected: FAIL because the data functions and task builders do not exist yet.

- [ ] **Step 4: Implement types and data functions**

Add `WooPaymentsOverviewShell`, `WooPaymentsOverviewTask`, `WooPaymentsOverviewTasksVisibility`, and `WooPaymentsOverviewDispute` types. Add `getWooPaymentsOverviewShell()` and `getWooPaymentsOverviewDisputes()` to `data.ts`, reusing the existing `buildPathWithQuery()` helper pattern from deposits/money-movement. Do not add a new `@wordpress/data` store for this slice.

- [ ] **Step 5: Implement pure task builders**

Create `overview-tasks.tsx` with pure helpers:

- `buildOverviewTasks( args ): WooPaymentsOverviewTask[]`
- `getVisibleOverviewTasks( tasks, visibility, now = Date.now() )`
- `isDisputeDueWithinDays( dispute, days, now = Date.now() )`
- `formatTaskCurrency( amount, currency )`

Preserve reference copy for task titles/actions/content using the `woocommerce` text domain. Route incomplete setup to `wc-settings&tab=checkout&path=/woopayments/onboarding&source=wcpay-finish-setup-task&from=WCPAY_OVERVIEW`. Route one urgent dispute to `/woopayments/transactions/details?id={charge_id}` and multiple urgent disputes to `/woopayments/disputes?filter=awaiting_response` through `getAdminLink()`. Trigger go-live with `document.dispatchEvent( new CustomEvent( 'wcpay:activate_payments' ) )` rather than hoisting the plugin modal.

- [ ] **Step 6: Run GREEN frontend data/task tests**

Run the same Jest command and verify it passes.

## Task 3: Frontend Shell Components And Page Integration

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-notices.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-task-list.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/update-business-details-modal.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/connection-success-modal.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-page.test.tsx`
- Create or modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-task-list.test.tsx`

- [ ] **Step 1: Write RED task-list and page tests**

Add task-list tests that render two tasks and visibility state, then assert hidden/deleted/snoozed tasks are filtered; clicking dismiss/delete/snooze calls `saveOption()` with the reference option names; undo actions restore the previous state; and the list uses accessible buttons rather than inert text.

Add page integration tests that mock shell/deposits/disputes responses and assert the render order is: top notices, task list card, spotlight, financial cards. Add a failure test where `getWooPaymentsOverviewShell()` rejects and the financial cards still render.

Add connection-success modal tests that assert `wcpay-connection-success=1` plus `can_process_payments` plus deposits-enabled shell state renders "You're ready to accept payments!", and dismissing it calls `saveOption( 'wcpay_connection_success_modal_dismissed', true )`.

- [ ] **Step 2: Run RED shell tests**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/admin/client/woopayments/admin/test/overview-page.test.tsx client/admin/client/woopayments/admin/test/overview-task-list.test.tsx
```

Expected: FAIL because the shell components are absent.

- [ ] **Step 3: Implement components**

Implement `OverviewTaskList` using native `<button>` elements, core notices, and the existing `saveOption()` action. Keep the live region stable where notices are visual state changes; do not conditionally mount a status-only region without content.

Implement `OverviewNotices` for the reference query-param notices that native can support without platform work: `wcpay-login-error`, `wcpay-loan-offer-error`, `wcpay-server-link-error`, and `wcpay-reset-account-error`. Use `Notice` with `isDismissible={ false }` and preserve the informational copy.

Implement `UpdateBusinessDetailsModal` with reference modal copy for restricted and restricted-soon states, warning notices for each error, a secondary Cancel button, and a primary Update button that opens an explicit `account_link` only when present. The incomplete-setup path should go directly to native onboarding and should not open this modal.

Implement `ConnectionSuccessModal` with heading "You're ready to accept payments!", description "Great news - your WooPayments account has been activated. You can now start accepting payments on your store.", and primary Dismiss button. Use Core text domain and persist dismissal through `saveOption()`.

- [ ] **Step 4: Integrate page loading**

Update `WooPaymentsOverviewPage` to load shell and disputes independently of deposits. The page must preserve A4q behavior if shell loading fails: render financial cards, account settings, and spotlight without tasks, and avoid showing action tasks from stale/incomplete shell state. When shell is present, render tasks before the financial cards and keep the `SpotlightPromotion` mount point.

- [ ] **Step 5: Add scoped styles**

Add styles under `.woocommerce-woopayments-overview__tasks`, `.woocommerce-woopayments-overview-task`, `.woocommerce-woopayments-overview-query-notices`, `.woocommerce-woopayments-update-business-details-modal`, and `.woocommerce-woopayments-connection-success-modal`. Keep dimensions stable, mobile responsive, and scoped to the existing admin Overview bundle.

- [ ] **Step 6: Run GREEN shell tests**

Run the same Jest command and verify it passes.

## Task 4: Verification, Browser Proof, Docs, And Commit

**Files:**
- Modify: `plugins/woocommerce/changelog/*`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Run focused automated gates**

Run:

```bash
pnpm run test:php:env -- --filter 'WooPaymentsOverviewServiceTest|WooPaymentsRestControllerTest::test_get_overview'
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/admin/client/woopayments/admin/test/overview-data.test.ts client/admin/client/woopayments/admin/test/overview-tasks.test.tsx client/admin/client/woopayments/admin/test/overview-task-list.test.tsx client/admin/client/woopayments/admin/test/overview-page.test.tsx
pnpm --filter=@woocommerce/admin-library lint:js -- client/admin/client/woopayments/admin/overview client/admin/client/woopayments/admin/test/overview-data.test.ts client/admin/client/woopayments/admin/test/overview-page.test.tsx
pnpm --filter=@woocommerce/admin-library lint:style -- client/admin/client/woopayments/admin/style.scss
pnpm --filter=@woocommerce/admin-library lint:lang:types
pnpm --filter=@woocommerce/admin-library build:project:bundle
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

Do not lint `.agents/scratchpad`.

- [ ] **Step 2: Run browser proof with Playwriter**

Use Playwriter against the target Overview URL:

```text
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview
```

Verify the page loads with no PHP notices in the UI, existing financial cards still render, the task shell renders only when data supports it, and persistent Payments navigation still reaches Overview. Compare against reference `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview` for visible copy/order where the local account state exposes the same shell sections.

- [ ] **Step 3: Run harness and A4 gate**

Run:

```bash
tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
tools/woopayments-merge/admin-surface-gate.sh
```

If container names differ, derive them from the current local env and record the exact commands in the implementation log. Treat green output only as the coverage it claims.

- [ ] **Step 4: Add changelog and docs**

Add a WooCommerce changelog entry with a concise merchant-facing description. Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with A4r scope, evidence, caveats, and remaining N12 gaps. Record that Stripe dashboard login-link generation, embedded Connect notifications, inbox notifications, active loan summary, dispute readiness, account details, Reports/Documents, and full final A4 exit gate remain open.

- [ ] **Step 5: Run final branch checks**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git diff --check HEAD -- . ':!.agents'
```

- [ ] **Step 6: Commit without pushing**

Commit one logical source/test change and one changelog/docs change if both are non-empty. Do not push. Report the git range from the commit before A4r through the last A4r commit.

## Self-Review

Spec coverage: This plan implements the N12 Overview action-shell subset after A4q and keeps unrelated Overview/dashboard surfaces as explicit follow-ups. It preserves fail-closed native admin readiness and avoids WPCOM code changes.

Placeholder scan: No task contains TBD or a deferred implementation step without an explicit follow-up disposition.

Type consistency: The backend projection uses snake_case response keys; frontend types consume the same snake_case contract and task builders stay pure so tests can exercise state variants without browser-only setup.
