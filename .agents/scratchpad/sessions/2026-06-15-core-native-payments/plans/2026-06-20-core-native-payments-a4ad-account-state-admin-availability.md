---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 02:22
reconciles:
  - ../analysis-a4ad-next-parity-slice.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: implemented
---

# A4ad Account-State Admin Availability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make protected native WooPayments admin surfaces respect the same account-state and optional-feature availability as persistent admin navigation, and widen the local A4 gate so future readiness cannot rely on a single connected-account smoke run.

**Architecture:** Keep the provider settings route reachable through the Core Settings > Payments route seam, because the provider Manage/configuration flow must still work. Add a backend route-availability projection next to the existing Reports feature flag, derived from `WooPaymentsAccountService` predicates already used by native menu registration. Add frontend route wrappers that check the projected availability before `Suspense`, so unavailable protected routes render a small accessible status message and do not import the protected chunk or call WooPayments admin REST.

**Tech Stack:** WooCommerce Core PHP services/tests, React/TypeScript admin route registration, Jest/React Testing Library, ignored local Playwriter harness.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php` to preload `wcSettings.admin.woopaymentsSettings.adminRouteAvailability`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php` to assert connected, disabled-gateway, restricted, onboarding, and optional-feature availability projections.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` to add route-availability helpers and protected route wrappers.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx` to RED/GREEN unavailable route no-chunk behavior and connected/restricted route access.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php` to use `WooPaymentsAccountService` and gate Capital route registration/legacy offer redirects on previous-loan eligibility.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php` to assert Capital routes and loan-offer redirects are absent when Capital is ineligible.
- Modify ignored harness files under `tools/woopayments-merge/` to add account/feature availability evidence without weakening the product assertions.
- Add one WooCommerce changelog entry under `plugins/woocommerce/changelog/`.

## Task 1: Backend Availability Projection And Capital REST Guard

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php`

- [x] **Step 1: Write RED PHP tests for the route-availability projection**

Add tests to `WooPaymentsAdminNavigationControllerTest` that call `preload_shared_settings()` on a Payments settings request and assert `adminRouteAvailability.allowedRoutes`.

Minimum cases:

```php
public function test_preloads_full_admin_route_availability_for_valid_native_account(): void {
	$_GET['page'] = 'wc-settings';
	$_GET['tab']  = 'checkout';

	$sut = $this->create_controller(
		true,
		array(
			'is_reports_enabled'          => true,
			'is_documents_enabled'        => true,
			'is_card_present_eligible'    => true,
			'has_card_readers_available'  => true,
			'has_previous_capital_loans'  => true,
		)
	);

	$settings = $sut->preload_shared_settings( array() );
	$routes   = $settings['woopaymentsSettings']['adminRouteAvailability']['allowedRoutes'];

	$this->assertTrue( $routes['/woopayments/overview'] );
	$this->assertTrue( $routes['/woopayments/payouts'] );
	$this->assertTrue( $routes['/woopayments/transactions'] );
	$this->assertTrue( $routes['/woopayments/disputes'] );
	$this->assertTrue( $routes['/woopayments/reports'] );
	$this->assertTrue( $routes['/woopayments/card-readers'] );
	$this->assertTrue( $routes['/woopayments/loans'] );
	$this->assertTrue( $routes['/woopayments/documents'] );
}

public function test_preloads_restricted_admin_route_availability(): void {
	$_GET['page'] = 'wc-settings';
	$_GET['tab']  = 'checkout';

	$sut = $this->create_controller(
		true,
		array(
			'is_account_under_review' => true,
			'is_reports_enabled'      => true,
			'is_documents_enabled'    => true,
		)
	);

	$routes = $sut->preload_shared_settings( array() )['woopaymentsSettings']['adminRouteAvailability']['allowedRoutes'];

	$this->assertTrue( $routes['/woopayments/overview'] );
	$this->assertTrue( $routes['/woopayments/transactions'] );
	$this->assertTrue( $routes['/woopayments/disputes'] );
	$this->assertFalse( $routes['/woopayments/payouts'] );
	$this->assertFalse( $routes['/woopayments/reports'] );
	$this->assertFalse( $routes['/woopayments/documents'] );
}
```

Also cover disabled gateway and not-connected/details-missing states so protected routes are false while `/woopayments/settings` stays true.

- [x] **Step 2: Write RED PHP tests for Capital REST eligibility**

Update `WooPaymentsCapitalRestControllerTest::create_controller()` to accept a `$has_previous_capital_loans` boolean, and add tests:

```php
public function test_registers_no_routes_when_capital_is_not_eligible(): void {
	$this->sut = $this->create_controller( true, false );
	$this->sut->register();

	$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'redirect_loan_offer_request' ) ) );
}

public function test_loan_offer_query_arg_is_ignored_when_capital_is_not_eligible(): void {
	$this->sut                       = $this->create_controller( true, false );
	$_GET['wcpay-loan-offer']        = '';

	$this->sut->redirect_loan_offer_request();

	$this->assertSame( '', $this->api_client->last_call );
}
```

- [x] **Step 3: Run the RED PHP tests**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest|WooPaymentsCapitalRestControllerTest'
```

Expected: failures because `adminRouteAvailability` is absent and Capital registration is not eligibility-gated.

- [x] **Step 4: Implement backend projection and Capital guard**

In `WooPaymentsAdminNavigationController::preload_shared_settings()`, preserve existing `featureFlags.reportsArea` and add:

```php
$settings['woopaymentsSettings']['adminRouteAvailability'] = $this->get_admin_route_availability();
```

Add private helpers that derive route availability from the same predicates used by `get_menu_items()`:

```php
private function get_admin_route_availability(): array {
	$gateway_enabled = $this->account_service->is_gateway_enabled();
	$restricted      = $this->account_service->is_account_rejected() || $this->account_service->is_account_under_review();
	$valid_account   = $this->account_service->has_valid_account_for_admin_navigation();
	$full_access     = $gateway_enabled && $valid_account && ! $restricted;
	$reduced_access  = $gateway_enabled && $restricted;

	return array(
		'gatewayEnabled' => $gateway_enabled,
		'accountState'   => $this->get_admin_route_account_state(),
		'allowedRoutes'  => array(
			self::PATH_SETTINGS            => true,
			self::PATH_ONBOARDING          => $gateway_enabled && ! $valid_account && ! $restricted,
			self::PATH_OVERVIEW            => $full_access || $reduced_access,
			self::PATH_PAYOUTS             => $full_access,
			self::PATH_PAYOUT_DETAILS      => $full_access,
			self::PATH_TRANSACTIONS        => $full_access || $reduced_access,
			self::PATH_TRANSACTION_DETAILS => $full_access || $reduced_access,
			self::PATH_DISPUTES            => $full_access || $reduced_access,
			self::PATH_DISPUTE_DETAILS     => $full_access || $reduced_access,
			self::PATH_DISPUTE_CHALLENGE   => $full_access || $reduced_access,
			self::PATH_REPORTS             => $full_access && $this->account_service->is_reports_enabled(),
			self::PATH_CARD_READERS        => $full_access && $this->account_service->is_card_present_eligible() && $this->account_service->has_card_readers_available(),
			self::PATH_LOANS               => $full_access && $this->account_service->has_previous_capital_loans(),
			self::PATH_DOCUMENTS           => $full_access && $this->account_service->is_documents_enabled(),
		),
	);
}
```

In `WooPaymentsCapitalRestController`, inject `WooPaymentsAccountService`, return early from `register()` when `! $this->account_service->has_previous_capital_loans()`, and include the same eligibility check in `redirect_loan_offer_request()`.

- [x] **Step 5: Run GREEN PHP tests**

Run the same focused PHP command. Expected: all tests pass.

## Task 2: Frontend Protected Route Wrappers

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`

- [x] **Step 1: Write RED route-wrapper Jest tests**

Add route tests that set:

```ts
window.wcSettings = {
	adminUrl: 'http://example.com/wp-admin',
	admin: {
		woopaymentsSettings: {
			adminRouteAvailability: {
				gatewayEnabled: true,
				accountState: 'full',
				allowedRoutes: {
					'/woopayments/settings': true,
					'/woopayments/overview': true,
					'/woopayments/transactions': true,
					'/woopayments/disputes': true,
					'/woopayments/loans': false,
					'/woopayments/documents': false,
					'/woopayments/card-readers': false,
					'/woopayments/reports': false,
				},
			},
		},
	},
};
```

Then render the Capital/Documents/Card Readers route elements and assert:

```ts
expect( screen.getByRole( 'status' ) ).toHaveTextContent(
	'This WooPayments admin area is unavailable.'
);
expect( screen.queryByText( 'Loading WooPayments…' ) ).not.toBeInTheDocument();
expect( mockApiFetch ).not.toHaveBeenCalled();
```

Add one restricted-account test that allows Overview/Transactions/Disputes but denies Payouts, Capital, Reports, Card Readers, and Documents. Keep the existing settings route and Reports disabled-feature test.

- [x] **Step 2: Run RED Jest tests**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
```

Expected: unavailable optional routes still show the `Suspense` loading fallback and/or call mocked REST, so the new tests fail.

- [x] **Step 3: Implement route availability wrappers**

In `routes.tsx`, extend the window type:

```ts
adminRouteAvailability?: {
	gatewayEnabled?: boolean;
	accountState?: string;
	allowedRoutes?: Record< string, boolean >;
};
```

Add helpers:

```tsx
const WooPaymentsAdminAreaUnavailable = () => (
	<div role="status" aria-live="polite">
		{ __( 'This WooPayments admin area is unavailable.', 'woocommerce' ) }
	</div>
);

const isWooPaymentsAdminRouteAvailable = ( routePath: string ) => {
	const allowedRoutes =
		( globalThis as WooPaymentsRouteWindow ).wcSettings?.admin
			?.woopaymentsSettings?.adminRouteAvailability?.allowedRoutes;

	if ( ! allowedRoutes ) {
		return true;
	}

	return allowedRoutes[ routePath ] !== false;
};

const ProtectedWooPaymentsRoute = ( {
	path: routePath,
	children,
}: {
	path: string;
	children: JSX.Element;
} ) =>
	isWooPaymentsAdminRouteAvailable( routePath ) ? (
		children
	) : (
		<WooPaymentsAdminAreaUnavailable />
	);
```

Wrap protected routes before `Suspense`:

```tsx
element: (
	<ProtectedWooPaymentsRoute path="/woopayments/loans">
		<Suspense fallback={ <LoadingFallback /> }>
			<WooPaymentsCapitalChunk />
		</Suspense>
	</ProtectedWooPaymentsRoute>
),
```

Apply the wrapper to Overview, Payouts, payout details, Transactions, transaction details, Reports, Disputes, dispute details, dispute challenge, Card Readers, Capital, and Documents. Do not wrap the provider settings route or express/fraud settings routes in this slice.

- [x] **Step 4: Run GREEN Jest tests**

Run the same Jest command. Expected: route tests pass and unavailable protected routes do not show the lazy loading fallback.

## Task 3: Widen The Local A4 Gate

**Files:**
- Modify: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`
- Optionally modify/add helper scripts under `tools/woopayments-merge/`

- [x] **Step 1: Add route availability evidence without masking bugs**

Extend the Playwriter gate evidence with an `availabilityMatrix` section. It should record at least:

- Current target connected/full account: eligible protected routes still load their expected chunks.
- Disabled optional features from the shared bootstrap where available: Reports/Capital/Documents/Card Readers route availability is captured from `wcSettings.admin.woopaymentsSettings.adminRouteAvailability.allowedRoutes`.
- If deterministic local mutation is not reliable through Dev Tools, record that limitation explicitly in JSON and keep PHP/Jest as the deterministic state matrix.

Do not waive failed responses, console issues, missing tokens, or missing assets as part of this task.

- [x] **Step 2: Run the gate and inspect output**

Run:

```bash
playwriter run tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs
```

Expected: the gate still fails closed if the current target route matrix regresses, and the evidence JSON includes route availability data.

## Task 4: Verification, Reviews, Docs, Changelog, Commit

**Files:**
- Add: `plugins/woocommerce/changelog/fix-native-payments-admin-availability`
- Update:
  - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
  - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
  - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
  - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Keep native WooPayments admin routes aligned with account and feature availability.
```

- [x] **Step 2: Run focused and adjacent verification**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest|WooPaymentsCapitalRestControllerTest|WooPaymentsReportsRestControllerTest|WooPaymentsDocumentsRestControllerTest|WooPaymentsMobileRestControllerTest'
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:js -- plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
pnpm --filter=@woocommerce/plugin-woocommerce ts:check
pnpm --filter=@woocommerce/plugin-woocommerce build:project:bundle
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
```

Run PHPStan for changed PHP production files from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php --memory-limit=2G
```

- [x] **Step 3: Browser/log proof**

Clear target/reference debug logs, run the widened Playwriter gate, and scan target/reference/local-WPCOM logs for PHP notices/warnings/fatals and actual HTTP 4xx/5xx signatures from the run window. Do not ignore new WP notices.

- [x] **Step 4: Review gates**

Use subagent review for:

- WordPress/backend architecture: route projection uses existing account-service predicates and does not break provider settings or mobile reader APIs.
- Frontend/accessibility: unavailable-route status is accessible and wrappers do not degrade loading semantics.
- Reliability: no silent unavailable protected admin REST calls and no weakened harness assertions.

- [x] **Step 5: Update scratchpad docs**

Record final status, evidence paths, gate limitations, and remaining A4 follow-ups: payment-detail/dispute-action parity and WooPay settings residual parity.

- [x] **Step 6: Commit product changes**

Commit only Git-visible product/changelog changes. Do not commit scratchpad or ignored harness files unless explicitly requested.

Expected commit grouping:

```bash
git add plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php \
	plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php \
	plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php \
	plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx \
	plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
git commit -m "fix(payments): gate native admin routes by account state"

git add plugins/woocommerce/changelog/fix-native-payments-admin-availability
git commit -m "chore(payments): add admin availability changelog"
```

No push.
