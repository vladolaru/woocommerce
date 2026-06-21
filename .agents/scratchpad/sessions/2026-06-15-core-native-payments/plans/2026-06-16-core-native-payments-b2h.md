# Core Native Payments B2h Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Match WooPayments shipping-rate decimal behavior in native multi-currency.

**Architecture:** Add a projection method for the store/default currency decimal count, then have the existing frontend currencies controller set `price_decimals` on `woocommerce_shipping_method_add_rate_args`. Keep this slice limited to shipping decimal behavior; do not implement order-currency query-var or formatted-total context callbacks.

**Tech Stack:** WooCommerce core PHP, native multi-currency services/controllers, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rule:

- `FrontendCurrencies::fix_price_decimals_for_shipping_rates()` sets `$args['price_decimals']` to the store/default currency decimal count.
- This prevents shipping-rate calculations from using selected-currency decimals when the selected currency differs from the store currency.
- Native already registers `woocommerce_shipping_method_add_rate_args` owner-gated in `MultiCurrencyFrontendCurrenciesController`.
- Native currently leaves `fix_price_decimals_for_shipping_rates()` as a pass-through.

B2h deliberately does not implement order-currency query-var initialization, order formatted-total context clearing, compatibility class loading, switcher UI, Storefront placement, REST/settings, async rendering, or Store API behavior.

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionService.php`
    - Add `get_store_currency_decimals()` returning default currency decimals from the localization service.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionServiceTest.php`
    - Assert store currency decimals are projected from default currency format, not selected currency format.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - Set `price_decimals` in `fix_price_decimals_for_shipping_rates()`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
    - Replace the pass-through shipping assertion with store-decimal behavior.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2h-multi-currency-shipping-decimals`

## Tasks

### Task 1: Store Currency Decimal Projection

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionServiceTest.php`

- [ ] **Step 1: Write failing projection test**

Add:

```php
/**
 * @testdox Should project store currency decimals for shipping calculations.
 */
public function test_projects_store_currency_decimals_for_shipping_calculations(): void {
	$sut = $this->create_service( $this->create_state( 'JPY' ) );

	$this->assertSame( 2, $sut->get_store_currency_decimals() );
}
```

- [ ] **Step 2: Run red projection test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendProjectionServiceTest
```

Expected: fail because `get_store_currency_decimals()` is missing.

- [ ] **Step 3: Implement projection method**

Add:

```php
public function get_store_currency_decimals(): int {
	$default_code = $this->state_builder->build()->get_default_currency()->get_code();

	return absint( $this->localization_service->get_currency_format( $default_code )['num_decimals'] );
}
```

- [ ] **Step 4: Run green projection test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendProjectionServiceTest
```

Expected: pass.

### Task 2: Shipping Rate Args Callback

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`

- [ ] **Step 1: Write failing controller test**

Update `test_order_context_callbacks_are_safe_pass_throughs()` by replacing the shipping assertion with:

```php
$this->assertSame(
	array(
		'cost'           => '10.00',
		'price_decimals' => 2,
	),
	$sut->fix_price_decimals_for_shipping_rates( array( 'cost' => '10.00' ), null )
);
```

Add `get_store_currency_decimals()` to the deterministic projection service test double:

```php
public function get_store_currency_decimals(): int {
	return 2;
}
```

- [ ] **Step 2: Run red controller test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: fail because `fix_price_decimals_for_shipping_rates()` still returns args unchanged.

- [ ] **Step 3: Implement controller callback**

Update:

```php
public function fix_price_decimals_for_shipping_rates( $args, $method ) {
	unset( $method );

	if ( ! is_array( $args ) ) {
		return $args;
	}

	$args['price_decimals'] = $this->get_frontend_projection_service()->get_store_currency_decimals();

	return $args;
}
```

- [ ] **Step 4: Run green controller test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: pass.

### Task 3: Verification, Changelog, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2h-multi-currency-shipping-decimals`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Set native payments multi-currency shipping rate decimals.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2h:

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
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2h.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2h code/changelog**

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
fix(payments): set multi-currency shipping decimals
```

## Self-Review

- Spec coverage: B2h covers only WooPayments shipping-rate `price_decimals` parity.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: projection and controller method names are consistent.
