---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 02:04
last_updated: 2026-06-20 02:08
target: A4ac native admin readiness reground
reconciles:
  - analysis-a4ac-admin-readiness-reground.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - spec-conformance-baseline.md
  - staging-log.md
status: final
---

# Core Native Payments A4ac Admin Readiness Reground Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments admin-surface readiness to fail-closed until the full N12 parity bar passes, while preserving the explicit filter override used by controlled gates.

**Architecture:** A4ab remains the audited smoke/runtime route/chunk/log baseline, not the authorization for cutover. `WooPaymentsCutoverController` should default `woocommerce_woopayments_native_admin_surfaces_ready` to `false`, and only an explicit `true` filter may allow otherwise-clean preflight to proceed while A4/N12 parity residuals remain. The correction is intentionally small and isolated to the cutover preflight contract plus tests and session docs.

**Tech Stack:** WooCommerce Core PHP, WordPress filters, PHPUnit via wp-env, WooCommerce changelog, local scratchpad docs.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: change the admin-surface readiness filter default from `true` back to `false`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: replace the A5d default-ready test with fail-closed coverage and keep explicit `__return_true` filters in tests that need to exercise non-admin blockers or handoff success.
- Add `plugins/woocommerce/changelog/fix-native-payments-admin-readiness-reground`: patch changelog entry.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md`: record A4ac as a corrective reground after A5e and mark A4/N12 parity still open.

## Task 1: Restore Fail-Closed Test Coverage

- [x] **Step 1: Rename and invert the default readiness test**

Replace the A5d default-ready test with a default-blocking test:

```php
/**
 * @testdox Cutover preflight defaults admin surfaces to unavailable until the N12 parity gate explicitly passes.
 */
public function test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
	add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
	$this->native_provider_ready = true;

	$failures = $this->sut->get_preflight_failures();

	$this->assertContains( 'native_admin_surfaces_unavailable', $failures );
	$this->assertFalse( $this->sut->should_show_soft_cutover_notice() );
}
```

- [x] **Step 2: Run the focused RED**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest::test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes
```

Expected before implementation: failure because `native_admin_surfaces_unavailable` is absent and the soft notice is true.

## Task 2: Restore Product Fail-Closed Default

- [x] **Step 1: Change the filter default**

In `WooPaymentsCutoverController::get_preflight_failures()`, change:

```php
if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, true ) ) {
	$failures[] = 'native_admin_surfaces_unavailable';
}
```

to:

```php
if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, false ) ) {
	$failures[] = 'native_admin_surfaces_unavailable';
}
```

- [x] **Step 2: Run the focused GREEN**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest::test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes
```

Expected: pass.

- [x] **Step 3: Run the full cutover controller test**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
```

Expected: pass. If a handoff test fails because it relied on the default, add an explicit `add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );` in that test setup rather than weakening the product default.

## Task 3: Verify Readiness Boundaries

- [x] **Step 1: Run the related readiness suite**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsCutoverControllerTest|NativePaymentsRuntimeArbiterTest|WooPaymentsPlatformConnectionServiceTest|WooPaymentsOperationalQueueServiceTest|WooPaymentsEventIngestorTest|NativeWooPaymentsGatewayTest'
```

Expected: pass. This confirms the admin readiness correction does not disturb platform, queue, event, runtime, or gateway gates.

- [x] **Step 2: Run static checks for touched PHP**

Run:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

Expected: syntax and PHPStan pass; PHPCS changed-file lint passes.

## Task 4: Local Preflight Evidence

- [x] **Step 1: Probe target preflight without an override**

Use the existing ignored local A5 state probe if available:

```bash
docker exec -i dcf21e0b058c wp --allow-root --user=1 eval-file /tmp/woopayments-a5e/a5-cutover-state.php
```

If the probe is not present in the container, copy `tools/woopayments-merge/a5-cutover-state.php` into a temporary container path and rerun. Expected: preflight includes `native_admin_surfaces_unavailable` unless a local mu-plugin/filter explicitly marks admin surfaces ready.

- [x] **Step 2: Scan fresh logs**

Check target `wp-content/debug.log` and focused Docker logs for fresh PHP fatal, warning, notice, deprecated, database error, uncaught exception, or 5xx signatures during the proof window. Expected: no new source-backed diagnostics from this change.

## Task 5: Changelog, Docs, Review, and Commit

- [x] **Step 1: Add the changelog**

Create `plugins/woocommerce/changelog/fix-native-payments-admin-readiness-reground`:

```text
Significance: patch
Type: fix

Keep native WooPayments admin-surface readiness fail-closed until parity gates explicitly pass.
```

- [x] **Step 2: Update session docs**

Update `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md` with A4ac evidence, including the reason A5d was corrected and the fact that A4/N12 parity remains open.

- [x] **Step 3: Run final repository gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
```

Expected: pass, with only the known existing broad JS ignored-file warnings from branch lint if they still appear.

- [x] **Step 4: Review and commit**

Ask at least one independent reviewer to validate the correction. If no blocking findings remain, commit product/test change and changelog as one or two conventional commits depending on staging boundaries. Do not force-add scratchpad or ignored harness files. Do not push.

## Self-Review

This plan directly covers the source-backed contradiction between N12/A4ab and A5d. It does not re-litigate A4ab's value, does not remove A5e handoff proof, does not touch WPCOM, and does not start a broader settings/dashboard rewrite inside the corrective slice. The explicit readiness filter remains available for controlled local gates; the default production posture returns to fail-closed until the full N12 parity gate is complete.
