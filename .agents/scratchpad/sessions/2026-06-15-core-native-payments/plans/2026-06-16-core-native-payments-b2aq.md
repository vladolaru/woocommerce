# B2aq Deposits Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the native multi-currency compatibility adapter for WooCommerce Deposits versions that still need WooPayments-style conversion support.

**Architecture:** Keep the existing adapter split: a static projection service owns hook metadata and pure compatibility decisions, while a runtime-gated controller owns WordPress hooks, backtrace checks, order updates, and selected-currency price projection. Register the hook group in `MultiCurrencyRuntimeRegistry` and the live controller in WooCommerce bootstrap only when Core owns multi-currency.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, PHPUnit through `wp-env`, PHPStan, PHPCS, pnpm scripts.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionService.php` for the Deposits hook manifest and pure predicates.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php` for guarded hook registration and runtime callbacks.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionServiceTest.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityControllerTest.php`.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php` to expose `deposits_compatibility`.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php` to assert the manifest.
- Modify `plugins/woocommerce/includes/class-woocommerce.php` to register the controller.
- Add `plugins/woocommerce/changelog/add-native-payments-b2aq-deposits-compatibility`.

## Task 1: Projection Service RED/GREEN

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Write the failing projection and registry tests**

Add tests that expect:

```php
$manifest = MultiCurrencyDepositsCompatibilityProjectionService::get_hook_manifest();

$this->assertSame(
	array(
		'woocommerce_get_cart_contents',
		'woocommerce_product_get__wc_deposit_amount',
		'wcpay_multi_currency_should_convert_product_price',
	),
	array_column( $manifest['filters'], 'hook' )
);
$this->assertSame(
	array( 'woocommerce_deposits_create_order' ),
	array_column( $manifest['actions'], 'hook' )
);
$this->assertTrue( MultiCurrencyDepositsCompatibilityProjectionService::should_register( true, '2.0.0' ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_register( true, '2.0.1' ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_register( false, '1.9.9' ) );
$this->assertTrue( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_cart_item_deposit_amount( array( 'is_deposit' => true, 'deposit_amount' => '10.00' ) ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_cart_item_deposit_amount( array( 'is_deposit' => false, 'deposit_amount' => '10.00' ) ) );
$this->assertTrue( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_deposit_amount_meta( 'percent', true ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_deposit_amount_meta( 'plan', true ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_product_price( true, 'plan', true ) );
$this->assertTrue( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_product_price( true, 'percent', true ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_convert_product_price( false, 'plan', true ) );
$this->assertTrue( MultiCurrencyDepositsCompatibilityProjectionService::should_align_order_currency( 'USD', 'GBP' ) );
$this->assertFalse( MultiCurrencyDepositsCompatibilityProjectionService::should_align_order_currency( 'GBP', 'GBP' ) );
```

Also add `deposits_compatibility` to the expected registry keys and assert its
four preserved hooks.

- [ ] **Step 2: Run projection RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDepositsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: FAIL because the Deposits projection service and registry group do not exist.

- [ ] **Step 3: Implement the projection service and registry group**

Implement:

```php
public static function should_register( bool $deposits_available, ?string $deposits_version ): bool {
	return $deposits_available && ( null === $deposits_version || version_compare( $deposits_version, '2.0.1', '<' ) );
}
```

Manifest hooks must match WooPayments:

```php
self::hook_entry( 'woocommerce_deposits_create_order', 'modify_order_currency', 10, 1 );
self::hook_entry( 'woocommerce_get_cart_contents', 'modify_cart_item_deposit_amounts', 10, 1 );
self::hook_entry( 'woocommerce_product_get__wc_deposit_amount', 'modify_cart_item_deposit_amount_meta', 10, 2 );
self::hook_entry( 'wcpay_multi_currency_should_convert_product_price', 'maybe_convert_product_prices_for_deposits', 10, 2 );
```

- [ ] **Step 4: Run projection GREEN**

Run the same command. Expected: PASS.

## Task 2: Controller RED/GREEN

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityControllerTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Write the failing controller tests**

Cover:

- Core-owned runtime registers all Deposits filters/actions exactly once.
- Plugin-owned runtime, missing Deposits runtime, and Deposits version `2.0.1` do not register hooks.
- Registration defers to `plugins_loaded` when the Deposits class is not available before plugins load.
- Deposit cart item amounts are projected through `MultiCurrencyPriceProjectionService::get_price( ..., 'product' )`.
- Deposit amount meta converts only for `percent` deposits while `WC_Deposits_Cart_Manager->deposits_form_output` is in the backtrace.
- Payment-plan products suppress default product-price conversion during `WC_Cart->calculate_totals`.
- Remaining-payment order creation aligns the new order currency to the original deposited order currency.
- WooCommerce bootstrap resolves and registers the controller.

- [ ] **Step 2: Run controller RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDepositsCompatibilityControllerTest|MultiCurrencyDepositsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: FAIL because the controller and bootstrap registration do not exist.

- [ ] **Step 3: Implement the controller**

Implement `MultiCurrencyDepositsCompatibilityController implements RegisterHooksInterface` with:

```php
public function register();
public function register_deposits_hooks(): void;
public function modify_cart_item_deposit_amounts( array $cart_contents ): array;
public function modify_cart_item_deposit_amount_meta( $amount, $product );
public function maybe_convert_product_prices_for_deposits( bool $result, $product ): bool;
public function modify_order_currency( int $order_id ): void;
protected function is_deposits_runtime_available(): bool;
protected function get_deposits_version(): ?string;
protected function have_plugins_loaded(): bool;
protected function is_call_in_backtrace( array $calls ): bool;
protected function get_product_deposit_type( $product );
public function set_price_projection_service( MultiCurrencyPriceProjectionService $price_projection_service ): void;
```

Use the same price-projection construction pattern as the Pre-Orders controller.

- [ ] **Step 4: Run controller GREEN**

Run the same focused command. Expected: PASS.

## Task 3: Verification, Changelog, Commit

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2aq-deposits-compatibility`

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix

Preserve native multi-currency conversion for WooCommerce Deposits compatibility.
```

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDepositsCompatibilityControllerTest|MultiCurrencyDepositsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyPointsRewardsCompatibilityControllerTest|MultiCurrencyFedExCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm exec markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2aq.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Commit source and changelog separately**

Source commit:

```bash
git add plugins/woocommerce/includes/class-woocommerce.php \
	plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php \
	plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php \
	plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionService.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityControllerTest.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyDepositsCompatibilityProjectionServiceTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git diff --cached --check
git commit -m 'fix(payments): add deposits multi-currency compatibility'
```

Changelog commit:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2aq-deposits-compatibility
git diff --cached --check
git commit -m 'chore(payments): add deposits compatibility changelog'
```

## Self-Review

- Spec coverage: Deposits version gating, hook manifest, cart/meta conversion,
  product-conversion bypass, order-currency alignment, bootstrap registration,
  registry metadata, changelog, and verification gates are all covered.
- Placeholder scan: No placeholder terms are present.
- Type consistency: Method and class names match the planned files and existing
  compatibility-controller patterns.
