---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 23:45
target: A4az fraud ruleset refresh/defaulting parity
reconciles:
  - analysis-a4az-fraud-ruleset-refresh-defaulting-parity.md
last_updated: 2026-06-21 00:20
status: final
---

# A4az Fraud Ruleset Refresh Defaulting Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments fraud ruleset read parity by refreshing missing local fraud ruleset caches from the platform and fail-closing the UI contract when refresh is unavailable.

**Architecture:** Keep fraud ruleset orchestration inside the native WooPayments provider settings service, behind the existing provider API client abstraction. Add the missing API client GET method at the transport boundary and use a per-service-instance refresh guard so a single settings response does not duplicate platform reads.

**Tech Stack:** WooCommerce Core PHP, native WooPayments API client, PHPUnit, WP options/transients.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add `get_latest_fraud_ruleset()` as the provider transport method for `GET fraud_ruleset`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`: add refresh/defaulting/matching helpers and widen the advanced fraud settings return shape to array or `"error"`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: cover the new GET endpoint.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingSettingsApiClient.php`: add deterministic latest-ruleset response/exception/count hooks for settings service tests.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`: add RED/GREEN tests for refresh, not-found defaulting, error sentinel, no duplicate fetch, and transient hit behavior.
- Add a WooCommerce changelog entry after code is green.

## Task 1: RED API Client Endpoint Test

- [x] Add `test_get_latest_fraud_ruleset_reads_from_fraud_ruleset_endpoint()` to `WooPaymentsApiClientTest.php`.

```php
public function test_get_latest_fraud_ruleset_reads_from_fraud_ruleset_endpoint(): void {
	$http_client           = new FakeWooPaymentsHttpClient();
	$http_client->blog_id  = 123;
	$http_client->response = array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( array( 'ruleset_config' => array( array( 'key' => 'avs_verification' ) ) ) ),
	);

	$sut = new WooPaymentsApiClient();
	$sut->init( $http_client, $this->create_account_service( false ) );

	$result = $sut->get_latest_fraud_ruleset();

	$this->assertSame( array( array( 'key' => 'avs_verification' ) ), $result['ruleset_config'] );
	$this->assertSame( '/sites/123/wcpay/fraud_ruleset?test_mode=0', $http_client->last_path );
	$this->assertSame( 'GET', $http_client->last_method );
}
```

- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsApiClientTest::test_get_latest_fraud_ruleset_reads_from_fraud_ruleset_endpoint` and verify it fails because `WooPaymentsApiClient::get_latest_fraud_ruleset()` does not exist.

## Task 2: RED Settings Refresh Tests

- [x] Extend `RecordingSettingsApiClient.php` with these test hooks before product implementation uses them.

```php
public ?array $latest_fraud_ruleset_response = null;
public ?WooPaymentsApiException $latest_fraud_ruleset_exception = null;
public int $latest_fraud_ruleset_requests = 0;

public function get_latest_fraud_ruleset(): array {
	++$this->latest_fraud_ruleset_requests;
	if ( $this->latest_fraud_ruleset_exception instanceof WooPaymentsApiException ) {
		throw $this->latest_fraud_ruleset_exception;
	}
	return $this->latest_fraud_ruleset_response ?? array();
}
```

- [x] Add tests to `WooPaymentsSettingsServiceTest.php` for the contract:

```php
public function test_get_settings_refreshes_missing_fraud_ruleset_from_platform(): void
public function test_get_settings_initializes_basic_fraud_ruleset_when_platform_ruleset_is_missing(): void
public function test_get_settings_returns_fraud_error_when_platform_refresh_fails(): void
public function test_get_settings_refreshes_fraud_ruleset_once_per_settings_response(): void
public function test_get_settings_uses_cached_fraud_transient_without_platform_fetch(): void
```

- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter "WooPaymentsSettingsServiceTest::test_get_settings_refreshes_missing_fraud_ruleset_from_platform|WooPaymentsSettingsServiceTest::test_get_settings_initializes_basic_fraud_ruleset_when_platform_ruleset_is_missing|WooPaymentsSettingsServiceTest::test_get_settings_returns_fraud_error_when_platform_refresh_fails|WooPaymentsSettingsServiceTest::test_get_settings_refreshes_fraud_ruleset_once_per_settings_response|WooPaymentsSettingsServiceTest::test_get_settings_uses_cached_fraud_transient_without_platform_fetch"` and verify the failures point to missing refresh/defaulting behavior, not fixture errors.

## Task 3: GREEN Transport And Service Implementation

- [x] Add `WooPaymentsApiClient::get_latest_fraud_ruleset(): array` that calls `$this->request( array(), self::FRAUD_RULESET_API, 'GET' );`.
- [x] Add a private boolean property to `WooPaymentsSettingsService`, for example `$fraud_protection_settings_refreshed = false`, and use it as the per-instance refresh guard.
- [x] Add `maybe_refresh_fraud_protection_settings()` that returns early when the guard is set or `get_transient( 'wcpay_fraud_protection_settings' )` is already an array. On a valid platform response with `ruleset_config` array, cache it for `DAY_IN_SECONDS` and update `current_protection_level` from a native matching helper. On `WooPaymentsApiException` with code `wcpay_fraud_ruleset_not_found`, save/cache Basic rules and set `current_protection_level` to `basic`. On other failures, leave the transient missing so the UI gets `"error"`.
- [x] Add a native `get_matching_fraud_protection_level( array $ruleset ): string` helper that compares Basic, Standard, and High preset arrays with strict equality and returns `advanced` otherwise.
- [x] Call the refresh helper in both `get_current_protection_level()` and `get_advanced_fraud_protection_settings()`.
- [x] Change `get_advanced_fraud_protection_settings()` to return `array|string`; when no cached array exists after refresh, return `"error"` instead of stale local gateway settings.

## Task 4: Focused Verification

- [x] Run the focused API client test from Task 1 and the focused settings tests from Task 2; then run the full focused classes: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter "WooPaymentsApiClientTest|WooPaymentsSettingsServiceTest"`.
- [x] Run PHP syntax checks for the touched PHP files.
- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`.
- [x] Run production PHPStan for `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`.

## Task 5: Closeout

- [x] Add a WooCommerce changelog entry for the native fraud refresh parity fix.
- [x] Run changelog validation, `git diff --check -- . ':!.agents'`, and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`.
- [x] If source changes stay backend-only and deterministic tests cover the runtime contract, run a lightweight local target fraud settings/browser-log proof rather than a full visual matrix; record any logs honestly.
- [x] Request focused review on reliability/API-contract implications before committing.
- [x] Commit source/tests and changelog locally on `exp/core-native-payments`; do not push.

## Review-Fix Closure

- [x] Fail closed for malformed successful platform `ruleset_config` arrays instead of caching them.
- [x] Preserve the `"error"` sentinel round-trip through the native settings POST route.
- [x] Reject unknown fraud-settings strings at the REST boundary; only arrays and exact `"error"` are accepted.
- [x] Allow changed canonical `basic`/`standard`/`high` fraud preset saves when the `"error"` sentinel is round-tripped, while keeping same-level sentinel saves and `advanced + error` as no-ops.
- [x] Match review-enabled Standard and High platform preset rulesets.
- [x] Add no-account no-fetch coverage for the refresh guard.
- [x] Close reliability and API-contract re-reviews with no remaining critical/high/medium findings.
