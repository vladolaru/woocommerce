# Core Native Payments B2n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WooPayments-compatible automatic geolocation selected-currency
switching for native multi-currency server-rendered frontend requests.

**Architecture:** Keep selected-currency entry points in
`MultiCurrencySelectedCurrencyController`. Register URL switching at `init:11`
and geolocation switching at `init:12`, matching WooPayments ordering. Use the
existing `MultiCurrencyGeolocationService` and
`MultiCurrencySelectedCurrencyPersistenceService`; add a small public stored
currency predicate to the persistence service so geolocation does not override
an explicitly stored user/session currency.

**Tech Stack:** WooCommerce PHP, PHPUnit via `pnpm test:php:env`, PHPCS,
PHPStan, markdownlint.

---

## File Map

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`

    - Register `handle_geolocation_init()` at `init:12`.
    - Add `set_geolocation_service()` for tests and future explicit bootstrap.
    - Add auto-switch guards for option disabled, switching disabled,
      cache-optimized async rendering, stored currency, and invalid geolocated
      currency.
    - Call `update_selected_currency( $currency, false )` for valid
      geolocated currencies.

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceService.php`

    - Add `has_stored_currency_code()` using the preserved `wcpay_currency`
      user/session key.

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

    - Add the preserved WooPayments geolocation selected-currency hook metadata
      at `init:12`.

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`

    - Add red coverage for hook registration and geolocation switching guards.
    - Extend the persistence-service test double to track `persist_change` and
      stored currency state.

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceServiceTest.php`

    - Add red coverage for detecting stored user meta and guest session
      currency.

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

    - Add red coverage for the second selected-currency `init` hook at
      priority `12`.

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2n-multi-currency-geolocation-switching`

    - Changelog entry for the merchant-facing geolocation auto-switch parity
      fix.

- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

    - Record scope, red/green checks, static gates, commit hash, and WPCOM
      read-only status.

- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

    - Append B2n gate evidence after verification.

## Task 1: Stored Currency Predicate

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceServiceTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceService.php`

- [ ] **Step 1: Write failing stored-currency predicate tests**

Add tests asserting that `has_stored_currency_code()` returns true for logged-in
user meta and guest session values, and false when neither is present.

- [ ] **Step 2: Run the focused red test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyPersistenceServiceTest
```

Expected: FAIL before implementation because
`has_stored_currency_code()` does not exist.

- [ ] **Step 3: Implement the predicate**

Add:

```php
public function has_stored_currency_code(): bool {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		$currency_code = get_user_meta( $user_id, self::CURRENCY_STORAGE_KEY, true );

		return is_string( $currency_code ) && '' !== $currency_code;
	}

	if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get' ) ) {
		$currency_code = WC()->session->get( self::CURRENCY_STORAGE_KEY );

		return is_string( $currency_code ) && '' !== $currency_code;
	}

	return false;
}
```

- [ ] **Step 4: Run focused green tests**

Run the same focused persistence-service command.

Expected: PASS.

## Task 2: Geolocation Selected-Currency Controller

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2n-multi-currency-geolocation-switching`

- [ ] **Step 1: Write failing controller and registry tests**

Add tests for:

- `register()` adds `handle_geolocation_init` on `init` at priority `12`.
- Auto currency option enabled + geolocation `CAD` + no stored currency updates
  selected currency as `CAD` with `persist_change=false`.
- Auto currency option disabled does not update.
- `pay_for_order` query disables geolocation switching.
- Existing stored currency does not get overwritten.
- Cache-optimized async mode skips geolocation without an active session.
- Cache-optimized mode still persists during Store API requests.
- Runtime registry selected-currency manifest contains both `init:11` and
  `init:12`.

- [ ] **Step 2: Run focused red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: FAIL before implementation because the geolocation callback, setter,
guards, and registry entry do not exist.

- [ ] **Step 3: Implement minimal controller behavior**

Add a geolocation service property/setter/default. Register:

```php
$this->add_action_once( 'init', array( $this, 'handle_init' ), 11 );
$this->add_action_once( 'init', array( $this, 'handle_geolocation_init' ), 12 );
```

Implement `handle_geolocation_init()` with these guards in order:

1. Auto currency option is `yes`.
2. `MultiCurrencyCompatibilityProjectionService::should_disable_currency_switching()`
   is false for current query args and external filter state.
3. Cache-optimized async rendering is not handling this non-Store request.
4. `has_stored_currency_code()` is false.
5. Geolocation returns a non-empty currency.

Then call:

```php
$this->get_persistence_service()->update_selected_currency( $currency_code, false );
```

Update `MultiCurrencyRuntimeRegistry` selected-currency metadata with
`update_selected_currency_by_geolocation` at priority `12`.

- [ ] **Step 4: Add changelog entry**

Create:

```text
Significance: minor
Type: fix

Add native multi-currency automatic geolocation currency switching.
```

- [ ] **Step 5: Run focused green tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: PASS.

- [ ] **Step 6: Run B2n regression and static gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyOrderContextServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2n.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all commands pass.

- [ ] **Step 7: Stage only B2n files and commit**

Run:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php \
	plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceService.php \
	plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceServiceTest.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php \
	plugins/woocommerce/changelog/add-native-payments-b2n-multi-currency-geolocation-switching
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Expected commit subject:

```text
fix(payments): add multi-currency geolocation switching
```

## Deferred From B2n

- Geolocation update notice rendering on `wp_footer`; this is a separate
  merchant-facing B2 slice because it introduces visible copy/markup.
- Storefront breadcrumb switcher live hook registration.
- Cache-optimized async renderer live hook registration.

## Self-Review

- Spec coverage: Covers WooPayments geolocation selected-currency switching
  entry point, ordering, persistence flag, and main guards. Defers the separate
  notice UI intentionally.
- Placeholder scan: No placeholders or deferred implementation steps inside
  the scoped B2n behavior.
- Type consistency: The plan uses existing native controller/service names and
  adds one explicit persistence-service predicate.
