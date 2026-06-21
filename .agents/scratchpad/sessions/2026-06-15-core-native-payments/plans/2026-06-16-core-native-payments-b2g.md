# Core Native Payments B2g Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WooPayments-compatible request-local currency overrides for native multi-currency non-Store REST requests.

**Architecture:** Reuse B2f's `MultiCurrencyRequestContext` to identify non-Store WooCommerce REST requests. Add a small arbiter-gated controller that registers the same compatibility filters WooPayments uses: `?currency=` becomes a request-local selected-currency override, while non-Store REST without `currency` forces store currency and disables product price conversion.

**Tech Stack:** WooCommerce core PHP, WordPress filters, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rules, read-only:

- `MultiCurrency::init_hooks()` checks `! Utils::is_store_batch_request() && ! Utils::is_store_api_request() && WC()->is_rest_api_request()`.
- In that non-Store REST context, `?currency=GBP` adds `wcpay_multi_currency_override_selected_currency` and returns uppercase `GBP`.
- In that same non-Store REST context without `currency`, WooPayments adds:
    - `wcpay_multi_currency_should_return_store_currency` => `__return_true`
    - `wcpay_multi_currency_should_convert_product_price` => `__return_false`
    - `wcpay_multi_currency_override_selected_currency` => store default currency code
- B2f already prevents persistent `?currency=` writes outside frontend or Store API. B2g must remain request-local and must not write `wcpay_currency`.

B2g deliberately does not implement Store API behavior, REST route handlers/settings, compatibility class loading, switcher UI, Storefront placement, async rendering, or shipping `price_decimals` parity.

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContext.php`
    - Add `should_register_rest_request_overrides()` returning true only for WooCommerce REST requests that are not Store API and not Store API batch.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php`
    - Assert non-Store REST is allowed and Store API/batch/non-REST contexts are blocked.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestRequestOverrideController.php`
    - Registers request-local compatibility filters when core owns multi-currency and the current context is non-Store REST.
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRestRequestOverrideControllerTest.php`
    - Assert `?currency=` override, store-currency fallback, price-conversion disablement, owner gating, and context gating.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller after selected-currency registration.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add static metadata for the request-override compatibility filter surface.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the manifest includes the B2g hook group and preserved filter names.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2g-multi-currency-rest-request-overrides`

## Tasks

### Task 1: Request Context Predicate

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContext.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php`

- [ ] **Step 1: Write failing request-context tests**

Add tests:

```php
public function test_allows_rest_request_overrides_for_non_store_rest_context(): void {
	$sut = $this->create_context( false, false, true, false );

	$this->assertTrue( $sut->should_register_rest_request_overrides() );
}

public function test_blocks_rest_request_overrides_for_store_api_context(): void {
	$sut = $this->create_context( false, false, true, true );

	$this->assertFalse( $sut->should_register_rest_request_overrides() );
}

public function test_blocks_rest_request_overrides_for_store_api_batch_context(): void {
	$_REQUEST['rest_route'] = '/wc/store/v1/batch';
	$sut                    = $this->create_context( false, false, true, false );

	$this->assertFalse( $sut->should_register_rest_request_overrides() );
}

public function test_blocks_rest_request_overrides_for_non_rest_context(): void {
	$sut = $this->create_context();

	$this->assertFalse( $sut->should_register_rest_request_overrides() );
}
```

- [ ] **Step 2: Run red request-context tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRequestContextTest
```

Expected: fail because `should_register_rest_request_overrides()` is missing.

- [ ] **Step 3: Implement the request-context predicate**

Add:

```php
public function should_register_rest_request_overrides(): bool {
	return $this->is_wc_rest_api_request() && ! $this->is_store_api_request() && ! $this->is_store_batch_request();
}
```

- [ ] **Step 4: Run green request-context tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRequestContextTest
```

Expected: pass.

### Task 2: REST Request Override Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestRequestOverrideController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRestRequestOverrideControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Add tests covering:

```php
public function test_does_not_register_when_plugin_owns_runtime(): void
public function test_does_not_register_outside_non_store_rest_context(): void
public function test_registers_query_currency_override_for_non_store_rest_request(): void
public function test_forces_store_currency_without_query_currency_for_non_store_rest_request(): void
```

The query-currency test should set `$_GET['currency'] = ' gbp '` and assert:

```php
$this->assertSame( 'GBP', apply_filters( 'wcpay_multi_currency_override_selected_currency', false ) );
$this->assertFalse( has_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' ) );
```

The no-query-currency test should assert:

```php
$this->assertSame( 'USD', apply_filters( 'wcpay_multi_currency_override_selected_currency', false ) );
$this->assertTrue( apply_filters( 'wcpay_multi_currency_should_return_store_currency', false ) );
$this->assertFalse( apply_filters( 'wcpay_multi_currency_should_convert_product_price', true, new \stdClass() ) );
```

- [ ] **Step 2: Run red controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRestRequestOverrideControllerTest
```

Expected: fail because `MultiCurrencyRestRequestOverrideController` is missing.

- [ ] **Step 3: Implement the controller**

Create an arbiter-gated controller implementing `RegisterHooksInterface` with:

```php
private const FILTER_OVERRIDE_SELECTED_CURRENCY = 'wcpay_multi_currency_override_selected_currency';
private const FILTER_SHOULD_RETURN_STORE_CURRENCY = 'wcpay_multi_currency_should_return_store_currency';
private const FILTER_SHOULD_CONVERT_PRODUCT_PRICE = 'wcpay_multi_currency_should_convert_product_price';

public function init( MultiCurrencyRuntimeArbiter $arbiter ): void
public function set_request_context( MultiCurrencyRequestContext $request_context ): void
public function register()
public function get_currency_from_query_param()
public function get_store_currency_code(): string
```

Registration rules:

```php
if ( ! $this->arbiter->should_core_register() || ! $this->get_request_context()->should_register_rest_request_overrides() ) {
	return;
}

if ( isset( $_GET['currency'] ) ) {
	$this->add_filter_once( self::FILTER_OVERRIDE_SELECTED_CURRENCY, array( $this, 'get_currency_from_query_param' ) );
	return;
}

$this->add_filter_once( self::FILTER_SHOULD_RETURN_STORE_CURRENCY, '__return_true' );
$this->add_filter_once( self::FILTER_SHOULD_CONVERT_PRODUCT_PRICE, '__return_false' );
$this->add_filter_once( self::FILTER_OVERRIDE_SELECTED_CURRENCY, array( $this, 'get_store_currency_code' ) );
```

- [ ] **Step 4: Run green controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRestRequestOverrideControllerTest
```

Expected: pass.

### Task 3: Boot and Manifest Wiring

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Write failing registry test**

Update `test_exposes_core_hook_groups_when_core_owns_runtime()` to expect `rest_request_overrides`.

Add:

```php
public function test_rest_request_override_manifest_contains_preserved_filters(): void {
	$hook_groups = MultiCurrencyRuntimeRegistry::get_core_hook_groups();
	$filter_hooks = array_column( $hook_groups['rest_request_overrides']['filters'], 'hook' );

	$this->assertSame(
		array(
			'wcpay_multi_currency_override_selected_currency',
			'wcpay_multi_currency_should_return_store_currency',
			'wcpay_multi_currency_should_convert_product_price',
		),
		$filter_hooks
	);
}
```

- [ ] **Step 2: Run red registry tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRuntimeRegistryTest
```

Expected: fail because `rest_request_overrides` metadata is missing.

- [ ] **Step 3: Wire runtime registry and boot**

Add `rest_request_overrides` to `MultiCurrencyRuntimeRegistry::get_core_hook_groups()` with the three filters above.

Register the controller in `plugins/woocommerce/includes/class-woocommerce.php`:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestRequestOverrideController::class )->register();
```

Place it after `MultiCurrencySelectedCurrencyController`.

- [ ] **Step 4: Run green registry and controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

### Task 4: Verification, Changelog, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2g-multi-currency-rest-request-overrides`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Add native payments multi-currency REST request overrides.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2f and B2g:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run static checks:

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2g.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2g code/changelog**

Do not stage `.agents/`, `docs/superpowers/`, or external staging log.

- [ ] **Step 4: Run staged lint and commit**

Run:

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Commit subject:

```text
fix(payments): add multi-currency REST request overrides
```

## Self-Review

- Spec coverage: B2g covers the non-Store REST request-local override path that B2f explicitly deferred.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: controller, helper, and test method names are consistent across tasks.
