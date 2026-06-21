# Core Native Payments B2o Geolocation Notice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WooPayments-compatible native multi-currency geolocation switch notice behavior.

**Architecture:** Keep the behavior in `MultiCurrencySelectedCurrencyController`, where the geolocation switch decision already lives. Register a `wp_footer` notice renderer only after auto-switching guards pass, render WooPayments-compatible store notice markup from existing WooCommerce country/currency data, and expose the preserved hook in `MultiCurrencyRuntimeRegistry`.

**Tech Stack:** WooCommerce core PHP, WordPress hooks, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
    - Register a `wp_footer` notice action during eligible geolocation switching.
    - Add `handle_wp_footer()` to render WooPayments-compatible hidden store notice markup.
    - Preserve `wcpay_multi_currency_override_notice_country` and `wcpay_multi_currency_override_notice_currency_name` filters.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add the preserved `wp_footer` notice callback to the selected-currency hook metadata.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
    - Add red tests for notice action registration and rendered notice gating.
    - Extend the geolocation test double to return both country and currency.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the selected-currency hook manifest includes the preserved `wp_footer` notice action.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2o-multi-currency-geolocation-notice`
    - Record the minor native multi-currency notice parity fix.

## Task 1: Write Failing Notice Tests

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [x] **Step 1: Extend controller test hook cleanup**

Add `wp_footer` to the `$hooks` property:

```php
private array $hooks = array(
	'init',
	'wp_footer',
	'woocommerce_created_customer',
	'woocommerce_edit_account_form',
	'woocommerce_save_account_details',
);
```

- [x] **Step 2: Add failing notice registration and rendering tests**

Add these tests after the existing geolocation persistence tests:

```php
/**
 * @testdox Should register geolocation notice when automatic switching passes guards.
 */
public function test_registers_geolocation_notice_when_auto_currency_passes_guards(): void {
	update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
	$service = $this->create_persistence_service();
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$sut->set_geolocation_service( $this->create_geolocation_service( 'CAD', 'CA' ) );

	$sut->handle_geolocation_init();
	$sut->handle_geolocation_init();

	$this->assertSame( 10, has_action( 'wp_footer', array( $sut, 'handle_wp_footer' ) ) );
}

/**
 * @testdox Should not register geolocation notice when switching is disabled.
 */
public function test_does_not_register_geolocation_notice_when_switching_is_disabled(): void {
	update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
	$service               = $this->create_persistence_service();
	$sut                   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$_GET['pay_for_order'] = '1';
	$sut->set_geolocation_service( $this->create_geolocation_service( 'CAD', 'CA' ) );

	$sut->handle_geolocation_init();

	$this->assertFalse( has_action( 'wp_footer', array( $sut, 'handle_wp_footer' ) ) );
}

/**
 * @testdox Should render geolocation currency update notice.
 */
public function test_renders_geolocation_currency_update_notice(): void {
	$service = $this->create_persistence_service( true, 'CAD' );
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$sut->set_geolocation_service( $this->create_geolocation_service( 'CAD', 'CA' ) );

	ob_start();
	$sut->handle_wp_footer();
	$markup = (string) ob_get_clean();

	$store_currency = get_woocommerce_currency();
	$currencies     = get_woocommerce_currencies();

	$this->assertStringContainsString( 'woocommerce-store-notice demo_store', $markup );
	$this->assertStringContainsString( 'visiting from Canada', $markup );
	$this->assertStringContainsString( '?currency=' . $store_currency, $markup );
	$this->assertStringContainsString( 'Use ' . $currencies[ $store_currency ] . ' instead.', $markup );
	$this->assertStringContainsString( 'woocommerce-store-notice__dismiss-link', $markup );
}

/**
 * @testdox Should not render geolocation notice for the store default currency.
 */
public function test_does_not_render_geolocation_notice_for_store_default_currency(): void {
	$store_currency = get_woocommerce_currency();
	$service        = $this->create_persistence_service( true, $store_currency );
	$sut            = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$sut->set_geolocation_service( $this->create_geolocation_service( $store_currency, 'US' ) );

	ob_start();
	$sut->handle_wp_footer();
	$markup = (string) ob_get_clean();

	$this->assertSame( '', $markup );
}

/**
 * @testdox Should not render geolocation notice for another selected currency.
 */
public function test_does_not_render_geolocation_notice_for_other_selected_currency(): void {
	$service = $this->create_persistence_service( true, 'CAD' );
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );
	$sut->set_geolocation_service( $this->create_geolocation_service( 'EUR', 'FR' ) );

	ob_start();
	$sut->handle_wp_footer();
	$markup = (string) ob_get_clean();

	$this->assertSame( '', $markup );
}
```

- [x] **Step 3: Extend the geolocation test double**

Replace `create_geolocation_service()` with:

```php
/**
 * Create a deterministic geolocation service.
 *
 * @param string|null $currency_code Currency code to return.
 * @param string      $country_code  Country code to return.
 * @return MultiCurrencyGeolocationService
 */
private function create_geolocation_service( ?string $currency_code, string $country_code = 'CA' ): MultiCurrencyGeolocationService {
	return new class( $currency_code, $country_code ) extends MultiCurrencyGeolocationService {
		/**
		 * Currency code to return.
		 *
		 * @var string|null
		 */
		private ?string $currency_code;

		/**
		 * Country code to return.
		 *
		 * @var string
		 */
		private string $country_code;

		/**
		 * Constructor.
		 *
		 * @param string|null $currency_code Currency code to return.
		 * @param string      $country_code  Country code to return.
		 */
		public function __construct( ?string $currency_code, string $country_code ) {
			$this->currency_code = $currency_code;
			$this->country_code  = $country_code;
		}

		/**
		 * Get the customer's currency based on location.
		 *
		 * @return string|null
		 */
		public function get_currency_by_customer_location(): ?string {
			return $this->currency_code;
		}

		/**
		 * Get the customer's country based on location.
		 *
		 * @return string
		 */
		public function get_country_by_customer_location(): string {
			return $this->country_code;
		}
	};
}
```

- [x] **Step 4: Update registry test expectations**

In `test_selected_currency_manifest_contains_preserved_hooks()`, expect the `wp_footer` hook and priority:

```php
$this->assertSame(
	array(
		'init',
		'init',
		'wp_footer',
		'woocommerce_created_customer',
		'woocommerce_edit_account_form',
		'woocommerce_save_account_details',
	),
	$selected_currency_hooks
);
$this->assertSame( 11, $hook_groups['selected_currency']['actions'][0]['priority'] );
$this->assertSame( 12, $hook_groups['selected_currency']['actions'][1]['priority'] );
$this->assertSame( 10, $hook_groups['selected_currency']['actions'][2]['priority'] );
```

- [x] **Step 5: Run red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: FAIL because `handle_wp_footer()` and the preserved `wp_footer` manifest entry do not exist yet.

## Task 2: Implement Notice Behavior

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [x] **Step 1: Add notice filter constants**

Add constants near the existing selected-currency constants:

```php
private const FILTER_OVERRIDE_NOTICE_COUNTRY       = 'wcpay_multi_currency_override_notice_country';
private const FILTER_OVERRIDE_NOTICE_CURRENCY_NAME = 'wcpay_multi_currency_override_notice_currency_name';
```

- [x] **Step 2: Register the footer notice after geolocation guards**

In `handle_geolocation_init()`, add the `wp_footer` registration after the auto/disabled/async guard and before the stored-currency guard:

```php
if (
	! $this->is_using_auto_currency_switching()
	|| $this->should_disable_currency_switching()
	|| $this->should_use_async_rendering()
) {
	return;
}

$this->add_action_once( 'wp_footer', array( $this, 'handle_wp_footer' ) );

if ( $this->get_persistence_service()->has_stored_currency_code() ) {
	return;
}
```

- [x] **Step 3: Add the footer notice handler**

Add this public hook callback after `handle_geolocation_init()`:

```php
/**
 * Render the geolocation currency update notice.
 *
 * @internal
 */
public function handle_wp_footer(): void {
	$current_currency_code    = strtoupper( $this->get_persistence_service()->get_selected_currency_code() );
	$store_currency_code      = strtoupper( get_woocommerce_currency() );
	$geolocated_currency_code = $this->get_geolocation_service()->get_currency_by_customer_location();

	if (
		$store_currency_code === $current_currency_code
		|| ! is_string( $geolocated_currency_code )
		|| strtoupper( $geolocated_currency_code ) !== $current_currency_code
	) {
		return;
	}

	$country_code = $this->get_geolocation_service()->get_country_by_customer_location();
	$countries    = WC()->countries->countries;
	$currencies   = get_woocommerce_currencies();
	$country_name = $countries[ $country_code ] ?? $country_code;

	/**
	 * Filters the country name displayed in the native multi-currency geolocation notice.
	 *
	 * @param string $country_name Country name.
	 *
	 * @since 11.0.0
	 */
	$country_name = apply_filters( self::FILTER_OVERRIDE_NOTICE_COUNTRY, $country_name );

	$current_currency_name = $currencies[ $current_currency_code ] ?? $current_currency_code;

	/**
	 * Filters the currency name displayed in the native multi-currency geolocation notice.
	 *
	 * @param string $current_currency_name Current currency name.
	 *
	 * @since 11.0.0
	 */
	$current_currency_name = apply_filters( self::FILTER_OVERRIDE_NOTICE_CURRENCY_NAME, $current_currency_name );

	$store_currency_name = $currencies[ $store_currency_code ] ?? $store_currency_code;
	$message             = sprintf(
		/* translators: %1$s: User country. %2$s: Selected currency name. %3$s: Store currency name. %4$s: Link to switch currency. */
		__( 'We noticed you\'re visiting from %1$s. We\'ve updated our prices to %2$s for your shopping convenience. <a href="%4$s">Use %3$s instead.</a>', 'woocommerce' ),
		esc_html( (string) $country_name ),
		esc_html( (string) $current_currency_name ),
		esc_html( $store_currency_name ),
		esc_url( '?currency=' . $store_currency_code )
	);
	$notice_id           = md5( $message );

	echo '<p class="woocommerce-store-notice demo_store" data-notice-id="' . esc_attr( $notice_id . 2 ) . '" style="display:none;">';
	echo wp_kses_post( $message );
	echo ' <a href="#" class="woocommerce-store-notice__dismiss-link">' . esc_html__( 'Dismiss', 'woocommerce' ) . '</a></p>';
}
```

- [x] **Step 4: Add registry metadata**

Add the notice metadata after the geolocation `init` entry:

```php
self::hook_entry( 'wp_footer', 'display_geolocation_currency_update_notice', 10 ),
```

- [x] **Step 5: Run green tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: PASS.

## Task 3: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2o-multi-currency-geolocation-notice`

- [x] **Step 1: Add changelog entry**

Create:

```text
Significance: minor
Type: fix

Add native multi-currency geolocation switch notice parity.
```

- [x] **Step 2: Run focused regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [x] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2o.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all PASS.

- [x] **Step 4: Stage only B2o source, tests, and changelog**

Run:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php plugins/woocommerce/changelog/add-native-payments-b2o-multi-currency-geolocation-notice
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

Expected: staged checks PASS. Do not stage `.agents/`, `docs/superpowers/`, WPCOM files, WooPayments client files, or external staging logs.

- [x] **Step 5: Commit**

Commit message:

```bash
git commit -m "fix(payments): add multi-currency geolocation notice" -m "[Context]
Native multi-currency now applies WooPayments-compatible geolocation currency switching, but customers still need the preserved frontend notice that explains the automatic switch and offers a default-currency link.

[Problem]
The native selected-currency controller did not register or render the geolocation switch notice, leaving the storefront without the WooPayments-compatible customer-facing explanation after automatic currency selection.

[Solution]
Register a footer notice after geolocation switching guards pass, render the preserved WooPayments notice markup with native WooCommerce text-domain escaping, and expose the preserved wp_footer callback in the runtime registry metadata."
```

Expected: commit succeeds. If signing fails, leave the staged files intact and report the intended commit.
