# Native Payments B1a Multi-Currency Foundation Implementation Plan

<!-- markdownlint-disable MD007 MD032 -->

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first B1 foundation slice: core-owned multi-currency default interface implementations plus rate-source resolution, without registering storefront price/currency hooks.

**Architecture:** Keep this slice non-mutating. Core supplies cache, localization, and settings services directly; provider-backed automatic FX flows through `CurrencyRateProvider`; manual rates resolve from preserved `wcpay_multi_currency_*` options without a payments provider. Runtime ownership remains controlled by `MultiCurrencyRuntimeArbiter`, and no B1a class registers WooCommerce pricing hooks.

**Tech Stack:** WooCommerce Core PHP, WordPress options/transients, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyDatabaseCache.php`
  - Implements `MultiCurrencyCacheInterface` with WooPayments-compatible option payloads: `data`, `fetched`, `errored`, `consecutive_errors`.
  - Supports stale cached data on provider/generator failure.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyLocalizationService.php`
  - Implements `MultiCurrencyLocalizationInterface` using WooCommerce `i18n/locale-info.php`.
  - Preserves `wcpay_{currency}_format` filter behavior.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsService.php`
  - Implements `MultiCurrencySettingsInterface` with core constants (`WC_PLUGIN_FILE`, `WC_VERSION`) and dev-mode from `WC_STRIPE_DEV_MODE`.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistry.php`
  - Keeps named rate providers and returns the first available provider deterministically.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php`
  - Adapts `MultiCurrencyAccountInterface` plus `MultiCurrencyApiClientInterface` to `CurrencyRateProvider`.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
  - Resolves manual per-currency rates from preserved options.
  - Resolves automatic rates through the available provider only when one exists.
- Create matching PHPUnit tests:
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDatabaseCacheTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyLocalizationServiceTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsServiceTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`
- Create changelog:
  - `plugins/woocommerce/changelog/add-native-payments-b1a-multi-currency-foundation`

## Task 1: Database Cache Default

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyDatabaseCache.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDatabaseCacheTest.php`

- [ ] **Step 1: Write failing tests**

Test these concrete behaviors:

```php
$cache = new MultiCurrencyDatabaseCache();

$refreshed = false;
$value     = $cache->get_or_add(
	'wcpay_multi_currency_cached_currencies',
	static fn() => array( 'currencies' => array( 'eur' => 1.2 ), 'updated' => 123 ),
	static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
	false,
	$refreshed
);

$this->assertSame( array( 'currencies' => array( 'eur' => 1.2 ), 'updated' => 123 ), $value );
$this->assertTrue( $refreshed );
$this->assertSame( $value, $cache->get( 'wcpay_multi_currency_cached_currencies' ) );
```

Also assert:
- cached data is reused without calling the generator again;
- generator failure returns the previous valid value and stores `errored => true`;
- `delete()` removes both the option and in-memory cache.

- [ ] **Step 2: Run red test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDatabaseCacheTest'
```

Expected: FAIL because `MultiCurrencyDatabaseCache` does not exist.

- [ ] **Step 3: Implement cache**

Implement the class with:
- `get()`
- `get_or_add()`
- `delete()`
- private `get_from_cache()`
- private `write_to_cache()`
- private `should_refresh_cache()`
- private `is_expired()`
- private `get_ttl()` using `3 * HOUR_IN_SECONDS` for `CURRENCIES_KEY` success and `12 * HOUR_IN_SECONDS` for non-admin reads, matching the plugin behavior for this MC cache key.

- [ ] **Step 4: Run green test**

Run the same focused filter. Expected: PASS.

## Task 2: Localization And Settings Defaults

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyLocalizationService.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsService.php`
- Test: matching `Services/*Test.php` files.

- [ ] **Step 1: Write failing tests**

Assert localization:
- `get_currency_format( 'USD' )` returns keys `currency_pos`, `thousand_sep`, `decimal_sep`, `num_decimals`;
- unknown currencies fall back to `left`, `,`, `.`, `2`;
- `wcpay_usd_format` can override the returned format.

Assert settings:
- `get_plugin_file_path()` returns `WC_PLUGIN_FILE`;
- `get_plugin_version()` returns `WC_VERSION`;
- `is_dev_mode()` follows the `WC_STRIPE_DEV_MODE` constant.

- [ ] **Step 2: Run red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest'
```

Expected: FAIL because the services do not exist.

- [ ] **Step 3: Implement services**

Use `WC()->plugin_path() . '/i18n/locale-info.php'` for locale data and the same default USD-ish fallback shape as WooPayments. Use `defined( 'WC_STRIPE_DEV_MODE' ) && WC_STRIPE_DEV_MODE` for dev mode so tests can run without WooPayments globals.

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 3: Provider Registry And WooPayments Adapter

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistry.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php`
- Test: matching `Providers/*Test.php` files.

- [ ] **Step 1: Write failing tests**

Assert registry:
- providers are stored by `get_id()`;
- `get_available_provider()` skips unavailable providers;
- duplicate IDs replace the previous provider.

Assert WooPayments adapter:
- `is_available()` requires API client connected, account connected, and account not rejected;
- `get_currency_rates( 'usd', array( 'eur' ) )` delegates to the API client with the same arguments;
- `get_id()` returns `woopayments`.

- [ ] **Step 2: Run red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest'
```

Expected: FAIL because provider classes do not exist.

- [ ] **Step 3: Implement providers**

Keep classes dependency-free except for the B0 interfaces. Do not reference WooPayments concrete classes directly.

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 4: Rate Resolution Foundation

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRateService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRateServiceTest.php`

- [ ] **Step 1: Write failing tests**

Assert:
- manual rate option `wcpay_multi_currency_exchange_rate_eur=manual` plus `wcpay_multi_currency_manual_rate_eur=1.25` returns rate `1.25` without any provider;
- automatic rate uses `CurrencyRateProviderRegistry::get_available_provider()`;
- automatic rate returns `null` when no provider is available;
- invalid non-positive manual rates return `null`.

- [ ] **Step 2: Run red test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRateServiceTest'
```

Expected: FAIL because `MultiCurrencyRateService` does not exist.

- [ ] **Step 3: Implement rate service**

Expose:

```php
public function get_rate( string $from_currency, string $to_currency ): ?float
```

Normalize currency codes to lowercase for option keys. For provider results, accept either `array( 'eur' => 1.2 )` or `array( 'EUR' => 1.2 )` and cast numeric positive values to float.

- [ ] **Step 4: Run green test**

Run the same focused filter. Expected: PASS.

## Task 5: Verification And Commit

- [ ] **Step 1: Run B1a regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse src/Internal/MultiCurrency --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

If directory PHPStan hits the known baseline-pattern artifact, rerun with explicit new file paths and record the artifact.

- [ ] **Step 3: Run repository checks**

```bash
git diff --check
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1a.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
```

Expected: PASS. Direct PHPCS remains the source of truth for untracked new PHP files before staging.

- [ ] **Step 4: Commit**

Commit one logical B1a package including source, tests, and changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b1a-multi-currency-foundation plugins/woocommerce/src/Internal/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency
git commit -m "feat(payments): add native payments B1a multi-currency foundation"
```

## Self-Review

- Spec coverage: This covers B1's default interface implementations and rate-source seam foundation. It intentionally does not port the full multi-currency engine or register frontend filters; those belong to later B1 slices and B2.
- Placeholder scan: No `TBD`, `TODO`, or unspecified "add tests" steps remain.
- Type consistency: All new services consume the B0 interfaces and keep provider coupling behind `CurrencyRateProvider`.
