---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:33
status: draft
---

# Core Native Payments B3d WooPayments Legacy Runtime Accessor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize transition-time WooPayments plugin runtime discovery for the native WooPayments provider bridge and WooPayments-backed multi-currency adapters.

**Architecture:** Add `WooPaymentsLegacyRuntime` as the provider-side seam that owns `LegacyProxy` calls for `WC_Payments` runtime availability, gateway lookup, account-service lookup, API-client lookup, and logger lookup. Rewire the gateway adapter, payment-method details service, and WooPayments multi-currency account/API adapters to consume that seam while preserving current fail-closed behavior.

**Tech Stack:** WooCommerce Core PHP, DI container init methods, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

---

## Files

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`.
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php` to consume `WooPaymentsLegacyRuntime` instead of `LegacyProxy` directly for gateway lookup.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php` to consume `WooPaymentsLegacyRuntime` for API-client and logger lookup.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php` to consume `WooPaymentsLegacyRuntime` for account-service lookup.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php` to consume `WooPaymentsLegacyRuntime` for API-client lookup.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php` so its local adapter factory initializes a `WooPaymentsLegacyRuntime` with the existing `LegacyProxyWithGateway`.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3d-legacy-runtime-accessor`.

### Task 1: Add RED Accessor Coverage

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`

- [ ] **Step 1: Add a test for fail-closed runtime absence and service lookup**

```php
public function test_fails_closed_when_woopayments_runtime_is_absent(): void {
	$sut = new WooPaymentsLegacyRuntime();
	$sut->init( new LegacyRuntimeProxy( false ) );

	$this->assertFalse( $sut->is_loaded() );
	$this->assertNull( $sut->get_gateway() );
	$this->assertNull( $sut->get_account_service() );
	$this->assertNull( $sut->get_payments_api_client() );
}
```

- [ ] **Step 2: Add a test for returning runtime services when available**

```php
public function test_returns_woopayments_runtime_services_when_available(): void {
	$gateway    = (object) array( 'id' => 'gateway' );
	$account    = (object) array( 'id' => 'account' );
	$api_client = (object) array( 'id' => 'api_client' );
	$logger     = (object) array( 'id' => 'logger' );
	$sut        = new WooPaymentsLegacyRuntime();
	$sut->init( new LegacyRuntimeProxy( true, $gateway, $account, $api_client, $logger ) );

	$this->assertTrue( $sut->is_loaded() );
	$this->assertSame( $gateway, $sut->get_gateway() );
	$this->assertSame( $account, $sut->get_account_service() );
	$this->assertSame( $api_client, $sut->get_payments_api_client() );
	$this->assertSame( $logger, $sut->get_logger() );
}
```

- [ ] **Step 3: Add a test for swallowed legacy lookup exceptions**

```php
public function test_swallows_runtime_lookup_exceptions(): void {
	$sut = new WooPaymentsLegacyRuntime();
	$sut->init( new ThrowingLegacyRuntimeProxy() );

	$this->assertFalse( $sut->is_loaded() );
	$this->assertNull( $sut->get_gateway() );
	$this->assertNull( $sut->get_account_service() );
	$this->assertNull( $sut->get_payments_api_client() );
	$this->assertNull( $sut->get_logger() );
}
```

- [ ] **Step 4: Run RED PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest'`

Expected: FAIL because `WooPaymentsLegacyRuntime` does not exist yet.

### Task 2: Implement the Accessor and Rewire Consumers

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify the four consumer classes listed above.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`

- [ ] **Step 1: Implement the accessor**

```php
class WooPaymentsLegacyRuntime {
	private LegacyProxy $legacy_proxy;

	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	public function is_loaded(): bool {
		try {
			return (bool) $this->legacy_proxy->call_function( 'class_exists', 'WC_Payments' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_gateway(): ?object {
		return $this->get_wc_payments_service( 'get_gateway' );
	}

	public function get_account_service(): ?object {
		return $this->get_wc_payments_service( 'get_account_service' );
	}

	public function get_payments_api_client(): ?object {
		return $this->get_wc_payments_service( 'get_payments_api_client' );
	}

	public function get_logger(): ?object {
		try {
			$logger = $this->legacy_proxy->call_function( 'wc_get_logger' );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $logger ) ? $logger : null;
	}

	private function get_wc_payments_service( string $method_name ): ?object {
		if ( ! $this->is_loaded() ) {
			return null;
		}

		try {
			$service = $this->legacy_proxy->call_static( 'WC_Payments', $method_name );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $service ) ? $service : null;
	}
}
```

- [ ] **Step 2: Rewire `WooPaymentsProviderGatewayAdapter`**

Change `init()` to accept `WooPaymentsLegacyRuntime $legacy_runtime`, store it, remove the `LegacyProxy` import/property, and change `get_legacy_gateway()` to:

```php
private function get_legacy_gateway(): ?object {
	return $this->legacy_runtime->get_gateway();
}
```

- [ ] **Step 3: Rewire `WooPaymentsPaymentMethodDetailsService`**

Change `init()` to accept `WooPaymentsLegacyRuntime $legacy_runtime`, use `$this->legacy_runtime->get_payments_api_client()` in `get_payment_method_details()`, and use `$this->legacy_runtime->get_logger()` in `log_fetch_error()`.

- [ ] **Step 4: Rewire the WooPayments multi-currency legacy adapters**

Change each adapter `init()` to accept `WooPaymentsLegacyRuntime $legacy_runtime`. In `WooPaymentsLegacyAccountAdapter::get_legacy_account()`, return `$this->legacy_runtime->get_account_service()`. In `WooPaymentsLegacyApiClientAdapter::get_legacy_api_client()`, return `$this->legacy_runtime->get_payments_api_client()`.

- [ ] **Step 5: Update the gateway adapter test helper**

```php
private function create_adapter( ?RecordingLegacyGateway $gateway ): WooPaymentsProviderGatewayAdapter {
	$legacy_runtime = new WooPaymentsLegacyRuntime();
	$legacy_runtime->init( new LegacyProxyWithGateway( $gateway ) );

	$sut = new WooPaymentsProviderGatewayAdapter();
	$sut->init( $legacy_runtime );

	return $sut;
}
```

- [ ] **Step 6: Run focused GREEN PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsPaymentMethodDetailsServiceTest|WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest|CurrencyRateProviderRegistryFactoryTest'`

Expected: PASS, preserving gateway/payment details/multi-currency adapter behavior through the centralized runtime accessor.

### Task 3: Verification, Changelog, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b3d-legacy-runtime-accessor`

- [ ] **Step 1: Add the changelog entry**

```text
Significance: patch
Type: dev
Comment: Centralize WooPayments legacy runtime lookup for native provider bridge adapters.
```

- [ ] **Step 2: Run PHPStan for touched production files**

Run: `cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php --memory-limit=2G`

Expected: No errors.

- [ ] **Step 3: Run scoped PHPCS for touched PHP files**

Run: `cd plugins/woocommerce && vendor/bin/phpcs -s src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsServiceTest.php tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapterTest.php tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapterTest.php`

Expected: No errors for touched files.

- [ ] **Step 4: Run changed-file lint and whitespace checks**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes && git diff --check`

Expected: PASS. Do not run markdownlint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 5: Stage source/test files and run staged checks**

Run: `git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php && pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged && git diff --cached --check`

Expected: Both staged checks pass.

- [ ] **Step 6: Commit source/tests**

Run: `git commit -m "refactor(payments): centralize WooPayments legacy runtime"`

Expected: Commit succeeds. If signing/authentication fails, leave files staged and record the pending commit instead of retrying with altered signing flags.

- [ ] **Step 7: Stage and commit changelog**

Run: `git add plugins/woocommerce/changelog/add-native-payments-b3d-legacy-runtime-accessor && git commit -m "chore(payments): add legacy runtime cleanup changelog"`

Expected: Commit succeeds. If signing/authentication fails, leave the changelog staged and record the pending commit instead of retrying with altered signing flags.

## Self-Review

- Spec coverage: The plan centralizes the repeated `WC_Payments` lookup logic in the narrow native WooPayments provider bridge scope without touching WPCOM or the WooPayments plugin checkout.
- Placeholder scan: No `TBD`, `TODO`, `implement later`, or unnamed follow-up placeholders are present.
- Type consistency: Consumers use the concrete internal `WooPaymentsLegacyRuntime` class so the existing Core DI init pattern can inject it without interface-binding changes.
