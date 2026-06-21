# Native Multi-Currency Automatic Rate Cache Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow the native multi-currency state builder to populate the
WooPayments-compatible automatic-rate cache when a rate provider is already
registered.

**Architecture:** Extend `MultiCurrencyRateService` with provider-level rate
resolution for all supported targets, then have `MultiCurrencyStateBuilder`
call `MultiCurrencyDatabaseCache::get_or_add()` for
`wcpay_multi_currency_cached_currencies`. Keep the fallback to forced cached
data when no provider is available so stale cache parity remains unchanged.

**Tech Stack:** WooCommerce PHP services, WordPress options, native
multi-currency rate provider seam, PHPUnit via
`pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan,
PHPCS, markdownlint.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
    - Add `has_available_provider(): bool`.
    - Add `get_rates( string $from_currency, ?array $currencies_to = null ): ?array`.
    - Normalize provider responses with either top-level rates or a
      `currencies` wrapper into `array<string,float>` keyed by lowercase
      currency code.
    - Catch provider exceptions for bulk refresh and return `null` so the cache
      service can fall back to old data.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`
    - Replace read-only cache access with a cache refresh helper.
    - Use forced cached data when no provider is available.
    - Use `get_or_add()` with a WooPayments-compatible payload shape when a
      provider is available.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`
    - Add bulk automatic-rate provider tests.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`
    - Change the automatic-provider snapshot test from "ignore provider" to
      "refresh cache from provider".
    - Keep preserved-cache fallback tests intact.
- Add `plugins/woocommerce/changelog/add-native-payments-b2ac-automatic-rate-cache-refresh`
    - Patch changelog entry.

## Task 1: Automatic Rate Cache RED Tests

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`

- [ ] **Step 1: Add rate-service bulk rate tests**

Add tests that call the new `get_rates()` method:

```php
public function test_resolves_bulk_automatic_rates_from_available_provider(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register(
		$this->create_provider(
			true,
			array(
				'currencies' => array(
					'eur' => '1.2',
					'GBP' => 0.82,
					'bad' => 'not-a-rate',
					'jpy' => 0,
				),
			)
		)
	);
	$service = new MultiCurrencyRateService( $registry );

	$this->assertTrue( $service->has_available_provider() );
	$this->assertSame(
		array(
			'eur' => 1.2,
			'gbp' => 0.82,
		),
		$service->get_rates( 'USD' )
	);
}

public function test_returns_null_for_bulk_rates_without_available_provider(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register( $this->create_provider( false, array( 'eur' => 1.2 ) ) );
	$service = new MultiCurrencyRateService( $registry );

	$this->assertFalse( $service->has_available_provider() );
	$this->assertNull( $service->get_rates( 'USD' ) );
}
```

- [ ] **Step 2: Change the state-builder provider snapshot test**

Replace `test_does_not_call_provider_for_automatic_currency_snapshots()` with:

```php
public function test_refreshes_automatic_currencies_from_available_provider(): void {
	$registry = new CurrencyRateProviderRegistry();
	$registry->register( $this->create_available_rate_provider() );
	update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
	update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

	$state  = $this->create_builder( $registry )->build();
	$cached = get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );

	$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
	$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
	$this->assertIsArray( $cached );
	$this->assertSame( array( 'gbp' => 0.82 ), $cached['data']['currencies'] );
	$this->assertIsInt( $cached['data']['updated'] );
}
```

- [ ] **Step 3: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest'
```

Expected before production code:

```text
ERRORS!
Call to undefined method MultiCurrencyRateService::get_rates()
Call to undefined method MultiCurrencyRateService::has_available_provider()
FAILURES!
Failed asserting that Array (...) is identical to Array (...)
```

## Task 2: Rate Service And State Builder Refresh

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`

- [ ] **Step 1: Add rate-service provider helpers**

Add:

```php
public function has_available_provider(): bool {
	return null !== $this->provider_registry->get_available_provider();
}

public function get_rates( string $from_currency, ?array $currencies_to = null ): ?array {
	$provider = $this->provider_registry->get_available_provider();
	if ( ! $provider ) {
		return null;
	}

	try {
		$rates = $provider->get_currency_rates(
			strtolower( $from_currency ),
			null === $currencies_to ? null : array_map( 'strtolower', $currencies_to )
		);
	} catch ( \Throwable $e ) {
		return null;
	}

	if ( isset( $rates['currencies'] ) && is_array( $rates['currencies'] ) ) {
		$rates = $rates['currencies'];
	}

	return $this->normalize_rates( $rates );
}
```

Add a private `normalize_rates()` helper that loops over provider rates,
normalizes each value through the existing `normalize_rate()`, drops invalid
rates, and returns lowercase currency-code keys.

- [ ] **Step 2: Refresh state-builder cache through the rate service**

Replace the direct cache read in `get_cached_currency_rates()` with a helper:

```php
$cache_data = $this->get_cached_currency_data( $default_code );
```

Add:

```php
private function get_cached_currency_data( string $default_code ): ?array {
	if ( ! $this->rate_service->has_available_provider() ) {
		$cache_data = $this->cache->get( MultiCurrencyCacheInterface::CURRENCIES_KEY, true );

		return is_array( $cache_data ) ? $cache_data : null;
	}

	$cache_data = $this->cache->get_or_add(
		MultiCurrencyCacheInterface::CURRENCIES_KEY,
		function () use ( $default_code ) {
			$rates = $this->rate_service->get_rates( $default_code );
			if ( null === $rates ) {
				return null;
			}

			return array(
				'currencies' => $rates,
				'updated'    => time(),
			);
		},
		static function ( $data ) {
			return is_array( $data ) && isset( $data['currencies'], $data['updated'] ) && is_array( $data['currencies'] );
		}
	);

	return is_array( $cache_data ) ? $cache_data : null;
}
```

- [ ] **Step 3: Verify GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest'
```

Expected:

```text
OK
```

## Task 3: Changelog, Regression, Static Checks, Commits

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2ac-automatic-rate-cache-refresh`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix
Comment: Refresh native multi-currency automatic rate cache from providers.
```

- [ ] **Step 2: Run regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderTest|MultiCurrencyDatabaseCacheTest|WooPaymentsCurrencyRateProviderTest|CurrencyRateProviderRegistryTest|MultiCurrencyStoreCurrencyLifecycleServiceTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected:

```text
OK
```

- [ ] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php --configuration "$TMPDIR/phpstan-b2ac.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ac.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
git diff --cached --check
```

Expected: all pass. Do not edit the PHPStan baseline.

- [ ] **Step 4: Commit source and changelog separately**

Commit source/tests:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php
git commit -m "fix(payments): refresh multi-currency automatic rate cache"
```

Commit changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ac-automatic-rate-cache-refresh
git commit -m "chore(payments): add automatic rate cache changelog"
```
