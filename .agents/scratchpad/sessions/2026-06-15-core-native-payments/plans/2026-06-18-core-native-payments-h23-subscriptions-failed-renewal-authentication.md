---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 03:07
tool: writing-plans
target: H23 Bucket-C WC Subscriptions failed-renewal authentication parity
reconciles:
  - analysis-h23-subscriptions-failed-renewal-authentication.md
  - spec-conformance-baseline.md
status: complete
last_updated: 2026-06-18 03:57
---

# H23 Subscriptions Failed-Renewal Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Preserve the WooPayments WC Subscriptions off-session renewal SCA/authentication-required behavior in native Core: failed renewals requiring customer action fire the preserved WooPayments action, fail the renewal order, register the same customer/admin email IDs, and keep retry email suppression/customization behavior intact.

**Architecture:** Keep the generic payment service provider-neutral by exposing the already-computed checkout `PaymentOutcome` to trusted internal callers without changing `process_checkout()`'s return array. Keep WooPayments-specific hooks and subscription emails in WooPayments-owned Core classes under `Internal\Payments\Providers\WooPayments\Subscriptions`, registered from the native WooPayments gateway only when subscriptions are supported.

**Tech Stack:** WooCommerce Core PHP, `WC_Email`, WC Subscriptions hooks, PHPUnit via WooCommerce wp-env, ignored local harness for N7b evidence.

---

## File Map

- Modify `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php`: add a public internal method that performs the existing checkout charge/lifecycle flow and returns `PaymentOutcome`; make `process_checkout()` call it and preserve its existing return shape.
- Modify `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`: use the outcome-returning method for scheduled renewals, emit the preserved `woocommerce_woocommerce_payments_payment_requires_action` action when a scheduled renewal outcome requires customer action, fail the renewal order after the hook, and register subscription email classes through `woocommerce_email_classes`.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsFailedRenewalAuthenticationEmail.php`: namespaced Core-native customer email with id `failed_renewal_authentication`, preserved subject/heading, checkout payment URL, WCS notification suppression, and retry-rule filters.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsFailedAuthenticationRetryEmail.php`: namespaced Core-native admin email with id `failed_authentication_requested`, preserved retry-time placeholder behavior, and preview fallbacks.
- Create `plugins/woocommerce/templates/emails/woopayments-failed-renewal-authentication.php`, `plugins/woocommerce/templates/emails/plain/woopayments-failed-renewal-authentication.php`, `plugins/woocommerce/templates/emails/woopayments-failed-renewal-authentication-requested.php`, and `plugins/woocommerce/templates/emails/plain/woopayments-failed-renewal-authentication-requested.php`: Core-owned templates using text domain `woocommerce`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: add RED tests for email registration and failed-renewal requires-action side effects.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: add RED coverage for the outcome-returning checkout method preserving provider charge/lifecycle behavior.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/RecordingPaymentProcessingService.php`: let gateway tests inject a recorded `PaymentOutcome` without invoking the real processing service.
- Modify `tools/woopayments-merge/subscriptions-renewal-drive.php` only if needed to capture the new email IDs and failed-renewal action evidence more explicitly; keep harness changes honest and fail-closed.

## Task 1: Outcome-Returning Scheduled Renewal SCA Path

**Files:**
- Modify `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php`
- Modify `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/RecordingPaymentProcessingService.php`

- [x] **Step 1: Write RED tests for the generic outcome-returning method**

Add a test to `PaymentProcessingServiceTest` that calls the new method name:

```php
/**
 * @testdox Should return the checkout outcome while applying lifecycle changes.
 */
public function test_process_checkout_outcome_returns_outcome_after_lifecycle_application(): void {
	$order    = $this->create_woopayments_order( '15.00' );
	$outcome  = new PaymentOutcome(
		PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
		'pi_requires_action',
		'#wcpay-confirm-pi:1:secret:nonce',
		'pm_requires_action',
		'cus_requires_action',
		array(
			'meta' => array(
				'_charge_id'              => 'ch_requires_action',
				'_wcpay_intent_currency' => 'usd',
			),
		)
	);
	$provider = new RecordingProvider( $outcome );

	$result = $this->sut->process_checkout_outcome( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_requires_action' ), $provider );
	$order  = wc_get_order( $order->get_id() );

	$this->assertSame( $outcome, $result );
	$this->assertInstanceOf( WC_Order::class, $order );
	$this->assertSame( 'pending', $order->get_status() );
	$this->assertSame( 'pi_requires_action', $order->get_meta( '_intent_id', true ) );
	$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
	$this->assertSame( 'pm_requires_action', $order->get_meta( '_payment_method_id', true ) );
	$this->assertSame( 'cus_requires_action', $order->get_meta( '_stripe_customer_id', true ) );
	$this->assertSame( 'ch_requires_action', $order->get_meta( '_charge_id', true ) );
}
```

- [x] **Step 2: Verify RED**

Run: `pnpm test:php:env -- --filter PaymentProcessingServiceTest::test_process_checkout_outcome_returns_outcome_after_lifecycle_application`

Expected: FAIL because `PaymentProcessingService::process_checkout_outcome()` does not exist.

- [x] **Step 3: Implement the generic method without changing existing checkout contract**

Refactor `process_checkout()` so it delegates to a new public method:

```php
public function process_checkout( PaymentContext $context, ProviderContract $provider ): array {
	$outcome = $this->process_checkout_outcome( $context, $provider );
	return $this->format_checkout_result( $context, $context->get_order(), $outcome );
}

/**
 * Process checkout payment through a provider and return the neutral outcome.
 *
 * @since 11.0.0
 *
 * @param PaymentContext   $context  Payment context.
 * @param ProviderContract $provider Provider.
 * @return PaymentOutcome
 */
public function process_checkout_outcome( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
	/* Move the existing lock/charge/apply body here. If the lock cannot be claimed, return a failed PaymentOutcome with an empty redirect and an error message instead of an array. */
}
```

Preserve the exact idempotency derivation, zero-total provider bypass, exception policy, lifecycle application, and unlock behavior from the existing `process_checkout()` body.

- [x] **Step 4: Verify GREEN for generic method and existing checkout tests**

Run: `pnpm test:php:env -- --filter PaymentProcessingServiceTest`

Expected: PASS.

- [x] **Step 5: Write RED tests for scheduled renewal requires-action side effects**

Extend `RecordingPaymentProcessingService` with a configurable outcome:

```php
public PaymentOutcome $checkout_outcome;

public function __construct() {
	$this->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_recorded' );
}
```

Add an override for `process_checkout_outcome()` that records the context and returns `$this->checkout_outcome`.

Add a test to `NativeWooPaymentsGatewayTest`:

```php
/**
 * @testdox Should fail scheduled renewals and fire the preserved action when customer authentication is required.
 */
public function test_scheduled_subscription_payment_fails_and_fires_requires_action_hook(): void {
	$user_id = self::factory()->user->create();
	$order   = $this->create_order();
	$order->set_customer_id( $user_id );
	$order->set_currency( 'USD' );
	$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
	$order->save();

	$service                   = new RecordingPaymentProcessingService();
	$service->checkout_outcome = new PaymentOutcome(
		PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
		'pi_requires_action',
		'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
		'pm_requires_action',
		'cus_requires_action',
		array(
			'meta' => array(
				'_charge_id' => 'ch_requires_action',
			),
		)
	);
	$received = array();
	add_action(
		'woocommerce_woocommerce_payments_payment_requires_action',
		static function ( WC_Order $hook_order, string $intent_id, string $payment_method_id, string $customer_id, string $charge_id, string $currency ) use ( &$received ): void {
			$received = compact( 'hook_order', 'intent_id', 'payment_method_id', 'customer_id', 'charge_id', 'currency' );
		},
		10,
		6
	);

	$gateway = new NativeWooPaymentsGateway();
	$gateway->init( $service, new WooPaymentsProvider() );
	$gateway->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
	$order = wc_get_order( $order->get_id() );

	$this->assertInstanceOf( WC_Order::class, $order );
	$this->assertSame( 'failed', $order->get_status() );
	$this->assertSame( $order->get_id(), $received['hook_order']->get_id() );
	$this->assertSame( 'pi_requires_action', $received['intent_id'] );
	$this->assertSame( 'pm_requires_action', $received['payment_method_id'] );
	$this->assertSame( 'cus_requires_action', $received['customer_id'] );
	$this->assertSame( 'ch_requires_action', $received['charge_id'] );
	$this->assertSame( 'USD', $received['currency'] );
}
```

- [x] **Step 6: Verify RED**

Run: `pnpm test:php:env -- --filter NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_fails_and_fires_requires_action_hook`

Expected: FAIL because the gateway still calls `process_checkout()` and never emits the hook or fails the renewal order for `requires_customer_action`.

- [x] **Step 7: Implement native scheduled-renewal requires-action handling**

Update `NativeWooPaymentsGateway::scheduled_subscription_payment()` to call `process_checkout_outcome()`, then add a private helper that handles only `PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION` for scheduled renewals:

```php
$outcome = $this->get_processing_service()->process_checkout_outcome( $context, $this->get_provider() );
$this->maybe_handle_subscription_customer_action_required( $renewal_order, $outcome );
```

The helper should fire the existing preserved action with order, provider payment ID, payment method ID, customer ID, charge ID from outcome data/meta, and order currency, then update the order status to failed if it is not already failed. Add a concise order note only if native failure handling does not already add one; avoid duplicating reference prose unless needed for parity. Keep WooPayments hook naming in `NativeWooPaymentsGateway`, not `PaymentProcessingService`.

- [x] **Step 8: Verify GREEN for scheduled renewal**

Run: `pnpm test:php:env -- --filter 'NativeWooPaymentsGatewayTest|PaymentProcessingServiceTest'`

Expected: PASS.

## Task 2: Core-Owned WooPayments Subscription SCA Emails

**Files:**
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsFailedRenewalAuthenticationEmail.php`
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsFailedAuthenticationRetryEmail.php`
- Create four templates under `plugins/woocommerce/templates/emails/`
- Modify `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
- Modify `tools/woopayments-merge/subscriptions-renewal-drive.php` only if needed

- [x] **Step 1: Write RED tests for email registration and retry-rule behavior**

Add tests to `NativeWooPaymentsGatewayTest` that instantiate a subscriptions-enabled gateway, apply `woocommerce_email_classes`, and assert:

```php
$this->assertArrayHasKey( 'WC_Payments_Email_Failed_Renewal_Authentication', $emails );
$this->assertSame( 'failed_renewal_authentication', $emails['WC_Payments_Email_Failed_Renewal_Authentication']->id );
$this->assertArrayHasKey( 'WC_Payments_Email_Failed_Authentication_Retry', $emails );
$this->assertSame( 'failed_authentication_requested', $emails['WC_Payments_Email_Failed_Authentication_Retry']->id );
```

Add a second test that triggers the customer email action with a renewal-like order and asserts the email object installs `wcs_get_retry_rule_raw` filters that clear `email_template_customer` and replace non-empty `email_template_admin` with `WC_Payments_Email_Failed_Authentication_Retry` for the same order ID.

- [x] **Step 2: Verify RED**

Run: `pnpm test:php:env -- --filter 'NativeWooPaymentsGatewayTest::test_subscription_support_registers_failed_renewal_authentication_emails|NativeWooPaymentsGatewayTest::test_failed_renewal_authentication_email_updates_retry_rules'`

Expected: FAIL because native does not register email classes.

- [x] **Step 3: Implement email classes and templates**

Create the two namespaced email classes using Core text domain `woocommerce`, reference-compatible IDs, subjects, headings, and template names prefixed with `woopayments-` to avoid collisions. Use the same email-class array keys as the extension when registering: `WC_Payments_Email_Failed_Renewal_Authentication` and `WC_Payments_Email_Failed_Authentication_Retry`. Customer email behavior must guard on `wcs_order_contains_subscription`, `wcs_is_subscription`, or `wcs_order_contains_renewal` when those functions exist, set recipient to billing email, remove the WCS renewal invoice hooks when present, and add retry-rule filters. Admin retry email must use `WCS_Retry_Manager::store()->get_last_retry_for_order()` when available, and return without fataling if WCS retry classes/functions are unavailable.

- [x] **Step 4: Register emails from native subscriptions handler**

Add `add_filter( 'woocommerce_email_classes', array( $this, 'add_subscription_emails' ), 20 );` inside `NativeWooPaymentsGateway::register_subscription_handlers()` and implement a public callback that constructs the two namespaced classes, calls their hook setup methods, and returns the extended emails array. Remove the filter in test tearDown to avoid cross-test leakage.

- [x] **Step 5: Verify GREEN for email registration**

Run: `pnpm test:php:env -- --filter NativeWooPaymentsGatewayTest`

Expected: PASS.

- [x] **Step 6: Update harness only if the current driver hides the new evidence**

Inspect `tools/woopayments-merge/subscriptions-renewal-drive.php`. If it already records email ids/classes/subjects, leave it alone. If it cannot distinguish `failed_renewal_authentication` and `failed_authentication_requested`, add normalized fields without masking failures. Re-run `php -l tools/woopayments-merge/subscriptions-renewal-drive.php` after any harness edit.

## Task 3: Verification, Reviews, Docs, and Commit

**Files:**
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-h23-subscriptions-failed-renewal-authentication.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-agent-findings.md`
- Add changelog for `@woocommerce/plugin-woocommerce`

- [x] **Step 1: Run source verification**

Run: `php -l` on every created/modified PHP file.

Run targeted PHP tests: `pnpm test:php:env -- --filter 'PaymentProcessingServiceTest|NativeWooPaymentsGatewayTest'`.

Run PHPStan on modified Core PHP files from `plugins/woocommerce`: `composer exec -- phpstan analyse src/Internal/Payments/PaymentProcessingService.php src/Internal/Payments/NativeWooPaymentsGateway.php src/Internal/Payments/Providers/WooPayments/Subscriptions --memory-limit=2G`.

- [x] **Step 2: Run lint/build gates without linting scratchpad**

Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`.

Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`.

Run `git diff --check`.

No frontend asset build should be required because H23 adds no JS/CSS bundles. If any frontend file unexpectedly changes, stop and reassess scope.

- [x] **Step 3: Run Bucket-C harness evidence**

Run `bash -n tools/woopayments-merge/subscriptions-renewal-gate.sh` and `php -l tools/woopayments-merge/subscriptions-renewal-drive.php`.

Run the subscriptions preflight gate against reference and target. If equivalent failed-renewal subscription fixtures are available, run compare mode and record the normalized email IDs, subjects/classes, order status, and meta evidence. If fixtures are not deterministic, record the exact precondition gap rather than claiming green.

- [x] **Step 4: Dispatch review gates**

Dispatch a spec/API-contract reviewer for WooPayments hook/email key parity, an architecture reviewer for provider/generic boundary quality, and a reliability reviewer for failed-renewal lifecycle/email failure modes. Any source-backed high/critical finding must be fixed before completion.

- [x] **Step 5: Record docs and changelog**

Update the analysis/log/staging artifacts with the outcome and the perf-evidence caveat from this plan. Add a WooCommerce Core changelog entry. Keep scratchpad and harness files uncommitted unless explicitly requested.

- [x] **Step 6: Commit product changes**

Commit product source/tests/templates/changelog only. Do not commit scratchpad or ignored harness files. Do not push.

## Self-Review

- Spec coverage: covers Bucket-C failed-renewal email parity, preserved WooPayments action hook, order lifecycle failure, email registration, retry-rule filters, and honest N7b harness evidence.
- Placeholder scan: no `TBD`, no “implement later”, and no undefined production classes beyond classes created by this plan.
- Architecture check: generic service exposes a neutral outcome; WooPayments-specific behavior stays in WooPayments gateway/email classes.
- Perf check: no frontend bundles and no broad timing claims; structural evidence is the gate for this slice.

## Implementation Results

- 2026-06-18 03:39: H23 product implementation is complete locally. Native now exposes `PaymentProcessingService::process_checkout_outcome()` without changing `process_checkout()`'s checkout-array contract; scheduled WooPayments subscription renewals use the outcome path, fire the preserved `woocommerce_woocommerce_payments_payment_requires_action` action for `requires_customer_action`, then fail the renewal order. Core-owned WooPayments subscription emails are registered under preserved keys and IDs: `WC_Payments_Email_Failed_Renewal_Authentication` / `failed_renewal_authentication` and `WC_Payments_Email_Failed_Authentication_Retry` / `failed_authentication_requested`.
- 2026-06-18 03:39: Verification passed: targeted PHPUnit `PaymentProcessingServiceTest|NativeWooPaymentsGatewayTest` passed with 46 tests and 203 assertions; `lint:php:changes` passed; explicit PHPCS for the six new email class/template files passed; PHP syntax passed for touched and new PHP files; `git diff --check` passed; widened local Bucket-C preflight passed on reference and target. PHPStan reported no H23 file errors but still exits nonzero in subset mode because unrelated existing ignored-baseline patterns under WCPay promotion/admin-note files were not matched.
- 2026-06-18 03:39: Harness widening is local and fail-closed: `tools/woopayments-merge/subscriptions-renewal-drive.php` now instantiates WooCommerce email classes during preflight and verifies the two preserved failed-authentication email registrations plus the requires-action hook callback. Browser-created subscription compare mode was not completed in this pass because Chrome DevTools MCP timed out on both `list_pages` and `new_page`, and Playwriter’s checkout tab became unstable while filling the Stripe iframe. No compare PASS is claimed from that browser attempt.
- 2026-06-18 03:53: H23 review gate completed with source-backed fixes. API-contract review drove legacy WooPayments template identifier preservation; reliability review drove hook-exception hardening and idempotent email registration; architecture review drove explicit provider-level `charge_id` outcome data with legacy `_charge_id` meta fallback. Final targeted PHPUnit passed with 49 tests and 220 assertions, including the adapter charge-id regression; `lint:php:changes`, explicit PHPCS, PHP syntax, `git diff --check`, and Bucket-C preflight passed. Product commits: `98e02db4cd` (`fix(payments): preserve subscription authentication renewals`) and `7a54cd0736` (`chore(payments): add subscription authentication changelog`). Git range: `628d95d8ad...7a54cd0736`.
- 2026-06-18 03:57: Post-commit branch hygiene `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch` exited 0 with existing ignored-file JS warnings and Composer auth/deprecation notices.
