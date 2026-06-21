---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 18:47
target: A5g rollout/default-on gate plus multisite runtime proof
reconciles:
  - analysis-a5g-next-slice-selection.md
  - plans/2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md
  - supervisor-prompt-2026-06-17-1311.md
status: final
last_updated: 2026-06-20 19:13
---

# A5g Cutover Rollout And Multisite Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the post-A5f cutover rollout state explicit and verifiable, and add a real multisite runtime gate for per-site and network-active WooPayments ownership without mutating the connected `:8889` target store.

**Architecture:** A5g does not flip the final production mandatory default. It records the current rollout default as an intentional fail-closed state in product code/tests, preserves the existing filter override seams for local gates and future release flip, and adds a disposable local wp-env multisite harness that proves runtime ownership only. The multisite gate intentionally avoids WPCOM, Stripe, account, transport, and browser checks because A5f already covered the connected single-site cutover path and A5g is closing the multisite/runtime coverage gap.

**Tech Stack:** WooCommerce PHP internals, PHPUnit via wp-env, Python 3 harness orchestration, local `wp-env`, WP-CLI, JSON scratchpad evidence.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php`: add an explicit default constant for the native runtime rollout flag and feed the existing `woocommerce_native_payments_enabled` filter from it.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: add an explicit default constant for the mandatory cutover rollout flag and feed the existing `woocommerce_woopayments_native_mandatory_cutover_enabled` filter from it.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsRuntimeArbiterTest.php`: assert the native runtime rollout default is fail-closed and the filter still receives and can override that default.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: assert the mandatory cutover rollout default is fail-closed and the filter still receives and can override that default while keeping the activation guard/preflight behavior.
- Create `tools/woopayments-merge/a5g-multisite-runtime-state.php`: local-only state probe that enables the native runtime for the current WP-CLI request and emits runtime ownership facts for the current multisite blog.
- Create `tools/woopayments-merge/a5g-multisite-runtime-gate.py`: local-only disposable wp-env orchestrator that starts a temporary multisite install, runs the per-site/network-active ownership matrix, prints progress, and writes JSON evidence.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`, `implementation-log.md`, `spec-conformance-baseline.md`, `README.md`, and this plan with the final A5g result.

## Task 1: Product Rollout Defaults

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsRuntimeArbiterTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [x] **Step 1: Load required implementation skills**

Run before writing PHP tests or product code:

```bash
sed -n '1,260p' .ai/skills/woocommerce-backend-dev/SKILL.md
sed -n '1,320p' /Users/vladolaru/.codex/skills/test-driven-development/SKILL.md
```

- [x] **Step 2: Add failing native runtime rollout tests**

Add tests equivalent to:

```php
public function test_native_runtime_rollout_default_is_fail_closed(): void {
	$this->fake_plugin();

	$this->assertFalse( NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED );
	$this->assertFalse( $this->sut->is_native_runtime_enabled(), 'Native runtime must stay default-off until the release rollout default is explicitly flipped.' );
	$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
}

public function test_native_runtime_rollout_filter_receives_default_and_can_enable(): void {
	$this->fake_plugin();
	$observed_default = null;
	add_filter(
		NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED,
		static function ( bool $enabled ) use ( &$observed_default ): bool {
			$observed_default = $enabled;
			return true;
		}
	);

	$this->assertTrue( $this->sut->is_native_runtime_enabled() );
	$this->assertFalse( $observed_default );
	$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner() );
}
```

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter=NativePaymentsRuntimeArbiterTest
```

Expected before implementation: fail on missing `DEFAULT_NATIVE_RUNTIME_ENABLED`.

- [x] **Step 3: Add failing mandatory cutover rollout tests**

Add tests equivalent to:

```php
public function test_mandatory_cutover_rollout_default_is_fail_closed(): void {
	$this->fake_wp_die_handler();
	$this->enable_ready_cutover();

	$this->assertFalse( WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED );
	$this->sut->guard_woopayments_activation( NativePaymentsRuntimeArbiter::PLUGIN_FILE );
	$this->assertTrue( true, 'Mandatory cutover remains fail-closed until the release rollout default is explicitly flipped.' );
}

public function test_mandatory_cutover_rollout_filter_receives_default_and_can_enable(): void {
	$this->fake_wp_die_handler();
	$this->enable_ready_cutover();
	$observed_default = null;
	add_filter(
		WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED,
		static function ( bool $enabled ) use ( &$observed_default ): bool {
			$observed_default = $enabled;
			return true;
		}
	);

	$this->expectException( WooPaymentsCutoverBlockedException::class );
	try {
		$this->sut->guard_woopayments_activation( NativePaymentsRuntimeArbiter::PLUGIN_FILE );
	} finally {
		$this->assertFalse( $observed_default );
	}
}
```

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter=WooPaymentsCutoverControllerTest
```

Expected before implementation: fail on missing `DEFAULT_MANDATORY_CUTOVER_ENABLED`.

- [x] **Step 4: Implement explicit fail-closed rollout defaults**

In `NativePaymentsRuntimeArbiter`, add:

```php
/**
 * Default state for the native WooPayments runtime rollout.
 *
 * This intentionally remains false until the final A5 stage-boundary gates approve the release/default-on flip.
 *
 * @var bool
 */
public const DEFAULT_NATIVE_RUNTIME_ENABLED = false;
```

Then change the filter default to:

```php
return (bool) apply_filters( self::FILTER_NATIVE_ENABLED, self::DEFAULT_NATIVE_RUNTIME_ENABLED );
```

In `WooPaymentsCutoverController`, add:

```php
/**
 * Default state for mandatory WooPayments native cutover.
 *
 * This intentionally remains false until the final A5 stage-boundary gates approve the release/default-on flip.
 *
 * @var bool
 */
public const DEFAULT_MANDATORY_CUTOVER_ENABLED = false;
```

Then change the filter default to:

```php
return (bool) apply_filters( self::FILTER_MANDATORY_CUTOVER_ENABLED, self::DEFAULT_MANDATORY_CUTOVER_ENABLED );
```

- [x] **Step 5: Run focused PHP tests**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter='NativePaymentsRuntimeArbiterTest|WooPaymentsCutoverControllerTest'
```

Expected: PASS.

## Task 2: Disposable Multisite Runtime Gate

**Files:**
- Create: `tools/woopayments-merge/a5g-multisite-runtime-state.php`
- Create: `tools/woopayments-merge/a5g-multisite-runtime-gate.py`

- [x] **Step 1: Add the multisite runtime state probe**

Create `a5g-multisite-runtime-state.php` with a local-only probe that adds `woocommerce_native_payments_enabled => true`, resolves `NativePaymentsRuntimeArbiter`, and emits JSON containing at least `is_multisite`, `blog_id`, `site_url`, `active_site_plugins`, `network_active_plugins`, `runtime_owner`, `native_runtime_enabled`, `plugin_runtime_active`, `should_native_register`, `ready`, and `failures`.

- [x] **Step 2: Add the disposable wp-env gate orchestrator**

Create `a5g-multisite-runtime-gate.py` that:

```text
1. Creates a unique `$TMPDIR` working directory with a `.wp-env.json` mapping this repo's WooCommerce plugin and the local WooPayments plugin.
2. Starts wp-env on the requested port using `plugins/woocommerce/node_modules/.bin/wp-env`.
3. Activates WooCommerce network-wide.
4. Converts the install to multisite and creates a second subdirectory site.
5. Runs the state probe on both sites after each runtime transition.
6. Verifies baseline native ownership on both sites when WooPayments is inactive.
7. Activates WooPayments only on the main site and verifies main=plugin, second=native.
8. Deactivates WooPayments on the main site and verifies both sites return to native.
9. Network-activates WooPayments and verifies both sites are plugin-owned.
10. Network-deactivates WooPayments and verifies both sites return to native.
11. Writes `<out-dir>/a5g-multisite-runtime-gate.json` continuously and prints progress for every phase.
12. Destroys the disposable wp-env on success/failure unless `--keep-env` is passed.
```

The gate must not query WPCOM, Stripe, local Transact, target `:8889`, or the reference `:8082` stores.

- [x] **Step 3: Validate harness syntax**

Run:

```bash
python3 -m py_compile tools/woopayments-merge/a5g-multisite-runtime-gate.py
php -l tools/woopayments-merge/a5g-multisite-runtime-state.php
```

Expected: both commands pass.

- [x] **Step 4: Run the multisite runtime gate**

Run:

```bash
tools/woopayments-merge/a5g-multisite-runtime-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --wcpay-repo /Users/vladolaru/Work/a8c/woocommerce-payments --runtime-mode existing-tests --existing-wp-env-dir /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce --existing-tests-url http://store8889.localhost:8087 --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5g-multisite-runtime
```

Expected: exit `0`, JSON evidence has `pass: true`, every phase is `pass`, and the final state has both sites native-owned. The disposable mode remains implemented as the default path, but local Docker failed while registering a phpMyAdmin layer; `existing-tests` mode uses the WooCommerce tests wp-env, resets/restores that tests database and multisite constants, and does not touch the connected `:8889` target or `:8082` reference stores.

## Task 3: Verification, Review, And Documentation

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-20-core-native-payments-a5g-rollout-multisite-gate.md`

- [x] **Step 1: Run product quality checks**

Run focused checks:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/NativePaymentsRuntimeArbiter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
```

Expected: PASS. Do not lint `.agents/scratchpad`.

- [x] **Step 2: Dispatch review subagents**

Dispatch at least one focused reviewer after implementation:

```text
Spec/reliability review: verify A5g preserves fail-closed rollout semantics, existing preflight blockers, `WC_ALLOW_MERGED_FEATURE_PLUGINS`, and multisite runtime ownership coverage without over-claiming WPCOM/account readiness.
Code quality review: verify the PHP constants/tests and Python/PHP harness code do not introduce brittle assumptions or mask implementation bugs.
```

- [x] **Step 3: Record A5g results**

Update scratchpad docs with the final evidence path, exact commands, verdict, and any limitations. Make clear that A5g proves the rollout seam is intentionally fail-closed and proves multisite runtime ownership, but does not flip final mandatory production default and does not replace later A5 canary/perf/error gates.

- [x] **Step 4: Commit product changes if applicable**

If WooCommerce product files or tests changed, create a Conventional Commit on `exp/core-native-payments` only after checks pass. Do not push. If only ignored harness and scratchpad files changed, do not force-add ignored files unless explicitly requested.

- [x] **Step 5: Preserve queued follow-ups**

Keep the existing queued follow-ups visible after A5g: check the N8 supervisor advisory after the current A5 cutover closure, and reopen A4 per `supervisor-prompt-2026-06-18-2344-N12.md` once fully done with the relevant A5 slice sequence.

## Self-Review

- Spec coverage: The plan covers A5g rollout/default-on gate by making the current default explicit and fail-closed, and covers the A5 multisite runtime gap with a disposable real multisite gate. It deliberately does not claim final mandatory rollout flip, WPCOM/account readiness, canary parity/error/perf completion, or A6 cleanup.
- Placeholder scan: No `TBD`, unspecified tests, or open-ended implementation placeholders remain.
- Type consistency: Product constants are named `DEFAULT_NATIVE_RUNTIME_ENABLED` and `DEFAULT_MANDATORY_CUTOVER_ENABLED`; gate evidence file is `a5g-multisite-runtime-gate.json`; the state probe and orchestrator names match the task file list.
