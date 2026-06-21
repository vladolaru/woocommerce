---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 06:07
tool: writing-plans
target: H27 N7a payout and converted-currency coverage
reconciles:
  - analysis-h27-n7a-payout-multicurrency-coverage.md
  - analysis-h26-n7a-provider-dispute-e2e.md
  - analysis-h25-n7a-dispute-payout-multicurrency.md
  - staging-log.md
  - implementation-log.md
status: draft
---

# H27 N7a Payout And Converted-Currency Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden N7a money-safety coverage for payout evidence and true converted-currency payments without turning unsupported or noisy local evidence into false PASS claims.

**Architecture:** Keep the first changes in the ignored local harness: make converted-currency checkout a real selected-currency runtime flow, make payout verification explicit about what Stripe/manual payouts can and cannot prove, and widen the reconciler only for paths it can verify against raw provider source. Add WooCommerce Core product code only after the converted-currency fixture proves a native runtime gap against the reference.

**Tech Stack:** WooCommerce Core PHP, WooPayments extension read-only reference, ignored `tools/woopayments-merge` Bash/PHP/Python harness, WP-CLI in the two local stores, read-only Stripe CLI raw-source checks.

---

## File Map

- Modify `tools/woopayments-merge/flow-drive.sh`: add `--currency=<ISO>` for deterministic charge/dispute paths and pass it into the deterministic reference/native charge drivers.
- Modify `tools/woopayments-merge/flow-drive-deterministic-charge.php`: before Test Lab checkout simulation, switch the selected currency for the Test Lab customer through `WC_Payments_Multi_Currency()->update_selected_currency()` and assert `get_woocommerce_currency()` matches before order creation.
- Modify `tools/woopayments-merge/flow-drive-native-charge.php`: switch selected currency through native multi-currency persistence before `wc_create_order()`, assert `get_woocommerce_currency()` matches, and restore the prior local user meta after the order is created/paid.
- Modify `tools/woopayments-merge/financial-reconcile.sh`: include `store_currency` and enough WC multi-currency state in the WC JSON for strict converted-order assertions.
- Modify `tools/woopayments-merge/financial-reconcile-normalize.py`: fail converted orders missing order/default currency meta, intent currency parity, or settlement exchange-rate meta when provider exchange-rate source exists; keep default-currency orders on the existing absence-pass path.
- Modify `tools/woopayments-merge/tests/financial-reconcile-fixtures.sh`: add default-currency, valid converted-currency, missing-order-meta, and missing-provider-rate-meta fixture cases.
- Add `tools/woopayments-merge/converted-currency-gate.sh`: configure a test currency on reference and target, drive deterministic converted charges, reconcile both orders, and print progress/evidence.
- Add `tools/woopayments-merge/payout-evidence-gate.sh`: drive instant-balance order evidence, create a provider payout when supported, retrieve the payout raw source, and explicitly classify manual-payout per-order membership as BLOCKED when Stripe does not expose linkage.
- Modify `tools/woopayments-merge/HARNESS.md`: document the converted-currency and payout evidence gates, including the manual-payout limitation and the performance measurement caveat.
- Conditional product files only if the true target converted order exposes the native settlement-rate gap: likely modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`, `WooPaymentsCheckoutAjaxController.php`, or a small provider-owned helper; add focused PHPUnit in the matching `WooPaymentsProviderGatewayAdapterTest.php`/`WooPaymentsCheckoutAjaxControllerTest.php`; add a WooCommerce changelog entry.

## Task 1: Make Deterministic Converted-Currency Checkout Real

- [ ] **Step 1: Extend `flow-drive.sh` argument parsing**

Add `CURRENCY=""`, parse both `--currency=GBP` and `--currency GBP`, and pass the currency as the fifth eval-file argument for deterministic charge/dispute paths:

```bash
--currency=*) CURRENCY="${1#--currency=}"; shift ;;
--currency) CURRENCY="$2"; shift 2 ;;
```

Reference deterministic call should become:

```bash
raw="$($WP eval-file - "$SKU" "$QUANTITY" "$TYPE" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-deterministic-charge.php" 2>&1)"
```

Native deterministic call should become:

```bash
raw="$($WP eval-file - "$SKU" "$QUANTITY" "$PAYMENT_METHOD" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-native-charge.php" 2>&1)"
```

- [ ] **Step 2: Switch currency in the reference deterministic driver before simulation**

In `flow-drive-deterministic-charge.php`, read `$currency = strtoupper( trim( (string) ( $args[4] ?? '' ) ) );`. After the deterministic customer is resolved and before creating `Checkout_Simulator`, switch as that customer and assert the WooCommerce currency:

```php
if ( '' !== $currency ) {
	$original_user = get_current_user_id();
	wp_set_current_user( $customer_id );
	try {
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			WP_CLI::error( 'WooPayments multi-currency runtime is not loaded.' );
		}
		WC_Payments_Multi_Currency()->update_selected_currency( $currency, true );
		$selected = strtoupper( get_woocommerce_currency() );
		if ( $selected !== $currency ) {
			WP_CLI::error( "Selected currency {$currency} did not become the WooCommerce order currency; got {$selected}." );
		}
	} finally {
		wp_set_current_user( $original_user );
	}
}
```

After simulation, append `currency_requested`, `order_currency`, `order_exchange_rate`, `order_default_currency`, and `stripe_exchange_rate` to the emitted result from the created order.

- [ ] **Step 3: Switch currency in the native deterministic driver before order creation**

In `flow-drive-native-charge.php`, read the same optional currency argument. If present, store the prior `wcpay_currency` user meta for the current WP-CLI user, update selection through native multi-currency persistence, assert `get_woocommerce_currency()` before `wc_create_order()`, and restore the prior meta in the final cleanup after payment processing:

```php
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;

$currency = strtoupper( trim( (string) ( $args[4] ?? '' ) ) );
$currency_user_id = get_current_user_id();
$previous_currency_meta = $currency_user_id ? get_user_meta( $currency_user_id, 'wcpay_currency', true ) : null;

if ( '' !== $currency ) {
	$service = wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )->create_selected_currency_persistence_service();
	if ( ! $service->update_selected_currency( $currency, true ) ) {
		WP_CLI::error( "Native multi-currency could not select {$currency}." );
	}
	$selected = strtoupper( get_woocommerce_currency() );
	if ( $selected !== $currency ) {
		WP_CLI::error( "Selected currency {$currency} did not become the WooCommerce order currency; got {$selected}." );
	}
}
```

When emitting JSON, include `currency_requested`, `order_currency`, `order_exchange_rate`, `order_default_currency`, and `stripe_exchange_rate`. In cleanup, restore or delete the prior user meta without changing the order.

- [ ] **Step 4: Run syntax and direct smoke gates**

Run:

```bash
bash -n tools/woopayments-merge/flow-drive.sh
php -l tools/woopayments-merge/flow-drive-deterministic-charge.php
php -l tools/woopayments-merge/flow-drive-native-charge.php
WP='docker exec -i wcpay_wp_default wp --allow-root' bash tools/woopayments-merge/flow-drive.sh charge --deterministic --sku=test-lab-beaker-001 --quantity=2 --type=success --currency=GBP
WP='docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1' bash tools/woopayments-merge/flow-drive.sh charge --deterministic --native --sku=test-lab-beaker-001 --quantity=2 --type=success --currency=GBP
```

Expected: both drivers either produce real orders with `order_currency=GBP` and order/default exchange-rate meta, or fail closed with a source-backed runtime error. A USD order after `--currency=GBP` is a fixture failure, not a product parity result.

## Task 2: Widen The Converted-Currency Financial Oracle

- [ ] **Step 1: Add WC-side store currency to the reconciler payload**

In `financial-reconcile.sh`, add `'store_currency' => strtoupper( (string) get_option( 'woocommerce_currency' ) )` to the order JSON next to `currency`.

- [ ] **Step 2: Make converted-order assertions strict**

In `financial-reconcile-normalize.py`, update `compare_multi_currency()` with this contract:

```python
wc_currency = lower(wc.get("currency"))
store_currency = lower(wc.get("store_currency"))
intent_currency = lower(wc.get("intent_currency"))
charge_currency = lower(charge.get("currency")) if charge else ""
is_converted_order = bool(wc_currency and store_currency and wc_currency != store_currency)

if is_converted_order:
    if not is_present(multi.get("order_exchange_rate")):
        verdict.fail("converted order is missing _wcpay_multi_currency_order_exchange_rate")
    if lower(multi.get("order_default_currency")) != store_currency:
        verdict.fail("converted order default-currency meta mismatch")
    if intent_currency and charge_currency and intent_currency != charge_currency:
        verdict.fail("intent currency does not match provider charge currency")
```

Keep the existing default-currency behavior: if WC and provider exchange rates are both absent, pass. For converted orders, if provider `balance.exchange_rate` exists, require WC `_wcpay_multi_currency_stripe_exchange_rate` and compare it within the existing tolerance. If provider exchange rate is absent on a converted order, print a clear `ok` or `BLOCKED` depending on whether the provider balance transaction is present; do not fabricate a WC requirement without source evidence.

- [ ] **Step 3: Add fixture coverage**

Update `tools/woopayments-merge/tests/financial-reconcile-fixtures.sh` with four cases: default USD order still passes with absent exchange rate, GBP order with order meta and provider exchange rate passes, GBP order missing `_wcpay_multi_currency_order_exchange_rate` fails, and GBP order with provider `balance.exchange_rate` but missing `_wcpay_multi_currency_stripe_exchange_rate` fails.

- [ ] **Step 4: Run fixture gates**

Run:

```bash
bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh
python3 -m py_compile tools/woopayments-merge/financial-reconcile-normalize.py
bash -n tools/woopayments-merge/financial-reconcile.sh
```

Expected: fixture gates pass, and the newly strict cases fail before the implementation and pass after.

## Task 3: Add Live Converted-Currency And Honest Payout Gates

- [ ] **Step 1: Add `converted-currency-gate.sh`**

The gate should accept `--ref "<WP>" --target "<WP>" --currency GBP`. It should configure the currency on both stores using existing WooPayments option names, drive deterministic converted charges through `flow-drive.sh`, assert both emitted orders have `order_currency=GBP`, and run `financial-reconcile.sh` against both order IDs. It should print progress as it runs so long Stripe/WP-CLI calls are visible.

Use this exact option setup for the local fixture currency:

```php
update_option( 'wcpay_multi_currency_enabled_currencies', array( $currency ) );
update_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency ), 'manual' );
update_option( 'wcpay_multi_currency_manual_rate_' . strtolower( $currency ), '0.80' );
update_option( 'wcpay_multi_currency_price_rounding_' . strtolower( $currency ), '0' );
update_option( 'wcpay_multi_currency_price_charm_' . strtolower( $currency ), '0' );
```

Do not patch order meta. If order currency remains the store default, the gate fails.

- [ ] **Step 2: Add `payout-evidence-gate.sh`**

The gate should accept `--wp "<WP>" --label reference|target`. It should drive one deterministic instant-balance charge, create a payout through Dev Tools when supported, retrieve the payout object with Stripe CLI, retrieve the order charge/balance transaction via `financial-reconcile.sh`, and then attempt provider membership evidence. If Stripe rejects manual payout membership lookup with the known manual-payout limitation, exit `3` with `BLOCKED: manual Stripe test-mode payouts do not expose per-order payout membership through this raw source`. If membership becomes source-readable, compare member charge IDs to the order charge IDs.

- [ ] **Step 3: Document the gate contracts**

Update `HARNESS.md` so the converted-currency gate is a money-path e2e gate and the payout gate is an honesty gate that may block. Add the measurement caveat: local performance timings are smoke/large-delta signals only when variability is high, not precise proof.

- [ ] **Step 4: Run syntax gates and live gates**

Run:

```bash
bash -n tools/woopayments-merge/converted-currency-gate.sh
bash -n tools/woopayments-merge/payout-evidence-gate.sh
bash tools/woopayments-merge/converted-currency-gate.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1' --currency GBP
bash tools/woopayments-merge/payout-evidence-gate.sh --wp 'docker exec -i wcpay_wp_default wp --allow-root' --label reference
```

Expected: converted-currency passes or exposes a real product gap. Payout either proves source-linked membership or blocks honestly; do not convert the manual-payout limitation into a broad payout PASS.

## Task 4: Conditional Native Settlement-Rate Product Fix

- [ ] **Step 1: Only enter this task if the true target converted order fails settlement-rate parity**

If the converted-currency gate proves reference writes `_wcpay_multi_currency_stripe_exchange_rate` and native does not while the provider balance transaction has `exchange_rate`, add a RED PHPUnit test against the native checkout normalization path. The test should create a GBP order with store currency USD, account default currency USD, a charge balance transaction `exchange_rate`, and assert the normalized lifecycle meta includes `_wcpay_multi_currency_stripe_exchange_rate`.

- [ ] **Step 2: Implement a provider-owned exchange-rate helper**

Add the smallest provider-level helper needed to mirror reference semantics: only write the settlement rate when store default equals account default and order currency differs from account default. For non-zero-decimal currency pairs, the Stripe rate can be stored as-is; if zero-decimal support is needed, copy the reference `interpret_string_exchange_rate()` semantics into a native provider helper with focused tests.

- [ ] **Step 3: Wire checkout and AJAX/native paths**

Wire the helper into the native checkout normalization path that already reads charge balance transactions, so both shortcode/AJAX and Store API/native gateway outcomes can persist the meta. Do not move this into generic multi-currency; it is provider settlement evidence from WooPayments/Stripe.

- [ ] **Step 4: Run product gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsProviderGatewayAdapterTest|WooPaymentsCheckoutAjaxControllerTest|MultiCurrencyFrontendPricesControllerTest'
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxController.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

Expected: focused tests, PHPStan, lint, and diff checks pass. Add a WooCommerce changelog entry only if this task modifies tracked product code.

## Task 5: Review, Verification, Logs, And Commit

- [ ] **Step 1: Run focused subagent reviews**

Dispatch one harness/reliability review and one architecture/API review. Ask reviewers to check that converted-currency selection is not meta-patching, payout limitations are not hidden, the reconciler only fails on source-backed requirements, and any product settlement-rate helper stays provider-level rather than coupling generic multi-currency to WooPayments.

- [ ] **Step 2: Run final gates**

Run:

```bash
bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh
bash tools/woopayments-merge/converted-currency-gate.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1' --currency GBP
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

If product code changed, also rerun the focused PHPUnit/PHPStan/lint gates from Task 4 and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`. Treat performance numbers as smoke only unless a large, repeatable delta appears; record variability rather than pretending precision.

- [ ] **Step 3: Commit only tracked product changes**

If Task 4 produced tracked product changes, commit source/tests/changelog as one logical WooCommerce Core commit. Ignored harness and scratchpad files remain local unless explicitly requested. Do not push.

- [ ] **Step 4: Update session docs**

Append H27 evidence to `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md`. Record whether payout is source-linked, provider-only, or blocked by manual-payout raw-source limitations. Record converted-currency reference/target order IDs, Stripe charge IDs, reconciler verdicts, and any tracked git range.

## Self-Review

- Spec coverage: N7a payout and converted-currency gaps are both addressed at the verification-contract level. Product code is conditional and provider-scoped.
- Placeholder scan: no TBD/TODO placeholders remain. Payout limitation is intentionally represented as a possible BLOCKED gate, not a placeholder.
- Type consistency: script names and argument names match the file map and commands. Currency flag is consistently `--currency`.
