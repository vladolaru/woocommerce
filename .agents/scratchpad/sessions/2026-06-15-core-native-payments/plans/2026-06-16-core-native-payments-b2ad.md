# Native Multi-Currency Account Supported Currencies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Filter native automatic multi-currency availability by the account
supported customer currencies exposed through the rate provider seam.

**Architecture:** Extend `CurrencyRateProvider` with
`get_supported_currencies()`, implement the WooPayments provider using the
existing account boundary, expose normalized supported codes through
`MultiCurrencyRateService`, and have `MultiCurrencyStateBuilder` filter cached
automatic rates only when a provider is available. Empty provider support means
"all WooCommerce currencies", matching WooPayments' fallback behavior.

**Tech Stack:** WooCommerce PHP services, WordPress currency APIs, native
multi-currency provider seams, PHPUnit via
`pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan,
PHPCS, markdownlint.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php`
    - Add `get_supported_currencies(): array`.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php`
    - Return account-supported customer currencies when account data provides a
      narrowed list.
    - Return all WooCommerce currency codes when no narrowed account list is
      available.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
    - Add `get_supported_currency_codes(): array`.
    - Normalize to uppercase WooCommerce-known codes, using all WooCommerce
      currencies when the provider returns an empty list.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`
    - Filter automatic cached rates by supported currency codes only when an
      automatic-rate provider is available.
- Modify affected provider test doubles in:
    - `tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryTest.php`
    - `tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`
    - `tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderTest.php`
    - Add account-supported currency tests.
- Add `plugins/woocommerce/changelog/add-native-payments-b2ad-account-supported-currencies`
    - Patch changelog entry.

## Task 1: Account-Supported Currency RED Tests

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`
- Modify provider test doubles that implement `CurrencyRateProvider`.

- [ ] **Step 1: Add WooPayments provider support tests**

Add:

```php
public function test_returns_account_supported_customer_currencies(): void {
	$provider = new WooPaymentsCurrencyRateProvider(
		$this->create_account( true, false, array( 'gbp', 'EUR', 'bad-code' ), array( 'customer_currencies' => array( 'supported' => array( 'gbp', 'EUR' ) ) ) ),
		$this->create_api_client( true )
	);

	$this->assertSame( array( 'GBP', 'EUR' ), $provider->get_supported_currencies() );
}

public function test_returns_all_woocommerce_currencies_without_account_supported_customer_currencies(): void {
	$provider = new WooPaymentsCurrencyRateProvider(
		$this->create_account( true, false, array(), array() ),
		$this->create_api_client( true )
	);

	$this->assertContains( 'USD', $provider->get_supported_currencies() );
	$this->assertContains( 'GBP', $provider->get_supported_currencies() );
}
```

Update `create_account()` to accept supported currencies and cached account
data.

- [ ] **Step 2: Add rate-service supported-code tests**

Add:

```php
public function test_returns_normalized_supported_currency_codes_from_provider(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register( $this->create_provider( true, array(), array( 'gbp', 'EUR', 'bad-code' ) ) );
	$service = new MultiCurrencyRateService( $registry );

	$this->assertSame( array( 'GBP', 'EUR' ), $service->get_supported_currency_codes() );
}

public function test_returns_all_woocommerce_currency_codes_when_provider_support_is_empty(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register( $this->create_provider( true, array(), array() ) );
	$service = new MultiCurrencyRateService( $registry );

	$this->assertContains( 'USD', $service->get_supported_currency_codes() );
	$this->assertContains( 'GBP', $service->get_supported_currency_codes() );
}
```

Update the test provider helper to accept supported currencies.

- [ ] **Step 3: Add state-builder filtering test**

Add:

```php
public function test_filters_automatic_currencies_by_provider_supported_currencies(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register(
		$this->create_available_rate_provider(
			array(
				'gbp' => 0.82,
				'eur' => 0.91,
			),
			array( 'GBP' )
		)
	);
	update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
	update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
	update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

	$state = $this->create_builder( $registry )->build();

	$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
	$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
}
```

- [ ] **Step 4: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest|CurrencyRateProviderRegistryTest'
```

Expected before production code:

```text
ERRORS!
Call to undefined method WooPaymentsCurrencyRateProvider::get_supported_currencies()
Call to undefined method MultiCurrencyRateService::get_supported_currency_codes()
```

## Task 2: Provider Contract And State Filtering

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`

- [ ] **Step 1: Add provider contract method**

Add to `CurrencyRateProvider`:

```php
public function get_supported_currencies(): array;
```

Document that an empty array means all WooCommerce currencies are supported.

- [ ] **Step 2: Implement WooPayments supported currencies**

Add:

```php
public function get_supported_currencies(): array {
	$wc_currencies       = array_keys( get_woocommerce_currencies() );
	$account             = $this->account->get_cached_account_data();
	$supported_currencies = $this->account->get_account_customer_supported_currencies();

	if ( $account && ! empty( $supported_currencies ) ) {
		return array_values( array_intersect( array_map( 'strtoupper', $supported_currencies ), $wc_currencies ) );
	}

	return $wc_currencies;
}
```

- [ ] **Step 3: Expose normalized supported codes through rate service**

Add:

```php
public function get_supported_currency_codes(): array {
	$provider = $this->provider_registry->get_available_provider();
	if ( ! $provider ) {
		return array();
	}

	$wc_currencies       = array_keys( get_woocommerce_currencies() );
	$supported_currencies = $provider->get_supported_currencies();
	if ( empty( $supported_currencies ) ) {
		return $wc_currencies;
	}

	return array_values( array_intersect( array_map( 'strtoupper', $supported_currencies ), $wc_currencies ) );
}
```

- [ ] **Step 4: Filter state-builder automatic cache rates**

In `get_cached_currency_rates()`, compute a supported lookup only when a
provider is available and skip cached rates outside that lookup:

```php
$supported_codes = $this->rate_service->has_available_provider()
	? array_fill_keys( $this->rate_service->get_supported_currency_codes(), true )
	: null;

foreach ( $cache_data['currencies'] as $currency_code => $rate ) {
	$currency_code = strtoupper( (string) $currency_code );

	if ( null !== $supported_codes && ! isset( $supported_codes[ $currency_code ] ) ) {
		continue;
	}
	// Existing validity/rate checks continue here.
}
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest|CurrencyRateProviderRegistryTest'
```

Expected:

```text
OK
```

## Task 3: Changelog, Regression, Static Checks, Commits

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2ad-account-supported-currencies`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix
Comment: Filter native multi-currency automatic rates by account-supported currencies.
```

- [ ] **Step 2: Run regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest|CurrencyRateProviderRegistryTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected:

```text
OK
```

- [ ] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php --configuration "$TMPDIR/phpstan-b2ad.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderTest.php tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ad.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
git diff --cached --check
```

Expected: all pass. Do not edit the PHPStan baseline.

- [ ] **Step 4: Commit source and changelog separately**

Commit source/tests:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php
git commit -m "fix(payments): filter multi-currency rates by account support"
```

Commit changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ad-account-supported-currencies
git commit -m "chore(payments): add account supported currencies changelog"
```
