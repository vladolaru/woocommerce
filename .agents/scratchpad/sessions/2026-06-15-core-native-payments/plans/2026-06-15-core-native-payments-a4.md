# Core Native Payments A4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move WooPayments admin ownership onto core-controlled seams: provider-specific client boundary, native-safe NOX adapter, and admin bundle dedupe for DataViews.

**Architecture:** A4 keeps the current core `settings-payments` UI as the visible entrypoint, but routes WooPayments-specific client consumers through `client/admin/client/woopayments/` so provider code stops living conceptually inside the generic settings page. PHP NOX/account checks move behind a WooPayments onboarding adapter in `Internal\Payments\Providers\WooPayments`; the adapter delegates to the A3 provider seam and preserves fail-closed behavior while the real core-owned WooPayments transport is not yet available. Admin webpack external mapping moves into a tested helper that externalizes `@wordpress/dataviews`/`@wordpress/dataviews/wp` to the WordPress `wp-dataviews` dependency instead of bundling a second `@wordpress/data` registry.

**Tech Stack:** WooCommerce Core PHP DI, PHPUnit 9.6, WordPress/WooCommerce admin React/TypeScript, Jest, webpack dependency extraction.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php`: native payments provider adapter used by NOX/admin onboarding to answer runtime availability, gateway, account, KYC fallback, and overview URL questions.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`: inject and use the onboarding adapter instead of reaching directly into `WC_Payments` for availability/account/gateway checks.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`: prove the service uses the adapter and no longer hard-codes the plugin class check.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php`: focused adapter tests for legacy-active, native-provider-available, and unavailable fail-closed states.
- Create `plugins/woocommerce/client/admin/client/woopayments/onboarding/index.ts`: provider-specific client boundary re-exporting the existing WooPayments onboarding implementation.
- Modify core client consumers of `~/settings-payments/onboarding/providers/woopayments...` to import from `~/woopayments/onboarding`.
- Create `plugins/woocommerce/client/admin/webpack-externals.js`: tested admin webpack dependency-extraction override helper.
- Modify `plugins/woocommerce/client/admin/webpack.config.js`: use the helper for `requestToExternal`/`requestToHandle`.
- Create `plugins/woocommerce/client/admin/client/build/test/webpack-externals.test.js`: Jest guard for DataViews externalization.
- Create changelog entries under `plugins/woocommerce/changelog/` and `packages/js/dependency-extraction-webpack-plugin/changelog/` only if the final touched package set requires them. If only admin package files lack a package changelog directory, record the admin-library touch in the WooCommerce plugin changelog.

## Task 1: Native-Safe NOX Onboarding Adapter

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`

- [ ] **Step 1: Write adapter tests first**

Add `WooPaymentsOnboardingAdapterTest` covering these cases:

```php
public function test_runtime_available_when_legacy_extension_is_active(): void {
	$this->legacy_proxy->register_function_mocks(
		array(
			'class_exists' => fn( $class ) => '\WC_Payments' === $class,
		)
	);
	$this->provider->method( 'can_process_payments' )->willReturn( false );

	self::assertTrue( $this->adapter->is_onboarding_runtime_available() );
}

public function test_runtime_available_when_native_provider_can_process_without_plugin(): void {
	$this->legacy_proxy->register_function_mocks(
		array(
			'class_exists' => fn( $class ) => false,
		)
	);
	$this->provider->method( 'can_process_payments' )->willReturn( true );

	self::assertTrue( $this->adapter->is_onboarding_runtime_available() );
}

public function test_account_state_is_fail_closed_when_no_runtime_is_available(): void {
	$this->legacy_proxy->register_function_mocks(
		array(
			'class_exists' => fn( $class ) => false,
		)
	);
	$this->provider->method( 'can_process_payments' )->willReturn( false );

	self::assertFalse( $this->adapter->has_account( $this->payment_gateway_provider ) );
	self::assertFalse( $this->adapter->has_valid_account( $this->payment_gateway_provider ) );
	self::assertFalse( $this->adapter->has_working_account( $this->payment_gateway_provider ) );
}
```

Expected initial result: FAIL because `WooPaymentsOnboardingAdapter` does not exist.

- [ ] **Step 2: Add the adapter implementation**

Implement a focused adapter with these public methods:

```php
final public function init( LegacyProxy $proxy, WooPaymentsProvider $provider ): void;
public function is_extension_active(): bool;
public function is_native_provider_available(): bool;
public function is_onboarding_runtime_available(): bool;
public function get_payment_gateway(): \WC_Payment_Gateway;
public function has_account( PaymentGateway $provider ): bool;
public function has_valid_account( PaymentGateway $provider ): bool;
public function has_working_account( PaymentGateway $provider ): bool;
public function has_test_account( PaymentGateway $provider ): bool;
public function has_sandbox_account( PaymentGateway $provider ): bool;
public function has_live_account( PaymentGateway $provider ): bool;
public function get_onboarding_kyc_fallback_url( PaymentGateway $provider ): string;
public function get_overview_page_url(): string;
```

Implementation constraints:

```php
public function is_onboarding_runtime_available(): bool {
	return $this->is_extension_active() || $this->is_native_provider_available();
}

public function is_native_provider_available(): bool {
	try {
		return $this->provider->can_process_payments();
	} catch ( \Throwable $e ) {
		return false;
	}
}
```

All account helpers must return `false` when `is_onboarding_runtime_available()` is false. Calls that still depend on plugin classes must keep using `LegacyProxy` so tests can mock them and production remains fail-closed when the plugin is absent.

- [ ] **Step 3: Run adapter tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsOnboardingAdapterTest
```

Expected: PASS.

- [ ] **Step 4: Write service delegation regression test**

In `WooPaymentsServiceTest`, add a test that passes a mocked `WooPaymentsOnboardingAdapter` into `WooPaymentsService::init()`. Mock global `class_exists( '\WC_Payments' )` to return false, but make the adapter report `is_onboarding_runtime_available() === true` and return a `FakePaymentGateway`. Assert `get_onboarding_details()` does not throw `woocommerce_woopayments_onboarding_extension_not_active`.

Expected initial result: FAIL because `WooPaymentsService` still checks `WC_Payments` directly.

- [ ] **Step 5: Delegate service internals to the adapter**

Change `WooPaymentsService::init()` to accept an optional adapter:

```php
final public function init(
	PaymentsProviders $payment_providers,
	LegacyProxy $proxy,
	?WooPaymentsOnboardingAdapter $onboarding_adapter = null
): void {
	$this->payments_providers = $payment_providers;
	$this->proxy              = $proxy;
	$this->onboarding_adapter = $onboarding_adapter;
	// existing WPCOM/provider setup remains.
}
```

Add:

```php
private function get_onboarding_adapter(): WooPaymentsOnboardingAdapter {
	if ( ! isset( $this->onboarding_adapter ) ) {
		$this->onboarding_adapter = wc_get_container()->get( WooPaymentsOnboardingAdapter::class );
	}

	return $this->onboarding_adapter;
}
```

Replace private service methods with adapter delegation:

```php
private function is_extension_active(): bool {
	return $this->get_onboarding_adapter()->is_onboarding_runtime_available();
}

private function get_payment_gateway(): \WC_Payment_Gateway {
	return $this->get_onboarding_adapter()->get_payment_gateway();
}

private function has_valid_account(): bool {
	return $this->get_onboarding_adapter()->has_valid_account( $this->provider );
}
```

Apply the same pattern for `has_account`, `has_working_account`, `has_test_account`, `has_sandbox_account`, `has_live_account`, `get_onboarding_kyc_fallback_url`, and `get_overview_page_url`.

- [ ] **Step 6: Run service and adapter tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsServiceTest|WooPaymentsOnboardingAdapterTest'
```

Expected: PASS.

## Task 2: Core WooPayments Client Boundary

**Files:**

- Create: `plugins/woocommerce/client/admin/client/woopayments/onboarding/index.ts`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-main.tsx`
- Modify: `plugins/woocommerce/client/admin/client/launch-your-store/hub/main-content/pages/payments-content.tsx`
- Modify: `plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/payments-mobile-header.tsx`
- Modify: `plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/payments-sidebar.tsx`
- Modify: `plugins/woocommerce/client/admin/client/launch-your-store/data/setup-payments-context.tsx`
- Modify related tests only where mocks point at the old provider path.

- [ ] **Step 1: Add the provider-specific boundary**

Create `client/admin/client/woopayments/onboarding/index.ts`:

```ts
export { default } from '~/settings-payments/onboarding/providers/woopayments';
export { default as WooPaymentsModal } from '~/settings-payments/onboarding/providers/woopayments';
export { default as WooPaymentsOnboarding } from '~/settings-payments/onboarding/providers/woopayments/components/onboarding';
export {
	OnboardingProvider,
	useOnboardingContext,
} from '~/settings-payments/onboarding/providers/woopayments/data/onboarding-context';
export {
	LYSPaymentsSteps,
	steps,
} from '~/settings-payments/onboarding/providers/woopayments/steps';
```

- [ ] **Step 2: Route core consumers through the boundary**

Replace external consumers of the old WooPayments provider path:

```ts
import WooPaymentsModal from '~/woopayments/onboarding';
import {
	WooPaymentsOnboarding,
	useOnboardingContext,
	OnboardingProvider,
	LYSPaymentsSteps,
} from '~/woopayments/onboarding';
```

Do not rewrite imports inside `settings-payments/onboarding/providers/woopayments/` itself in this stage; those remain the compatibility implementation source.

- [ ] **Step 3: Update Jest mocks for changed import paths**

Where tests mock the old context path, update the mock path to:

```ts
jest.mock( '~/woopayments/onboarding', () => ( {
	useOnboardingContext: jest.fn(),
} ) );
```

Keep mock exports aligned with each test's imports.

- [ ] **Step 4: Run focused admin Jest tests**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- settings-payments-main.test.tsx payments-sidebar.test.tsx
```

Expected: PASS.

## Task 3: Externalize DataViews From Admin Bundles

**Files:**

- Create: `plugins/woocommerce/client/admin/webpack-externals.js`
- Modify: `plugins/woocommerce/client/admin/webpack.config.js`
- Create: `plugins/woocommerce/client/admin/client/build/test/webpack-externals.test.js`

- [ ] **Step 1: Write the external mapping test**

Create `client/admin/client/build/test/webpack-externals.test.js`:

```js
const {
	requestToExternal,
	requestToHandle,
} = require( '../../../webpack-externals' );

describe( 'admin webpack externals', () => {
	it.each( [ '@wordpress/dataviews', '@wordpress/dataviews/wp' ] )(
		'externalizes %s to the WordPress DataViews script',
		( request ) => {
			expect( requestToExternal( request ) ).toEqual( [
				'wp',
				'dataviews',
			] );
			expect( requestToHandle( request ) ).toBe( 'wp-dataviews' );
		}
	);
} );
```

Expected initial result: FAIL because `webpack-externals.js` does not exist.

- [ ] **Step 2: Extract admin webpack external overrides**

Create `webpack-externals.js` with the current custom mapping from `webpack.config.js`. Preserve current behavior for `moment-timezone`, React JSX runtime compatibility, `react-dom/client`, `@wordpress/global-styles-engine`, `@wordpress/theme`, `@wordpress/ui`, and build/build-module imports.

For DataViews, add:

```js
if (
	request === '@wordpress/dataviews' ||
	request === '@wordpress/dataviews/wp'
) {
	return [ 'wp', 'dataviews' ];
}
```

And in `requestToHandle`:

```js
if (
	request === '@wordpress/dataviews' ||
	request === '@wordpress/dataviews/wp'
) {
	return 'wp-dataviews';
}
```

- [ ] **Step 3: Wire webpack config to the helper**

In `webpack.config.js`, import:

```js
const {
	requestToExternal,
	requestToHandle,
} = require( './webpack-externals' );
```

Then pass those functions into `new WooCommerceDependencyExtractionWebpackPlugin( { requestToExternal, requestToHandle } )`.

- [ ] **Step 4: Run the external mapping test**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- webpack-externals.test.js
```

Expected: PASS.

## Task 4: Verification, Changelog, Review, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-a4-admin-js-dedupe`
- Create package changelog for `@woocommerce/dependency-extraction-webpack-plugin` only if that package is touched after implementation.
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run focused PHP tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsServiceTest|WooPaymentsOnboardingAdapterTest|WooPaymentsProviderTest|NativePaymentsGatewayRegistryTest'
```

Expected: PASS.

- [ ] **Step 2: Run focused JS tests**

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- webpack-externals.test.js settings-payments-main.test.tsx payments-sidebar.test.tsx
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php --memory-limit=2G
composer exec -- phpcs -s -p src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
pnpm --filter='@woocommerce/admin-library' lint:lang:js -- --quiet
```

Expected: PASS or only pre-existing unrelated warnings explicitly documented.

- [ ] **Step 4: Run build/dedupe evidence**

```bash
pnpm --filter='@woocommerce/admin-library' build:project:bundle
rg -n "@wordpress/dataviews|@wordpress/data" plugins/woocommerce/assets/client/admin/settings-email/index.asset.php plugins/woocommerce/assets/client/admin/app/index.asset.php
```

Expected: built asset metadata lists `wp-dataviews` where DataViews is needed; admin bundles do not contain a bundled DataViews module body. If the full build is too slow or fails for unrelated environment reasons, keep the Jest helper test as the deterministic A4 dedupe gate and document the build limitation.

- [ ] **Step 5: Run existing harness self-check**

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: 5/5 deterministic gates pass.

- [ ] **Step 6: Update logs**

Record A4 files, tests, any build limitation, dedupe evidence, and the A3 transport constraint in both implementation logs before committing.

- [ ] **Step 7: Commit A4**

Stage only A4-owned files, excluding `.agents/` and `docs/superpowers/`:

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php \
	plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php \
	plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php \
	plugins/woocommerce/client/admin/client/woopayments/onboarding/index.ts \
	plugins/woocommerce/client/admin/client/launch-your-store/hub/main-content/pages/payments-content.tsx \
	plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/payments-mobile-header.tsx \
	plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/payments-sidebar.tsx \
	plugins/woocommerce/client/admin/client/launch-your-store/data/setup-payments-context.tsx \
	plugins/woocommerce/client/admin/client/settings-payments/settings-payments-main.tsx \
	plugins/woocommerce/client/admin/webpack-externals.js \
	plugins/woocommerce/client/admin/webpack.config.js \
	plugins/woocommerce/client/admin/client/build/test/webpack-externals.test.js \
	plugins/woocommerce/changelog/add-native-payments-a4-admin-js-dedupe
git commit -m "feat(payments): add native payments A4 admin dedupe path"
```

Expected: commit succeeds. If signing fails, leave files staged and continue per repository instructions.

## Self-Review

- Spec coverage: A4 admin app boundary is covered by Task 2, JS dedupe by Task 3, NOX adapter by Task 1, and gates/logging by Task 4.
- Known non-goal: this stage does not port every WooPayments plugin admin screen or implement core-owned WooPayments network transport. That remains gated by the A3 architecture disposition and must be completed before A5 default-on cutover.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: PHP adapter method names in Task 1 match the service delegation names; JS boundary exports match the planned imports.
