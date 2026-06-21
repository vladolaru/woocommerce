# Core Native Payments A1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development
> (recommended) or $executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the A1 internal payments skeleton and a true same-store read-only
shadow log for the WooPayments merge.

**Architecture:** Add internal-only value objects and services under
`plugins/woocommerce/src/Internal/Payments/`, with WooPayments-specific code kept
under `Providers/WooPayments/`. A1 shadow mode records a WooPayments-only
same-store projection baseline beside the active plugin without mutating orders;
later A2/A3 stages replace the placeholder projection with lifecycle and
processing logic.

**Tech Stack:** WooCommerce PHP `src/Internal/`, `RuntimeContainer` DI,
`WC_Unit_Test_Case`, WP/WC CRUD order APIs, WooCommerce logger, `wp-env` PHPUnit,
and `tools/woopayments-merge/verify.sh`.

---

## File Structure

- Create: `plugins/woocommerce/src/Internal/Payments/OrderPaymentStore.php`
    - Owns Bucket-E payment meta key constants, HPOS-safe order/refund metadata
    projection, and the shared WooPayments processing lock transient.
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentContext.php`
    - Internal neutral context for classic/Blocks payment processing inputs.
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentOutcome.php`
    - Internal neutral outcome model with non-card states.
- Create: `plugins/woocommerce/src/Internal/Payments/ProviderContract.php`
    - Internal provider metadata seam for identity and capability information.
- Create: `plugins/woocommerce/src/Internal/Payments/CapabilityManifest.php`
    - Capability set used by the generic layer to avoid card-only assumptions.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php`
    - First-party provider metadata skeleton, intentionally non-mutating in A1.
- Create: `plugins/woocommerce/src/Internal/Payments/Shadow/PaymentSurfaceDiffer.php`
    - Pure recursive differ for shadow projections.
- Create: `plugins/woocommerce/src/Internal/Payments/Shadow/ShadowComparison.php`
    - Value object for one shadow comparison record.
- Create: `plugins/woocommerce/src/Internal/Payments/Shadow/NativePaymentsShadowMode.php`
    - Feature-flagged, plugin-mode-only read-only hook runner and logger.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register `NativePaymentsShadowMode` via DI with `->register()`.
- Create tests under `plugins/woocommerce/tests/php/src/Internal/Payments/`.
- Create changelog: `plugins/woocommerce/changelog/add-native-payments-a1-shadow-scaffold`.
- Update logs:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`
  and `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`.

## Shadow Choice

A1 chooses **true same-store shadow output**. The active WooPayments plugin owns
runtime behavior; native reads WooPayments orders' resulting order/refund surface
and logs a comparison record outside the order. The initial subject is explicitly
an A1 projection baseline from `OrderPaymentStore`, not independent native
processing. This proves the skeleton, recording path, and diffing shape without
pretending A1 can shadow lifecycle or payment processing that does not exist
until A2/A3.

Cross-store `parity-diff.sh` remains useful, but it is not the A1 shadow gate.

## Task 1: Order Payment Store

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/OrderPaymentStoreTest.php`
- Create: `plugins/woocommerce/src/Internal/Payments/OrderPaymentStore.php`

- [ ] **Step 1: Write failing tests for preserved meta constants and HPOS-safe projection**

```php
/**
 * @testdox Should expose preserved WooPayments order meta keys.
 */
public function test_exposes_preserved_meta_keys(): void {
	$keys = OrderPaymentStore::get_payment_meta_keys();

	$this->assertContains( '_intent_id', $keys );
	$this->assertContains( '_payment_method_id', $keys );
	$this->assertContains( '_charge_id', $keys );
	$this->assertContains( '_intention_status', $keys );
	$this->assertContains( '_stripe_customer_id', $keys );
	$this->assertContains( '_stripe_mandate_id', $keys );
	$this->assertContains( 'is_woopay', $keys );
	$this->assertContains( 'last4', $keys );
	$this->assertContains( '_card_brand', $keys );
}

/**
 * @testdox Should read only payment-relevant order meta into a stable projection.
 */
public function test_reads_stable_payment_surface(): void {
	$order = wc_create_order();
	$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
	$order->set_transaction_id( 'txn_123' );
	$order->update_meta_data( OrderPaymentStore::INTENT_ID_META_KEY, 'pi_123' );
	$order->update_meta_data( OrderPaymentStore::CHARGE_ID_META_KEY, 'ch_123' );
	$order->update_meta_data( 'unrelated_key', 'ignore-me' );
	$order->save();

	$surface = $this->sut->read_payment_surface( $order );

	$this->assertSame( OrderPaymentStore::GATEWAY_ID, $surface['payment_method'] );
	$this->assertSame( 'txn_123', $surface['transaction_id'] );
	$this->assertSame( array( 'pi_123' ), $surface['meta'][ OrderPaymentStore::INTENT_ID_META_KEY ] );
	$this->assertSame( array( 'ch_123' ), $surface['meta'][ OrderPaymentStore::CHARGE_ID_META_KEY ] );
	$this->assertArrayNotHasKey( 'unrelated_key', $surface['meta'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter OrderPaymentStoreTest
```

Expected: FAIL because `OrderPaymentStore` does not exist.

- [ ] **Step 3: Implement `OrderPaymentStore`**

Implement constants for the preserved key strings, `get_payment_meta_keys()`,
`read_payment_surface( WC_Abstract_Order $order ): array`,
`is_order_payment_locked()`, `lock_order_payment()`, and
`unlock_order_payment()`. Use `$order->get_meta_data()`,
`update_meta_data()`, `save_meta_data()`, `get_transient()`,
`set_transient()`, and `delete_transient()` only.

- [ ] **Step 4: Add failing tests for the shared processing lock**

```php
/**
 * @testdox Should use the WooPayments processing transient for order payment locks.
 */
public function test_uses_woopayments_processing_transient_for_locks(): void {
	$order = wc_create_order();

	$this->assertFalse( $this->sut->is_order_payment_locked( $order, 'pi_123' ) );

	$this->sut->lock_order_payment( $order, 'pi_123' );

	$this->assertSame( 'pi_123', get_transient( 'wcpay_processing_intent_' . $order->get_id() ) );
	$this->assertTrue( $this->sut->is_order_payment_locked( $order, 'pi_123' ) );
	$this->assertFalse( $this->sut->is_order_payment_locked( $order, 'pi_456' ) );

	$this->sut->unlock_order_payment( $order );

	$this->assertFalse( get_transient( 'wcpay_processing_intent_' . $order->get_id() ) );
}
```

- [ ] **Step 5: Run and pass the focused test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter OrderPaymentStoreTest
```

Expected: PASS.

## Task 2: Context, Outcome, Capability Manifest, Provider Skeleton

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentContextTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentOutcomeTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/CapabilityManifestTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/WooPaymentsProviderTest.php`
- Create: source files listed in File Structure.

- [ ] **Step 1: Write failing tests for neutral DTO behavior**

Test `PaymentContext` stores a `WC_Order`, gateway ID, payment method ID,
payment data, and provider data without Stripe-specific field names. Test
`PaymentOutcome` supports `completed`, `authorized`, `pending_async`,
`requires_redirect`, `requires_customer_action`, `failed`, and
`no_external_payment`. Test `CapabilityManifest::supports()` returns false for
unknown capabilities and true for declared capabilities.

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter "PaymentContextTest|PaymentOutcomeTest|CapabilityManifestTest|WooPaymentsProviderTest"
```

Expected: FAIL because the classes do not exist.

- [ ] **Step 3: Implement minimal DTOs and provider skeleton**

`WooPaymentsProvider::get_id()` returns `woocommerce_payments`.
`WooPaymentsProvider::get_capability_manifest()` returns a manifest with the
known capability vocabulary but no money-moving capability enabled in A1.
`ProviderContract` intentionally exposes metadata only in A1; `charge()`,
`capture()`, `cancel()`, and `refund()` are added only when A2/A3 introduces real
call sites.

- [ ] **Step 4: Run and pass focused tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter "PaymentContextTest|PaymentOutcomeTest|CapabilityManifestTest|WooPaymentsProviderTest"
```

Expected: PASS.

## Task 3: Same-Store Shadow Mode

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Shadow/PaymentSurfaceDifferTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Shadow/NativePaymentsShadowModeTest.php`
- Create: shadow source files listed in File Structure.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`.

- [ ] **Step 1: Write failing tests for pure diffing**

`PaymentSurfaceDiffer::diff( $expected, $actual )` returns an empty array for
equal structures and reports changed nested keys for value/type differences.

- [ ] **Step 2: Write failing tests for shadow registration gating**

Tests should prove:

- Shadow hooks are not registered by default.
- Shadow hooks register only when
  `woocommerce_native_payments_shadow_mode_enabled` is true and the arbiter
  reports plugin ownership.
- Shadow hooks do not register when native owns the runtime.

- [ ] **Step 3: Write failing tests for read-only shadow records**

Create a WooPayments order with preserved meta, call
`record_shadow_for_order( $order, 'unit_test' )`, and assert:

- The comparison trigger is `unit_test`.
- Actual and baseline surfaces are equal at A1.
- Diff is empty.
- Order meta is unchanged before/after the call.
- The logger payload identifies `a1_projection_baseline` and
  `independent_native_computation: false`.
- Non-WooPayments orders are ignored.

- [ ] **Step 4: Run tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter "PaymentSurfaceDifferTest|NativePaymentsShadowModeTest"
```

Expected: FAIL because shadow classes do not exist.

- [ ] **Step 5: Implement shadow classes and hook registration**

`NativePaymentsShadowMode::register()` adds read-only hooks at priority 100 for
`woocommerce_payment_complete` and `woocommerce_order_refunded` only when shadow
mode is enabled and the plugin owns the runtime. The handlers ignore orders whose
payment method is not `woocommerce_payments` or a `woocommerce_payments_*` split
gateway. The logger source is `native-payments-shadow`. The default log record is
compact and includes `trigger`, `order_id`, `comparison_type`, `has_diff`, `diff`,
surface hashes, and elapsed milliseconds; full surfaces are logged only behind
the diagnostic full-surfaces filter.

- [ ] **Step 6: Wire registration in WooCommerce bootstrap**

Add:

```php
$container->get( Automattic\WooCommerce\Internal\Payments\Shadow\NativePaymentsShadowMode::class )->register();
```

to the `init_hooks()` register-method section.

- [ ] **Step 7: Run and pass focused tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter "PaymentSurfaceDifferTest|NativePaymentsShadowModeTest"
```

Expected: PASS.

## Task 4: Verification, Log, and Commit Gate

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-a1-shadow-scaffold`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

- [ ] **Step 1: Run all A1 PHP tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter "OrderPaymentStoreTest|PaymentContextTest|PaymentOutcomeTest|CapabilityManifestTest|WooPaymentsProviderTest|PaymentSurfaceDifferTest|NativePaymentsShadowModeTest|NativePaymentsRuntimeArbiterTest"
```

- [ ] **Step 2: Run PHP lint on changed PHP files only**

```bash
pnpm lint:php -- plugins/woocommerce/src/Internal/Payments/OrderPaymentStore.php plugins/woocommerce/src/Internal/Payments/PaymentContext.php plugins/woocommerce/src/Internal/Payments/PaymentOutcome.php plugins/woocommerce/src/Internal/Payments/ProviderContract.php plugins/woocommerce/src/Internal/Payments/CapabilityManifest.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php plugins/woocommerce/src/Internal/Payments/Shadow/PaymentSurfaceDiffer.php plugins/woocommerce/src/Internal/Payments/Shadow/ShadowComparison.php plugins/woocommerce/src/Internal/Payments/Shadow/NativePaymentsShadowMode.php plugins/woocommerce/tests/php/src/Internal/Payments/OrderPaymentStoreTest.php plugins/woocommerce/tests/php/src/Internal/Payments/PaymentContextTest.php plugins/woocommerce/tests/php/src/Internal/Payments/PaymentOutcomeTest.php plugins/woocommerce/tests/php/src/Internal/Payments/CapabilityManifestTest.php plugins/woocommerce/tests/php/src/Internal/Payments/WooPaymentsProviderTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Shadow/PaymentSurfaceDifferTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Shadow/NativePaymentsShadowModeTest.php
```

- [ ] **Step 3: Run PHPStan on changed source files**

```bash
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/OrderPaymentStore.php src/Internal/Payments/PaymentContext.php src/Internal/Payments/PaymentOutcome.php src/Internal/Payments/ProviderContract.php src/Internal/Payments/CapabilityManifest.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php src/Internal/Payments/Shadow/PaymentSurfaceDiffer.php src/Internal/Payments/Shadow/ShadowComparison.php src/Internal/Payments/Shadow/NativePaymentsShadowMode.php --memory-limit=2G
```

- [ ] **Step 4: Run harness gates**

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: PASS or INCOMPLETE only if documented local preconditions are missing;
do not record A1 green on a red gate.

- [ ] **Step 5: Run adversarial reviews**

Dispatch review agents for:

- API/BC contract: preserved keys, IDs, and shadow logging do not alter runtime.
- Performance: A1 adds no order-query loop or autoloaded option.
- Architecture/simplification: A1 is internal-first and does not overclaim
  lifecycle or processing parity.

- [ ] **Step 6: Update staging log and implementation log**

Record A1 gate evidence with these labels:

- `AUTOMATED-DETERMINISTIC`: PHP unit tests, PHP lint, PHPStan, A0 self-check
  harness gates.
- `RUNBOOK-JUDGMENT`: no browser/admin/bundle/broad-perf runbook claimed for A1
  because A1 does not add shopper/admin assets or money-moving behavior.

- [ ] **Step 7: Commit**

Commit one logical A1 change with a Conventional Commit message after tests,
lint, PHPStan, changelog, and harness evidence are recorded.

## Self-Review

- Spec coverage: A1 skeleton files, same-store shadow choice, fail-closed gate
  labels, Bucket-E and Tracks constraints are covered. A2/A3 lifecycle and
  processing parity are explicitly out of A1.
- Placeholder scan: no `TBD`/`TODO`/`later` placeholders.
- Type consistency: `PaymentContext`, `PaymentOutcome`, `ProviderContract`, and
  `CapabilityManifest` names match the design spec and implementation tasks.
