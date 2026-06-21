# Core Native Payments B2p Storefront Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the native multi-currency Storefront breadcrumb switcher integration when core owns multi-currency.

**Architecture:** Add a small `MultiCurrencyStorefrontIntegrationController` that gates on the runtime arbiter, Storefront-compatible theme, enabled-currency count, saved switcher setting, and onboarding simulation override. The controller reuses `MultiCurrencyStorefrontProjectionService` for hook metadata/CSS/breadcrumb injection and `MultiCurrencySwitcherProjectionService` for WooPayments-compatible switcher markup.

**Tech Stack:** WooCommerce core PHP, WordPress hooks, Storefront breadcrumb filter, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php`
    - Live Storefront hook registration and callbacks.
    - Lazy native service construction following existing multi-currency controller patterns.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationControllerTest.php`
    - TDD coverage for registration gates, breadcrumb injection, CSS enqueueing, and compatibility disabling.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller from WooCommerce bootstrap.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2p-multi-currency-storefront-switcher`
    - Record the native Storefront switcher parity fix.

## Task 1: Write Failing Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationControllerTest.php`

- [x] **Step 1: Create the test class skeleton**

Create the test file with helpers for runtime owner, theme resolution, state snapshots, and a fake switcher service:

```php
<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStorefrontIntegrationController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyStorefrontIntegrationController class.
 */
class MultiCurrencyStorefrontIntegrationControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_breadcrumb_defaults',
		'wp_enqueue_scripts',
		'wcpay_multi_currency_storefront_widget_instance',
		'wcpay_multi_currency_storefront_widget_args',
		'wcpay_multi_currency_storefront_widget_css',
		'wcpay_multi_currency_should_disable_currency_switching',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		unset(
			$_GET['currency'],
			$_GET['s'],
			$_GET['is_mc_onboarding_simulation'],
			$_GET['enable_storefront_switcher']
		);
		delete_option( 'wcpay_multi_currency_enable_storefront_switcher' );

		parent::tearDown();
	}

	// Tests and helper methods are added in the next steps.
}
```

- [x] **Step 2: Add failing registration tests**

Add these tests inside the class:

```php
/**
 * @testdox Should not register Storefront switcher hooks when plugin owns runtime.
 */
public function test_does_not_register_hooks_when_plugin_owns_runtime(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

	$sut->register();

	$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
	$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
}

/**
 * @testdox Should register Storefront switcher hooks when enabled for a Storefront theme.
 */
public function test_registers_hooks_when_storefront_switcher_is_enabled(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$sut->register();
	$sut->register();

	$this->assertSame( 9999, has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
	$this->assertSame( 50, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
}

/**
 * @testdox Should not register Storefront switcher hooks for non-Storefront themes.
 */
public function test_does_not_register_hooks_for_non_storefront_theme(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, 2, 'twentytwentyfive', 'twentytwentyfive' );

	$sut->register();

	$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
	$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
}

/**
 * @testdox Should not register Storefront switcher hooks with one enabled currency.
 */
public function test_does_not_register_hooks_with_one_enabled_currency(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, 1 );

	$sut->register();

	$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
	$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
}

/**
 * @testdox Should allow simulation to enable Storefront switcher hooks.
 */
public function test_allows_simulation_to_enable_storefront_switcher_hooks(): void {
	$_GET['is_mc_onboarding_simulation'] = 'true';
	$_GET['enable_storefront_switcher']  = 'true';
	$sut                                 = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$sut->register();

	$this->assertSame( 9999, has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
	$this->assertSame( 50, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
}
```

- [x] **Step 3: Add failing callback behavior tests**

Add these tests inside the class:

```php
/**
 * @testdox Should inject switcher markup into Storefront breadcrumb defaults.
 */
public function test_injects_switcher_markup_into_breadcrumb_defaults(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$_GET['currency'] = 'EUR';
	$_GET['s']        = 'shirts';
	$switcher         = $this->create_switcher_service( '<div id="woocommerce-payments-multi-currency-storefront-widget">Switcher</div>' );
	$sut              = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, 2, 'storefront', 'storefront', $switcher );

	add_filter(
		'wcpay_multi_currency_storefront_widget_instance',
		static function () {
			return array( 'title' => 'Header currency' );
		}
	);
	add_filter(
		'wcpay_multi_currency_storefront_widget_args',
		static function () {
			return array(
				'before_widget' => '<aside>',
				'after_widget'  => '</aside>',
			);
		}
	);

	$result = $sut->handle_woocommerce_breadcrumb_defaults(
		array(
			'wrap_before' => '<div class="storefront-breadcrumb"><nav class="woocommerce-breadcrumb">',
			'wrap_after'  => '</nav></div>',
		)
	);

	$this->assertSame(
		'<div class="storefront-breadcrumb"><div id="woocommerce-payments-multi-currency-storefront-widget">Switcher</div><nav class="woocommerce-breadcrumb">',
		$result['wrap_before']
	);
	$this->assertSame( array( 'title' => 'Header currency' ), $switcher->last_instance );
	$this->assertSame( array( 'before_widget' => '<aside>', 'after_widget' => '</aside>' ), $switcher->last_args );
	$this->assertSame( array( 'currency' => 'EUR', 's' => 'shirts' ), $switcher->last_query_args );
	$this->assertFalse( $switcher->last_switching_disabled );
}

/**
 * @testdox Should keep breadcrumb defaults unchanged when switching is disabled.
 */
public function test_keeps_breadcrumb_defaults_when_switching_is_disabled(): void {
	update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
	$switcher = $this->create_switcher_service( '<div>Switcher</div>' );
	$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, 2, 'storefront', 'storefront', $switcher );

	add_filter( 'wcpay_multi_currency_should_disable_currency_switching', '__return_true' );

	$defaults = array(
		'wrap_before' => '<div class="storefront-breadcrumb"><nav class="woocommerce-breadcrumb">',
	);
	$result   = $sut->handle_woocommerce_breadcrumb_defaults( $defaults );

	$this->assertSame( $defaults, $result );
	$this->assertTrue( $switcher->last_switching_disabled );
}

/**
 * @testdox Should enqueue filtered Storefront inline CSS.
 */
public function test_enqueues_filtered_storefront_inline_css(): void {
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
	$css = null;

	add_filter(
		'wcpay_multi_currency_storefront_widget_css',
		static function () {
			return '#woocommerce-payments-multi-currency-storefront-widget { float: left; }';
		}
	);
	add_filter(
		'pre_wp_add_inline_style',
		static function ( $pre, $handle, $data ) use ( &$css ) {
			if ( 'storefront-style' === $handle ) {
				$css = $data;
			}

			return true;
		},
		10,
		3
	);

	try {
		$sut->handle_wp_enqueue_scripts();
	} finally {
		remove_all_filters( 'pre_wp_add_inline_style' );
	}

	$this->assertSame( '#woocommerce-payments-multi-currency-storefront-widget { float: left; }', $css );
}
```

- [x] **Step 4: Add helper methods and fakes**

Add the helper methods and fake switcher class described by the tests:

```php
private function create_controller(
	string $owner,
	int $enabled_currency_count = 2,
	string $stylesheet = 'storefront',
	string $template = 'storefront',
	?MultiCurrencySwitcherProjectionService $switcher_service = null
): MultiCurrencyStorefrontIntegrationController {
	$controller = new MultiCurrencyStorefrontIntegrationController();
	$controller->init( $this->create_arbiter( $owner ) );
	$controller->set_state_builder( $this->create_state_builder( $enabled_currency_count ) );
	$controller->set_theme_resolver(
		static function () use ( $stylesheet, $template ) {
			return array(
				'stylesheet' => $stylesheet,
				'template'   => $template,
			);
		}
	);

	if ( null !== $switcher_service ) {
		$controller->set_switcher_projection_service( $switcher_service );
	}

	return $controller;
}

private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
	return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
		private string $owner;

		public function __construct( string $owner ) {
			$this->owner = $owner;
		}

		public function get_runtime_owner(): string {
			return $this->owner;
		}

		public function should_core_register(): bool {
			return MultiCurrencyRuntimeArbiter::OWNER_CORE === $this->owner;
		}
	};
}

private function create_state_builder( int $enabled_currency_count ): MultiCurrencyStateBuilder {
	$state = $this->create_state( $enabled_currency_count );

	return new class( $state ) extends MultiCurrencyStateBuilder {
		private MultiCurrencyState $state;

		public function __construct( MultiCurrencyState $state ) {
			$this->state = $state;
		}

		public function build(): MultiCurrencyState {
			return $this->state;
		}
	};
}

private function create_state( int $enabled_currency_count ): MultiCurrencyState {
	$localization = $this->create_localization_service();
	$usd          = new MultiCurrencyCurrency( $localization, 'USD', 1.0, true );
	$enabled      = array( 'USD' => $usd );

	if ( 1 < $enabled_currency_count ) {
		$enabled['CAD'] = new MultiCurrencyCurrency( $localization, 'CAD', 1.25, false );
	}

	return new MultiCurrencyState( $enabled, $enabled, $usd, $usd );
}

private function create_localization_service(): MultiCurrencyLocalizationInterface {
	return new class() implements MultiCurrencyLocalizationInterface {
		public function get_currency_format( $currency_code ): array {
			return array(
				'num_decimals' => 2,
			);
		}

		public function get_country_locale_data( $country ): array {
			return array();
		}
	};
}

private function create_switcher_service( string $markup ): MultiCurrencySwitcherProjectionService {
	return new class( $markup ) extends MultiCurrencySwitcherProjectionService {
		public array $last_instance = array();
		public array $last_args = array();
		public array $last_query_args = array();
		public bool $last_switching_disabled = false;
		private string $markup;

		public function __construct( string $markup ) {
			$this->markup = $markup;
		}

		public function get_widget_markup(
			array $instance = array(),
			array $args = array(),
			array $query_args = array(),
			bool $switching_disabled = false
		): string {
			$this->last_instance           = $instance;
			$this->last_args               = $args;
			$this->last_query_args         = $query_args;
			$this->last_switching_disabled = $switching_disabled;

			return $switching_disabled ? '' : $this->markup;
		}
	};
}
```

- [x] **Step 5: Run red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyStorefrontIntegrationControllerTest
```

Expected: FAIL because `MultiCurrencyStorefrontIntegrationController` does not exist yet.

## Task 2: Implement Live Storefront Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [x] **Step 1: Create the controller class**

Create `MultiCurrencyStorefrontIntegrationController` implementing `RegisterHooksInterface` with these public methods:

```php
final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void
public function set_state_builder( MultiCurrencyStateBuilder $state_builder ): void
public function set_switcher_projection_service( MultiCurrencySwitcherProjectionService $switcher_projection_service ): void
public function set_theme_resolver( callable $theme_resolver ): void
public function register()
public function handle_woocommerce_breadcrumb_defaults( array $defaults ): array
public function handle_wp_enqueue_scripts(): void
```

- [x] **Step 2: Implement registration gates**

`register()` must return unless:

```php
$this->arbiter->should_core_register()
MultiCurrencyStorefrontProjectionService::is_storefront_theme( $stylesheet, $template )
MultiCurrencyStorefrontProjectionService::should_activate(
	count( $this->get_state_builder()->build()->get_enabled_currencies() ),
	'yes' === get_option( 'wcpay_multi_currency_enable_storefront_switcher', 'no' ),
	$this->get_simulation_variables()
)
```

When active, register once:

```php
add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'handle_woocommerce_breadcrumb_defaults' ), 9999 );
add_action( 'wp_enqueue_scripts', array( $this, 'handle_wp_enqueue_scripts' ), 50 );
```

- [x] **Step 3: Implement breadcrumb and CSS callbacks**

Use `MultiCurrencyStorefrontProjectionService::get_default_widget_args()`,
`get_inline_style_manifest()`, and `inject_switcher_into_breadcrumb()`. Pass
sanitized `$_GET` query args to `MultiCurrencySwitcherProjectionService::get_widget_markup()`, preserving the `currency` query key so the projection service can remove it like WooPayments.

- [x] **Step 4: Register the controller in WooCommerce bootstrap**

Add this line after the existing native multi-currency controllers in `plugins/woocommerce/includes/class-woocommerce.php`:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStorefrontIntegrationController::class )->register();
```

- [x] **Step 5: Run green tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyStorefrontIntegrationControllerTest
```

Expected: PASS.

## Task 3: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2p-multi-currency-storefront-switcher`

- [x] **Step 1: Add changelog entry**

Create:

```text
Significance: minor
Type: fix

Add native multi-currency Storefront switcher integration.
```

- [x] **Step 2: Run focused regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStorefrontIntegrationControllerTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [x] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2p.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all PASS.

- [x] **Step 4: Stage only B2p source, tests, and changelog**

Run:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2p-multi-currency-storefront-switcher
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

Expected: staged checks PASS. Do not stage `.agents/`, `docs/superpowers/`, WPCOM files, WooPayments client files, or external staging logs.

- [x] **Step 5: Commit**

Commit message:

```bash
git commit -m "fix(payments): add multi-currency Storefront switcher" -m "[Context]
Native multi-currency already projected the WooPayments Storefront breadcrumb switcher behavior, but the native runtime did not register the live Storefront hooks.

[Problem]
Storefront themes running the core-owned multi-currency runtime would not inject the currency switcher into breadcrumbs or enqueue the preserved inline CSS, even when the Storefront switcher setting was enabled.

[Solution]
Add a Storefront integration controller that gates on runtime ownership, theme compatibility, enabled currencies, saved settings, and simulation overrides, then reuses the existing projection services to register the breadcrumb filter, render switcher markup, and enqueue inline CSS."
```

Expected: commit succeeds. If signing fails, leave the staged files intact and report the intended commit.
