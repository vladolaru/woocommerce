# Core Native Payments B2ae Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire native multi-currency state builders to a fail-closed WooPayments automatic-rate provider registry instead of fresh empty registries.

**Architecture:** Add transitional WooPayments account/API adapters that read compatible local WooPayments runtime boundaries through `LegacyProxy` without editing the plugin. Centralize provider registration and state-builder construction in small factories, then convert the first native multi-currency runtime paths to use those factories while preserving manual/no-provider fallback behavior.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/MultiCurrency/`, WooCommerce DI container, PHPUnit, PHPStan, PHPCS.

---

## File Map

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapter.php`
    - Implements `MultiCurrencyAccountInterface` by delegating to `WC_Payments::get_account_service()` when the local plugin runtime is loaded.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapter.php`
    - Implements `MultiCurrencyApiClientInterface` by delegating to `WC_Payments::get_payments_api_client()` when available.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php`
    - Builds a `CurrencyRateProviderRegistry` and registers `WooPaymentsCurrencyRateProvider` with the account/API adapters.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactory.php`
    - Builds `MultiCurrencyStateBuilder` with localization, database cache, and a rate service using the registry factory.
- Modify source fallback builders that currently use `new CurrencyRateProviderRegistry()`:
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php`
    - `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php`
- Test:
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapterTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapterTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactoryTest.php`
- Create changelog:
    - `plugins/woocommerce/changelog/add-native-payments-b2ae-multi-currency-provider-registry`

## Task 1: Add Legacy Account Adapter

- [ ] **Step 1: Write failing tests**

Add `WooPaymentsLegacyAccountAdapterTest` covering:

```php
public function test_fails_closed_when_woopayments_runtime_is_absent(): void {
	$this->mock_woopayments_runtime( false, null );
	$sut = wc_get_container()->get( WooPaymentsLegacyAccountAdapter::class );

	$this->assertFalse( $sut->is_provider_connected() );
	$this->assertFalse( $sut->is_account_rejected() );
	$this->assertFalse( $sut->get_cached_account_data() );
	$this->assertSame( array(), $sut->get_account_customer_supported_currencies() );
	$this->assertSame( array(), $sut->get_supported_countries() );
	$this->assertSame( '', $sut->get_provider_onboarding_page_url() );
}

public function test_delegates_to_legacy_account_service_when_available(): void {
	$account = new RecordingLegacyMultiCurrencyAccount();
	$this->mock_woopayments_runtime( true, $account );
	$sut = wc_get_container()->get( WooPaymentsLegacyAccountAdapter::class );

	$this->assertTrue( $sut->is_provider_connected() );
	$this->assertTrue( $sut->is_account_rejected() );
	$this->assertSame( array( 'account_id' => 'acct_123' ), $sut->get_cached_account_data( true ) );
	$this->assertSame( array( 'GBP', 'EUR' ), $sut->get_account_customer_supported_currencies() );
}
```

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAccountAdapterTest
```

Expected: fail with missing `WooPaymentsLegacyAccountAdapter`.

- [ ] **Step 3: Implement adapter**

Create `WooPaymentsLegacyAccountAdapter` with `LegacyProxy` injection, `class_exists( 'WC_Payments' )` guard, `WC_Payments::get_account_service()` lookup, `method_exists` guards, and `try/catch ( \Throwable $e )` fail-closed returns.

- [ ] **Step 4: Verify GREEN**

Run the same focused test and expect all tests in the class to pass.

## Task 2: Add Legacy API Client Adapter

- [ ] **Step 1: Write failing tests**

Add `WooPaymentsLegacyApiClientAdapterTest` covering absent runtime, connected runtime, rate delegation with lowercase parameters preserved, and thrown API exceptions returning an empty rates array.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyApiClientAdapterTest
```

Expected: fail with missing `WooPaymentsLegacyApiClientAdapter`.

- [ ] **Step 3: Implement adapter**

Create `WooPaymentsLegacyApiClientAdapter` with `LegacyProxy` injection, `WC_Payments::get_payments_api_client()` lookup, `is_server_connected()` delegation, and `get_currency_rates()` returning `array()` if the client is absent, missing the method, or throws.

- [ ] **Step 4: Verify GREEN**

Run the focused API adapter test and expect it to pass.

## Task 3: Add Provider Registry Factory

- [ ] **Step 1: Write failing tests**

Add `CurrencyRateProviderRegistryFactoryTest` asserting:

- Factory always returns a fresh `CurrencyRateProviderRegistry`.
- The registry contains the WooPayments provider ID.
- With connected account/API doubles, `get_available_provider()` returns the WooPayments provider and resolves rates/support.
- With absent/disconnected doubles, `get_available_provider()` returns `null`.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter CurrencyRateProviderRegistryFactoryTest
```

Expected: fail with missing `CurrencyRateProviderRegistryFactory`.

- [ ] **Step 3: Implement factory**

Create `CurrencyRateProviderRegistryFactory` with DI for `WooPaymentsLegacyAccountAdapter` and `WooPaymentsLegacyApiClientAdapter`. Its `create()` method should instantiate a fresh registry and register `new WooPaymentsCurrencyRateProvider( $account_adapter, $api_client_adapter )`.

- [ ] **Step 4: Verify GREEN**

Run the focused factory test and expect it to pass.

## Task 4: Add State Builder Factory and Wire Runtime Paths

- [ ] **Step 1: Write failing tests**

Add `MultiCurrencyStateBuilderFactoryTest` showing a provider-backed builder includes automatic GBP from a mocked WooPayments API client and excludes unsupported EUR from B2ad support filtering.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyStateBuilderFactoryTest
```

Expected: fail with missing `MultiCurrencyStateBuilderFactory`.

- [ ] **Step 3: Implement factory**

Create `MultiCurrencyStateBuilderFactory` with DI for `CurrencyRateProviderRegistryFactory`. Its `create()` method should build `MultiCurrencyStateBuilder` with:

```php
$localization_service = new MultiCurrencyLocalizationService();

return new MultiCurrencyStateBuilder(
	$localization_service,
	new MultiCurrencyRateService( $this->provider_registry_factory->create() ),
	new MultiCurrencyDatabaseCache()
);
```

- [ ] **Step 4: Wire source fallback builders**

Replace duplicated fallback `new MultiCurrencyStateBuilder( ..., new MultiCurrencyRateService( new CurrencyRateProviderRegistry() ), ... )` blocks with `wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create()`. Keep existing test setter methods intact.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest|CurrencyRateProviderRegistryFactoryTest|MultiCurrencyStateBuilderFactoryTest|MultiCurrencyStateBuilderTest'
```

Expected: all focused tests pass.

## Task 5: Regression, Static Checks, Changelog, Commits

- [ ] **Step 1: Run affected regression tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest|CurrencyRateProviderRegistryFactoryTest|MultiCurrencyStateBuilderFactoryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyAsyncPriceRendererControllerTest|MultiCurrencyRestControllerTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyStorefrontIntegrationControllerTest|MultiCurrencyStoreCurrencyLifecycleControllerTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyTrackingControllerTest|MultiCurrencySwitcherBlockControllerTest|MultiCurrencySwitcherWidgetControllerTest|MultiCurrencyAnalyticsControllerTest|MultiCurrencyShadowModeTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

- [ ] **Step 2: Run static checks**

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapter.php src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapter.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactory.php --configuration "$TMPDIR/phpstan-b2ae.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapter.php src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapter.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactory.php tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapterTest.php tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapterTest.php tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderFactoryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ae.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Add changelog**

Create a patch fix changelog:

```text
Significance: patch
Type: fix
Comment: Wire native multi-currency automatic rates through the WooPayments provider registry.
```

- [ ] **Step 4: Commit source, then changelog**

Commit source/test changes:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git diff --cached --check
git commit -m "fix(payments): wire multi-currency provider registry"
```

Commit changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ae-multi-currency-provider-registry
git diff --cached --check
git commit -m "chore(payments): add provider registry changelog"
```

## Self-Review

- Spec coverage: addresses the explicit B2ac/B2ad follow-up by connecting native state builders to a real provider registry path while staying fail-closed when no compatible provider boundaries are available.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: adapters implement the existing multi-currency account/API interfaces; provider registry factory returns `CurrencyRateProviderRegistry`; state-builder factory returns `MultiCurrencyStateBuilder`.
