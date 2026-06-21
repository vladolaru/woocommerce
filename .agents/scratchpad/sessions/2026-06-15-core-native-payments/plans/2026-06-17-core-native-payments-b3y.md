---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 00:08
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - plans/2026-06-16-core-native-payments-b3x.md
status: final
last_updated: 2026-06-17 00:15
---

# Core Native Payments B3y Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make WooPayments soft cutover readiness come from Core's native WooPayments provider instead of an always-false transport override, while keeping mandatory plugin deactivation disabled by default.

**Architecture:** The cutover controller remains the single owner of plugin-to-native handoff UX. Native runtime enablement remains a separate filter gate, and mandatory cutover remains default-off; this slice only replaces the obsolete `false` default for `woocommerce_woopayments_native_transport_ready` with Core's own `WooPaymentsProvider::can_process_payments()` result. The legacy transport-ready filter stays available as an override and the failure code remains `native_transport_unavailable` so existing diagnostics do not drift.

**Tech Stack:** WooCommerce Core PHP, DI container `init()` wiring, PHPUnit, PHPStan, PHPCS, restored local harness.

---

### Task 1: Add Cutover Readiness RED Coverage

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [x] **Step 1: Inject a mocked WooPayments provider into the cutover tests**

Add the provider import and property:

```php
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
```

```php
/**
 * Native WooPayments provider mock.
 *
 * @var WooPaymentsProvider&\PHPUnit\Framework\MockObject\MockObject
 */
private WooPaymentsProvider $provider;

/**
 * Whether the native provider can process payments.
 *
 * @var bool
 */
private bool $native_provider_ready = false;
```

Change `setUp()` to create a direct SUT so tests control provider readiness without touching global account state:

```php
$this->provider = $this->getMockBuilder( WooPaymentsProvider::class )
	->disableOriginalConstructor()
	->onlyMethods( array( 'can_process_payments' ) )
	->getMock();
$this->provider
	->method( 'can_process_payments' )
	->willReturnCallback( fn() => $this->native_provider_ready );

$this->sut = new WooPaymentsCutoverController();
$this->sut->init(
	wc_get_container()->get( NativePaymentsRuntimeArbiter::class ),
	wc_get_container()->get( LegacyProxy::class ),
	$this->provider
);
```

- [x] **Step 2: Make the existing ready helper use provider readiness**

Change `enable_ready_cutover()` so it no longer adds `WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY`:

```php
private function enable_ready_cutover(): void {
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	$this->native_provider_ready = true;
}
```

- [x] **Step 3: Add explicit override tests**

Add tests proving the preserved filter can still block or force readiness:

```php
/**
 * @testdox Transport readiness filter can still block cutover when the native provider is ready.
 */
public function test_transport_filter_can_block_provider_backed_preflight(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	$this->enable_ready_cutover();
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_false' );

	$this->assertFalse( $this->sut->should_show_soft_cutover_notice() );
	$this->assertContains( 'native_transport_unavailable', $this->sut->get_preflight_failures() );
}

/**
 * @testdox Transport readiness filter can still force cutover readiness for controlled rollouts.
 */
public function test_transport_filter_can_force_preflight_when_provider_is_not_ready(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_true' );

	$this->assertTrue( $this->sut->should_show_soft_cutover_notice() );
}
```

- [x] **Step 4: Run the focused RED**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-tests-cli-1 bash -lc 'cd /var/www/html/wp-content/plugins/woocommerce && vendor/bin/phpunit -c phpunit.xml --verbose --filter WooPaymentsCutoverControllerTest'
```

Expected: FAIL because `WooPaymentsCutoverController::init()` does not accept a provider yet and the controller still defaults transport readiness to false.

### Task 2: Wire Provider-Backed Cutover Preflight

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`

- [x] **Step 1: Add the provider collaborator**

Add a provider property:

```php
/**
 * Native WooPayments provider.
 *
 * @var WooPaymentsProvider
 */
private WooPaymentsProvider $provider;
```

Change `init()` to accept and store the provider:

```php
final public function init( NativePaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider ): void {
	$this->arbiter      = $arbiter;
	$this->legacy_proxy = $legacy_proxy;
	$this->provider     = $provider;
}
```

- [x] **Step 2: Replace the default false transport preflight**

Add a helper:

```php
/**
 * Tell whether native WooPayments can process after plugin deactivation.
 *
 * @return bool
 */
private function is_native_transport_ready(): bool {
	try {
		return $this->provider->can_process_payments();
	} catch ( \Throwable $e ) {
		return false;
	}
}
```

Change the preflight filter call in `get_preflight_failures()`:

```php
if ( ! (bool) apply_filters( self::FILTER_NATIVE_TRANSPORT_READY, $this->is_native_transport_ready() ) ) {
	$failures[] = 'native_transport_unavailable';
}
```

- [x] **Step 3: Run focused GREEN**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-tests-cli-1 bash -lc 'cd /var/www/html/wp-content/plugins/woocommerce && vendor/bin/phpunit -c phpunit.xml --verbose --filter WooPaymentsCutoverControllerTest'
```

Expected: PASS, including the new provider-backed readiness and filter override tests.

### Task 3: Verify Cutover Does Not Change Mandatory Defaults

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [x] **Step 1: Add a mandatory cutover default-off regression**

Add:

```php
/**
 * @testdox Mandatory activation guard remains default-off even when native provider preflight is ready.
 */
public function test_mandatory_activation_guard_remains_default_off_when_preflight_is_ready(): void {
	$this->fake_wp_die_handler();
	$this->enable_ready_cutover();

	$this->sut->guard_woopayments_activation( NativePaymentsRuntimeArbiter::PLUGIN_FILE );

	$this->assertTrue( true, 'Mandatory cutover must still require an explicit rollout filter.' );
}
```

- [x] **Step 2: Run focused GREEN again**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-tests-cli-1 bash -lc 'cd /var/www/html/wp-content/plugins/woocommerce && vendor/bin/phpunit -c phpunit.xml --verbose --filter WooPaymentsCutoverControllerTest'
```

Expected: PASS.

### Task 4: Run Gates, Harness, Review, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3y-provider-backed-cutover-preflight`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] **Step 1: Run focused and related PHP**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-tests-cli-1 bash -lc 'cd /var/www/html/wp-content/plugins/woocommerce && vendor/bin/phpunit -c phpunit.xml --verbose --filter "WooPaymentsCutoverControllerTest|WooPaymentsProviderTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest"'
```

Expected: PASS.

- [x] **Step 2: Run static checks**

Run:

```bash
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: PASS.

- [x] **Step 3: Run restored harness and log scan**

Run:

```bash
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Then scan target/reference `debug.log` and recent container stderr for fresh PHP notices, warnings, deprecations, fatals, and uncaught errors.

Expected: harness PASS, no fresh product PHP warnings.

- [x] **Step 4: Run code review gate**

Request a read-only code review focused on cutover safety: no automatic mandatory deactivation by default, provider-backed preflight correctness, filter compatibility, and activation guard behavior. Fix critical/high/medium findings before committing.

- [x] **Step 5: Add changelog and commit**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-b3y-provider-backed-cutover-preflight
```

with:

```text
Significance: patch
Type: dev
Comment: Use native WooPayments provider readiness for guarded plugin-to-core cutover preflight.
```

Commit source/tests and changelog as separate logical commits. Do not push.
