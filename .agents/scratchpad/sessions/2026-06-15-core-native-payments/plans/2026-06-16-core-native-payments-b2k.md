# Core Native Payments B2k Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Use WooPayments-compatible order-context heuristics for native
multi-currency order total formatting.

**Architecture:** Add a focused `MultiCurrencyOrderContextService` that mirrors
WooPayments' page/query-var/backtrace heuristic for deciding when order
currency should override selected currency formatting. Inject it into
`MultiCurrencyFrontendCurrenciesController` so order-total reads initialize
order currency only in matching contexts and formatted totals clear it only in
matching contexts.

**Tech Stack:** WooCommerce core PHP, WordPress `WP` query vars,
`wp_debug_backtrace_summary()`, native multi-currency frontend controller,
PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rule:

- `should_use_order_currency()` returns true only when:
    - current query vars name a WooCommerce order page:
      `pagename` is `my-account` or `checkout`; and
    - one of `order-received`, `order-pay`, `orders`, or `view-order` is set;
      and
    - the backtrace contains one of:
      `WC_Shortcode_My_Account::view_order`,
      `WC_Shortcode_Checkout::order_received`,
      `WC_Shortcode_Checkout::order_pay`, or
      `WC_Order->get_formatted_order_total`.
- `maybe_init_order_currency_from_order_total_prop()` initializes order currency
  from the order only when the heuristic passes.
- `maybe_clear_order_currency_after_formatted_order_total()` clears order
  currency only when it is set and the heuristic passes.
- B2k does not change B2j query-var initialization.

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextService.php`
    - Encapsulates page/query-var/backtrace matching.
    - Accepts an optional backtrace summary provider for deterministic tests.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextServiceTest.php`
    - Covers true and false heuristic cases with temporary `$wp->query_vars`.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - Adds nullable order-context service state and setter.
    - Uses the service in order-total initialization and formatted-total
      clearing.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
    - Injects deterministic order-context service doubles.
    - Covers automatic order-total initialization and context-gated clearing.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2k-multi-currency-order-total-context`

## Tasks

### Task 1: Order Context Heuristic Service

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextServiceTest.php`
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextService.php`

- [ ] **Step 1: Write failing service tests**

Create
`plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextServiceTest.php`:

```php
<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyOrderContextService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyOrderContextService class.
 */
class MultiCurrencyOrderContextServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should use order currency on WooCommerce order pages and formatting backtraces.
	 */
	public function test_uses_order_currency_on_order_page_formatting_backtrace(): void {
		$sut = $this->create_service(
			array( 'WC_Order->get_formatted_order_total' )
		);

		$this->with_query_vars(
			array(
				'pagename'  => 'checkout',
				'order-pay' => 123,
			),
			function () use ( $sut ): void {
				$this->assertTrue( $sut->should_use_order_currency() );
			}
		);
	}

	/**
	 * @testdox Should not use order currency outside supported WooCommerce pages.
	 */
	public function test_does_not_use_order_currency_outside_supported_pages(): void {
		$sut = $this->create_service(
			array( 'WC_Order->get_formatted_order_total' )
		);

		$this->with_query_vars(
			array(
				'pagename'  => 'shop',
				'order-pay' => 123,
			),
			function () use ( $sut ): void {
				$this->assertFalse( $sut->should_use_order_currency() );
			}
		);
	}

	/**
	 * @testdox Should not use order currency without supported order query vars.
	 */
	public function test_does_not_use_order_currency_without_supported_order_query_vars(): void {
		$sut = $this->create_service(
			array( 'WC_Order->get_formatted_order_total' )
		);

		$this->with_query_vars(
			array( 'pagename' => 'checkout' ),
			function () use ( $sut ): void {
				$this->assertFalse( $sut->should_use_order_currency() );
			}
		);
	}

	/**
	 * @testdox Should not use order currency without supported formatting backtrace.
	 */
	public function test_does_not_use_order_currency_without_supported_formatting_backtrace(): void {
		$sut = $this->create_service( array( 'Other_Class->render' ) );

		$this->with_query_vars(
			array(
				'pagename'       => 'my-account',
				'view-order'     => 123,
				'unrelated-data' => 'present',
			),
			function () use ( $sut ): void {
				$this->assertFalse( $sut->should_use_order_currency() );
			}
		);
	}

	/**
	 * Create an order context service with a deterministic backtrace.
	 *
	 * @param array<int, string> $backtrace_summary Backtrace summary.
	 * @return MultiCurrencyOrderContextService
	 */
	private function create_service( array $backtrace_summary ): MultiCurrencyOrderContextService {
		return new MultiCurrencyOrderContextService(
			static function () use ( $backtrace_summary ): array {
				return $backtrace_summary;
			}
		);
	}

	/**
	 * Run a callback with temporary WordPress query vars.
	 *
	 * @param array<string, mixed> $query_vars Query vars.
	 * @param callable             $callback   Callback to run.
	 */
	private function with_query_vars( array $query_vars, callable $callback ): void {
		global $wp;

		$this->assertInstanceOf( \WP::class, $wp );

		$previous_query_vars = $wp->query_vars;
		$wp->query_vars      = $query_vars;

		try {
			$callback();
		} finally {
			$wp->query_vars = $previous_query_vars;
		}
	}
}
```

- [ ] **Step 2: Run red service tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyOrderContextServiceTest
```

Expected: fail because `MultiCurrencyOrderContextService` does not exist.

- [ ] **Step 3: Implement the order context service**

Create
`plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyOrderContextService.php`:

```php
<?php
/**
 * MultiCurrencyOrderContextService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Detects WooCommerce order contexts where order currency should be used.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyOrderContextService {

	/**
	 * Optional provider for deterministic backtrace summaries.
	 *
	 * @var callable|null
	 */
	private $backtrace_summary_provider;

	/**
	 * Constructor.
	 *
	 * @param callable|null $backtrace_summary_provider Optional backtrace summary provider.
	 */
	public function __construct( ?callable $backtrace_summary_provider = null ) {
		$this->backtrace_summary_provider = $backtrace_summary_provider;
	}

	/**
	 * Tell whether order currency should override selected currency formatting.
	 *
	 * @return bool
	 */
	public function should_use_order_currency(): bool {
		$pages = array( 'my-account', 'checkout' );
		$vars  = array( 'order-received', 'order-pay', 'orders', 'view-order' );

		if ( ! $this->is_page_with_vars( $pages, $vars ) ) {
			return false;
		}

		return $this->is_call_in_backtrace(
			array(
				'WC_Shortcode_My_Account::view_order',
				'WC_Shortcode_Checkout::order_received',
				'WC_Shortcode_Checkout::order_pay',
				'WC_Order->get_formatted_order_total',
			)
		);
	}

	/**
	 * Tell whether the current request has one of the given page and query vars.
	 *
	 * @param array<int, string> $pages Page slugs.
	 * @param array<int, string> $vars  Query var names.
	 * @return bool
	 */
	private function is_page_with_vars( array $pages, array $vars ): bool {
		global $wp;

		if ( ! $wp instanceof \WP || empty( $wp->query_vars['pagename'] ) ) {
			return false;
		}

		if ( ! in_array( $wp->query_vars['pagename'], $pages, true ) ) {
			return false;
		}

		foreach ( $vars as $var ) {
			if ( isset( $wp->query_vars[ $var ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether one of the expected calls is present in the current backtrace.
	 *
	 * @param array<int, string> $calls Expected call summaries.
	 * @return bool
	 */
	private function is_call_in_backtrace( array $calls ): bool {
		$backtrace = null === $this->backtrace_summary_provider
			? wp_debug_backtrace_summary( null, 0, false ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			: call_user_func( $this->backtrace_summary_provider );

		if ( ! is_array( $backtrace ) ) {
			return false;
		}

		foreach ( $calls as $call ) {
			if ( in_array( $call, $backtrace, true ) ) {
				return true;
			}
		}

		return false;
	}
}
```

- [ ] **Step 4: Run green service tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyOrderContextServiceTest
```

Expected: pass.

### Task 2: Controller Order-Total Wiring

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`

- [ ] **Step 1: Write failing controller tests**

In the controller test file, add this import:

```php
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyOrderContextService;
```

Update `create_controller()` to accept an optional context service:

```php
private function create_controller(
	string $owner,
	?MultiCurrencyRequestContext $request_context = null,
	?MultiCurrencyOrderContextService $order_context_service = null
): MultiCurrencyFrontendCurrenciesController {
	$controller = new MultiCurrencyFrontendCurrenciesController();
	$controller->init( $this->create_arbiter( $owner ) );
	$controller->set_frontend_projection_service( $this->create_projection_service() );
	if ( null !== $request_context && method_exists( $controller, 'set_request_context' ) ) {
		$controller->set_request_context( $request_context );
	}
	if ( null !== $order_context_service && method_exists( $controller, 'set_order_context_service' ) ) {
		$controller->set_order_context_service( $order_context_service );
	}

	return $controller;
}
```

Update `test_clears_order_currency_after_formatted_order_total()` to inject a
matching order context service:

```php
$sut = $this->create_controller(
	MultiCurrencyRuntimeArbiter::OWNER_CORE,
	null,
	$this->create_order_context_service( true )
);
```

Add these tests after the clearing test:

```php
/**
 * @testdox Should initialize order currency from order total in matching order context.
 */
public function test_initializes_order_currency_from_order_total_in_matching_order_context(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller(
		MultiCurrencyRuntimeArbiter::OWNER_CORE,
		null,
		$this->create_order_context_service( true )
	);

	$this->assertSame( 42.0, $sut->maybe_init_order_currency_from_order_total_prop( 42.0, $order ) );

	$this->assertSame( 'JPY', $sut->get_order_currency() );
	$this->assertSame( 'JPY', $sut->get_woocommerce_currency( 'USD' ) );
}

/**
 * @testdox Should not initialize order currency from order total outside order context.
 */
public function test_does_not_initialize_order_currency_from_order_total_outside_order_context(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller(
		MultiCurrencyRuntimeArbiter::OWNER_CORE,
		null,
		$this->create_order_context_service( false )
	);

	$this->assertSame( 42.0, $sut->maybe_init_order_currency_from_order_total_prop( 42.0, $order ) );

	$this->assertNull( $sut->get_order_currency() );
	$this->assertSame( 'GBP', $sut->get_woocommerce_currency( 'USD' ) );
}

/**
 * @testdox Should keep order currency after formatted order total outside order context.
 */
public function test_keeps_order_currency_after_formatted_order_total_outside_order_context(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller(
		MultiCurrencyRuntimeArbiter::OWNER_CORE,
		null,
		$this->create_order_context_service( false )
	);

	$sut->init_order_currency( $order );
	$this->assertSame( '$42.00', $sut->maybe_clear_order_currency_after_formatted_order_total( '$42.00', $order, '', false ) );

	$this->assertSame( 'JPY', $sut->get_order_currency() );
}
```

Add this helper near the other private helpers:

```php
/**
 * Create a deterministic order context service.
 *
 * @param bool $should_use_order_currency Whether order currency should be used.
 * @return MultiCurrencyOrderContextService
 */
private function create_order_context_service( bool $should_use_order_currency ): MultiCurrencyOrderContextService {
	return new class( $should_use_order_currency ) extends MultiCurrencyOrderContextService {
		/**
		 * Whether order currency should be used.
		 *
		 * @var bool
		 */
		private bool $should_use_order_currency;

		/**
		 * Constructor.
		 *
		 * @param bool $should_use_order_currency Whether order currency should be used.
		 */
		public function __construct( bool $should_use_order_currency ) {
			$this->should_use_order_currency = $should_use_order_currency;
		}

		/**
		 * Tell whether order currency should be used.
		 *
		 * @return bool
		 */
		public function should_use_order_currency(): bool {
			return $this->should_use_order_currency;
		}
	};
}
```

- [ ] **Step 2: Run red controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: fail because the controller has no `set_order_context_service()`, the
order-total callback is still a pass-through, and formatted-total clearing is
not context-gated.

- [ ] **Step 3: Implement controller wiring**

Add this import:

```php
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyOrderContextService;
```

Add this property:

```php
/**
 * Order context service.
 *
 * @var MultiCurrencyOrderContextService|null
 */
private ?MultiCurrencyOrderContextService $order_context_service = null;
```

Add this setter near the other setters:

```php
/**
 * Set the order context service.
 *
 * @internal Used by tests and future explicit bootstrap definitions.
 *
 * @param MultiCurrencyOrderContextService $order_context_service Order context service.
 */
public function set_order_context_service( MultiCurrencyOrderContextService $order_context_service ): void {
	$this->order_context_service = $order_context_service;
}
```

Update `maybe_init_order_currency_from_order_total_prop()`:

```php
public function maybe_init_order_currency_from_order_total_prop( $total, $order ) {
	if ( $this->get_order_context_service()->should_use_order_currency() ) {
		$this->init_order_currency( $order );
	}

	return $total;
}
```

Update `maybe_clear_order_currency_after_formatted_order_total()`:

```php
public function maybe_clear_order_currency_after_formatted_order_total( $formatted_total, $order, $tax_display, $display_refunded ) {
	unset( $order, $tax_display, $display_refunded );

	if ( null !== $this->order_currency && $this->get_order_context_service()->should_use_order_currency() ) {
		$this->order_currency = null;
	}

	return $formatted_total;
}
```

Add this getter near `get_request_context()`:

```php
/**
 * Get the order context service.
 *
 * @return MultiCurrencyOrderContextService
 */
private function get_order_context_service(): MultiCurrencyOrderContextService {
	if ( null === $this->order_context_service ) {
		$this->order_context_service = new MultiCurrencyOrderContextService();
	}

	return $this->order_context_service;
}
```

- [ ] **Step 4: Run green controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyOrderContextServiceTest|MultiCurrencyFrontendCurrenciesControllerTest'
```

Expected: pass.

### Task 3: Verification, Changelog, Commit

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2k-multi-currency-order-total-context`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Use native payments multi-currency order total context for formatting.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2k:

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
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2k.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2k code/changelog**

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
fix(payments): use multi-currency order total context
```

## Self-Review

- Spec coverage: B2k covers the WooPayments page/query-var/backtrace heuristic
  plus order-total initialization and clearing.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: controller service setter/getter, service class, and test
  doubles all use `MultiCurrencyOrderContextService`.
