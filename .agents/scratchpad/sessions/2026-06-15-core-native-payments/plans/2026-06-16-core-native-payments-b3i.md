# Core Native Payments B3i Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the remaining provider/onboarding `wc_get_container()->get()` fallbacks by making WooPayments provider runtime dependencies explicit.

**Architecture:** B3d-B3h moved runtime discovery and multi-currency service factories behind explicit seams, but `WooPaymentsProvider` and `WooPaymentsOnboardingAdapter` still lazily pull collaborators from the DI container. B3i keeps constructor-free `init()` dependency injection, adds the native WooPayments gateway as an explicit onboarding dependency, removes provider/onboarding container fallbacks, and adds source-boundary coverage for those files. `NativeWooPaymentsGateway` stays out of scope because WooCommerce registers payment gateways by class name and can instantiate the gateway directly, so its existing direct-instantiation fallback remains a deliberate compatibility boundary.

**Tech Stack:** WooCommerce Core internal PHP services under `plugins/woocommerce/src/Internal/Payments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Boundary Test

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php`

- [ ] **Step 1: Add provider boundary coverage**

Add a source-boundary assertion to `WooPaymentsProviderTest` that fails while `WooPaymentsProvider::get_gateway_adapter()` calls `wc_get_container()->get( WooPaymentsProviderGatewayAdapter::class )`.

```php
/**
 * @testdox Provider should receive the gateway adapter through dependency injection.
 */
public function test_provider_gateway_adapter_access_is_injected(): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for provider-boundary regression coverage.
	$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php' );

	$this->assertDoesNotMatchRegularExpression(
		'/wc_get_container\(\)\s*->get\(\s*WooPaymentsProviderGatewayAdapter::class\s*\)/',
		$source,
		'WooPaymentsProvider should receive the gateway adapter through init injection.'
	);
}
```

- [ ] **Step 2: Add onboarding boundary coverage**

Add a source-boundary assertion to `WooPaymentsOnboardingAdapterTest` that fails while `WooPaymentsOnboardingAdapter` fetches `NativeWooPaymentsGateway`, `WooPaymentsLegacyRuntime`, or `WooPaymentsProvider` from the container.

```php
/**
 * @testdox Onboarding adapter should receive runtime collaborators through dependency injection.
 */
public function test_onboarding_runtime_collaborators_are_injected(): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for provider-boundary regression coverage.
	$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php' );

	foreach ( array( 'NativeWooPaymentsGateway', 'WooPaymentsLegacyRuntime', 'WooPaymentsProvider' ) as $dependency ) {
		$this->assertDoesNotMatchRegularExpression(
			'/wc_get_container\(\)\s*->get\(\s*' . $dependency . '::class\s*\)/',
			$source,
			"{$dependency} should be supplied through init injection."
		);
	}
}
```

- [ ] **Step 3: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderTest|WooPaymentsOnboardingAdapterTest'`

Expected: FAIL because `WooPaymentsProvider.php` and `WooPaymentsOnboardingAdapter.php` still contain direct container fallbacks.

### Task 2: Provider and Onboarding DI

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php`

- [ ] **Step 1: Remove provider lazy fallback**

Keep `WooPaymentsProvider::init( WooPaymentsProviderGatewayAdapter $gateway_adapter )` unchanged, and simplify `get_gateway_adapter()` to return the injected property directly.

```php
private function get_gateway_adapter(): WooPaymentsProviderGatewayAdapter {
	return $this->gateway_adapter;
}
```

- [ ] **Step 2: Inject native gateway into onboarding adapter**

Add a private `NativeWooPaymentsGateway $native_gateway` property and extend `init()` to accept it.

```php
/**
 * Native WooPayments gateway.
 *
 * @var NativeWooPaymentsGateway
 */
private NativeWooPaymentsGateway $native_gateway;

/**
 * @param WooPaymentsLegacyRuntime $legacy_runtime WooPayments legacy runtime.
 * @param WooPaymentsProvider      $provider       WooPayments provider.
 * @param NativeWooPaymentsGateway $native_gateway Native WooPayments gateway.
 */
final public function init( WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsProvider $provider, NativeWooPaymentsGateway $native_gateway ): void {
	$this->legacy_runtime = $legacy_runtime;
	$this->provider       = $provider;
	$this->native_gateway = $native_gateway;
}
```

- [ ] **Step 3: Replace onboarding lazy lookups**

In `get_payment_gateway()`, replace the native gateway container lookup with `$this->native_gateway`. Simplify `get_legacy_runtime()` and `get_provider()` to return the injected properties directly.

```php
if ( $this->is_native_provider_available() ) {
	return $this->native_gateway;
}
```

Use an `instanceof WC_Payment_Gateway` guard only if PHPStan requires it; `NativeWooPaymentsGateway` already extends `WC_Payment_Gateway_CC`.

- [ ] **Step 4: Update tests**

Update all `WooPaymentsOnboardingAdapter::init()` calls in `WooPaymentsOnboardingAdapterTest` to pass a new `NativeWooPaymentsGateway()` or a test-specific gateway double if needed. Existing provider and legacy runtime mocks should remain unchanged.

### Task 3: Verification and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3i-provider-onboarding-di`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderTest|WooPaymentsOnboardingAdapterTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsLegacyRuntimeTest|WooPaymentsWebhookRestControllerTest|WooPaymentsWebhookReliabilityServiceTest'`

Expected: PASS.

- [ ] **Step 2: Run static gates**

Run PHPStan for `WooPaymentsProvider.php` and `WooPaymentsOnboardingAdapter.php`. Run scoped PHPCS for the touched source/tests. Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, then staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged` and `git diff --cached --check` before committing. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Commit source/tests and changelog separately**

Commit source/tests first with `refactor(payments): inject WooPayments provider collaborators`, then commit the changelog with `chore(payments): add provider DI cleanup changelog`.

- [ ] **Step 4: Record evidence**

Append B3i final evidence above the implementation-log append marker and above the staging-log append marker. Include the focused test result, PHPStan/PHPCS/lint evidence, commit hashes, and git range.
