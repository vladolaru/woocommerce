# Core Native Payments B2l Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip stale price filters after native multi-currency URL currency
switches.

**Architecture:** Extend
`MultiCurrencySelectedCurrencyController::handle_init()` so a successful
`?currency=` update mirrors WooPayments' `clear_url_price_params()` behavior.
Browser requests with `min_price` or `max_price` redirect to the same URL
without those filter bounds; Store API and `?rest_route=` REST requests are
left untouched.

**Tech Stack:** WooCommerce core PHP, WordPress redirect helpers, WooCommerce
REST request detection, PHPUnit via
`pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rule:

- `MultiCurrency::update_selected_currency_by_url()` persists a `?currency=`
  switch, then `FrontendCurrencies::clear_url_price_params()` strips
  `min_price` and `max_price` from browser URLs.
- Store API URLs must not redirect because REST clients expect the response for
  the URL they requested.
- `?rest_route=/wc/store/...` must also skip redirects because WordPress uses
  that URL shape when pretty permalinks are disabled.
- B2l does not change price-range conversion itself. Native price conversion is
  already covered in `MultiCurrencyFrontendPricesController`.

## File Structure

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
    - Add redirect capture coverage for browser requests with stale price
      filters.
    - Add no-redirect coverage for Store API and `rest_route` requests.
    - Extend teardown cleanup for the extra query vars.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
    - Call a new private `clear_url_price_params()` after persisting a URL
      currency switch.
    - Implement WooPayments-compatible browser redirect and REST guards.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2l-multi-currency-price-filter-url-cleanup`

## Tasks

### Task 1: Price Filter URL Cleanup

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`

- [ ] **Step 1: Write failing redirect and REST guard tests**

Update `tearDown()` query-var cleanup:

```php
unset(
	$_GET['currency'],
	$_GET['min_price'],
	$_GET['max_price'],
	$_GET['rest_route'],
	$_POST['wcpay_selected_currency']
);
```

Add these tests after `test_updates_currency_from_url_parameter()`:

```php
/**
 * @testdox Should redirect browser currency switches to strip stale price filters.
 */
public function test_redirects_browser_currency_switch_to_strip_stale_price_filters(): void {
	$service                = $this->create_persistence_service();
	$sut                    = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$_GET['currency']       = 'eur';
	$_GET['min_price']      = '10';
	$_GET['max_price']      = '50';
	$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
	$_SERVER['REQUEST_URI'] = '/shop/?currency=EUR&min_price=10&max_price=50';
	$captured_url           = null;

	add_filter(
		'wp_redirect',
		static function ( $location ) use ( &$captured_url ) {
			$captured_url = $location;
			throw new \Exception( 'redirect captured' );
		}
	);

	try {
		$sut->handle_init();
		$this->fail( 'Expected handle_init() to redirect on a browser currency switch with stale price filters.' );
	} catch ( \Exception $e ) {
		$this->assertSame( 'redirect captured', $e->getMessage(), 'Unexpected exception thrown.' );
		$this->assertSame( array( 'EUR' ), $service->updated_currencies );
		$this->assertIsString( $captured_url, 'Expected a redirect URL to be captured.' );
		$this->assertStringNotContainsString( 'min_price', $captured_url, 'min_price should have been stripped.' );
		$this->assertStringNotContainsString( 'max_price', $captured_url, 'max_price should have been stripped.' );
	} finally {
		remove_all_filters( 'wp_redirect' );
		if ( null === $original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		}
	}
}

/**
 * @testdox Should not redirect Store API currency switches with price filters.
 */
public function test_does_not_redirect_store_api_currency_switch_with_price_filters(): void {
	$service                = $this->create_persistence_service();
	$sut                    = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$_GET['currency']       = 'eur';
	$_GET['min_price']      = '10';
	$_GET['max_price']      = '50';
	$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
	$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/products?currency=EUR&min_price=10&max_price=50';

	add_filter(
		'wp_redirect',
		static function ( $location ) {
			throw new \Exception( 'Unexpected redirect during REST request to: ' . $location );
		}
	);

	try {
		$sut->handle_init();

		$this->assertSame( array( 'EUR' ), $service->updated_currencies );
	} finally {
		remove_all_filters( 'wp_redirect' );
		if ( null === $original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		}
	}
}

/**
 * @testdox Should not redirect rest_route currency switches with price filters.
 */
public function test_does_not_redirect_rest_route_currency_switch_with_price_filters(): void {
	$service                = $this->create_persistence_service();
	$sut                    = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$_GET['currency']       = 'eur';
	$_GET['min_price']      = '10';
	$_GET['max_price']      = '50';
	$_GET['rest_route']     = '/wc/store/v1/products';
	$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
	$_SERVER['REQUEST_URI'] = '/?rest_route=/wc/store/v1/products&currency=EUR&min_price=10&max_price=50';

	add_filter(
		'wp_redirect',
		static function ( $location ) {
			throw new \Exception( 'Unexpected redirect during rest_route request to: ' . $location );
		}
	);

	try {
		$sut->handle_init();

		$this->assertSame( array( 'EUR' ), $service->updated_currencies );
	} finally {
		remove_all_filters( 'wp_redirect' );
		if ( null === $original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		}
	}
}
```

- [ ] **Step 2: Run red selected-currency tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: fail because browser currency switches do not redirect to remove
`min_price` and `max_price`.

- [ ] **Step 3: Implement URL price-filter cleanup**

In `handle_init()`, after `update_selected_currency()` succeeds, call:

```php
$this->clear_url_price_params();
```

Add this private method:

```php
/**
 * Clear stale URL price filters after a browser currency switch.
 */
private function clear_url_price_params(): void {
	if (
		( function_exists( 'WC' ) && WC()->is_rest_api_request() )
		|| ! empty( $_GET['rest_route'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	) {
		return;
	}

	if ( isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = remove_query_arg( array( 'min_price', 'max_price' ) );

		wp_safe_redirect( $url );
		exit;
	}
}
```

- [ ] **Step 4: Run green selected-currency tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: pass.

### Task 2: Verification, Changelog, Commit

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2l-multi-currency-price-filter-url-cleanup`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Clear stale multi-currency price filters after URL currency switches.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2l:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyOrderContextServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run static checks:

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2l.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2l code/changelog**

Do not stage `.agents/`, `docs/superpowers/`, or the external staging log.

- [ ] **Step 4: Run staged lint and commit**

Run:

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Commit subject:

```text
fix(payments): clear stale multi-currency price filters
```

## Self-Review

- Spec coverage: B2l covers browser redirect cleanup and both REST no-redirect
  guards.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: all behavior stays inside
  `MultiCurrencySelectedCurrencyController`.
