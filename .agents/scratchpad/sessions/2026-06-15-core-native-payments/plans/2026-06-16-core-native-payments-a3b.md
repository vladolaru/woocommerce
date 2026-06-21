---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 15:19
last_updated: 2026-06-16 15:35
tool: writing-plans
reconciles:
  - implementation-log.md
status: final
---

# A3b WooPayments Native Transport Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a core-owned WooPayments transport foundation and switch refund, capture, and cancel off the legacy gateway bridge while deliberately keeping checkout charge on the guarded legacy path.

**Architecture:** Introduce a provider-scoped `Api/` layer under `src/Internal/Payments/Providers/WooPayments/` that owns the Jetpack-signed WPCOM transport, endpoint construction, idempotency-header lifting, and API error normalization for non-checkout money operations. Keep `WooPaymentsProviderGatewayAdapter::charge()` on the legacy gateway because the checkout intent-creation and browser confirmation path is still plugin-backed, but have `refund()`, `capture()`, and `cancel()` prefer the new transport whenever the site has a live Jetpack/WPCOM connection and fall back to the legacy gateway bridge only when that transport is unavailable.

**Tech Stack:** WooCommerce Core PHP, Jetpack connection client, WooCommerce DI container, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Lock the Native Transport Contract With RED Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/FakeWooPaymentsHttpClient.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`

- [x] **Step 1: Add focused API-client RED coverage**

Create `WooPaymentsApiClientTest.php` to lock these transport rules:

```php
/**
 * @testdox Should build the site-scoped WPCOM endpoint and lift idempotency_key into the request headers.
 */
public function test_request_lifts_idempotency_key_and_preserves_filtered_params(): void {
	$http_client = new FakeWooPaymentsHttpClient();
	$http_client->blog_id = 123;
	$http_client->response = array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( array( 'id' => 're_test' ) ),
	);

	$sut = new WooPaymentsApiClient();
	$sut->init( $http_client );

	add_filter(
		'wcpay_api_request_params',
		static function ( array $params ): array {
			$params['metadata']['filtered'] = 'yes';
			return $params;
		},
		10,
		3
	);

	$sut->refund_charge( 'ch_test', 250, 'requested_by_customer', 'native_transport', 'idem_test' );

	$this->assertSame( '/sites/123/wcpay/refunds/ch_test', $http_client->last_path );
	$this->assertSame( 'POST', $http_client->last_method );
	$this->assertSame( 'idem_test', $http_client->last_headers['Idempotency-Key'] );
	$this->assertStringNotContainsString( 'idempotency_key', (string) $http_client->last_body );
	$this->assertStringContainsString( '\"filtered\":\"yes\"', (string) $http_client->last_body );
}
```

Also add an error-path test asserting that a `402` JSON error payload becomes a `WooPaymentsApiException` with the server message and code preserved.

- [x] **Step 2: Add adapter RED coverage for transport-first non-checkout operations**

Extend `WooPaymentsProviderGatewayAdapterTest.php` so that:

```php
/**
 * @testdox Refund should prefer the native transport before the legacy gateway bridge.
 */
public function test_refund_prefers_native_transport_when_available(): void;

/**
 * @testdox Capture should prefer the native transport before the legacy gateway bridge.
 */
public function test_capture_prefers_native_transport_when_available(): void;

/**
 * @testdox Cancel should prefer the native transport before the legacy gateway bridge.
 */
public function test_cancel_prefers_native_transport_when_available(): void;
```

Each test should inject a fake transport-backed API client plus a recording legacy gateway and assert that the returned `PaymentOutcome` comes from the transport path while the legacy gateway call count stays at `0`.

- [x] **Step 3: Run the RED suite**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest'
```

Expected RED: missing provider-side API classes and missing transport-first behavior in the gateway adapter.

### Task 2: Build the Native WooPayments Transport Foundation

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClientInterface.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiException.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`

- [x] **Step 1: Add the provider-scoped HTTP client seam**

Create `WooPaymentsHttpClientInterface` with methods:

```php
public function is_connected(): bool;
public function get_blog_id(): ?int;
public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70 );
```

Implement `WooPaymentsHttpClient` using the site-wide Jetpack connection plus `Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog()`:

```php
$site_id = $this->get_blog_id();
$response = Jetpack_Connection_Client::wpcom_json_api_request_as_blog(
	sprintf( '/sites/%d/%s', $site_id, ltrim( $path, '/' ) ),
	'2',
	array(
		'headers' => $headers,
		'method'  => $method,
		'timeout' => $timeout,
	),
	$body,
	'wpcom'
);
```

Fail closed when the site is not connected or the blog ID is unavailable.

- [x] **Step 2: Implement the provider API client**

Create `WooPaymentsApiClient` with:

```php
public function init( WooPaymentsHttpClient $http_client, ?WooPaymentsLegacyRuntime $legacy_runtime = null ): void;
public function is_available(): bool;
public function refund_charge( string $charge_id, ?int $amount, ?string $reason, string $source, string $idempotency_key ): array;
public function capture_intention( string $intent_id, int $amount_to_capture, array $metadata = array() ): array;
public function cancel_intention( string $intent_id ): array;
```

Internal request rules:
- Keep `WooPaymentsHttpClientInterface` as the provider transport contract, but inject the concrete `WooPaymentsHttpClient` until the runtime container grows an explicit interface binding.
- Apply the `wcpay_api_request_params` and `wcpay_api_request_headers` filters.
- Default `test_mode` from the existing WooPayments runtime mode when available, otherwise from the `wcpay_test_mode` option.
- Lift `idempotency_key` from params into the `Idempotency-Key` header for non-GET requests and strip it from the JSON body.
- Send requests to `/sites/{blog_id}/wcpay/{api}` with `Content-Type: application/json; charset=utf-8`.
- Decode JSON responses and throw `WooPaymentsApiException` on malformed JSON, transport failures, or `4xx/5xx` API responses while preserving the server error code and message in the exception.

- [x] **Step 3: Switch non-checkout adapter operations to the new transport**

Update `WooPaymentsProviderGatewayAdapter` so it receives the new API client via `init()` alongside `WooPaymentsLegacyRuntime`.

Required behavior:
- `charge()` remains on the legacy gateway path unchanged.
- `refund()`, `capture()`, and `cancel()` use the new API client when `is_available()` is true.
- When the native transport is unavailable, those three operations fall back to the existing legacy gateway bridge.
- The normalized `PaymentOutcome` shapes stay equivalent to the current adapter contract:
  - refund success → `STATUS_COMPLETED`
  - capture success with `status === 'succeeded'` → `STATUS_COMPLETED`
  - capture response with `status === 'requires_capture'` and no message → `STATUS_AUTHORIZED`
  - cancel success with `status === 'canceled'` → `STATUS_CANCELED`
  - failures preserve server `error_code` and `error_message` in outcome data

- [x] **Step 4: Run the focused GREEN suite**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest'
```

Expected GREEN: the new provider-side transport passes its focused request/error tests and the adapter now prefers it for refund/capture/cancel.

### Task 3: Broader Verification, Session Logging, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-a3b-native-transport-foundation`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-a3b.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add the changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-a3b-native-transport-foundation
```

with:

```text
Significance: patch
Type: dev
Comment: Add a core-owned WooPayments transport foundation for native refund, capture, and cancel operations.
```

- [x] **Step 2: Run focused PHP verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsProviderGatewayAdapterTest|PaymentProcessingServiceTest|NativeWooPaymentsGatewayTest'
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClient.php
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClient.php --memory-limit=2G
git diff --check
```

Then run direct changed-file PHPCS if the branch wrapper trips over deleted files again:

```bash
./vendor/bin/phpcs-changed -s --git
```

- [x] **Step 3: Run branch-level verification and commit**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/changelog/add-native-payments-a3b-native-transport-foundation
git commit -m "feat(payments): add native woopayments transport foundation"
```

Completed as `be53921273` (`feat(payments): add native woopayments transport foundation`). The verified git range for A3b is `3428350c17...be53921273`, and checkout `charge()` intentionally remains on the guarded legacy bridge.
