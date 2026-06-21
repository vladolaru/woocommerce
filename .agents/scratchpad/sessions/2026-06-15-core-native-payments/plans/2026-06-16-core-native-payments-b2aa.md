# Native Multi-Currency Refund Meta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist WooPayments-compatible multi-currency refund meta when the core runtime owns multi-currency.

**Architecture:** Reuse the existing `MultiCurrencyPriceProjectionService::get_refund_meta_candidates()` projection and extend `MultiCurrencyFrontendPricesController`, which already owns order multi-currency meta writes. Register `woocommerce_order_refunded` only when core owns multi-currency and the request context allows frontend price hooks, then copy projected order meta onto the refund without mutating default-currency refunds.

**Tech Stack:** WooCommerce PHP hooks, WC order/refund CRUD, native multi-currency projection services, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
    - Add a runtime-gated `woocommerce_order_refunded` hook at priority 99 with two accepted args.
    - Add `add_refund_meta( $order_id, $refund_id ): void`.
    - Fetch order/refund via `wc_get_order()`, require `\WC_Order` and `\WC_Order_Refund`, get projected candidates, update refund meta, and save refund meta data.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php`
    - Add hook cleanup for `woocommerce_order_refunded`.
    - Assert hook registration only when core owns runtime.
    - Assert refund meta is copied for non-default orders, including optional Stripe exchange-rate meta.
    - Assert default-currency orders or invalid IDs do not mutate refunds.
    - Extend the deterministic projection test double with `get_refund_meta_candidates()`.
- Add `plugins/woocommerce/changelog/add-native-payments-b2aa-multi-currency-refund-meta`
    - Patch changelog entry.

## Task 1: Refund Meta RED Test

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php`

- [ ] **Step 1: Add hook cleanup**

Add `woocommerce_order_refunded` to the `$hooks` array so tests do not leak the new hook:

```php
'woocommerce_order_refunded',
```

- [ ] **Step 2: Assert the refund hook registration**

Extend `test_registers_frontend_price_hooks_when_core_owns_runtime()`:

```php
$this->assertSame( 99, has_action( 'woocommerce_order_refunded', array( $sut, 'add_refund_meta' ) ) );
```

Extend `test_does_not_register_when_plugin_multi_currency_owns_runtime()`:

```php
$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $sut, 'add_refund_meta' ) ) );
```

Extend `test_does_not_register_when_no_multi_currency_runtime_owns_site()`:

```php
$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $sut, 'add_refund_meta' ) ) );
```

Extend `test_does_not_register_frontend_price_hooks_in_blocked_request_context()`:

```php
$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $sut, 'add_refund_meta' ) ) );
```

- [ ] **Step 3: Add refund meta behavior tests**

Add a test for copying projected refund meta:

```php
/**
 * @testdox Should persist projected multi-currency refund meta.
 */
public function test_persists_projected_refund_meta(): void {
    $sut   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
    $order = wc_create_order();
    $order->set_currency( 'GBP' );
    $order->set_total( 100 );
    $order->save();

    $refund = wc_create_refund(
        array(
            'order_id' => $order->get_id(),
            'amount'   => 10,
            'reason'   => 'Multi-currency refund meta test',
        )
    );

    $this->assertInstanceOf( \WC_Order_Refund::class, $refund );

    $sut->add_refund_meta( $order->get_id(), $refund->get_id() );

    $refund = wc_get_order( $refund->get_id() );

    $this->assertInstanceOf( \WC_Order_Refund::class, $refund );
    $this->assertSame( '0.82', $refund->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
    $this->assertSame( 'USD', $refund->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
    $this->assertSame( '1.25', $refund->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
}
```

Add a test for skip paths:

```php
/**
 * @testdox Should skip refund meta when no projected candidates exist.
 */
public function test_skips_refund_meta_when_no_projected_candidates_exist(): void {
    $sut   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
    $order = wc_create_order();
    $order->set_currency( 'USD' );
    $order->set_total( 100 );
    $order->save();

    $refund = wc_create_refund(
        array(
            'order_id' => $order->get_id(),
            'amount'   => 10,
            'reason'   => 'Default currency refund meta test',
        )
    );

    $this->assertInstanceOf( \WC_Order_Refund::class, $refund );

    $sut->add_refund_meta( $order->get_id(), $refund->get_id() );
    $sut->add_refund_meta( 0, $refund->get_id() );
    $sut->add_refund_meta( $order->get_id(), 0 );

    $refund = wc_get_order( $refund->get_id() );

    $this->assertInstanceOf( \WC_Order_Refund::class, $refund );
    $this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
    $this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_order_default_currency', true ) );
    $this->assertSame( '', $refund->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
}
```

- [ ] **Step 4: Extend the projection test double**

Add this method to the anonymous `MultiCurrencyPriceProjectionService` test double:

```php
/**
 * Project refund meta candidates for a selected-currency order.
 *
 * @param \WC_Order $order Order.
 * @return array<string,float|string>
 */
public function get_refund_meta_candidates( \WC_Order $order ): array {
    if ( 'GBP' !== strtoupper( $order->get_currency() ) ) {
        return array();
    }

    return array(
        '_wcpay_multi_currency_order_exchange_rate'    => 0.82,
        '_wcpay_multi_currency_order_default_currency' => 'USD',
        '_wcpay_multi_currency_stripe_exchange_rate'   => 1.25,
    );
}
```

- [ ] **Step 5: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendPricesControllerTest
```

Expected before production code:

```text
FAILURES!
Failed asserting that false is identical to 99.
Error: Call to undefined method MultiCurrencyFrontendPricesController::add_refund_meta()
```

## Task 2: Runtime Hook And Refund Writer

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`

- [ ] **Step 1: Register the refund hook**

Inside `register()`, after the existing `woocommerce_new_order` hook, add:

```php
$this->add_action_once( 'woocommerce_order_refunded', array( $this, 'add_refund_meta' ), 99, 2 );
```

The hook must remain behind the existing runtime and request-context gate:

```php
if ( ! $this->arbiter->should_core_register() || ! $this->get_request_context()->should_register_frontend_hooks() ) {
    return;
}
```

- [ ] **Step 2: Persist projected refund meta**

Add this public method near `add_order_meta()`:

```php
/**
 * Persist projected multi-currency refund meta.
 *
 * @param mixed $order_id  Order ID.
 * @param mixed $refund_id Refund ID.
 */
public function add_refund_meta( $order_id, $refund_id ): void {
    $order  = wc_get_order( absint( $order_id ) );
    $refund = wc_get_order( absint( $refund_id ) );

    if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
        return;
    }

    $meta_candidates = $this->get_price_projection_service()->get_refund_meta_candidates( $order );
    if ( empty( $meta_candidates ) ) {
        return;
    }

    foreach ( $meta_candidates as $meta_key => $meta_value ) {
        $refund->update_meta_data( $meta_key, (string) $meta_value );
    }

    $refund->save_meta_data();
}
```

- [ ] **Step 3: Verify GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendPricesControllerTest
```

Expected:

```text
OK
```

## Task 3: Changelog And Verification

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2aa-multi-currency-refund-meta`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix
Comment: Preserve native multi-currency refund metadata.
```

- [ ] **Step 2: Run focused regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendPricesControllerTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected:

```text
OK
```

- [ ] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php --configuration "$TMPDIR/phpstan-b2aa.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2aa.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected:

```text
PASS
```

- [ ] **Step 4: Commit source and changelog separately**

Commit source/test first:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php
git commit -m "fix(payments): preserve multi-currency refund meta"
```

Commit changelog second:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2aa-multi-currency-refund-meta
git commit -m "chore(payments): add multi-currency refund changelog"
```

## Self-Review

- Scope is one persisted-data parity gap: refund meta copied from multi-currency orders to refunds.
- The plan does not touch WPCOM, WooPayments client source, React assets, Subscriptions compatibility, or provider rate wiring.
- RED coverage fails before production code because the hook and handler do not exist.
- GREEN coverage verifies runtime gating, mutation behavior, and skip behavior through WC CRUD.
- The existing projection service remains the source of truth for which meta keys to copy.
