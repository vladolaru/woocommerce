---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 15:45
reconciles:
  - analysis-supervisor-realignment.md
  - supervisor-prompt-2026-06-17-1311.md
status: draft
---

# H13 Cutover Interlock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent WooPayments plugin-to-native cutover from proceeding until native merchant admin surfaces and provider-event sync dispositions are explicitly ready.

**Architecture:** `WooPaymentsCutoverController` remains the single deterministic preflight owner. It gains two fail-closed readiness seams: native admin surfaces default to unavailable, and provider-event cutover readiness defaults to the current `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` list. Future A4/A5 packages must satisfy these seams with real code, not by assuming transport readiness is enough.

**Tech Stack:** WooCommerce Core PHP, PHPUnit through `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, restored WooPayments merge harness.

---

### Task 1: Add RED Cutover Preflight Regressions

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [ ] **Step 1: Add tests for fail-closed admin and event readiness**

Add tests near the existing transport readiness tests:

```php
/**
 * @testdox Cutover preflight blocks while native admin surfaces are unavailable.
 */
public function test_preflight_blocks_when_native_admin_surfaces_are_unavailable(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
	$this->native_provider_ready = true;

	$this->assertContains( 'native_admin_surfaces_unavailable', $this->sut->get_preflight_failures() );
	$this->assertFalse( $this->sut->should_show_soft_cutover_notice() );
}

/**
 * @testdox Cutover preflight blocks while provider event types remain undispositioned.
 */
public function test_preflight_blocks_when_provider_events_are_undispositioned(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
	$this->native_provider_ready = true;

	$this->assertContains( 'provider_events_undispositioned', $this->sut->get_preflight_failures() );
	$this->assertFalse( $this->sut->should_show_soft_cutover_notice() );
}
```

- [ ] **Step 2: Update test cleanup and readiness helper for the future green path**

Add the new filters to `tearDown()`, and update `enable_ready_cutover()` to opt into admin readiness and an empty pending-event list:

```php
remove_all_filters( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY );
remove_all_filters( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER );
```

```php
private function enable_ready_cutover(): void {
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
	$this->native_provider_ready = true;
}
```

- [ ] **Step 3: Run RED test**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest'`

Expected: fail because `FILTER_NATIVE_ADMIN_SURFACES_READY`, `FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER`, `native_admin_surfaces_unavailable`, and `provider_events_undispositioned` do not exist yet.

### Task 2: Implement Fail-Closed Preflight Seams

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-h13-cutover-interlock`

- [ ] **Step 1: Add filter constants**

Add constants after `FILTER_NATIVE_TRANSPORT_READY`:

```php
/**
 * Filter that reports whether native WooPayments merchant admin surfaces are ready after deactivation.
 *
 * @var string
 */
public const FILTER_NATIVE_ADMIN_SURFACES_READY = 'woocommerce_woopayments_native_admin_surfaces_ready';

/**
 * Filter that reports provider event types still pending native cutover disposition.
 *
 * @var string
 */
public const FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER = 'woocommerce_woopayments_native_cutover_pending_event_types';
```

- [ ] **Step 2: Add preflight failure checks**

Add these checks in `get_preflight_failures()` after transport readiness:

```php
if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, false ) ) {
	$failures[] = 'native_admin_surfaces_unavailable';
}

if ( array() !== $this->get_pending_provider_event_types() ) {
	$failures[] = 'provider_events_undispositioned';
}
```

- [ ] **Step 3: Add pending event helper**

Add this private method near `is_native_transport_ready()`:

```php
/**
 * Get provider event types that still need native cutover disposition.
 *
 * @return array<int,string> Event type identifiers.
 */
private function get_pending_provider_event_types(): array {
	/**
	 * Filters provider event types that still need native cutover disposition.
	 *
	 * @since 11.0.0
	 *
	 * @param array<int,string> $event_types Event types that still block cutover.
	 */
	$event_types = apply_filters( self::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES );

	if ( ! is_array( $event_types ) ) {
		return array( 'provider_events_filter_invalid' );
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $event_types ),
				static fn( string $event_type ): bool => '' !== $event_type
			)
		)
	);
}
```

- [ ] **Step 4: Add changelog**

Create `plugins/woocommerce/changelog/add-native-payments-h13-cutover-interlock`:

```text
Significance: patch
Type: dev
Comment: Block native WooPayments cutover until admin surfaces and provider event sync are ready.
```

- [ ] **Step 5: Run focused GREEN**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest'`

Expected: pass, with the new tests proving default cutover blocks without explicit admin/event readiness and existing tests proving controlled rollout can opt in.

### Task 3: Verify, Review, Harness, Commit

**Files:**
- Update: `implementation-log.md`
- Update: `staging-log.md`

- [ ] **Step 1: Run static gates**

Run:

```bash
composer -d plugins/woocommerce exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: all pass.

- [ ] **Step 2: Run restored harness**

Run: `tools/woopayments-merge/verify.sh --ref "docker exec -i wcpay_wp_default wp --allow-root" --target "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root"`

Expected: 7/7 pass. If the target CLI requires an explicit user for a route, add `--user=1` only for that command form and record why.

- [ ] **Step 3: Scan logs**

Scan target WordPress, target CLI, and reference WordPress Docker logs since the harness start timestamp for PHP warning/notice/deprecated/fatal/parse/uncaught/stack-trace and HTTP 5xx matches.

- [ ] **Step 4: Run code review gate**

Dispatch a focused code-reviewer on the H13 diff with emphasis on fail-closed cutover behavior, filter semantics, and whether the new seams can mask missing admin/event work. Fix any important finding before proceeding.

- [ ] **Step 5: Update session logs**

Append an H13 staging entry with `Canonical stage: A5 cutover interlock plus A4/A5 readiness dependency`, source/tests/gates/review/commit evidence. Update `implementation-log.md` with RED/GREEN and gate evidence.

- [ ] **Step 6: Commit source/tests and changelog**

Commit source/tests and changelog as one logical change unless the changelog is split by convention:

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php plugins/woocommerce/changelog/add-native-payments-h13-cutover-interlock
git commit -m "fix(payments): block incomplete woopayments cutover"
```

Expected: commit succeeds locally; do not push.
