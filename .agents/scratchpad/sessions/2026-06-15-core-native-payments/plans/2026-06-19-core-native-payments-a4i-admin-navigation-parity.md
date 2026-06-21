---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 00:21
target: A4i native WooPayments admin navigation parity
reconciles:
  - analysis-a4i-admin-navigation-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: final
last_updated: 2026-06-19 00:56
---

# A4i Native WooPayments Admin Navigation Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make native WooPayments admin surfaces reachable from persistent WP admin navigation through canonical WooCommerce Settings > Payments provider sub-routes, with reference-compatible account-state gating for the native routes that already exist.

**Architecture:** Keep the generic `PaymentsController` as the owner of the Core top-level Payments menu and generic provider list. Add a WooPayments-specific native admin navigation controller under `Internal\Admin\Settings\PaymentsProviders\WooPayments` that runs only when `NativePaymentsRuntimeArbiter::should_native_register()` is true, projects menu state from `WooPaymentsAccountService`, and appends submenus under the existing Core Payments parent slug. Add account-state helpers to `WooPaymentsAccountService` so menu reachability uses the preserved defensive account cache instead of raw option reads.

**Tech Stack:** WooCommerce Core PHP, WordPress admin menu globals, PHPUnit in wp-env, ignored local harness scripts, Playwriter for browser verification.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`: add source-backed account-state helpers for reference menu variants.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`: add RED/GREEN coverage for rejected, under-review, partial onboarding, valid admin account, card-reader eligibility, card-reader availability, and Capital loan visibility.
- Create `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`: register native WooPayments submenu items under the Core Payments menu when native owns runtime and the user can manage WooCommerce.
- Create `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`: assert native-runtime gating, capability gating, full/reduced/onboarding menu projections, canonical Settings > Payments URLs, and no plugin-era `/payments/*` menu targets.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the new controller in the existing WooPayments service registration cluster.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py`: add source assertions for the native navigation controller and canonical menu targets. This harness file is ignored/local and must not mask product bugs.
- Modify `tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh`: update fixtures for the widened source gate if needed.
- Add a WooCommerce Core changelog entry for the product change.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` after verification.

### Task 1: Account-State Helpers

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`

- [ ] **Step 1: Add RED tests for the reference account-state predicates**

Append focused tests to `WooPaymentsAccountServiceTest` near the existing account type state tests. The test data should use the preserved `wcpay_account_data` wrapper shape already used in the file.

```php
public function test_exposes_reference_admin_account_state_from_cache(): void {
	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'        => 'acct_rejected',
				'is_live'           => true,
				'status'            => 'rejected.fraud',
				'payments_enabled'  => false,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments' => 'active',
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertTrue( $sut->is_account_rejected() );
	$this->assertFalse( $sut->is_account_under_review() );
	$this->assertTrue( $sut->is_details_submitted() );
	$this->assertTrue( $sut->has_valid_account_for_admin_navigation() );

	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'        => 'acct_review',
				'is_live'           => true,
				'status'            => 'under_review',
				'payments_enabled'  => false,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments' => 'pending_verification',
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertFalse( $sut->is_account_rejected() );
	$this->assertTrue( $sut->is_account_under_review() );
	$this->assertTrue( $sut->has_valid_account_for_admin_navigation() );

	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'        => 'acct_partial',
				'is_live'           => true,
				'status'            => 'restricted',
				'payments_enabled'  => false,
				'details_submitted' => false,
				'capabilities'      => array(
					'card_payments' => 'active',
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertFalse( $sut->is_details_submitted() );
	$this->assertFalse( $sut->has_valid_account_for_admin_navigation() );

	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'        => 'acct_unrequested',
				'is_live'           => true,
				'status'            => 'complete',
				'payments_enabled'  => true,
				'details_submitted' => true,
				'capabilities'      => array(
					'card_payments' => 'unrequested',
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertFalse( $sut->has_valid_account_for_admin_navigation() );
}

public function test_exposes_conditional_native_admin_surface_state_from_cache(): void {
	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'                 => 'acct_123',
				'is_live'                    => true,
				'card_present_eligible'      => true,
				'has_card_readers_available' => true,
				'capital'                    => array(
					'has_previous_loans' => true,
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertTrue( $sut->is_card_present_eligible() );
	$this->assertTrue( $sut->has_card_readers_available() );
	$this->assertTrue( $sut->has_previous_capital_loans() );

	update_option(
		'wcpay_account_data',
		array(
			'data' => array(
				'account_id'                 => 'acct_123',
				'is_live'                    => true,
				'card_present_eligible'      => false,
				'has_card_readers_available' => false,
				'capital'                    => array(
					'has_previous_loans' => false,
				),
			),
		)
	);

	$sut = $this->create_service();

	$this->assertFalse( $sut->is_card_present_eligible() );
	$this->assertFalse( $sut->has_card_readers_available() );
	$this->assertFalse( $sut->has_previous_capital_loans() );
}
```

- [ ] **Step 2: Run the RED account-service tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsAccountServiceTest
```

## Completion Notes

A4i was completed as the first reopened-A4 parity chunk after A5c. The implementation followed the plan at the slice level but used smaller focused tests than the sample snippets where the existing fixtures already provided account payload helpers.

Completed scope:

- Added `WooPaymentsAdminNavigationController` under the WooPayments provider settings namespace and registered it from WooCommerce bootstrap.
- Added cached-account helper methods to `WooPaymentsAccountService`.
- Added PHPUnit coverage for runtime gating, capability gating, menu variants, canonical Settings > Payments route URLs, and account-state projection.
- Widened the ignored A4 admin-surface harness to assert the native navigation controller and canonical route contract.
- Verified live target reachability with Playwriter using both direct route loads and an actual WP admin submenu click.

Remaining A4 parity work is intentionally outside this slice: reference badge counts, Settings page component/SCSS fidelity, dashboard visual/UX parity, copy/content parity, conditional Reports/Documents disposition, and the final widened A4 exit gate.

Expected: fail with undefined methods `is_account_rejected()`, `is_account_under_review()`, `is_details_submitted()`, `has_valid_account_for_admin_navigation()`, `is_card_present_eligible()`, `has_card_readers_available()`, and `has_previous_capital_loans()`.

- [ ] **Step 3: Implement the account-state helpers through the existing cached account reader**

Add methods to `WooPaymentsAccountService` below `has_live_account()`, keeping all reads through `get_cached_account_data()` and `is_truthy()`.

```php
public function is_account_rejected(): bool {
	if ( ! $this->has_account() ) {
		return false;
	}

	$account_data = $this->get_cached_account_data();
	$status       = isset( $account_data['status'] ) && is_scalar( $account_data['status'] ) ? (string) $account_data['status'] : '';

	return str_starts_with( $status, 'rejected' );
}

public function is_account_under_review(): bool {
	if ( ! $this->has_account() ) {
		return false;
	}

	$account_data = $this->get_cached_account_data();

	return 'under_review' === ( $account_data['status'] ?? false );
}

public function is_details_submitted(): bool {
	$account_data = $this->get_cached_account_data();

	return true === ( $account_data['details_submitted'] ?? false );
}

public function has_valid_account_for_admin_navigation(): bool {
	$account_data = $this->get_cached_account_data();
	if ( ! $this->has_account() || empty( $account_data['details_submitted'] ) ) {
		return false;
	}

	$capabilities = isset( $account_data['capabilities'] ) && is_array( $account_data['capabilities'] ) ? $account_data['capabilities'] : array();

	return isset( $capabilities['card_payments'] ) && 'unrequested' !== $capabilities['card_payments'];
}

public function is_card_present_eligible(): bool {
	$account_data = $this->get_cached_account_data();

	return $this->is_truthy( $account_data['card_present_eligible'] ?? false );
}

public function has_card_readers_available(): bool {
	$account_data = $this->get_cached_account_data();

	return $this->is_truthy( $account_data['has_card_readers_available'] ?? false );
}

public function has_previous_capital_loans(): bool {
	$account_data = $this->get_cached_account_data();
	$capital      = isset( $account_data['capital'] ) && is_array( $account_data['capital'] ) ? $account_data['capital'] : array();

	return $this->is_truthy( $capital['has_previous_loans'] ?? false );
}
```

- [ ] **Step 4: Run the GREEN account-service tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsAccountServiceTest
```

Expected: pass.

### Task 2: Native Admin Navigation Controller

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`
- Create: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add RED controller tests for native menu projection**

Create `WooPaymentsAdminNavigationControllerTest.php` with tests that instantiate a testable controller using mocks for `NativePaymentsRuntimeArbiter` and `WooPaymentsAccountService`, call `add_menu_items()`, and inspect the global `$submenu` for exact menu titles and URLs. The test fixture should reset `$submenu`, set an administrator user for positive cases, and set a customer/no-user for capability negative cases.

```php
<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

class WooPaymentsAdminNavigationControllerTest extends WC_Unit_Test_Case {
	private NativePaymentsRuntimeArbiter&MockObject $arbiter;
	private WooPaymentsAccountService&MockObject $account_service;
	private WooPaymentsAdminNavigationController $sut;

	public function setUp(): void {
		parent::setUp();
		global $submenu;
		$submenu = array();
		$this->arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )->disableOriginalConstructor()->onlyMethods( array( 'should_native_register' ) )->getMock();
		$this->account_service = $this->getMockBuilder( WooPaymentsAccountService::class )->disableOriginalConstructor()->onlyMethods( array( 'has_account', 'has_valid_account_for_admin_navigation', 'is_account_rejected', 'is_account_under_review', 'is_details_submitted', 'is_card_present_eligible', 'has_card_readers_available', 'has_previous_capital_loans' ) )->getMock();
		$this->sut = new WooPaymentsAdminNavigationController();
		$this->sut->init( $this->arbiter, $this->account_service );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_does_not_register_native_submenus_when_native_runtime_does_not_own_payments(): void {
		global $submenu;
		$this->arbiter->method( 'should_native_register' )->willReturn( false );
		$this->account_service->expects( $this->never() )->method( 'has_account' );

		$this->sut->add_menu_items();

		$this->assertSame( array(), $submenu );
	}

	public function test_does_not_register_native_submenus_without_manage_woocommerce_capability(): void {
		global $submenu;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->arbiter->expects( $this->never() )->method( 'should_native_register' );

		$this->sut->add_menu_items();

		$this->assertSame( array(), $submenu );
	}

	public function test_registers_full_native_menu_for_valid_accounts(): void {
		$this->given_native_runtime();
		$this->given_account_state(
			array(
				'has_account' => true,
				'valid' => true,
				'rejected' => false,
				'under_review' => false,
				'details_submitted' => true,
				'card_readers' => true,
				'capital' => true,
			)
		);

		$this->sut->add_menu_items();

		$this->assertMenuLabels( array( 'Overview', 'Payouts', 'Transactions', 'Disputes', 'Card Readers', 'Capital Loans', 'Settings' ) );
		$this->assertMenuUrls(
			array(
				'/woopayments/overview',
				'/woopayments/payouts',
				'/woopayments/transactions',
				'/woopayments/disputes',
				'/woopayments/card-readers',
				'/woopayments/loans',
				'/woopayments/settings',
			)
		);
	}

	public function test_registers_reduced_menu_for_rejected_or_under_review_accounts(): void {
		foreach ( array( 'rejected', 'under_review' ) as $state ) {
			global $submenu;
			$submenu = array();
			$this->given_native_runtime();
			$this->given_account_state(
				array(
					'has_account' => true,
					'valid' => true,
					'rejected' => 'rejected' === $state,
					'under_review' => 'under_review' === $state,
					'details_submitted' => true,
					'card_readers' => true,
					'capital' => true,
				)
			);

			$this->sut->add_menu_items();

			$this->assertMenuLabels( array( 'Overview', 'Transactions', 'Disputes' ) );
			$this->assertMenuUrls( array( '/woopayments/overview', '/woopayments/transactions', '/woopayments/disputes' ) );
		}
	}

	public function test_registers_onboarding_menu_for_missing_or_partial_accounts(): void {
		foreach ( array( 'missing', 'partial' ) as $state ) {
			global $submenu;
			$submenu = array();
			$this->given_native_runtime();
			$this->given_account_state(
				array(
					'has_account' => 'partial' === $state,
					'valid' => false,
					'rejected' => false,
					'under_review' => false,
					'details_submitted' => false,
					'card_readers' => false,
					'capital' => false,
				)
			);

			$this->sut->add_menu_items();

			$this->assertMenuLabels( array( 'Onboarding' ) );
			$this->assertMenuUrls( array( '/woopayments/onboarding' ) );
		}
	}

	private function given_native_runtime(): void {
		$this->arbiter->method( 'should_native_register' )->willReturn( true );
	}

	private function given_account_state( array $state ): void {
		$this->account_service->method( 'has_account' )->willReturn( (bool) $state['has_account'] );
		$this->account_service->method( 'has_valid_account_for_admin_navigation' )->willReturn( (bool) $state['valid'] );
		$this->account_service->method( 'is_account_rejected' )->willReturn( (bool) $state['rejected'] );
		$this->account_service->method( 'is_account_under_review' )->willReturn( (bool) $state['under_review'] );
		$this->account_service->method( 'is_details_submitted' )->willReturn( (bool) $state['details_submitted'] );
		$this->account_service->method( 'is_card_present_eligible' )->willReturn( (bool) $state['card_readers'] );
		$this->account_service->method( 'has_card_readers_available' )->willReturn( (bool) $state['card_readers'] );
		$this->account_service->method( 'has_previous_capital_loans' )->willReturn( (bool) $state['capital'] );
	}

	private function assertMenuLabels( array $expected_labels ): void {
		$items = $this->getMenuItems();
		$this->assertSame( $expected_labels, array_map( static fn( array $item ): string => wp_strip_all_tags( (string) $item[0] ), $items ) );
	}

	private function assertMenuUrls( array $expected_paths ): void {
		$items = $this->getMenuItems();
		$urls = array_map( static fn( array $item ): string => (string) $item[2], $items );
		foreach ( $expected_paths as $index => $path ) {
			$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $urls[ $index ] );
			$this->assertStringContainsString( 'path=' . $path, rawurldecode( $urls[ $index ] ) );
			$this->assertStringNotContainsString( 'page=wc-admin&path=/payments', $urls[ $index ] );
		}
	}

	private function getMenuItems(): array {
		global $submenu;
		$parent = 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM;
		return array_values( $submenu[ $parent ] ?? array() );
	}
}
```

- [ ] **Step 2: Run the RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsAdminNavigationControllerTest
```

Expected: fail because `WooPaymentsAdminNavigationController` does not exist.

- [ ] **Step 3: Implement the native menu controller**

Create `WooPaymentsAdminNavigationController.php`. Use `RegisterHooksInterface`, inject `NativePaymentsRuntimeArbiter` and `WooPaymentsAccountService`, register on `admin_menu` after `PaymentsController`, and append exact submenu rows under the Core Payments parent slug. If `add_submenu_page()` preserves the exact slug under test, use it; if it rewrites route URLs, use a private `append_submenu_item()` wrapper with a targeted PHPCS ignore because these are absolute Settings > Payments route URLs.

```php
<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

class WooPaymentsAdminNavigationController implements RegisterHooksInterface {
	private const CAPABILITY = 'manage_woocommerce';

	private NativePaymentsRuntimeArbiter $arbiter;
	private WooPaymentsAccountService $account_service;

	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service ): void {
		$this->arbiter = $arbiter;
		$this->account_service = $account_service;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_items' ), 70 );
	}

	public function add_menu_items(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->arbiter->should_native_register() ) {
			return;
		}

		foreach ( $this->get_visible_menu_items() as $item ) {
			$this->append_submenu_item( $item['title'], $item['path'] );
		}
	}

	private function get_visible_menu_items(): array {
		if ( $this->account_service->is_account_rejected() || $this->account_service->is_account_under_review() ) {
			return array(
				array( 'title' => __( 'Overview', 'woocommerce' ), 'path' => '/woopayments/overview' ),
				array( 'title' => __( 'Transactions', 'woocommerce' ), 'path' => '/woopayments/transactions' ),
				array( 'title' => __( 'Disputes', 'woocommerce' ), 'path' => '/woopayments/disputes' ),
			);
		}

		if ( ! $this->account_service->has_valid_account_for_admin_navigation() ) {
			return array(
				array( 'title' => __( 'Onboarding', 'woocommerce' ), 'path' => '/woopayments/onboarding' ),
			);
		}

		$items = array(
			array( 'title' => __( 'Overview', 'woocommerce' ), 'path' => '/woopayments/overview' ),
			array( 'title' => __( 'Payouts', 'woocommerce' ), 'path' => '/woopayments/payouts' ),
			array( 'title' => __( 'Transactions', 'woocommerce' ), 'path' => '/woopayments/transactions' ),
			array( 'title' => __( 'Disputes', 'woocommerce' ), 'path' => '/woopayments/disputes' ),
		);

		if ( $this->account_service->is_card_present_eligible() && $this->account_service->has_card_readers_available() ) {
			$items[] = array( 'title' => __( 'Card Readers', 'woocommerce' ), 'path' => '/woopayments/card-readers' );
		}

		if ( $this->account_service->has_previous_capital_loans() ) {
			$items[] = array( 'title' => __( 'Capital Loans', 'woocommerce' ), 'path' => '/woopayments/loans' );
		}

		$items[] = array( 'title' => __( 'Settings', 'woocommerce' ), 'path' => '/woopayments/settings' );

		return $items;
	}

	private function append_submenu_item( string $title, string $path ): void {
		global $submenu;
		$parent = $this->get_parent_menu_slug();
		if ( ! isset( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			$submenu[ $parent ] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$submenu[ $parent ][] = array( $title, self::CAPABILITY, Utils::wc_payments_settings_url( $path, array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	private function get_parent_menu_slug(): string {
		return 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM;
	}
}
```

- [ ] **Step 4: Register the controller in WooCommerce bootstrap**

Add the new controller next to the existing WooPayments settings controller registration in `plugins/woocommerce/includes/class-woocommerce.php`.

```php
$container->get( Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController::class )->register();
```

- [ ] **Step 5: Run the GREEN controller tests plus existing menu tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest|PaymentsControllerTest|WooPaymentsAccountServiceTest'
```

Expected: pass.

### Task 3: Widen The A4 Admin Source Gate

**Files:**
- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py`
- Modify: `tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh`

- [ ] **Step 1: Add gate assertions for persistent navigation source**

Extend the ignored local gate so it checks that `WooPaymentsAdminNavigationController.php` exists, contains canonical `/woopayments/*` submenu paths for the existing native surfaces, does not contain `wc-admin&path=/payments`, and references the native runtime arbiter. Keep this source gate honest; it should not declare visual parity.

```python
EXPECTED_NAVIGATION_PATHS = {
    "/woopayments/settings",
    "/woopayments/overview",
    "/woopayments/payouts",
    "/woopayments/transactions",
    "/woopayments/disputes",
    "/woopayments/card-readers",
    "/woopayments/loans",
    "/woopayments/onboarding",
}

def check_navigation_source(repo: Path, failures: list[str]) -> dict[str, Any]:
    progress("Checking native WooPayments admin navigation source")
    path = repo / "plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php"
    if not path.is_file():
        add_failure(failures, f"missing native WooPayments admin navigation controller: {rel(path, repo)}")
        return {"status": "missing", "expected_paths": sorted(EXPECTED_NAVIGATION_PATHS)}

    source = read_text(path)
    found_paths = sorted(route for route in EXPECTED_NAVIGATION_PATHS if route in source)
    for route in EXPECTED_NAVIGATION_PATHS:
        if route not in source:
            add_failure(failures, f"missing native navigation target in source: {route}")
    if "wc-admin&path=/payments" in source:
        add_failure(failures, "native navigation source contains plugin-era wc-admin /payments target")
    if "NativePaymentsRuntimeArbiter" not in source or "should_native_register" not in source:
        add_failure(failures, "native navigation source does not gate on NativePaymentsRuntimeArbiter")

    return {"status": "checked", "path": rel(path, repo), "found_paths": found_paths}
```

Call `check_navigation_source()` from the main gate and include the result in JSON evidence.

- [ ] **Step 2: Update fixture gate tests**

Run:

```bash
tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh
```

Expected before fixture updates: fail if fixture source does not include the new controller. Update the fixture setup to create a minimal `WooPaymentsAdminNavigationController.php` with canonical paths and the arbiter token, then rerun and expect pass.

- [ ] **Step 3: Run the widened gate against the real repository**

Run:

```bash
tools/woopayments-merge/a4-admin-surface-gate.py --repo . --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4i-admin-surface-gate.json
```

Expected: pass and write JSON evidence. This remains an architecture/source/chunk gate, not a visual parity claim.

### Task 4: Browser Reachability And Review Gates

**Files:**
- Read/use: Playwriter browser against `http://store8889.localhost:8889` and `http://localhost:8082`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/*` evidence files
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-agent-findings.md`

- [ ] **Step 1: Run Playwriter skill setup before browser automation**

Run:

```bash
playwriter skill
```

Expected: Playwriter prints the local usage contract. Follow it for all browser steps.

- [ ] **Step 2: Use Playwriter to assert target persistent menu reachability**

Drive an admin browser session on the target store as the existing logged-in admin. Open `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout`, inspect the WP admin menu, and click each visible native WooPayments submenu. Record the final URL for each clicked item and fail if any clicked URL does not stay under `page=wc-settings&tab=checkout&path=/woopayments/...` or if any clicked route has a failed WooPayments REST response or target PHP warning.

- [ ] **Step 3: Compare reference menu labels and target menu labels for the current account state**

Open `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout` in the reference store and capture the visible Payments submenu labels. Compare only the current account-state intersection that native can faithfully implement in this slice: full/reduced/onboarding state labels, conditional Card Readers/Capital if the source predicates are true, and Settings. Record Reports/Documents as not exposed unless native routes exist and are implemented before the browser check.

- [ ] **Step 4: Dispatch read-only reviews**

Use subagents for two bounded reviews: one architecture/API-contract review over the account-state helpers and navigation controller, and one adversarial parity review against N12 Surface 1. The review prompt must forbid WPCOM access and code edits, must mention that harness files are ignored/local, and must ask for findings first with source anchors.

- [ ] **Step 5: Fix source-backed findings and rerun focused gates**

If reviewers find source-backed issues, add RED tests before product fixes where practical. Rerun the focused PHP tests and widened harness gate after every fix.

### Task 5: Final Verification, Changelog, Logs, And Commit

**Files:**
- Add changelog under `plugins/woocommerce/changelog/`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Run focused product verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest|PaymentsControllerTest|WooPaymentsAccountServiceTest'
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php --memory-limit=2G
cd /Users/vladolaru/Work/a8c/woocommerce-develop-2 && git diff --check
```

Expected: all pass.

- [ ] **Step 2: Run widened ignored-harness gates without linting scratchpad docs**

Run:

```bash
tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh
tools/woopayments-merge/a4-admin-surface-gate.py --repo . --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4i-admin-surface-gate-final.json
```

Expected: both pass. Do not run markdown lint over `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Add and validate changelog**

Run the WooCommerce changelog workflow and choose a fix/change description that reflects merchant admin reachability, for example "Add persistent native WooPayments admin navigation under Payments settings."

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog add
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
```

Expected: changelog validates, with only known dependency deprecation noise if present.

- [ ] **Step 4: Run branch-level PHP lint and final diff checks**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git diff --check
git diff --cached --check
```

Expected: PHP clean; JS branch lint may still report the existing ignored-file warning pattern only if no JS files are touched in this slice.

- [ ] **Step 5: Update session logs and commit**

Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with A4i scope, source/tests, Playwriter evidence, review results, harness output, residual A4 parity gaps, and commit hash/range after commit. Keep `FILTER_NATIVE_ADMIN_SURFACES_READY` documented as fail-closed. Commit product source/tests/changelog only; do not force-add scratchpad or ignored harness files unless explicitly instructed.

```bash
git status --short
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/<new-entry>
git commit
```

Expected: one logical product commit for A4i unless changelog separation is needed to match branch convention. No push.

## Self-Review

Spec coverage: This plan covers N12 Surface 1 merchant reachability for native routes that already exist, keeps link targets under Settings > Payments provider sub-routes, uses `manage_woocommerce` gating, preserves connected/rejected/under-review/not-connected variants, and keeps native admin readiness fail-closed. It intentionally does not claim settings visual/copy/SCSS parity or dashboard visual parity; those remain reopened A4 follow-up slices.

Placeholder scan: No TBD/TODO/implement-later placeholders are present. Conditional Reports/Documents are not implemented because native routes are not present in the current route registry; they remain tracked under broader A4 parity rather than silently exposed as dead links.

Type consistency: New account methods are declared on `WooPaymentsAccountService` and mocked under the same names in `WooPaymentsAdminNavigationControllerTest`. Menu paths match current native route paths and the onboarding modal path.
