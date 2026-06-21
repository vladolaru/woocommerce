# Core Native Payments B3j Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the WooPayments admin service lazy container fallback for `WooPaymentsOnboardingAdapter`.

**Architecture:** B3i made the onboarding adapter’s own collaborators explicit, but `WooPaymentsService` still accepts that adapter as optional and lazily fetches it from the container. B3j makes the adapter a required `init()` dependency for `WooPaymentsService`, removes the private lazy lookup, and updates the focused service test setup to pass either the real container adapter or the existing mock adapter. `NativeWooPaymentsGateway` remains out of scope because WooCommerce can instantiate payment gateway classes directly.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Boundary Test

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`

- [ ] **Step 1: Add failing source-boundary coverage**

Add a test that reads `WooPaymentsService.php` and fails while it calls `wc_get_container()->get( WooPaymentsOnboardingAdapter::class )`.

```php
/**
 * @testdox Should receive the onboarding adapter through dependency injection.
 */
public function test_onboarding_adapter_access_is_injected(): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for admin-service boundary regression coverage.
	$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php' );

	$this->assertDoesNotMatchRegularExpression(
		'/wc_get_container\(\)\s*->get\(\s*WooPaymentsOnboardingAdapter::class\s*\)/',
		$source,
		'WooPaymentsService should receive the onboarding adapter through init injection.'
	);
}
```

- [ ] **Step 2: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsServiceTest::test_onboarding_adapter_access_is_injected'`

Expected: FAIL because `WooPaymentsService::get_onboarding_adapter()` still fetches the adapter from the container.

### Task 2: Admin Service DI

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`

- [ ] **Step 1: Make the adapter dependency required**

Change the property to non-null, make the `init()` parameter required, and update the PHPDoc.

```php
/**
 * The WooPayments onboarding adapter.
 *
 * @var WooPaymentsOnboardingAdapter
 */
private WooPaymentsOnboardingAdapter $onboarding_adapter;

/**
 * @param PaymentsProviders          $payment_providers  The PaymentsProviders instance.
 * @param LegacyProxy                $proxy              The LegacyProxy instance.
 * @param WooPaymentsOnboardingAdapter $onboarding_adapter The WooPayments onboarding adapter.
 */
final public function init( PaymentsProviders $payment_providers, LegacyProxy $proxy, WooPaymentsOnboardingAdapter $onboarding_adapter ): void {
	$this->payments_providers = $payment_providers;
	$this->proxy              = $proxy;
	$this->onboarding_adapter = $onboarding_adapter;
	// Existing setup remains unchanged.
}
```

- [ ] **Step 2: Simplify the getter**

Replace `get_onboarding_adapter()` with a direct return.

```php
private function get_onboarding_adapter(): WooPaymentsOnboardingAdapter {
	return $this->onboarding_adapter;
}
```

- [ ] **Step 3: Update tests**

In `WooPaymentsServiceTest::setUp()`, pass the container adapter:

```php
$this->sut->init(
	$this->mock_providers,
	$this->mockable_proxy,
	wc_get_container()->get( WooPaymentsOnboardingAdapter::class )
);
```

Keep `test_get_onboarding_details_uses_onboarding_adapter_runtime_availability()` passing its mock adapter explicitly.

### Task 3: Verification and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3j-admin-onboarding-di`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsServiceTest|WooPaymentsOnboardingAdapterTest|WooPaymentsRestControllerIntegrationTest'`

Expected: PASS.

- [ ] **Step 2: Run static gates**

Run PHPStan for `WooPaymentsService.php`, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Commit source/tests and changelog separately**

Commit source/tests first with `refactor(payments): inject WooPayments admin onboarding adapter`, then commit the changelog with `chore(payments): add admin onboarding DI changelog`.

- [ ] **Step 4: Record evidence**

Append B3j final evidence above the implementation-log append marker and above the staging-log append marker. Include the focused test result, PHPStan/PHPCS/lint evidence, commit hashes, and git range.
