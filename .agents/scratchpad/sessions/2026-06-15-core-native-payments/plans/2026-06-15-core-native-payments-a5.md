# Core Native Payments A5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the WooPayments-to-core cutover controller that owns the safe admin UX for disabling the standalone plugin while keeping mandatory deactivation fail-closed until native processing is truly ready.

**Architecture:** A5 follows WooCommerce's `Packages.php` merged-plugin pattern but keeps WooPayments-specific cutover logic in `Internal\Payments\Providers\WooPayments`. The controller detects plugin ownership through `NativePaymentsRuntimeArbiter`, validates cutover preflight state, shows the soft admin notice, handles the nonce-protected one-click disable action, and installs a default-off mandatory auto-deactivation/activation-guard path. Because A3 still uses a legacy gateway bridge, the core-owned transport readiness check is a dedicated filter that defaults to false; this prevents an unsafe plugin disable while preserving a tested switch for the later real transport.

**Tech Stack:** WooCommerce Core PHP DI, WordPress admin hooks, `LegacyProxy`, PHPUnit 9.6, WooCommerce changed-file lint/PHPStan.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: transitional cutover controller, hook registration, preflight checks, admin notice rendering, one-click deactivation, mandatory cutover guard.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the controller with other hook-attaching services.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: unit coverage for notice visibility, fail-closed readiness, deactivation command, and activation guard.
- Create `plugins/woocommerce/changelog/add-native-payments-a5-cutover-controller`: WooCommerce plugin changelog entry.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` and `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md` as evidence is gathered.

## Task 1: Cutover Controller Behavior Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [ ] **Step 1: Write the failing test file**

Create a PHPUnit test class that constructs `WooPaymentsCutoverController` with a `NativePaymentsRuntimeArbiter` and `LegacyProxy`, using legacy proxy function mocks to control plugin state and WordPress admin functions.

Required tests:

```php
/**
 * @testdox Soft cutover notice is hidden until native cutover preflight is ready.
 */
public function test_soft_notice_is_hidden_until_preflight_is_ready(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );

	$this->assertFalse(
		$this->sut->should_show_soft_cutover_notice(),
		'The notice must not invite disabling WooPayments while native transport readiness is false.'
	);
}

/**
 * @testdox Soft cutover notice is shown when plugin runtime owns the site and preflight is ready.
 */
public function test_soft_notice_is_shown_when_preflight_is_ready(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_true' );

	$this->assertTrue( $this->sut->should_show_soft_cutover_notice() );
}

/**
 * @testdox Disable action deactivates the WooPayments plugin only after nonce, capability, and preflight pass.
 */
public function test_disable_action_deactivates_plugin_after_guards_pass(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	$this->fake_valid_disable_request();
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_true' );

	$result = $this->sut->disable_woopayments_plugin();

	$this->assertTrue( $result );
	$this->assertSame(
		array( NativePaymentsRuntimeArbiter::PLUGIN_FILE, false, false ),
		$this->deactivate_plugin_calls[0]
	);
}

/**
 * @testdox Mandatory activation guard blocks WooPayments reactivation only when mandatory cutover is enabled.
 */
public function test_mandatory_activation_guard_blocks_reactivation_when_enabled(): void {
	add_filter( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );

	$this->expectException( WooPaymentsCutoverBlockedException::class );

	$this->sut->guard_woopayments_activation( NativePaymentsRuntimeArbiter::PLUGIN_FILE );
}
```

Expected initial result:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsCutoverControllerTest
```

FAIL because `WooPaymentsCutoverController` does not exist.

## Task 2: Cutover Controller Implementation

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Implement the controller skeleton**

Add a new class:

```php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

class WooPaymentsCutoverController implements RegisterHooksInterface {
	public const ACTION_DISABLE = 'disable_woopayments';
	public const FILTER_SOFT_CUTOVER_ENABLED = 'woocommerce_woopayments_native_soft_cutover_enabled';
	public const FILTER_MANDATORY_CUTOVER_ENABLED = 'woocommerce_woopayments_native_mandatory_cutover_enabled';
	public const FILTER_NATIVE_TRANSPORT_READY = 'woocommerce_woopayments_native_transport_ready';
	public const FILTER_PREFLIGHT_FAILURES = 'woocommerce_woopayments_native_cutover_preflight_failures';
	public const NONCE_ACTION = 'woocommerce_disable_woopayments';
	public const NONCE_NAME = '_wc_woopayments_cutover_nonce';
	public const QUERY_ACTION = 'wc_woopayments_cutover_action';
	public const QUERY_STATUS = 'wc_woopayments_cutover_status';
	public const STATUS_DISABLED = 'disabled';

	private NativePaymentsRuntimeArbiter $arbiter;
	private LegacyProxy $legacy_proxy;

	final public function init( NativePaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy ): void {
		$this->arbiter      = $arbiter;
		$this->legacy_proxy = $legacy_proxy;
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_action( 'admin_notices', array( $this, 'output_admin_notices' ) );
		add_action( 'activate_plugin', array( $this, 'guard_woopayments_activation' ) );
	}
}
```

- [ ] **Step 2: Implement readiness and notice visibility**

Add:

```php
public function should_show_soft_cutover_notice(): bool {
	return $this->is_soft_cutover_enabled()
		&& $this->arbiter->is_plugin_runtime_active()
		&& $this->current_user_can_cutover()
		&& $this->is_cutover_ready();
}

public function get_preflight_failures(): array {
	$failures = array();

	if ( ! $this->arbiter->is_native_runtime_enabled() ) {
		$failures[] = 'native_runtime_disabled';
	}

	if ( ! (bool) apply_filters( self::FILTER_NATIVE_TRANSPORT_READY, false ) ) {
		$failures[] = 'native_transport_unavailable';
	}

	/**
	 * Filters WooPayments native cutover preflight failures.
	 *
	 * @param array<int,string> $failures Failure codes.
	 *
	 * @since 11.0.0
	 */
	return (array) apply_filters( self::FILTER_PREFLIGHT_FAILURES, $failures );
}
```

The staging queue-handoff manifest remains evidence, not runtime input. Production code must not read `tools/woopayments-merge/queue-handoff-manifest.json` because that file is outside the shipped plugin and outside the wp-env plugin mount. The A5 runtime preflight should rely on shipped checks only: native runtime enabled plus `FILTER_NATIVE_TRANSPORT_READY` true. Existing A2 tests continue to guard the preserved Action Scheduler group and webhook hook constants.

- [ ] **Step 3: Implement deactivation and guards**

Add:

```php
public function disable_woopayments_plugin(): bool {
	if ( ! $this->arbiter->is_plugin_runtime_active() || ! $this->current_user_can_cutover() || ! $this->is_cutover_ready() ) {
		return false;
	}

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$network_wide = $this->is_woopayments_network_active();
	$this->legacy_proxy->call_function( 'deactivate_plugins', NativePaymentsRuntimeArbiter::PLUGIN_FILE, false, $network_wide );

	return ! $this->arbiter->is_plugin_runtime_active();
}

public function guard_woopayments_activation( string $plugin ): void {
	if (
		NativePaymentsRuntimeArbiter::PLUGIN_FILE !== $plugin ||
		! $this->is_mandatory_cutover_enabled() ||
		Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' )
	) {
		return;
	}

	wp_die(
		esc_html__( 'WooPayments cannot be activated because its functionality is now included in WooCommerce core.', 'woocommerce' ),
		esc_html__( 'Plugin activation error', 'woocommerce' ),
		array(
			'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
			'link_text' => esc_html__( 'Return to the Plugins page', 'woocommerce' ),
		)
	);
}
```

In tests, use a temporary `wp_die_handler` filter to throw `WooPaymentsCutoverBlockedException` so the guard is assertable.

- [ ] **Step 4: Implement admin notices and request handler**

`output_admin_notices()` must render:

```text
WooPayments is now part of WooCommerce core. Disable the WooPayments extension to continue processing payments with WooPayments.
```

Button text:

```text
Disable WooPayments
```

Success notice:

```text
WooPayments is now fully native in WooCommerce. Everything works as before.
```

Use sentence case and escape all output. `handle_admin_init()` should process the nonce-protected query action, call `disable_woopayments_plugin()`, redirect to `admin_url( 'plugins.php' )` with `wc_woopayments_cutover_status=disabled|blocked`, then exit through `LegacyProxy::exit()`.

- [ ] **Step 5: Register the controller**

In `includes/class-woocommerce.php`, add:

```php
$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class )->register();
```

near the other native payments hook-attaching services.

## Task 3: Verification and Commit

- [ ] **Step 1: Run focused A5 tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest'
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

```bash
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: all pass. If PHPCS reports unrelated legacy warnings outside changed lines, use `lint:php:changes` as the gating check and record the broad-file debt separately.

- [ ] **Step 3: Add changelog and update evidence logs**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-a5-cutover-controller
```

with:

```text
Significance: minor
Type: add

Add a native WooPayments cutover controller for safe plugin disable flows.
```

Update the implementation log and staging log with red/green results, static checks, and the final commit range.

- [ ] **Step 4: Commit A5**

Stage only A5 code/test/changelog files:

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-a5-cutover-controller
git commit -m "feat(payments): add native payments A5 cutover controller"
```

Expected: commit succeeds. If signing/auth fails, leave staged and record the intended commit.
