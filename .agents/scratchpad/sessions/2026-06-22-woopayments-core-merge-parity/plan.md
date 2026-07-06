---
session: 2026-06-22-woopayments-core-merge-parity
type: plan
by: claude
created: 2026-06-23 09:40
target: exp/core-native-payments — remediation of surviving review findings
reconciles:
  - parity.md
  - /tmp/branch-review-Users-vladolaru-Work-a8c-woocommerce-develop-2--exp-core-native-payments-/review-findings.json
status: final
last_updated: 2026-07-06 16:22
promoted_to: implementation-log.md
---

> **Executed 2026-07-06.** All 7 phases implemented via subagent-driven development across 41 commits (range `5abb021c6e..0cae35157d`). One finding changed disposition during execution: `7522b93d` (referrer nonce) was reverted to a documented accepted-risk after the handler proved to be an email CTA where a session nonce is the wrong mechanism. Execution detail, per-finding disposition, Phase 7 gate results, and PR follow-ups are in `implementation-log.md`.

# Native WooPayments Core Merge — Robustness & Backward-Compatibility Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Before writing any PHP test file, invoke the `woocommerce-backend-dev` skill. Follow `woocommerce-dev-cycle` for test/lint commands.

**Goal:** Resolve every surviving finding from the `exp/core-native-payments` review so the in-core native WooPayments solution is regression-free against the shipping WooPayments client (v10.8.0), measurably more robust where it can be, and strictly backward compatible with the `wcpay_*` hook/REST surface the client and ecosystem depend on.

**Architecture:** The native solution lives in `plugins/woocommerce/src/Internal/Payments/` (payment core + `Providers/WooPayments/*`) and `plugins/woocommerce/src/Internal/MultiCurrency/`, with React admin under `client/admin/client/woopayments/` and legacy checkout JS under `client/blocks/.../woopayments/` and `client/legacy/js/frontend/`. Fixes are sequenced by risk: (P0) regressions where core is worse than the client, (P1) backward-compatibility restorations, (P2) hardening that beats the client without breaking BC, (P3) shared parity bugs worth fixing in core, (P4) accessibility, (P5) tests/docs. BC rules throughout: never rename a public `wcpay_*` hook; never tighten a filter so existing callbacks stop working (make it strengthen-only); keep REST methods/routes the mobile app and client already call; remove public surfaces only behind `_deprecated_hook()`/`_deprecated_function()` with a re-fired equivalent.

**Tech Stack:** PHP 8.1 (PSR-4, WP/WC coding standards, PHPUnit 9.6 via `wp-env`), TypeScript/React (Jest + React Testing Library), WordPress hooks/REST API, Action Scheduler, `@wordpress/a11y` `speak()`.

**Finding traceability:** Each task cites the review finding id(s) it closes. Source of truth: `review-findings.json` and `parity.md` in this session. The full disposition (regression / parity / new-in-core / close) is in `parity.md`.

---

## File Structure

Files created or modified, grouped by responsibility:

- **Payment processing core** — `src/Internal/Payments/PaymentProcessingService.php` (checkout-outcome guard; refund-instance serialization), `src/Internal/Payments/NativeWooPaymentsGateway.php` (SetupIntent ownership).
- **WooPayments provider services** — `Providers/WooPayments/WooPaymentsDisputeEventHandler.php` (note escaping), `WooPaymentsEventIngestor.php` (atomic webhook claim), `WooPaymentsWooPaySessionController.php` (strengthen-only auth filter, log on failure), `WooPaymentsSettingsService.php` (account-field sanitization, WooPay message kses), `WooPaymentsCustomerService.php` (customer create lock), `WooPaymentsCapitalRestController.php` / `WooPaymentsAccountSessionRestController.php` (log on failure, error status), `WooPaymentsOperationalQueueService.php` (referrer nonce), `WooPaymentsTransactionsRestController.php` + `WooPaymentsPaginatedListRequest.php` (send() BC), `WooPaymentsMobileRestController.php` (metadata sanitize, order_id regex), `WooPaymentsTransactionsListRequest.php` (timezone guard), `Api/WooPaymentsApiClient.php` (disputes-summary, VAT encode, resource-id regex, backoff, REPORTING constant).
- **Backward-compat surface** — `src/Admin/Features/OnboardingTasks/Tasks/Payments.php` (re-fire + `_deprecated_hook()` the removed onboarding filters), `src/Internal/Admin/Suggestions/Incentives/WooPayments.php` (incentive badge/data re-fire), `includes/admin/settings/class-wc-settings-payment-gateways.php` (`@since`).
- **Multi-currency** — `src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php` (cache existence query), `Services/MultiCurrencyStateBuilder.php` (bulk option load), `Services/MultiCurrencyAnalyticsSqlProjectionService.php` (`$wpdb->prepare`), `MultiCurrencySubscriptionsCompatibilityController.php` (+ Bookings/Deposits/FedEx/UPS/Points controllers) (memoize backtrace + cart-type cache).
- **Blocks/admin JS** — `client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` (already escaped server-side after fix) and the bridge `Providers/WooPayments/WooPaymentsCheckoutBridge.php` (kses blocks value after filter); React a11y: `client/admin/client/woopayments/admin/payout-details.tsx`, `.../capital/page.tsx`, `.../money-movement/transaction-detail-sections.tsx`, `.../settings/payment-methods-list.tsx`.
- **Tests** — new/updated PHPUnit under `tests/php/src/Internal/...`, Jest under `client/admin/client/woopayments/...`.
- **Changelog** — one entry per affected package under `plugins/woocommerce/changelog/`.

---

## Phase 0 — Regressions (core behaves worse than the shipping client). MUST fix.

### Task 0.1: Successful charge must never leave the order pending (finding `bfcb6ce5`)

The client persists the charge reference before the status flip and converts any post-charge exception into a logged soft-success (`class-wc-payment-gateway-wcpay.php:1266-1294`). Core's `process_checkout_outcome()` runs `charge_provider()` then `apply_checkout_outcome()` in one `try/finally`; if `apply_checkout_outcome()` throws after a successful charge, the exception propagates and the order stays `pending` with no note/log.

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php:116-127`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`

- [ ] **Step 1: Write the failing test** — append to `PaymentProcessingServiceTest`:

```php
/**
 * A provider charge that succeeds must report success even if applying the
 * outcome to the order throws — money moved, so the result must not be FAILED.
 */
public function test_successful_charge_with_failing_apply_returns_success_and_logs(): void {
    $order = WC_Helper_Order::create_order();
    $order->set_total( 10 );
    $order->save();

    // Provider returns a successful charge.
    $provider = new RecordingProvider( PaymentOutcome::STATUS_SUCCEEDED );

    // Force apply_checkout_outcome() to throw via a lifecycle service double
    // that throws on transition.
    $this->lifecycle_service->method( 'transition_to_outcome' )
        ->willThrowException( new RuntimeException( 'db save failed' ) );

    $context = new PaymentContext( $order );
    $outcome = $this->service->process_checkout_outcome( $context, $provider );

    // Money moved: outcome stays SUCCEEDED, not FAILED.
    $this->assertTrue( $outcome->is_successful() );
    // The payment reference is persisted on the order despite the apply failure.
    $reloaded = wc_get_order( $order->get_id() );
    $this->assertNotEmpty( $reloaded->get_transaction_id() );
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `pnpm test:php:env -- --filter test_successful_charge_with_failing_apply_returns_success_and_logs`
Expected: FAIL — the `RuntimeException` propagates out of `process_checkout_outcome()`.

- [ ] **Step 3: Guard `apply_checkout_outcome()` and persist the reference first**

Replace the body of `process_checkout_outcome()` (lines 116-127) with:

```php
		try {
			$outcome = 0.0 >= $amount && ! $this->should_call_provider_for_zero_total_checkout( $context, $provider )
				? new PaymentOutcome( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT )
				: $this->charge_provider( $context, $provider, $idempotency_key );

			try {
				$this->apply_checkout_outcome( $order, $outcome );
			} catch ( Throwable $apply_exception ) {
				// The charge already moved money. Never downgrade the outcome to
				// FAILED here — that would risk a re-charge on retry. Persist the
				// payment reference so the order can be reconciled, log loudly, and
				// keep the successful outcome. Mirrors the WooPayments client soft-
				// success path (class-wc-payment-gateway-wcpay.php:1266-1294).
				if ( $outcome->is_successful() ) {
					$this->persist_payment_reference( $order, $outcome );
					$this->log_post_charge_apply_failure( $order, $outcome, $apply_exception );
				} else {
					throw $apply_exception;
				}
			}

			return $outcome;
		} finally {
			$this->order_payment_store->unlock_order_payment( $order );
		}
```

Add the two private helpers below `process_checkout_outcome()`:

```php
	/**
	 * Persist the provider payment reference on the order after a successful
	 * charge whose outcome application failed, so the order can be reconciled.
	 *
	 * @param WC_Order       $order   Order.
	 * @param PaymentOutcome $outcome Successful provider outcome.
	 */
	private function persist_payment_reference( WC_Order $order, PaymentOutcome $outcome ): void {
		$reloaded = wc_get_order( $order->get_id() );
		if ( ! $reloaded instanceof WC_Order ) {
			return;
		}
		$reference = $outcome->get_payment_reference();
		if ( '' !== $reference && '' === (string) $reloaded->get_transaction_id() ) {
			$reloaded->set_transaction_id( $reference );
			$reloaded->save();
		}
	}

	/**
	 * Log a post-charge apply failure at error level with order + reference.
	 *
	 * @param WC_Order       $order     Order.
	 * @param PaymentOutcome $outcome   Successful provider outcome.
	 * @param Throwable      $exception Apply failure.
	 */
	private function log_post_charge_apply_failure( WC_Order $order, PaymentOutcome $outcome, Throwable $exception ): void {
		wc_get_logger()->error(
			'Native payment charge succeeded but applying the outcome to the order failed; order needs reconciliation: ' . $exception->getMessage(),
			array(
				'source'            => 'native-payments',
				'order_id'          => $order->get_id(),
				'payment_reference' => $outcome->get_payment_reference(),
			)
		);
	}
```

Confirm `PaymentOutcome::get_payment_reference()` exists; if the accessor differs, use the actual getter (read `src/Internal/Payments/PaymentOutcome.php`).

- [ ] **Step 4: Run the test, expect pass**

Run: `pnpm test:php:env -- --filter test_successful_charge_with_failing_apply_returns_success_and_logs`
Expected: PASS.

- [ ] **Step 5: Run the full processing-service suite to confirm no regression**

Run: `pnpm test:php:env -- --filter PaymentProcessingServiceTest`
Expected: PASS (all).

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php
git commit -m "fix(payments): keep order reconcilable when post-charge apply fails

Refs review finding bfcb6ce5"
```

---

### Task 0.2: Escape webhook-supplied dispute values in order notes (finding `0b11bf8a`)

The client wraps dispute notes in `esc_interpolated_html()` which `esc_html()`s the webhook `status`; core `sprintf`s the raw `$status` and a non-`esc_url`'d dispute URL into order-note HTML. This is both a stored-XSS shape and a BC/dedup risk (differing note strings break `order_note_exists()` matching on client→core migration).

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php` — `process_dispute_updated()` (~251), `get_dispute_created_note()` (~436), `get_dispute_closed_note()` (~467)
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
/**
 * A dispute status carrying HTML must be escaped before it lands in the order note.
 */
public function test_dispute_closed_note_escapes_status(): void {
    $note = $this->invoke_private(
        $this->handler,
        'get_dispute_closed_note',
        array( 'ch_123', '<script>alert(1)</script>', false, 'txn_1' )
    );

    $this->assertStringNotContainsString( '<script>', $note );
    $this->assertStringContainsString( '&lt;script&gt;', $note );
}
```

(Use the test's existing private-invoker helper, or add a small reflection helper if none exists.)

- [ ] **Step 2: Run the test, expect failure**

Run: `pnpm test:php:env -- --filter test_dispute_closed_note_escapes_status`
Expected: FAIL — raw `<script>` present in the note.

- [ ] **Step 3: Escape runtime values at every note builder**

In `process_dispute_updated()` wrap the URL:

```php
		$note = sprintf(
			/* translators: %1: the dispute message, %2: the dispute details URL */
			__( '%1$s. See <a href="%2$s">dispute overview</a> for more details.', 'woocommerce' ),
			$message,
			esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
		);
```

In `get_dispute_created_note()` (both branches) wrap `$amount`, `$reason`, `$due_by` with `esc_html()` and the URL with `esc_url()`. In `get_dispute_closed_note()` (both branches) wrap `$status` with `esc_html()` and the URL with `esc_url()`. Example for the closed non-inquiry branch:

```php
		return sprintf(
			/* translators: %1: the dispute status; %2: dispute details URL */
			__( 'Dispute has been closed with status %1$s. See <a href="%2$s" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.', 'woocommerce' ),
			esc_html( $status ),
			esc_url( $this->get_dispute_url( $charge_id, $balance_transaction_id ) )
		);
```

`$amount` from `get_formatted_dispute_amount()` is already `wc_price()` HTML — do NOT `esc_html()` that one; only escape the webhook-supplied scalars (`$status`, `$reason`) and URLs. Verify which args are pre-formatted HTML before wrapping.

- [ ] **Step 4: Run the test, expect pass**

Run: `pnpm test:php:env -- --filter test_dispute_closed_note_escapes_status`
Expected: PASS.

- [ ] **Step 5: Run the handler suite**

Run: `pnpm test:php:env -- --filter WooPaymentsDisputeEventHandlerTest`
Expected: PASS. If existing note-string assertions break, update them to the escaped form (this is the intended change).

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandlerTest.php
git commit -m "fix(payments): escape webhook dispute values in order notes

Refs review finding 0b11bf8a"
```

---

### Task 0.3: Restore a `send()`-compatible filtered request object (finding `147606e9`)

The client fires `wcpay_list_transactions_request` (and `wcpay_get_reporting_balance_summary_request`) with a full `Request` object exposing `final public function send()`. Core's native `WooPaymentsPaginatedListRequest` base has only `get_params()`, so a client extension that calls `$request->send()` inside the filter fatals. Add a BC `send()` that executes the request via the API client.

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequestTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
/** The filtered request object must expose a callable send() for BC. */
public function test_send_executes_request_via_api_client(): void {
    $request = WooPaymentsTransactionsListRequest::from_rest_request(
        new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' )
    );

    $this->assertTrue( method_exists( $request, 'send' ) );
    $this->assertTrue( is_callable( array( $request, 'send' ) ) );
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `pnpm test:php:env -- --filter WooPaymentsPaginatedListRequestTest`
Expected: FAIL — `send()` does not exist.

- [ ] **Step 3: Add a BC `send()` to the base**

In `WooPaymentsPaginatedListRequest`, after `get_method()` add:

```php
	/**
	 * Execute the request against the platform and return the decoded result.
	 *
	 * Backward-compatibility shim: the standalone WooPayments plugin's request
	 * objects expose `send()`, and extensions hooking the preserved
	 * `wcpay_list_transactions_request` / `wcpay_get_reporting_balance_summary_request`
	 * filters may call `$request->send()`. The native object preserves that
	 * contract by delegating to the API client. Do not remove without a
	 * `_deprecated_function()` cycle.
	 *
	 * @since 11.0.0
	 * @return array<string,mixed>
	 */
	public function send(): array {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );

		return $api_client->request( $this->get_params(), $this->get_api(), $this->get_method() );
	}
```

Add `use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;` and confirm `WooPaymentsApiClient::request()` is `public` (it is called as `$this->request(...)` internally — if it is `private`, add a thin public `request_path( array $params, string $api, string $method ): array` wrapper on the client and call that instead). Verify `wc_get_container()` is the correct accessor in this namespace.

- [ ] **Step 4: Run the test, expect pass**

Run: `pnpm test:php:env -- --filter WooPaymentsPaginatedListRequestTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequestTest.php
git commit -m "fix(payments): restore send() on preserved list-request filter objects

Refs review finding 147606e9"
```

---

### Task 0.4: Sanitize and validate account-setting fields (finding `deb2d8c9`)

The client never persists raw request params locally — it round-trips to the server and enforces per-field `validate_callback`s. Core writes `ACCOUNT_SETTING_MAP` fields straight from `$params` into the local `wcpay` settings option with no per-type sanitization (`WooPaymentsSettingsService.php:577-581`).

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:577-581`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_account_settings_are_sanitized_per_type(): void {
    $this->service->update_settings( array(
        'account_business_name'          => '  Acme <b>Co</b>  ',
        'account_business_support_email' => 'BAD EMAIL<script>',
        'account_business_url'           => 'javascript:alert(1)',
    ) );

    $settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION );
    $this->assertSame( 'Acme Co', $settings['account_business_name'] );
    $this->assertStringNotContainsString( '<script>', (string) $settings['account_business_support_email'] );
    $this->assertStringNotContainsString( 'javascript:', (string) $settings['account_business_url'] );
}
```

(Use the real `ACCOUNT_SETTING_MAP` request keys — read the constant first to use exact keys/types.)

- [ ] **Step 2: Run the test, expect failure**

Run: `pnpm test:php:env -- --filter test_account_settings_are_sanitized_per_type`
Expected: FAIL — raw values persisted.

- [ ] **Step 3: Add a per-type sanitizer keyed off `ACCOUNT_SETTING_MAP`**

Change `ACCOUNT_SETTING_MAP` so each entry carries a type (mirror `LOCAL_SETTING_MAP`’s `[setting_key, type]` shape if it does not already), then replace lines 577-581:

```php
		foreach ( self::ACCOUNT_SETTING_MAP as $request_key => $mapping ) {
			if ( ! array_key_exists( $request_key, $params ) ) {
				continue;
			}
			list( $setting_key, $type ) = is_array( $mapping ) ? $mapping : array( $mapping, 'text' );
			$settings[ $setting_key ]   = $this->sanitize_account_setting_value( $params[ $request_key ], $type );
		}
```

Add the sanitizer:

```php
	/**
	 * Sanitize an account setting value by declared type.
	 *
	 * @param mixed  $value Raw request value.
	 * @param string $type  One of: text, url, email, color.
	 * @return string
	 */
	private function sanitize_account_setting_value( $value, string $type ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		switch ( $type ) {
			case 'url':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'color':
				return sanitize_hex_color( $value ) ?? '';
			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}
```

If `ACCOUNT_SETTING_MAP` is currently a flat `request_key => setting_key` map, the `is_array($mapping)` fallback keeps it working while you add types incrementally; assign the correct type to each field (business name → `text`, urls → `url`, emails → `email`, brand colors → `color`).

- [ ] **Step 4: Run the test, expect pass**

Run: `pnpm test:php:env -- --filter test_account_settings_are_sanitized_per_type`
Expected: PASS.

- [ ] **Step 5: Run the settings-service suite**

Run: `pnpm test:php:env -- --filter WooPaymentsSettingsServiceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php
git commit -m "fix(payments): sanitize account settings by field type before persisting

Refs review finding deb2d8c9"
```

---

### Task 0.5: Log silently-swallowed REST/session exceptions (findings `5076c7dc`, `6ea1b4a8`, `24a6f21d`)

Three handlers catch and discard exceptions with zero diagnostic trail. The client logs the failure in each equivalent. Add `wc_get_logger()->error(...)` in the catch while preserving the existing graceful public response.

**Files:**
- Modify: `Providers/WooPayments/WooPaymentsWooPaySessionController.php:157-159` (`get_session`)
- Modify: `Providers/WooPayments/WooPaymentsAccountSessionRestController.php` (`create_embedded_account_session`, the `unset($exception)` catch)
- Modify: `Providers/WooPayments/WooPaymentsCapitalRestController.php` (`get_loan_offer_redirect_url`, the `unset($exception)` catch)
- Test: extend each controller's existing test class

- [ ] **Step 1: Write a failing test (WooPay session example)** in `WooPaymentsWooPaySessionControllerTest`:

```php
public function test_get_session_logs_on_failure(): void {
    $logged = array();
    add_filter( 'woocommerce_logger_log_message', function ( $message ) use ( &$logged ) {
        $logged[] = $message;
        return $message;
    } );

    // Force the session service to throw.
    $this->session_service->method( 'get_session_data' )
        ->willThrowException( new RuntimeException( 'boom' ) );

    $result = $this->controller->get_session( new WP_REST_Request() );

    $this->assertWPError( $result );
    $this->assertNotEmpty( array_filter( $logged, fn( $m ) => str_contains( (string) $m, 'WooPay session' ) ) );
}
```

- [ ] **Step 2: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_get_session_logs_on_failure`
Expected: FAIL — nothing logged.

- [ ] **Step 3a: WooPay session controller** — replace the catch at line 157:

```php
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Unable to assemble WooPay session data: ' . $exception->getMessage(),
				array( 'source' => 'woopayments-woopay-session' )
			);

			return new WP_Error( 'wcpay_server_error', __( 'Unable to get WooPay session data.', 'woocommerce' ), array( 'status' => 400 ) );
		}
```

- [ ] **Step 3b: Account session controller** — replace `unset( $exception );` in `create_embedded_account_session` with:

```php
			wc_get_logger()->error(
				'Failed to create embedded account session: ' . $exception->getMessage(),
				array( 'source' => 'woopayments-account-session' )
			);
```

- [ ] **Step 3c: Capital controller** — replace `unset( $exception );` in `get_loan_offer_redirect_url` with:

```php
			wc_get_logger()->error(
				'Failed to build Capital loan offer redirect URL: ' . $exception->getMessage(),
				array( 'source' => 'woopayments-capital' )
			);
```

- [ ] **Step 4: Run the WooPay session test, expect pass**

Run: `pnpm test:php:env -- --filter test_get_session_logs_on_failure`
Expected: PASS. Add analogous logging assertions to the account-session and capital controller test classes.

- [ ] **Step 5: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionController.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionControllerTest.php
git commit -m "fix(payments): log swallowed WooPay session, account, and capital errors

Refs review findings 5076c7dc, 6ea1b4a8, 24a6f21d"
```

---

### Task 0.6: Restore the dropped `REPORTING_API` constant / remove the orphan docblock (finding `d3111113`)

`Api/WooPaymentsApiClient.php:137-140` has a dangling "reporting API path" docblock with no constant; the client declares `const REPORTING_API = 'reporting'`. Decide by usage: if `get_reporting_balance_summary()` builds its path from a hardcoded string, restore the constant and use it; otherwise just delete the orphan docblock.

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`

- [ ] **Step 1: Determine usage**

Run: `grep -n "'reporting'\|REPORTING_API\|reporting/" plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
Expected: shows whether `'reporting'` is hardcoded in `get_reporting_balance_summary()`.

- [ ] **Step 2: Apply the fix**

If `'reporting'` is hardcoded, replace the orphan docblock (lines 137-139, above `AUTHORIZATIONS_API`) with the constant and use it at the call site:

```php
	/**
	 * WooPayments reporting API path.
	 */
	private const REPORTING_API = 'reporting';
```

Otherwise (no reporting path used), delete the three orphan docblock lines so only the `AUTHORIZATIONS_API` docblock remains.

- [ ] **Step 3: Lint the file**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`
Expected: no new violations.

- [ ] **Step 4: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php
git commit -m "fix(payments): repair orphaned reporting API path docblock

Refs review finding d3111113"
```

---

## Phase 1 — Backward compatibility restorations.

### Task 1.1: Re-fire the removed onboarding-task filters with deprecation notice (finding `c49baad5`)

The branch deleted `Tasks/WooCommercePayments.php` and with it the documented public filters `woocommerce_admin_woopayments_onboarding_task_badge` (@since 8.2.0) and `woocommerce_admin_woopayments_onboarding_task_additional_data` (@since 9.4.0). The shipping client unconditionally hooks both (`class-wc-payments-incentives-service.php:80-81`); they now silently no-op. Re-fire them from the native payments onboarding-task surface and emit `_deprecated_hook()`.

**Files:**
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/Payments.php` (the native payments task that replaced the deleted one) — its `get_badge()` / `get_additional_data()` (or equivalent) methods
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Onboarding/...` or the existing Payments task test

- [ ] **Step 1: Locate the native badge/additional-data surface**

Run: `grep -rn "function get_badge\|function get_additional_data\|woopayments" plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/Payments.php`
Expected: identifies where the WooPayments task badge/extra data is produced (the redirect-to-settings task). If neither method exists on `Payments.php`, the surface is the suggestion incentive — use `src/Internal/Admin/Suggestions/Incentives/WooPayments.php::get_incentives()` instead.

- [ ] **Step 2: Write the failing test**

```php
public function test_legacy_woopayments_onboarding_badge_filter_still_fires(): void {
    $called = false;
    add_filter( 'woocommerce_admin_woopayments_onboarding_task_badge', function ( $badge ) use ( &$called ) {
        $called = true;
        return 'BADGE';
    } );

    // Invoke the native surface that should re-fire the legacy filter.
    $badge = ( new Payments() )->get_badge();

    $this->assertTrue( $called, 'Legacy onboarding badge filter must still fire for BC.' );
    $this->assertSame( 'BADGE', $badge );
}
```

- [ ] **Step 3: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_legacy_woopayments_onboarding_badge_filter_still_fires`
Expected: FAIL — filter never fires.

- [ ] **Step 4: Re-fire both filters with `_deprecated_hook()`**

At the native badge/additional-data production point, return the legacy-filtered value and signal deprecation:

```php
	/**
	 * @return string
	 */
	public function get_badge() {
		$badge = ''; // native default

		/**
		 * Filters the WooPayments onboarding task badge.
		 *
		 * @deprecated 11.0.0 The WooPayments onboarding task moved into core; the
		 * incentive badge now renders via the native payments surface.
		 * @since 8.2.0
		 * @param string $badge Badge markup.
		 */
		$badge = apply_filters( 'woocommerce_admin_woopayments_onboarding_task_badge', $badge );
		if ( '' !== $badge ) {
			_deprecated_hook( 'woocommerce_admin_woopayments_onboarding_task_badge', '11.0.0' );
		}

		return $badge;
	}
```

Do the same for `get_additional_data()` with the `woocommerce_admin_woopayments_onboarding_task_additional_data` filter (native default `null`). Keep firing the filter unconditionally so existing callbacks still influence the value; only the `_deprecated_hook()` notice is gated on a non-default return to avoid noise for sites with no hook.

- [ ] **Step 5: Run, expect pass**

Run: `pnpm test:php:env -- --filter test_legacy_woopayments_onboarding_badge_filter_still_fires`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/Payments.php plugins/woocommerce/tests/php/...
git commit -m "fix(payments): re-fire removed WooPayments onboarding filters with deprecation

Refs review finding c49baad5"
```

---

### Task 1.2: Document the GET account-session route as a deliberate BC choice (finding `3eece18f`)

The account-session creation route is registered `READABLE` (GET). REST semantics prefer POST, but the client also registers it GET (`class-wc-rest-payments-accounts-controller.php:40`) and the mobile app calls it as GET. Changing to POST would break those clients. Keep GET; record why so the finding does not resurface.

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php` (route registration)

- [ ] **Step 1: Add an explanatory comment above the route registration**

```php
		// NOTE: Registered as READABLE (GET) deliberately for backward compatibility
		// with the WooPayments client and mobile app, which call this endpoint as GET
		// (class-wc-rest-payments-accounts-controller.php:40). Do not change to POST
		// without a coordinated client/mobile migration. See review finding 3eece18f.
```

- [ ] **Step 2: Lint**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`
Expected: no new violations.

- [ ] **Step 3: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php
git commit -m "docs(payments): record GET account-session route as a BC requirement

Refs review finding 3eece18f"
```

---

## Phase 2 — Beat the client (hardening that stays backward compatible).

### Task 2.1: Make the WooPay session auth filter strengthen-only (finding `c17bc8f3`)

The filter `wcpay_woopay_is_signed_with_blog_token` is parity with the client (exists since 5.9.0), so it is not a regression — but as written any plugin returning `true` bypasses the blog-token check entirely. Harden it so the filter can only *tighten* auth, never grant it. Existing legitimate callbacks (which return `true` only when already signed) keep working, preserving BC.

**Files:**
- Modify: `Providers/WooPayments/WooPaymentsWooPaySessionController.php:123-137`
- Test: `WooPaymentsWooPaySessionControllerTest`

- [ ] **Step 1: Write the failing test**

```php
public function test_auth_filter_cannot_grant_access_when_unsigned(): void {
    // Force the real check to fail (unsigned).
    add_filter( 'pre_option_woocommerce_woopay_test_unsigned', '__return_true' );
    // A malicious/over-eager filter tries to force-allow.
    add_filter( 'wcpay_woopay_is_signed_with_blog_token', '__return_true' );

    $result = $this->controller->check_permission( new WP_REST_Request() );

    $this->assertWPError( $result, 'Filter must not grant access when the blog-token check fails.' );
}
```

(Adapt the "force unsigned" mechanism to however the test doubles `Rest_Authentication::is_signed_with_blog_token()`.)

- [ ] **Step 2: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_auth_filter_cannot_grant_access_when_unsigned`
Expected: FAIL — the filter currently grants access.

- [ ] **Step 3: AND the filter result with the real check**

Replace lines 123-137:

```php
		$signed = class_exists( Rest_Authentication::class )
			? Rest_Authentication::is_signed_with_blog_token()
			: false;

		/**
		 * Filters whether a WooPay session request is signed with the connected blog token.
		 *
		 * Strengthen-only: the real blog-token check is authoritative. This filter can
		 * further restrict access but can never grant it when the request is unsigned.
		 *
		 * @param bool            $signed  Whether the request is signed.
		 * @param WP_REST_Request $request REST request.
		 *
		 * @since 11.0.0
		 */
		$signed = $signed && (bool) apply_filters( 'wcpay_woopay_is_signed_with_blog_token', $signed, $request );

		if ( ! $signed ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}
```

- [ ] **Step 4: Run, expect pass; run the controller suite**

Run: `pnpm test:php:env -- --filter WooPaymentsWooPaySessionControllerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionController.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionControllerTest.php
git commit -m "fix(payments): make WooPay session auth filter strengthen-only

Refs review finding c17bc8f3"
```

---

### Task 2.2: Sanitize blocks `testingInstructions` server-side after the filter (finding `195a39ae`)

Parity with the client (both render via `dangerouslySetInnerHTML`), but core can beat it. The classic path already escapes (`WooPaymentsCheckoutBridge.php:319` `wp_kses_post`); the blocks data (`get_card_testing_instructions()` exposed via `get_blocks_payment_method_data()` at line 457) ships raw, and the value passes through `wcpay_payment_fields_js_config`. Apply `wp_kses_post()` to the blocks value before it reaches JS.

**Files:**
- Modify: `Providers/WooPayments/WooPaymentsCheckoutBridge.php` — `get_card_testing_instructions()` (~497) or the `'testingInstructions'` assignment (~457)
- Test: `WooPaymentsCheckoutBridgeTest`

- [ ] **Step 1: Write the failing test**

```php
public function test_blocks_testing_instructions_are_kses_filtered(): void {
    add_filter( 'wcpay_payment_fields_js_config', function ( $config ) {
        $config['paymentMethodsConfig']['card']['testingInstructions'] = '<script>alert(1)</script><strong>ok</strong>';
        return $config;
    } );

    $data = $this->bridge->get_blocks_payment_method_data();

    $this->assertStringNotContainsString( '<script>', $data['testingInstructions'] );
    $this->assertStringContainsString( '<strong>ok</strong>', $data['testingInstructions'] );
}
```

If `testingInstructions` is set before the `wcpay_payment_fields_js_config` filter runs, the sanitization must wrap the value *after* the filter — adjust the assertion target to wherever the final config is assembled.

- [ ] **Step 2: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_blocks_testing_instructions_are_kses_filtered`
Expected: FAIL — `<script>` present.

- [ ] **Step 3: Apply `wp_kses_post()` after the filter**

Wherever the final `paymentMethodsConfig['card']['testingInstructions']` value is read post-filter for the blocks payload, wrap it:

```php
			'testingInstructions' => wp_kses_post( $this->get_card_testing_instructions() ),
```

If the value originates from the filtered `$config`, sanitize at the point the blocks payload is built from `$config` so third-party filter mutations are also covered.

- [ ] **Step 4: Run, expect pass**

Run: `pnpm test:php:env -- --filter test_blocks_testing_instructions_are_kses_filtered`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php
git commit -m "fix(payments): kses-filter blocks testing instructions after config filter

Refs review finding 195a39ae"
```

---

### Task 2.3: Verify SetupIntent customer ownership before attaching (finding `47644832`)

Parity with the client (both check only `status === succeeded`), but a clean win: verify the SetupIntent's `customer` matches the current user's Stripe customer before creating the token, preventing a known-ID attach of another user's payment method.

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php:231-245`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_add_payment_method_rejects_setup_intent_owned_by_another_customer(): void {
    $this->set_post( 'wcpay-setup-intent', 'seti_123' );
    $user_id = self::factory()->user->create();
    wp_set_current_user( $user_id );

    // Current user's Stripe customer is cus_ME; the intent belongs to cus_OTHER.
    $this->customer_service->method( 'get_customer_id_by_user_id' )->willReturn( 'cus_ME' );
    $this->api_client->method( 'get_setup_intention' )->willReturn( array(
        'status'   => 'succeeded',
        'customer' => 'cus_OTHER',
    ) );

    $result = $this->gateway->add_payment_method();

    $this->assertSame( 'failure', $result['result'] ?? 'failure' );
}
```

- [ ] **Step 2: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_add_payment_method_rejects_setup_intent_owned_by_another_customer`
Expected: FAIL — the method is attached regardless of owner.

- [ ] **Step 3: Add the ownership check after the status check (line 235)**

```php
			$intent_customer = isset( $setup_intent['customer'] ) ? (string) $setup_intent['customer'] : '';
			$user_customer   = (string) $this->get_customer_service()->get_customer_id_by_user_id( $user_id );
			if ( '' !== $user_customer && '' !== $intent_customer && $intent_customer !== $user_customer ) {
				return $this->add_payment_method_error( __( 'Failed to add the provided payment method. Please try again later.', 'woocommerce' ) );
			}
```

Use the gateway's actual customer-service accessor and method name (confirm in `NativeWooPaymentsGateway`). If `customer` is absent from the intent payload (server already scopes it), the `'' !== $intent_customer` guard makes the check a no-op rather than a false rejection — preserving BC.

- [ ] **Step 4: Run, expect pass; run the gateway suite**

Run: `pnpm test:php:env -- --filter NativeWooPaymentsGatewayTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php
git commit -m "fix(payments): verify SetupIntent ownership before attaching a card

Refs review finding 47644832"
```

---

### Task 2.4: Close the webhook and refund concurrency windows (findings `53de2d4b`, `7c43c8da`)

Both are improvements over the client (which has no inbound dedup and no refund lock), so neither is a regression — but the residual check-then-act windows are worth closing. (a) Make the webhook processed-marker claim atomic; (b) serialize refund-instance resolution under the order lock.

**Files:**
- Modify: `Providers/WooPayments/WooPaymentsEventIngestor.php:197-209`
- Modify: `src/Internal/Payments/PaymentProcessingService.php:148-152`
- Test: `WooPaymentsEventIngestorTest`, `PaymentProcessingServiceTest`

- [ ] **Step 1: Write the failing webhook test**

```php
/** A concurrent re-delivery must be rejected by an atomic claim, not a read-then-write. */
public function test_atomic_claim_blocks_concurrent_duplicate_event(): void {
    $event = array( 'id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => array() );

    // First claim succeeds.
    $this->assertTrue( $this->invoke_private( $this->ingestor, 'claim_event', array( 'evt_1' ) ) );
    // Second claim for the same id must fail atomically.
    $this->assertFalse( $this->invoke_private( $this->ingestor, 'claim_event', array( 'evt_1' ) ) );
}
```

- [ ] **Step 2: Run, expect failure**

Run: `pnpm test:php:env -- --filter test_atomic_claim_blocks_concurrent_duplicate_event`
Expected: FAIL — no `claim_event` method.

- [ ] **Step 3: Replace check-then-mark with an atomic claim** in `process()`:

```php
	public function process( array $event ): void {
		$event_id = $this->get_event_id( $event );

		if ( '' !== $event_id && ! $this->claim_event( $event_id ) ) {
			return;
		}

		try {
			$this->dispatch( $event );
		} catch ( Throwable $exception ) {
			// Allow retry by releasing the claim if processing failed.
			if ( '' !== $event_id ) {
				$this->release_event_claim( $event_id );
			}
			throw $exception;
		}
	}

	/**
	 * Atomically claim an event id for processing.
	 *
	 * Uses wp_cache_add(), which is atomic on persistent object caches
	 * (Memcached/Redis), so concurrent deliveries cannot both claim the same id.
	 *
	 * @param string $event_id Event id.
	 * @return bool True if the claim was acquired.
	 */
	private function claim_event( string $event_id ): bool {
		if ( $this->is_event_already_processed( $event_id ) ) {
			return false;
		}
		return wp_cache_add( $this->event_marker_key( $event_id ), 1, 'woopayments_events', $this->get_event_marker_ttl() );
	}
```

Add `release_event_claim()` (delete the cache key) and `event_marker_key()` helpers, and keep `mark_event_processed()` writing the durable transient marker on success so the dedup survives cache eviction. Preserve the existing `is_event_already_processed()` transient fast-path for cross-request dedup. (If there is no persistent object cache, `wp_cache_add` is per-request only; the durable transient still bounds duplicates as before, so behavior never regresses below today.)

- [ ] **Step 4: Serialize refund-instance resolution** — in `process_refund()` move `resolve_refund_instance_id()` under the lock. Because the idempotency key depends on the resolved instance, take a coarse per-order lock keyed on a stable refund-scope token first:

```php
		$refund_scope_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'refund-scope', $amount, (string) $order->get_currency(), $reason );
		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $refund_scope_key ) ) {
			return new WP_Error( 'native_payment_refund_locked', __( 'A refund is already in progress for this order.', 'woocommerce' ) );
		}

		try {
			$refund_instance = $this->resolve_refund_instance_id( $order, $amount, $reason );
			$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'refund', $amount, (string) $order->get_currency(), $reason, $refund_instance );

			try {
				$outcome = $provider->refund( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$outcome = $this->exception_policy->to_failed_outcome( $exception );
			}

			if ( $outcome->is_successful() ) {
				$this->apply_refund_outcome( $order, $outcome, $amount, $reason );
			}
		} finally {
			$this->order_payment_store->unlock_order_payment( $order );
		}
```

This serializes distinct same-amount/same-reason refunds so each resolves a distinct instance (distinct key) instead of colliding. Confirm `claim_order_payment_lock`/`unlock_order_payment` are re-entrant per order or use the scope key consistently for both lock and unlock.

- [ ] **Step 5: Run both suites, expect pass**

Run: `pnpm test:php:env -- --filter WooPaymentsEventIngestorTest`
Run: `pnpm test:php:env -- --filter PaymentProcessingServiceTest`
Expected: PASS.

- [ ] **Step 6: Commit (two logical commits)**

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php
git commit -m "perf(payments): make webhook event claim atomic to block concurrent retries

Refs review finding 53de2d4b"
git add plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php
git commit -m "fix(payments): serialize refund-instance resolution under the order lock

Refs review finding 7c43c8da"
```

---

### Task 2.5: Multi-currency hot-path performance (findings `1062b791`, `f42b6e68`, `f35bc174`, `e0e84777`)

All four are parity with the client (carried forward verbatim), but each is a measurable win on multi-currency stores and safe to optimize.

**Files:**
- Modify: `src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php` (`has_multi_currency_orders`)
- Modify: `src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php` (bulk option load)
- Modify: `src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php` (memoize backtrace + cart-type cache); apply the same backtrace memoization in the Bookings/Deposits/FedEx/UPS/Points controllers
- Test: matching `*Test.php` for each

- [ ] **Step 1 (cache existence query): failing test** in `MultiCurrencyAnalyticsControllerTest`:

```php
public function test_has_multi_currency_orders_is_cached_within_request(): void {
    $calls = 0;
    $controller = $this->make_controller_with_resolver( function () use ( &$calls ) {
        $calls++;
        return true;
    } );

    $this->invoke_private( $controller, 'has_multi_currency_orders', array() );
    $this->invoke_private( $controller, 'has_multi_currency_orders', array() );

    $this->assertSame( 1, $calls, 'Existence query must run at most once per request.' );
}
```

- [ ] **Step 2:** Run: `pnpm test:php:env -- --filter test_has_multi_currency_orders_is_cached_within_request` → FAIL.

- [ ] **Step 3:** In `has_multi_currency_orders()` add a transient + static cache around the SQL (the injectable `multi_currency_orders_resolver` stays the test seam):

```php
	private function has_multi_currency_orders(): bool {
		if ( null !== $this->multi_currency_orders_resolver ) {
			return (bool) call_user_func( $this->multi_currency_orders_resolver );
		}

		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}

		$cached = get_transient( self::HAS_MC_ORDERS_TRANSIENT );
		if ( false !== $cached ) {
			$memo = '1' === $cached;
			return $memo;
		}

		global $wpdb;
		// ... existing HPOS / postmeta EXISTS query unchanged ...
		$memo = 1 === (int) $result;
		set_transient( self::HAS_MC_ORDERS_TRANSIENT, $memo ? '1' : '0', HOUR_IN_SECONDS );
		return $memo;
	}
```

Add `private const HAS_MC_ORDERS_TRANSIENT = 'wc_mc_has_orders';` and invalidate it on `woocommerce_new_order` (register in the controller's hook setup: `add_action( 'woocommerce_new_order', fn() => delete_transient( self::HAS_MC_ORDERS_TRANSIENT ) )`).

- [ ] **Step 4:** Run the test → PASS.

- [ ] **Step 5 (bulk option load): failing test** in `MultiCurrencyStateBuilderTest` asserting the per-currency option reads happen once via a counting `pre_option_*` filter, then bulk-load the `_exchange_rate_*`, `_manual_rate_*`, `_price_charm_*`, `_price_rounding_*` options for all enabled currencies in `build()` before the per-currency loop and pass the preloaded map into `apply_currency_settings()` / `uses_manual_rate()`. `build()` already memoizes `cached_state`, so this only affects the cold build.

- [ ] **Step 6 (memoize backtrace): failing test** in `MultiCurrencySubscriptionsCompatibilityControllerTest` asserting `is_call_in_backtrace()` computes once per request for the same expected-calls set; cache by a hash of `$expected_calls` in a per-request static:

```php
	protected function is_call_in_backtrace( array $expected_calls ): bool {
		static $memo = array();
		$key = md5( implode( '|', $expected_calls ) );
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}
		// ... existing debug_backtrace scan ...
		$memo[ $key ] = $found;
		return $found;
	}
```

Note: the per-request static must be cleared between cart mutations if the expected call can appear/disappear within a request — key includes `$expected_calls` only, so guard correctness by confirming the backtrace truth value for a given call-set is stable within a request (it is: presence of a function in the current call stack at the hook point). Apply the same pattern to the Bookings/Deposits/FedEx/UPS/Points controllers (or hoist the shared helper).

- [ ] **Step 7 (cart-type cache): failing test** asserting `get_subscription_type_from_cart()` recomputes only when the cart changes; cache the result in a per-request static keyed on `$type`, cleared on `woocommerce_cart_updated` and `woocommerce_cart_emptied`.

- [ ] **Step 8:** Run all four MC suites → PASS:

Run: `pnpm test:php:env -- --filter MultiCurrencyAnalyticsControllerTest`
Run: `pnpm test:php:env -- --filter MultiCurrencyStateBuilderTest`
Run: `pnpm test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityControllerTest`

- [ ] **Step 9: Commit (one logical commit per finding)**

```bash
git commit -am "perf(multi-currency): cache has-multi-currency-orders existence query

Refs review finding 1062b791"
# ... separate commits for f42b6e68, f35bc174, e0e84777 ...
```

---

## Phase 3 — Shared parity bugs worth fixing in core.

Each is present in the client too (not a regression), but functionally wrong and cheap to fix; fixing in core delivers the "do better than the client" goal. All are backward compatible (bug fixes, no surface change).

### Task 3.1: `get_disputes_summary` must pass filters flat (finding `0175d50d`)

**Files:** `Api/WooPaymentsApiClient.php:998`; test in `WooPaymentsApiClientTest`.

- [ ] **Step 1:** Failing test asserting the query string is `match=...` not `0[match]=...`:

```php
public function test_get_disputes_summary_passes_filters_flat(): void {
    $this->fake_http->expect_query_contains( 'match=' );
    $this->fake_http->expect_query_not_contains( '0%5Bmatch%5D=' );
    $this->client->get_disputes_summary( array( 'match' => 'all' ) );
}
```

- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Change line 998 to:

```php
		return $this->request( $filters, self::DISPUTES_API . '/summary', 'GET' );
```

- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `fix(payments): pass dispute summary filters flat to the platform` (Refs 0175d50d).

### Task 3.2: Allow hyphens in route resource IDs (finding `6022b9aa`)

**Files:** `Api/WooPaymentsApiClient.php:1729-1734`; test in `WooPaymentsApiClientTest`.

- [ ] **Step 1:** Failing test asserting a hyphenated id (e.g. `pi_3Nxyz-abc`) is accepted by `validate_route_resource_id`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Change the regex to match `validate_document_id`:

```php
		if ( '' === $id || ! preg_match( '/^[\w-]+$/', $id ) ) {
```

- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): accept hyphenated Stripe IDs in route validation` (Refs 6022b9aa).

### Task 3.3: `rawurlencode` the VAT number in the API path (finding `bd8596d0`)

**Files:** `Api/WooPaymentsApiClient.php:1282-1289`.

- [ ] **Step 1:** Failing test asserting the requested path encodes a VAT containing a slash/space.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Change line 1285 to `self::VAT_API . '/' . rawurlencode( $vat_number )`.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): rawurlencode VAT number in API path` (Refs bd8596d0).

### Task 3.4: Give the retry backoff a real, jittered base (finding `54b15aba`)

**Files:** `Api/WooPaymentsApiClient.php:40` and the `usleep` at line 1894.

- [ ] **Step 1:** Change `REQUEST_RETRIES_BACKOFF_MICROSECONDS = 250` to a millisecond base, e.g. `private const REQUEST_RETRIES_BACKOFF_MICROSECONDS = 250000;` (250 ms) and add jitter at the call site:

```php
			$backoff = self::REQUEST_RETRIES_BACKOFF_MICROSECONDS * ( 2 ** $retries );
			usleep( $backoff + wp_rand( 0, (int) ( $backoff / 4 ) ) );
```

- [ ] **Step 2:** Run: `pnpm test:php:env -- --filter WooPaymentsApiClientTest` (ensure retry tests still pass; if a test asserts the exact constant, update it). **Step 3:** Commit `perf(payments): use a jittered millisecond retry backoff` (Refs 54b15aba).

### Task 3.5: Guard `DateTimeZone` in the transactions list request (finding `8ad0a91a`)

**Files:** `Providers/WooPayments/WooPaymentsTransactionsListRequest.php:~173`.

- [ ] **Step 1:** Failing test passing an invalid `user_timezone` and asserting no exception (fallback to UTC), matching `WooPaymentsReportsRestController`'s guarded version.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Wrap the `new DateTimeZone( $user_timezone )` in try/catch, falling back to `new DateTimeZone( 'UTC' )` on `Exception`.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): guard user timezone parsing in transactions list request` (Refs 8ad0a91a).

### Task 3.6: Restore `$wpdb->prepare()` in analytics SQL projection (finding `b6ab58da`)

Core swapped the client's single-currency `$wpdb->prepare()` for `esc_sql()` interpolation — a step back. Use prepared statements with an IN-list placeholder helper.

**Files:** `Services/MultiCurrencyAnalyticsSqlProjectionService.php:~121`.

- [ ] **Step 1:** Failing test asserting the generated clause uses placeholders (assert via a `query` filter capturing the prepared SQL, or unit-test the clause builder returns a `%s`-bearing prepared fragment).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Replace `esc_sql()` + `sprintf()` with `$wpdb->prepare()` using a generated placeholder list (`implode( ', ', array_fill( 0, count( $codes ), '%s' ) )`) for the IN clause and `%s` for the single-currency `=` clause.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(multi-currency): use prepared statements for analytics where clauses` (Refs b6ab58da).

### Task 3.7: `wp_kses_post` the WooPay custom message (finding `6a3e12e5`)

**Files:** `Providers/WooPayments/WooPaymentsSettingsService.php:530-534`.

- [ ] **Step 1:** Failing test asserting a `<script>` in `woopay_custom_message` is stripped from the stored option.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Wrap line 534: `$settings['platform_checkout_custom_message'] = wp_kses_post( $custom_message );` (keep the shortcode `str_replace` calls before kses).
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): sanitize WooPay custom message before storing` (Refs 6a3e12e5).

### Task 3.8: Sanitize mobile terminal `metadata` (finding `8965abd1`)

**Files:** `Providers/WooPayments/WooPaymentsMobileRestController.php` (`create_terminal_intent` / `register_reader` metadata handling) and/or the route `args` schema.

- [ ] **Step 1:** Failing test asserting metadata values are `sanitize_text_field`'d (and keys `sanitize_key`'d) before being forwarded to the API client.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add a `sanitize_callback` to the `metadata` route arg (or map the array through `sanitize_text_field`/`sanitize_key` before `set_metadata()`), capping array depth/size.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): sanitize terminal intent metadata from the mobile API` (Refs 8965abd1).

### Task 3.9: Tighten mobile `order_id` route regex (finding `1a302332`)

**Files:** `Providers/WooPayments/WooPaymentsMobileRestController.php` route definitions.

- [ ] **Step 1:** Change the inconsistent `(?P<order_id>\w+)` to `(?P<order_id>\d+)` on the order-scoped routes to match the sibling that already uses `\d+` and the `absint()` consumption.
- [ ] **Step 2:** Run: `pnpm test:php:env -- --filter WooPaymentsMobileRestControllerTest` (PASS; update any test asserting the old pattern). **Step 3:** Commit `fix(payments): use numeric order_id regex across mobile routes` (Refs 1a302332).

### Task 3.10: Add a nonce check to the referrer Tracks handler (finding `7522b93d`)

The `admin_init` handler records a Tracks event from `wcpay_referrer`/`wcpay_referrer_stage` GET params with only a capability check. Parity with the client, but CSRF-pollutable.

**Files:** `Providers/WooPayments/WooPaymentsOperationalQueueService.php:~496` (the handler).

- [ ] **Step 1:** Failing test asserting the event is not recorded when the nonce is missing/invalid.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Verify a nonce on the referrer query (`wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wcpay-referrer' )`) before recording; emit the nonce wherever the referrer URL is generated. If the referrer URL is generated by the client/onboarding redirect (cross-surface), gate the Tracks recording behind both capability and nonce, and no-op silently when absent (do not break the redirect).
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): require a nonce before recording referrer tracks` (Refs 7522b93d).

### Task 3.11: Lock get-or-create Stripe customer (finding `61a68295`)

**Files:** `Providers/WooPayments/WooPaymentsCustomerService.php:~163`.

- [ ] **Step 1:** Failing test simulating two concurrent get-or-create calls for the same user resolving to a single `create_customer` call (use a counting API-client double + a pre-seeded `wp_cache_add` lock).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Wrap the get→create in a per-user advisory lock via `wp_cache_add( "wcpay_cust_lock_{$user_id}", 1, 'woopayments', 10 )`; if the lock is held, re-read the stored customer id instead of creating. Release the lock after write.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): guard concurrent Stripe customer creation per user` (Refs 61a68295).

### Task 3.12: Include HTTP status in Capital error responses (finding `4be7ec7c`)

**Files:** `Providers/WooPayments/WooPaymentsCapitalRestController.php:~208`.

- [ ] **Step 1:** Failing test asserting Capital error `WP_Error` carries `array( 'status' => 4xx/5xx )` in its data, consistent with sibling controllers.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Add the `'status'` key to the `WP_Error` data in the Capital error path(s).
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): include HTTP status in Capital error responses` (Refs 4be7ec7c).

### Task 3.13: Confirm the settings-save race needs no change (finding `a9e93194`)

Core already batched the read-modify-write into a single `update_option` (an improvement over the client's per-field writes). No code change; document the residual last-writer-wins is the same as core options elsewhere.

- [ ] **Step 1:** Add a one-line comment above the `update_option( self::SETTINGS_OPTION, $settings )` call (line 583) noting the single-write design and that concurrent admin saves are last-writer-wins by design (review finding a9e93194). **Step 2:** Commit `docs(payments): note settings save is a single batched write` (Refs a9e93194).

---

## Phase 4 — Accessibility (new-in-core React admin).

### Task 4.1: Stop double-announcing copy/empty-state success (findings `90565c18`, `bccc465d`)

Both fire `speak()` AND render an `aria-live` region with the same text, so screen readers announce twice. Keep one channel.

**Files:**
- Modify: `client/admin/client/woopayments/admin/payout-details.tsx:~271` (copy success)
- Modify: `client/admin/client/woopayments/admin/capital/page.tsx:~357` (empty state rendered twice)
- Test: `.../admin/test/payout-details-page.test.tsx`, `.../admin/test/capital-page.test.tsx`

- [ ] **Step 1:** Failing test (payout-details) asserting the success string is announced through exactly one mechanism — assert the visible duplicate empty-state node count is 1 for capital, and that copy success uses `speak()` without also mounting a same-text `aria-live` node (or vice versa).
- [ ] **Step 2:** Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- payout-details-page` → FAIL.
- [ ] **Step 3:** Remove the redundant channel: for copy success keep `speak( __( 'Copied to clipboard', 'woocommerce' ) )` and drop the duplicate `aria-live` region (or keep the region and drop `speak()`). For capital, render the empty-state message once (remove the duplicate node), keeping a single polite status region.
- [ ] **Step 4:** Run both JS tests → PASS.
- [ ] **Step 5:** Commit `fix(payments): remove duplicate screen-reader announcements in admin` (Refs 90565c18, bccc465d).

### Task 4.2: Give the Dash placeholder an announceable role (finding `99309eb5`)

**Files:** `client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:~11`.

- [ ] **Step 1:** Failing test asserting the placeholder exposes an accessible name via a role-bearing element (e.g. `role="img"` with `aria-label`, or visually-hidden text), not a bare `<span aria-label>`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Replace the bare `<span aria-label="…">—</span>` with `<span role="img" aria-label={ __( 'Not available', 'woocommerce' ) }>—</span>` (or render visually-hidden text).
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): make dash placeholder announceable` (Refs 99309eb5).

### Task 4.3: Only set `aria-controls` when the target exists (finding `901cb561`)

**Files:** `client/admin/client/woopayments/settings/payment-methods-list.tsx:~557`.

- [ ] **Step 1:** Failing test asserting the trigger has no `aria-controls` while the tooltip/controlled element is unmounted, and gains it when expanded.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Make `aria-controls` conditional: `aria-controls={ isOpen ? tooltipId : undefined }`.
- [ ] **Step 4:** Run → PASS. **Step 5:** Commit `fix(payments): set aria-controls only when the tooltip is mounted` (Refs 901cb561).

---

## Phase 5 — Test and documentation hardening.

### Task 5.1: Cover nonce rejection for the `update_order_status` AJAX endpoint (finding `5bd7b33b`)

**Files:** `tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php`.

- [ ] **Step 1:** Add a test asserting the handler rejects (no order mutation, failure response) when the nonce is missing/invalid.
- [ ] **Step 2:** Run: `pnpm test:php:env -- --filter WooPaymentsCheckoutAjaxControllerTest` → PASS (the production code already verifies the nonce; this closes the coverage gap). If it fails, the nonce check is missing — add it before mutating order state, then re-run.
- [ ] **Step 3:** Commit `test(payments): cover nonce rejection for update_order_status ajax` (Refs 5bd7b33b).

### Task 5.2: Use WC framework `set_up`/`tear_down` in MC tests (finding `3593fad3`)

**Files:** `tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php` and any sibling using raw `setUp`/`tearDown` that mutate superglobals.

- [ ] **Step 1:** Rename `setUp()`→`set_up()` and `tearDown()`→`tear_down()` (calling `parent::set_up()`/`parent::tear_down()`), so the WC test framework restores `$_GET`/`$_SERVER` between tests.
- [ ] **Step 2:** Run: `pnpm test:php:env -- --filter MultiCurrencyRequestContextTest` → PASS.
- [ ] **Step 3:** Commit `test(multi-currency): use WC framework setup/teardown hooks` (Refs 3593fad3).

### Task 5.3: Await `userEvent` in documents-page tests (finding `23405cc0`)

**Files:** `client/admin/client/woopayments/admin/test/documents-page.test.tsx`.

- [ ] **Step 1:** Add `await` to every `userEvent.click()`/`userEvent.type()` in the VAT-flow tests; make the enclosing test callbacks `async`.
- [ ] **Step 2:** Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- documents-page` → PASS.
- [ ] **Step 3:** Commit `test(payments): await user-event interactions in documents-page tests` (Refs 23405cc0).

### Task 5.4: Reduce blanket data-hook mocking in settings-page tests (finding `11adbec5`)

**Files:** `client/admin/client/woopayments/settings/test/settings-page.test.tsx`.

- [ ] **Step 1:** Replace the whole-module `jest.mock( '../data/hooks' )` with targeted stubs for only the hooks that require network/store I/O, leaving pure-derivation hooks real, so a contract change in `useSettings()` surfaces.
- [ ] **Step 2:** Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- settings-page` → PASS.
- [ ] **Step 3:** Commit `test(payments): stop over-mocking the settings data layer` (Refs 11adbec5).

### Task 5.5: Replace `assertTrue(true)` placeholders with real assertions (finding `7c7309ac`)

The cited line is fine; the real placeholders are in the route-assertion helpers (`WooPaymentsWooPaySessionControllerTest`, `WooPaymentsDisputeReadinessRestControllerTest`).

**Files:** the `assertRouteHasMethod()`-style helpers that end in `assertTrue( true )`.

- [ ] **Step 1:** Replace the trailing `assertTrue( true )` with a positive assertion on the matched route/method (e.g. `assertContains( $method, $route_methods )`) and keep the `$this->fail()` miss branch.
- [ ] **Step 2:** Run the two suites → PASS.
- [ ] **Step 3:** Commit `test(payments): assert route methods explicitly instead of assertTrue(true)` (Refs 7c7309ac).

### Task 5.6: Add `@since` to `WOOPAYMENTS_SECTION_NAME` (finding `9d771cef`)

**Files:** `includes/admin/settings/class-wc-settings-payment-gateways.php` (constant declaration).

- [ ] **Step 1:** Add a docblock with `@since <current version>` above `const WOOPAYMENTS_SECTION_NAME = 'woocommerce_payments';` (read the current version from `includes/class-woocommerce.php` `$version`, drop any `-dev`). The value already matches the client's `woocommerce_payments` gateway id, so the deep-link contract is preserved — no value change.
- [ ] **Step 2:** Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` → clean.
- [ ] **Step 3:** Commit `docs(payments): document WOOPAYMENTS_SECTION_NAME since version` (Refs 9d771cef).

---

## Phase 6 — Documented closures (no code).

Record these in the PR description / review thread so they do not resurface; no code changes:

- [ ] **`048c849b`** — file endpoint `__return_true` is **by-design parity**: the client uses an empty `permission_callback` too, and both gate inside the handler to a `business_logo`/`business_icon` purpose allowlist with a purpose cache. Close as not-a-vulnerability.
- [ ] **`339eeb0c`** — `$response['data']` is always normalized to an array by the sole supplier (`WooPaymentsFailedEventsProvider`); the described fatal is unreachable. Optional: add a defensive `?? array()` at the call site for self-evidence (1-line, no test needed). Close.
- [ ] **`106f2823`** — accept-dispute modal focus is handled by WP `<Modal>` `useFocusReturn()` on dismiss; core's extra machinery intentionally moves focus to the resolved heading on successful accept. Not a bug. Close.
- [ ] Re-rate in the review record: **`53de2d4b`** and **`7c43c8da`** are improvements over the client, not regressions (still hardened in Task 2.4).

---

## Phase 7 — Gate: changelog, lint, full suites.

- [ ] **Step 1: Changelog entry** (required before PR):

Run: `pnpm --filter=@woocommerce/plugin-woocommerce changelog add`
Add one entry summarizing the robustness/BC hardening (type: `fix`). Repeat for any other package touched.

- [ ] **Step 2: Lint changed PHP (per-file + branch):**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`
Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`
Expected: no warnings.

- [ ] **Step 3: PHPStan on modified files:**

Run (from `plugins/woocommerce`): `composer exec -- phpstan analyse <each modified PHP file> --memory-limit=2G`
Expected: no new errors; do not add to `phpstan-baseline.neon` (fix in code).

- [ ] **Step 4: Run the full affected PHP + JS suites:**

Run: `pnpm test:php:env -- --filter WooPayments`
Run: `pnpm test:php:env -- --filter MultiCurrency`
Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- woopayments`
Expected: PASS.

- [ ] **Step 5: Update the parity session record** — set `parity.md` and this plan's `status: final`, and note in the session `README.md` which findings were fixed vs. closed.

---

## Self-Review notes (coverage map)

Every one of the 45 review findings is addressed: P0 (bfcb6ce5, 0b11bf8a, 147606e9, deb2d8c9, 5076c7dc/6ea1b4a8/24a6f21d, d3111113), P1 (c49baad5, 3eece18f), P2 (c17bc8f3, 195a39ae, 47644832, 53de2d4b, 7c43c8da, 1062b791, f42b6e68, f35bc174, e0e84777), P3 (0175d50d, 6022b9aa, bd8596d0, 54b15aba, 8ad0a91a, b6ab58da, 6a3e12e5, 8965abd1, 1a302332, 7522b93d, 61a68295, 4be7ec7c, a9e93194), P4 (90565c18, bccc465d, 99309eb5, 901cb561), P5 (5bd7b33b, 3593fad3, 23405cc0, 11adbec5, 7c7309ac, 9d771cef), P6 closures (048c849b, 339eeb0c, 106f2823). BC is preserved throughout: no `wcpay_*` hook renamed, the removed onboarding filters are re-fired under `_deprecated_hook()`, the GET account-session route is retained, the WooPay auth filter is made strengthen-only (existing callbacks keep working), and the native list-request object regains a `send()` so client extensions do not fatal.
