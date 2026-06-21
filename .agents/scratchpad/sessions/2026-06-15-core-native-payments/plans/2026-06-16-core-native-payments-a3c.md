---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 15:45
last_updated: 2026-06-16 16:02
tool: writing-plans
reconciles:
  - implementation-log.md
status: final
---

# A3c WooPayments Native Checkout Charge Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a core-owned positive-amount WooPayments checkout charge path that creates and confirms PaymentIntents through the native transport, persists customer IDs in Core-owned storage, retries missing-customer failures by recreating the customer, and preserves the order meta and display writes required before order completion or later browser confirmation.

**Architecture:** Extend the provider-scoped `Api/` layer with customer and create-and-confirm intention endpoints, add a WooPayments-owned customer service that mirrors the plugin's mode-aware user/session customer persistence, and switch `WooPaymentsProviderGatewayAdapter::charge()` to prefer a native positive-amount card path when the transport is available. Keep zero-total/setup-intent flows and runtime registration fail-closed for now: zero-total checkout still follows the existing generic no-external-payment behavior, and `WooPaymentsProvider::can_process_payments()` remains unchanged until the shopper-side browser seam is ported.

**Tech Stack:** WooCommerce Core PHP, WooPayments Jetpack/WPCOM transport, WooCommerce DI container, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Lock the Native Charge Contract With RED Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCustomerServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`

- [ ] **Step 1: Add RED coverage for customer persistence and recreation**

Create `WooPaymentsCustomerServiceTest.php` with focused cases for:

```php
public function test_get_or_create_customer_id_uses_mode_aware_user_storage_for_logged_in_customers(): void;
public function test_get_or_create_customer_id_uses_session_storage_for_guests(): void;
public function test_recreate_customer_replaces_a_missing_customer_id_and_updates_storage(): void;
```

Minimum expectations:
- live mode stores `_wcpay_customer_id_live`, test mode stores `_wcpay_customer_id_test`
- guest checkout stores `wcpay_customer_id` in `WC()->session`
- recreating a customer deletes the stale persisted ID and stores the replacement

- [ ] **Step 2: Extend API-client RED coverage for customers and PaymentIntents**

Add new cases to `WooPaymentsApiClientTest.php` asserting:

```php
public function test_create_customer_posts_to_customers_endpoint(): void;
public function test_update_customer_posts_to_customer_resource(): void;
public function test_create_and_confirm_payment_intention_lifts_idempotency_and_preserves_request_shape(): void;
```

The PaymentIntent request test should prove that the native client sends:
- `POST /sites/{blog_id}/wcpay/intentions`
- `Idempotency-Key` header instead of `idempotency_key` in the body
- `amount`, `currency`, `customer`, `metadata`, `payment_method_types`, and exactly one of `payment_method` or `confirmation_token`

- [ ] **Step 3: Add RED adapter coverage for native charge behavior**

Extend `WooPaymentsProviderGatewayAdapterTest.php` with cases for:

```php
public function test_charge_prefers_native_positive_amount_transport_when_available(): void;
public function test_charge_retries_after_missing_customer_by_recreating_customer(): void;
public function test_charge_sets_card_display_meta_before_returning_requires_action(): void;
```

Required assertions:
- the native path bypasses `RecordingLegacyGateway::process_payment()`
- `PaymentOutcome` carries the response payment method ID and customer ID, not just the request credential
- `requires_action` returns `#wcpay-confirm-pi:{order}:{secret}:{nonce}`
- the order already has `_wcpay_payment_method_details`, `last4`, `_card_brand`, and a non-generic payment method title before control returns

- [ ] **Step 4: Run the RED suite**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCustomerServiceTest|WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest'
```

Expected RED: missing customer service, missing API-client methods, and legacy-only charge behavior in the adapter.

### Task 2: Build the Native Customer and Charge Foundation

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCustomerService.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`

- [ ] **Step 1: Add a Core-owned WooPayments customer service**

Create `WooPaymentsCustomerService` with:

```php
public const DEPRECATED_CUSTOMER_ID_OPTION = '_wcpay_customer_id';
public const LIVE_CUSTOMER_ID_OPTION = '_wcpay_customer_id_live';
public const TEST_CUSTOMER_ID_OPTION = '_wcpay_customer_id_test';
public const CUSTOMER_ID_SESSION_KEY = 'wcpay_customer_id';
```

Methods to implement:

```php
public function get_or_create_customer_id_for_order( WC_Order $order ): string;
public function recreate_customer_for_order( WC_Order $order ): string;
public function map_customer_data( WC_Order $order ): array;
```

Behavior:
- logged-in customers read/write user options keyed by current mode
- guests read/write `WC()->session`
- when the deprecated `_wcpay_customer_id` exists, migrate it to the mode-aware key before use
- `map_customer_data()` mirrors the plugin contract for name, description, email, phone, billing address, and shipping address when present

- [ ] **Step 2: Extend the native API client with customer and charge endpoints**

Add methods:

```php
public function create_customer( array $customer_data ): string;
public function update_customer( string $customer_id, array $customer_data = array() ): void;
public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array;
```

Request rules:
- `create_customer()` -> `POST customers`
- `update_customer()` -> `POST customers/{customer_id}`
- `create_and_confirm_payment_intention()` -> `POST intentions`
- keep `test_mode` defaulting and filter application inside the shared request helper
- validate that exactly one of `payment_method` or `confirmation_token` is sent

- [ ] **Step 3: Port positive-amount charge to the native transport**

Update `WooPaymentsProviderGatewayAdapter::init()` to receive `WooPaymentsCustomerService` and implement a native charge branch with these rules:

```php
if ( $this->get_api_client()->is_available() && (float) $order->get_total() > 0 ) {
    // resolve customer
    // build metadata + billing updates + save-card flags
    // create and confirm native PaymentIntent
    // on resource_missing/customer failure: recreate customer and retry once
    // set order display/meta writes before returning
}
```

Native request shape for this slice:
- `amount`
- `currency`
- `customer`
- `metadata`
- `payment_method_types` => `array( 'card' )`
- `payment_method` or `confirmation_token`
- `payment_method_update_data.billing_details` for reusable saved methods when not using legacy `card_` / `src_`
- `setup_future_usage` => `off_session` when `save_payment_method` is true
- `cvc_confirmation` when present
- fingerprint merged into metadata when present

Keep these behaviors explicit:
- completed success -> `STATUS_COMPLETED`
- `requires_capture` -> `STATUS_AUTHORIZED`
- `processing` -> `STATUS_PENDING_ASYNC`
- `requires_action|requires_confirmation` -> `STATUS_REQUIRES_CUSTOMER_ACTION`
- `requires_payment_method` or a surfaced provider error -> `STATUS_FAILED`
- when customer action is required, return `#wcpay-confirm-pi:{order_id}:{client_secret}:{nonce}`

Pre-return order writes for the native branch:
- meta: `_charge_id`, `_intention_status`, `_wcpay_intent_currency`, `_wcpay_payment_transaction_id`, `_charge_risk_level`, `_wcpay_payment_method_details`, `last4`, `_card_brand`
- order title: `Link` for Link wallet cards, otherwise `Credit / Debit Cards`

Leave the legacy bridge as fallback only when the native transport is unavailable. Do not widen zero-total or setup-intent handling in this package.

- [ ] **Step 4: Run the focused GREEN suite**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCustomerServiceTest|WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest'
```

Expected GREEN: the new customer service, charge API methods, and transport-first positive-amount adapter path all pass their focused contract tests.

### Task 3: Broader Verification, Logging, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-a3c-native-charge-foundation`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-a3c.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add the changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-a3c-native-charge-foundation
```

with:

```text
Significance: patch
Type: dev
Comment: Add a core-owned WooPayments native checkout charge foundation for positive-amount card payments.
```

- [ ] **Step 2: Run broader PHP verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentProcessingServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderTest|WooPaymentsCustomerServiceTest|WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest'
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCustomerService.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCustomerService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php --memory-limit=2G
git diff --check
```

Then run direct changed-file PHPCS from `plugins/woocommerce`:

```bash
./vendor/bin/phpcs-changed -s --git
```

- [ ] **Step 3: Run branch-level verification and commit**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/changelog/add-native-payments-a3c-native-charge-foundation
git commit -m "feat(payments): add native woopayments charge foundation"
```

Commit scope note: this package intentionally keeps zero-total/setup-intent checkout on the existing deferred path and keeps `WooPaymentsProvider::can_process_payments()` fail-closed until the shopper-side browser seam is ported.
