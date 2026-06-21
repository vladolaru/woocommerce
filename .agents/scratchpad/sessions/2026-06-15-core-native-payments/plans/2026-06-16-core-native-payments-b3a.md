---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:07
status: draft
---

# Core Native Payments B3a Rate Provider Registrar Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple native multi-currency registry creation from direct WooPayments legacy adapter construction by introducing an internal rate-provider registrar seam.

**Architecture:** `CurrencyRateProviderRegistryFactory` remains the only service that creates fresh provider registries, but it no longer constructs WooPayments providers directly. A new internal `CurrencyRateProviderRegistrarInterface` supplies providers to a registry, and `WooPaymentsCurrencyRateProviderRegistrar` preserves the existing default WooPayments automatic-rate provider while allowing the factory to create an empty registry when no registrars are configured.

**Tech Stack:** WooCommerce Core PHP, PSR-4 classes under `plugins/woocommerce/src/Internal/MultiCurrency/Providers`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

---

## Files

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php` to add RED coverage for empty registrar lists and explicit registrar-provided providers.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php` for the internal registrar contract.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php` for the default WooPayments provider registrar.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php` so it owns registry creation and delegates provider supply to configured registrars.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3a-rate-provider-registrars` as the plugin changelog entry.

### Task 1: Write RED Provider Registrar Factory Tests

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php`

- [ ] **Step 1: Add imports for the desired registrar seam and fake provider support**

```php
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\WooPaymentsCurrencyRateProviderRegistrar;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\WooPaymentsLegacyAccountAdapter;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\WooPaymentsLegacyApiClientAdapter;
```

- [ ] **Step 2: Add a test proving the factory can create an empty registry when provider supply is empty**

```php
/**
 * @testdox Should create an empty registry when no provider registrars are configured.
 */
public function test_creates_empty_registry_when_provider_registrars_are_empty(): void {
	$sut = $this->create_factory_with_default_registrar();
	$sut->set_provider_registrars( array() );

	$registry = $sut->create();

	$this->assertSame( array(), $registry->get_providers() );
	$this->assertNull( $registry->get_available_provider() );
}
```

- [ ] **Step 3: Add a test proving configured registrars supply providers to fresh registries**

```php
/**
 * @testdox Should register providers from configured registrars.
 */
public function test_registers_providers_from_configured_registrars(): void {
	$sut = $this->create_factory_with_default_registrar();
	$sut->set_provider_registrars( array( $this->create_fake_provider_registrar() ) );

	$registry = $sut->create();

	$this->assertArrayHasKey( 'fake-provider', $registry->get_providers() );
	$this->assertTrue( $registry->get_provider( 'fake-provider' )->is_available() );
}
```

- [ ] **Step 4: Add helper methods used by the new tests**

```php
/**
 * Create a standalone factory with the default WooPayments registrar.
 *
 * @return CurrencyRateProviderRegistryFactory
 */
private function create_factory_with_default_registrar(): CurrencyRateProviderRegistryFactory {
	$registrar = new WooPaymentsCurrencyRateProviderRegistrar();
	$registrar->init( new WooPaymentsLegacyAccountAdapter(), new WooPaymentsLegacyApiClientAdapter() );

	$sut = new CurrencyRateProviderRegistryFactory();
	$sut->init( $registrar );

	return $sut;
}

/**
 * Create a fake provider registrar.
 *
 * @return CurrencyRateProviderRegistrarInterface
 */
private function create_fake_provider_registrar(): CurrencyRateProviderRegistrarInterface {
	return new class() implements CurrencyRateProviderRegistrarInterface {
		/**
		 * Register fake providers.
		 *
		 * @param CurrencyRateProviderRegistry $registry Rate provider registry.
		 */
		public function register( CurrencyRateProviderRegistry $registry ): void {
			$registry->register(
				new class() implements CurrencyRateProvider {
					/**
					 * Get the provider identifier.
					 *
					 * @return string
					 */
					public function get_id(): string {
						return 'fake-provider';
					}

					/**
					 * Tell whether the provider is available.
					 *
					 * @return bool
					 */
					public function is_available(): bool {
						return true;
					}

					/**
					 * Get supported currencies.
					 *
					 * @return string[]
					 */
					public function get_supported_currencies(): array {
						return array( 'EUR' );
					}

					/**
					 * Get currency rates.
					 *
					 * @param string        $currency_from Source currency.
					 * @param string[]|null $currencies_to Target currencies.
					 * @return array<string,mixed>
					 */
					public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
						unset( $currency_from, $currencies_to );

						return array( 'eur' => 1.2 );
					}
				}
			);
		}
	};
}
```

- [ ] **Step 5: Run RED PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'CurrencyRateProviderRegistryFactoryTest'`

Expected: FAIL because `CurrencyRateProviderRegistrarInterface`, `WooPaymentsCurrencyRateProviderRegistrar`, and `CurrencyRateProviderRegistryFactory::set_provider_registrars()` do not exist yet.

### Task 2: Implement the Internal Registrar Seam

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php`

- [ ] **Step 1: Add `CurrencyRateProviderRegistrarInterface`**

```php
<?php
/**
 * CurrencyRateProviderRegistrarInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Providers;

/**
 * Registers automatic-rate providers into a registry.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
interface CurrencyRateProviderRegistrarInterface {

	/**
	 * Register rate providers.
	 *
	 * @param CurrencyRateProviderRegistry $registry Rate provider registry.
	 */
	public function register( CurrencyRateProviderRegistry $registry ): void;
}
```

- [ ] **Step 2: Add `WooPaymentsCurrencyRateProviderRegistrar`**

```php
<?php
/**
 * WooPaymentsCurrencyRateProviderRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Providers;

/**
 * Registers the WooPayments-backed automatic FX rate provider.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class WooPaymentsCurrencyRateProviderRegistrar implements CurrencyRateProviderRegistrarInterface {

	/**
	 * WooPayments account adapter.
	 *
	 * @var WooPaymentsLegacyAccountAdapter
	 */
	private WooPaymentsLegacyAccountAdapter $account_adapter;

	/**
	 * WooPayments API client adapter.
	 *
	 * @var WooPaymentsLegacyApiClientAdapter
	 */
	private WooPaymentsLegacyApiClientAdapter $api_client_adapter;

	/**
	 * Initialize the registrar.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyAccountAdapter   $account_adapter    WooPayments account adapter.
	 * @param WooPaymentsLegacyApiClientAdapter $api_client_adapter WooPayments API client adapter.
	 */
	final public function init( WooPaymentsLegacyAccountAdapter $account_adapter, WooPaymentsLegacyApiClientAdapter $api_client_adapter ): void {
		$this->account_adapter    = $account_adapter;
		$this->api_client_adapter = $api_client_adapter;
	}

	/**
	 * Register the WooPayments rate provider.
	 *
	 * @param CurrencyRateProviderRegistry $registry Rate provider registry.
	 */
	public function register( CurrencyRateProviderRegistry $registry ): void {
		$registry->register( new WooPaymentsCurrencyRateProvider( $this->account_adapter, $this->api_client_adapter ) );
	}
}
```

- [ ] **Step 3: Update `CurrencyRateProviderRegistryFactory` to delegate provider supply**

```php
/**
 * Rate provider registrars.
 *
 * @var CurrencyRateProviderRegistrarInterface[]
 */
private array $provider_registrars = array();

/**
 * Initialize the class instance.
 *
 * @internal
 *
 * @param WooPaymentsCurrencyRateProviderRegistrar $woo_payments_provider_registrar WooPayments provider registrar.
 */
final public function init( WooPaymentsCurrencyRateProviderRegistrar $woo_payments_provider_registrar ): void {
	$this->provider_registrars = array( $woo_payments_provider_registrar );
}

/**
 * Set explicit rate-provider registrars.
 *
 * @internal Used by tests and future explicit provider bootstrap.
 *
 * @param CurrencyRateProviderRegistrarInterface[] $provider_registrars Provider registrars.
 */
public function set_provider_registrars( array $provider_registrars ): void {
	$this->provider_registrars = array_values( $provider_registrars );
}

/**
 * Create a fresh rate provider registry.
 *
 * @return CurrencyRateProviderRegistry
 */
public function create(): CurrencyRateProviderRegistry {
	$registry = new CurrencyRateProviderRegistry();

	foreach ( $this->provider_registrars as $provider_registrar ) {
		$provider_registrar->register( $registry );
	}

	return $registry;
}
```

- [ ] **Step 4: Run focused GREEN PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'CurrencyRateProviderRegistryFactoryTest|WooPaymentsCurrencyRateProviderTest|WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderFactoryTest'`

Expected: PASS, preserving default WooPayments automatic-rate behavior and proving empty registrar lists are allowed.

### Task 3: Verification, Changelog, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b3a-rate-provider-registrars`

- [ ] **Step 1: Add the changelog entry**

```text
Significance: patch
Type: dev
Comment: Decouple native multi-currency rate-provider registry creation from direct WooPayments adapter construction.
```

- [ ] **Step 2: Run PHPStan for touched production files**

Run: `cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapter.php src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapter.php src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactory.php --memory-limit=2G`

Expected: No errors.

- [ ] **Step 3: Run scoped PHPCS for touched PHP files**

Run: `cd plugins/woocommerce && vendor/bin/phpcs -s src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php`

Expected: No errors for touched files.

- [ ] **Step 4: Run branch changed-file lint without scratchpad docs**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`

Expected: PASS. Do not run markdownlint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 5: Run diff whitespace check**

Run: `git diff --check`

Expected: No output.

- [ ] **Step 6: Stage source/test files and run staged checks**

Run: `git add plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php && pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged && git diff --cached --check`

Expected: Both staged checks pass.

- [ ] **Step 7: Commit source/tests**

Run: `git commit -m "refactor(payments): decouple rate provider registration"`

Expected: Commit succeeds. If signing/authentication fails, leave files staged and record the pending commit instead of retrying with altered signing flags.

- [ ] **Step 8: Stage and commit changelog**

Run: `git add plugins/woocommerce/changelog/add-native-payments-b3a-rate-provider-registrars && git commit -m "chore(payments): add rate provider cleanup changelog"`

Expected: Commit succeeds. If signing/authentication fails, leave the changelog staged and record the pending commit instead of retrying with altered signing flags.

## Self-Review

- Spec coverage: B3 cleanup begins by removing direct WooPayments provider construction from the generic registry factory while preserving current automatic-rate behavior through an internal default registrar.
- Placeholder scan: No `TBD`, `TODO`, `implement later`, or unnamed follow-up placeholders are present.
- Type consistency: The interface name, registrar class name, method signatures, and file paths are consistent across the tests, production code, and verification commands.
