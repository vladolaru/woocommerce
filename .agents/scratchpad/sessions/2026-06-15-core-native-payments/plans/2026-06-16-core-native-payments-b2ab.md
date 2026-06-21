# Native Multi-Currency Store Currency Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments-compatible store-currency change behavior when
the Core native multi-currency runtime owns the site.

**Architecture:** Add a runtime-gated lifecycle controller that registers an
`init` priority 10 callback, matching WooPayments' multi-currency init timing.
Move the option/cache mutation into a focused service so the behavior can be
tested as state changes: seed the last-known store currency, detect changes,
clear cached rates, and write the manual-rate notice option from enabled
currencies.

**Tech Stack:** WooCommerce PHP hooks, WordPress options, native
multi-currency state builder/cache services, PHPUnit via
`pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan,
PHPCS, markdownlint.

---

## File Structure

- Add `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleService.php`
    - Own option/cache side effects for store-currency lifecycle parity.
    - Use `MultiCurrencyCacheInterface::CURRENCIES_KEY` for cache deletion.
    - Use `MultiCurrencyStateBuilder` to collect enabled manual-rate currency
      names.
- Add `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php`
    - Runtime-gated hook registration only.
    - Register `init` at priority 10 with `handle_init()`.
    - Lazily build the lifecycle service from the existing cache, localization,
      rate-provider, and state-builder services.
- Add `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleServiceTest.php`
    - Test option seeding, unchanged store currency, changed store currency,
      manual-rate notice writing, no-manual-rate behavior, and invalid store
      currency guard.
- Add `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleControllerTest.php`
    - Test runtime-gated hook registration and callback delegation.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add a `store_currency_lifecycle` hook group with `init` priority 10.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the new hook group and preserved hook metadata.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the lifecycle controller before other multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2ab-store-currency-lifecycle`
    - Patch changelog entry.

## Task 1: Store Currency Lifecycle RED Tests

**Files:**

- Add: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleServiceTest.php`
- Add: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add service tests**

Create the service test file with this behavior coverage:

```php
<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;
use WC_Unit_Test_Case;

class MultiCurrencyStoreCurrencyLifecycleServiceTest extends WC_Unit_Test_Case {
	private const STORE_CURRENCY_OPTION = 'wcpay_multi_currency_store_currency';
	private const NOTICE_OPTION         = 'wcpay_multi_currency_show_store_currency_changed_notice';

	private string $original_currency;

	public function set_up(): void {
		parent::set_up();

		$this->original_currency = get_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency', 'USD' );
		$this->delete_options();
	}

	public function tear_down(): void {
		$this->delete_options();
		update_option( 'woocommerce_currency', $this->original_currency );

		parent::tear_down();
	}

	public function test_seeds_missing_store_currency_without_clearing_cache(): void {
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertIsArray( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertFalse( get_option( self::NOTICE_OPTION, false ) );
	}

	public function test_keeps_options_when_store_currency_is_unchanged(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertIsArray( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
	}

	public function test_updates_store_currency_clears_cache_and_writes_manual_rate_notice(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'CAD', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_cad', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_cad', '1.47' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.84' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertTrue( $changed );
		$this->assertSame( 'EUR', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertFalse( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, false ) );
		$this->assertSame( array( 'Canadian dollar', 'Pound sterling' ), get_option( self::NOTICE_OPTION ) );
	}

	public function test_does_not_write_notice_when_changed_currencies_have_no_manual_rates(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertTrue( $changed );
		$this->assertFalse( get_option( self::NOTICE_OPTION, false ) );
	}

	public function test_skips_unknown_store_currency_without_mutating_state(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'XYZ' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertIsArray( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
	}

	private function create_service(): MultiCurrencyStoreCurrencyLifecycleService {
		$cache = new MultiCurrencyDatabaseCache();

		return new MultiCurrencyStoreCurrencyLifecycleService(
			$cache,
			new MultiCurrencyStateBuilder(
				new MultiCurrencyLocalizationService(),
				new MultiCurrencyRateService( new CurrencyRateProviderRegistry() ),
				$cache
			)
		);
	}

	private function delete_options(): void {
		foreach ( array(
			self::STORE_CURRENCY_OPTION,
			self::NOTICE_OPTION,
			'wcpay_multi_currency_enabled_currencies',
			'wcpay_multi_currency_exchange_rate_cad',
			'wcpay_multi_currency_manual_rate_cad',
			'wcpay_multi_currency_exchange_rate_gbp',
			'wcpay_multi_currency_manual_rate_gbp',
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
		) as $option_name ) {
			delete_option( $option_name );
		}
	}
}
```

- [ ] **Step 2: Add controller tests**

Create the controller test file with runtime gate and callback delegation:

```php
public function test_registers_init_hook_once_when_core_owns_runtime(): void {
	$service = $this->create_lifecycle_service();
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );

	$sut->register();
	$sut->register();

	$this->assertSame( 10, has_action( 'init', array( $sut, 'handle_init' ) ) );
}

public function test_does_not_register_init_hook_when_plugin_owns_runtime(): void {
	$service = $this->create_lifecycle_service();
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, $service );

	$sut->register();

	$this->assertFalse( has_action( 'init', array( $sut, 'handle_init' ) ) );
}

public function test_handle_init_synchronizes_store_currency(): void {
	$service = $this->create_lifecycle_service();
	$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $service );

	$sut->handle_init();

	$this->assertSame( 1, $service->calls );
}
```

Use an anonymous subclass of `MultiCurrencyStoreCurrencyLifecycleService` with
`synchronize_store_currency()` overridden to increment `$calls`.

- [ ] **Step 3: Add runtime registry RED assertions**

In `test_exposes_core_hook_groups_when_core_owns_runtime()`, add
`store_currency_lifecycle` before `selected_currency` in the expected keys.
Add a focused test:

```php
public function test_store_currency_lifecycle_manifest_contains_preserved_hook(): void {
	$hook_groups = MultiCurrencyRuntimeRegistry::get_core_hook_groups();

	$this->assertSame(
		array(
			array(
				'hook'          => 'init',
				'callback'      => 'sync_store_currency',
				'priority'      => 10,
				'accepted_args' => 1,
			),
		),
		$hook_groups['store_currency_lifecycle']['actions']
	);
	$this->assertSame( array(), $hook_groups['store_currency_lifecycle']['filters'] );
}
```

- [ ] **Step 4: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStoreCurrencyLifecycleServiceTest|MultiCurrencyStoreCurrencyLifecycleControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected before production code:

```text
ERRORS!
Class "Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService" not found
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStoreCurrencyLifecycleController" not found
Failed asserting that two arrays are identical.
```

## Task 2: Lifecycle Service And Controller

**Files:**

- Add: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleService.php`
- Add: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the lifecycle service**

Implement `synchronize_store_currency()` with these exact side effects:

```php
public function synchronize_store_currency(): bool {
	$store_currency = strtoupper( get_woocommerce_currency() );

	if ( ! array_key_exists( $store_currency, get_woocommerce_currencies() ) ) {
		return false;
	}

	$last_known_currency = get_option( self::STORE_CURRENCY_OPTION, false );
	if ( ! $last_known_currency ) {
		update_option( self::STORE_CURRENCY_OPTION, $store_currency );
		return false;
	}

	if ( strtoupper( (string) $last_known_currency ) === $store_currency ) {
		return false;
	}

	update_option( self::STORE_CURRENCY_OPTION, $store_currency );
	$this->cache->delete( MultiCurrencyCacheInterface::CURRENCIES_KEY );
	$this->update_manual_rate_currencies_notice_option();

	return true;
}
```

The notice writer should iterate
`$this->state_builder->build()->get_enabled_currencies()`, read
`wcpay_multi_currency_exchange_rate_{$currency->get_id()}`, and update
`wcpay_multi_currency_show_store_currency_changed_notice` only when at least
one enabled currency uses `manual`.

- [ ] **Step 2: Add the lifecycle controller**

Implement `register()`, `handle_init()`, `set_lifecycle_service()`, lazy
service construction, and a private `add_action_once()` helper:

```php
public function register() {
	if ( ! $this->arbiter->should_core_register() ) {
		return;
	}

	$this->add_action_once( 'init', array( $this, 'handle_init' ), 10 );
}

public function handle_init(): void {
	$this->get_lifecycle_service()->synchronize_store_currency();
}
```

The lazy service must use one shared `MultiCurrencyDatabaseCache` instance for
the service and the `MultiCurrencyStateBuilder`.

- [ ] **Step 3: Add the runtime manifest group**

In `MultiCurrencyRuntimeRegistry::get_core_hook_groups()`, add:

```php
'store_currency_lifecycle' => self::get_store_currency_lifecycle_hook_group(),
```

Add:

```php
private static function get_store_currency_lifecycle_hook_group(): array {
	return array(
		'filters' => array(),
		'actions' => array(
			self::hook_entry( 'init', 'sync_store_currency', 10 ),
		),
	);
}
```

- [ ] **Step 4: Bootstrap the controller**

In `plugins/woocommerce/includes/class-woocommerce.php`, register the new
controller before the other multi-currency runtime controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStoreCurrencyLifecycleController::class )->register();
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStoreCurrencyLifecycleServiceTest|MultiCurrencyStoreCurrencyLifecycleControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected:

```text
OK
```

## Task 3: Changelog, Regression, Static Checks, Commits

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2ab-store-currency-lifecycle`

- [ ] **Step 1: Add changelog**

Create the changelog:

```text
Significance: patch
Type: fix
Comment: Preserve native multi-currency store currency change handling.
```

- [ ] **Step 2: Run focused regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStoreCurrencyLifecycleServiceTest|MultiCurrencyStoreCurrencyLifecycleControllerTest|MultiCurrencyAdminNoticesControllerTest|MultiCurrencyStateBuilderTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected:

```text
OK
```

- [ ] **Step 3: Run static checks**

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleService.php --configuration "$TMPDIR/phpstan-b2ab.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleService.php tests/php/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleServiceTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ab.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
git diff --cached --check
```

Expected: all pass. The temporary PHPStan config may include the project config
and set `reportUnmatchedIgnoredErrors: false`; do not edit the baseline.

- [ ] **Step 4: Commit source and changelog separately**

Commit source/tests first:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleControllerTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStoreCurrencyLifecycleServiceTest.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php plugins/woocommerce/includes/class-woocommerce.php
git commit -m "fix(payments): preserve multi-currency store currency changes"
```

Commit changelog second:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ab-store-currency-lifecycle
git commit -m "chore(payments): add store currency lifecycle changelog"
```

If signing fails, leave staged changes in place and report the intended commit
message to the user. Do not use `--no-gpg-sign`.
