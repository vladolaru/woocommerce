---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 22:46
status: draft
reconciles:
  - staging-log.md
  - spec-conformance-baseline.md
  - supervisor-prompt-2026-06-17-1311.md
---

# A5b Cutover Financial Migration Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Native WooPayments cutover must preserve and rebind the canceled-authorization fee remediation queue so plugin-scheduled work is not orphaned and cutover schedules the migration when affected orders exist.

**Architecture:** Add a Core-owned WooPayments remediation service that registers the preserved `wcpay_remediate_canceled_authorization_fees`, `wcpay_remediate_canceled_authorization_fees_dry_run`, and `wcpay_check_affected_auth_fee_orders` hooks only when the native runtime owns WooPayments. The cutover controller injects the service and, after a successful plugin deactivation, schedules the preserved remediation hook when affected orders exist and the migration is not complete. The service keeps the reference option keys and data effects but lives in Core, avoids generic payments abstractions, and remains removable in A6 as a one-shot transitional runner.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments`, Action Scheduler, WooCommerce order CRUD/HPOS/CPT SQL, Woo Admin order stats datastore when available.

---

## File Map

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationService.php`: native service for preserved remediation action hooks, affected-order detection, batch scheduling, batch processing, dry runs, and single-order remediation.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationServiceTest.php`: focused unit coverage for hook registration, scheduling, affected-order queries, and data remediation effects.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: inject the remediation service and call `ensure_scheduled()` after successful deactivation; optionally add a preflight failure only when affected orders exist but Action Scheduler is unavailable and no existing schedule can drain.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: prove cutover schedules the migration after successful deactivation, does not schedule when preflight fails, does not schedule on clean/completed stores, and blocks only when scheduling is impossible.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the remediation service hook setup beside other native WooPayments services.
- Add a WooCommerce Core changelog entry for the A5b source change.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`, `implementation-log.md`, and `spec-conformance-baseline.md` after verification.

## Task 1: Native Remediation Service RED/GREEN

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationService.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationServiceTest.php`

- [ ] **Step 1: Write RED coverage for native hook registration.**

Add tests that instantiate the service with a mocked `NativePaymentsRuntimeArbiter` and assert:

```php
$sut->register();
$this->assertSame( 10, has_action( 'wcpay_remediate_canceled_authorization_fees', array( $sut, 'process_batch' ) ) );
$this->assertSame( 10, has_action( 'wcpay_remediate_canceled_authorization_fees_dry_run', array( $sut, 'process_batch_dry_run' ) ) );
$this->assertSame( 10, has_action( 'wcpay_check_affected_auth_fee_orders', array( $sut, 'check_and_cache_affected_orders' ) ) );
```

The same test file must assert no hooks register while `should_native_register()` is false, to avoid duplicate callbacks while the standalone plugin still owns runtime.

- [ ] **Step 2: Write RED coverage for affected-order detection and scheduling.**

Create WooPayments orders with `_intention_status = canceled`, `date_created_gmt >= 2023-04-01`, status `refunded` or status `cancelled` plus `_wcpay_transaction_fee`, then assert `has_affected_orders()` is true. Assert a clean order, non-WooPayments order, non-canceled intent, and pre-bug-date order return false. Assert `ensure_scheduled()` preserves reference option keys:

```php
$this->assertSame( 'scheduled', $sut->ensure_scheduled() );
$this->assertSame( 'running', get_option( 'wcpay_fee_remediation_status' ) );
$this->assertTrue( as_has_scheduled_action( 'wcpay_remediate_canceled_authorization_fees', array(), 'woocommerce-payments' ) );
```

Also assert `ensure_scheduled()` returns `completed`, `already_scheduled`, or `not_needed` without duplicate actions for completed, already-running, and clean stores.

- [ ] **Step 3: Write RED coverage for order remediation data effects.**

Create a refunded canceled-authorization order with `_wcpay_transaction_fee`, `_wcpay_net`, `_wcpay_refund_id`, `_wcpay_refund_status`, and one WooPayments refund carrying `_wcpay_refund_id` plus one manual refund without that meta. After `remediate_order( $order )`, assert:

```php
$this->assertSame( 'cancelled', $order->get_status() );
$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
$this->assertSame( '', $order->get_meta( '_wcpay_refund_id', true ) );
$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
$this->assertFalse( (bool) wc_get_order( $wcpay_refund_id ) );
$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $manual_refund_id ) );
```

Assert the order note contains `Removed incorrect data from canceled authorization` and `No actual payment or refund occurred.`

- [ ] **Step 4: Implement the service.**

Implementation details:

- Constants must mirror the reference option/action names: `wcpay_fee_remediation_status`, `wcpay_fee_remediation_last_order_id`, `wcpay_fee_remediation_batch_size`, `wcpay_fee_remediation_stats`, `wcpay_fee_remediation_dry_run`, `wcpay_has_affected_auth_fee_orders`, `wcpay_remediate_canceled_authorization_fees`, `wcpay_remediate_canceled_authorization_fees_dry_run`, and `wcpay_check_affected_auth_fee_orders`.
- Use `NativePaymentsRuntimeArbiter` injection and register hooks only when native owns runtime.
- Use direct HPOS and CPT SQL for affected-order IDs, then hydrate only the bounded batch.
- Preserve reference batch sizing options and stats keys.
- Use Action Scheduler group `woocommerce-payments` so existing queued extension actions continue to drain after cutover.
- Delete only refunds that have `_wcpay_refund_id`; never delete manual refunds or merchant historical payment data outside the documented incorrect canceled-authorization artifacts.
- Log under source `wcpay-fee-remediation`.
- Do not import or call concrete standalone WooPayments plugin classes.

- [ ] **Step 5: Run focused service tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCanceledAuthorizationFeeRemediationServiceTest
```

Expected after implementation: PASS with all service tests green.

## Task 2: Cutover Scheduling Integration

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [ ] **Step 1: Add RED tests for post-deactivation scheduling.**

Extend the cutover controller test fixture with an injectable remediation service double. Assert successful soft disable and mandatory auto-deactivation call `ensure_scheduled()` exactly once after `deactivate_plugins()` reports the plugin inactive.

- [ ] **Step 2: Add RED tests for fail-closed scheduling availability.**

If affected orders exist and Action Scheduler is unavailable with no pending remediation action, `get_preflight_failures()` should include `financial_migrations_unavailable` and both soft and mandatory cutover must remain blocked. If the migration is already complete, already scheduled, or clean, no preflight failure is added.

- [ ] **Step 3: Implement controller integration.**

Add `WooPaymentsCanceledAuthorizationFeeRemediationService` as an optional `init()` dependency defaulted from the container. In `get_preflight_failures()`, append `financial_migrations_unavailable` only for the narrow impossible-to-schedule case. In `deactivate_woopayments_plugin()`, after the plugin active signals are removed, call `ensure_scheduled()` and continue returning success; scheduling failure should already have been caught by preflight.

- [ ] **Step 4: Run focused cutover tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
```

Expected: PASS, including existing A5a preflight tests.

## Task 3: Bootstrap, Changelog, and Local Runtime Probe

**Files:**
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Add: `plugins/woocommerce/changelog/*`

- [ ] **Step 1: Register the service in WooCommerce boot.**

Add the service to the WooPayments hook setup area so the preserved AS hooks are available when native owns runtime.

- [ ] **Step 2: Add a Core changelog entry.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog add
```

Use type `fix` and a concise description such as `Preserve WooPayments cutover financial remediation queue.`

- [ ] **Step 3: Run focused combined PHP tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsCanceledAuthorizationFeeRemediationServiceTest|WooPaymentsCutoverControllerTest|WooPaymentsOperationalQueueServiceTest|WooPaymentsEventIngestorTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [ ] **Step 4: Probe target runtime hook registration.**

Use WP-CLI on the target store with native runtime enabled and plugin inactive to assert the three preserved remediation hooks are registered. Also assert they are not duplicated while the standalone plugin owns runtime if the local environment can safely simulate plugin ownership without modifying WPCOM.

## Task 4: Review and Gate Closeout

**Files:**
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-agent-findings.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Dispatch review agents.**

Run at least:

- `reliability-reviewer`: migration idempotency, logging, fail-closed preflight, AS scheduling, and retry behavior.
- `architecture-reviewer` or `wp-architecture-reviewer`: native/runtime boundary, no standalone plugin concrete coupling, hook ownership, and A6 removability.
- `php-tests-reviewer` if the service tests become broad or fragile.

- [ ] **Step 2: Run static checks.**

Run:

```bash
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
composer exec -- phpcs -s -p src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationServiceTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check
```

- [ ] **Step 3: Run A5-relevant harness gates.**

Run at least:

```bash
bash tools/woopayments-merge/bc-drift-gate.sh
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo . --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments
tools/woopayments-merge/subscriptions-renewal-gate.sh preflight --ref "docker exec -i wcpay_wp_default wp --allow-root" --target "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1"
```

Run the full `tools/woopayments-merge/verify.sh` only if the target/reference stores are in a stable state for the full flow; otherwise record why the narrower A5 gates are the honest evidence for this slice.

- [ ] **Step 4: Commit source/tests and changelog separately if gates pass.**

Use one logical source/test commit and one changelog commit. Do not push.

---

## Self-Review

- Spec coverage: This plan covers the A5 financial migration ensure-applied and AS queue continuity requirement for `remediate-canceled-auth-fees`. It does not cover owner-token/connected-plugin registry readiness; a parallel explorer is source-verifying that separately before it becomes A5c or is marked already covered.
- Data safety: The service deletes only documented incorrect artifacts for canceled authorizations and preserves all merchant historical data outside those artifacts.
- Cutover safety: Hook registration is native-owned to avoid duplicate callbacks while the extension is active, but the hook names are preserved so extension-scheduled actions drain after deactivation.
- Verification: Tests cover RED/GREEN service behavior and controller integration; runtime probe covers hook registration in the target store; review agents cover migration reliability and architecture.
