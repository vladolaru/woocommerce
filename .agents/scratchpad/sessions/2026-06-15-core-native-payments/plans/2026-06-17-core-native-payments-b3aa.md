---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 00:52
target: native WooPayments Settings Payments provider onboarding API handoff
status: completed
last_updated: 2026-06-17 01:33
---

# B3aa Native WooPayments Settings Onboarding API Handoff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the generic WooCommerce > Settings > Payments provider UX working in native WooPayments mode by removing plugin-only WooPayments onboarding REST-route dependencies from the Core-owned provider payload and modal actions.

**Architecture:** Extend the Core native WooPayments API client with the onboarding API operations that Settings already needs, then inject it into `WooPaymentsService` as an optional native collaborator. The service should prefer the native client when available, preserve the existing legacy REST fallback for standalone WooPayments plugin mode, and normalize platform exceptions back into the existing `WP_Error`/`ApiException` handling so frontend behavior stays stable.

**Tech Stack:** WooCommerce Core PHP, wp-env PHPUnit, WooCommerce admin REST, Chrome/browser verification, local merge harness.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` to add Core-owned onboarding API calls for fields, test-drive init, embedded KYC session, KYC finalize, and account deletion.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php` to inject the native API client and route native-mode onboarding calls through it while retaining legacy route fallbacks.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php` to cover the new API paths and payloads.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php` to prove Settings onboarding metadata and actions work when plugin REST routes are unavailable.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-settings-payments-provider-list.md`, `implementation-log.md`, and `staging-log.md` with evidence and gate results.

## Tasks

- [x] **Task 1: Add native API-client RED coverage**

Add tests that assert:

```php
$client->get_onboarding_fields_data( 'en_US' );
// GET /sites/{blog_id}/wcpay/onboarding/fields_data?locale=en_US&test_mode=...

$client->initialize_test_drive_account( 'US', array( 'card_payments' => true ), $site_data, $user_data, $account_data, $actioned_notes );
// POST /sites/{blog_id}/wcpay/onboarding/init

$client->initialize_onboarding_embedded_kyc( true, $site_data, $user_data, $account_data, $actioned_notes );
// POST /sites/{blog_id}/wcpay/onboarding/embedded

$client->finalize_onboarding_embedded_kyc( 'en_US', WooPaymentsService::SESSION_ENTRY_DEFAULT, array() );
// POST /sites/{blog_id}/wcpay/onboarding/embedded/finalize

$client->delete_account( true );
// POST /sites/{blog_id}/wcpay/accounts/delete
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest'
```

Expected: FAIL because the new methods do not exist yet.

- [x] **Task 2: Add service RED coverage for plugin-route-free Settings onboarding**

Add tests that initialize `WooPaymentsService` with a fake native `WooPaymentsApiClient`, configure legacy `Utils::rest_endpoint_*_request` calls to return `rest_no_route`, and assert:

```php
$details = $service->get_onboarding_details( 'US', '/wc-admin/settings/payments/woopayments/onboarding' );
// business_verification.context.fields contains the native fields data and has no fields_error.

$service->get_onboarding_kyc_session( 'US', array( 'business_type' => 'individual' ) );
// returns the native embedded-session response and never depends on /wc/v3/payments/onboarding/kyc/session.
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsServiceTest'
```

Expected: FAIL because `WooPaymentsService` still calls plugin-only REST routes.

- [x] **Task 3: Implement native API-client onboarding methods**

Add minimal methods that mirror the read-only WooPayments reference API-client paths:

```php
public function get_onboarding_fields_data( string $locale = '' ): array {
    return $this->request( array( 'locale' => $locale ), 'onboarding/fields_data', 'GET' );
}

public function initialize_onboarding( bool $live_account, array $site_data, array $user_data, array $account_data, array $actioned_notes = array(), ?string $referral_code = null ): array {
    return $this->request( array( 'site_data' => $site_data, 'user_data' => $user_data, 'account_data' => $account_data, 'actioned_notes' => $actioned_notes, 'create_live_account' => $live_account, 'referral_code' => $referral_code ), 'onboarding/init', 'POST' );
}
```

Implement the embedded KYC finalize and account delete methods with the same path names as the reference plugin. Keep validation narrow and let `request()` normalize WPCOM transport failures.

- [x] **Task 4: Implement service-native handoff with legacy fallback**

Update `WooPaymentsService::init()` to accept `?WooPaymentsApiClient $api_client = null`. Add helper methods that:

```php
private function call_native_or_legacy_get( callable $native_callback, string $legacy_route ) { ... }
private function call_native_or_legacy_post( callable $native_callback, string $legacy_route, array $payload ) { ... }
```

Prefer native calls only when `$this->api_client && $this->api_client->is_available()`. Convert `WooPaymentsApiException` to `WP_Error` for action handlers so existing failure tracking remains intact. Keep the existing `Utils::rest_endpoint_*_request` fallback unchanged for active plugin runtime and older local setups.

- [x] **Task 5: Preserve native account side effects around onboarding state**

When native test-drive init succeeds, keep the same Core-owned local side effects the plugin REST controller previously caused indirectly: gateway enabled, test mode enabled for test-drive account, selected payment methods persisted, account cache refreshed through existing Core account data reads, and NOX progress updated through the already existing step methods. When native reset/disable succeeds, keep the existing NOX cleanup and onboarding test-mode option reset.

- [x] **Task 6: Run focused and broader verification gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsServiceTest|WooPaymentsRestControllerTest|WooPaymentsRestControllerIntegrationTest'
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

Then verify locally:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 eval '/* request /wc-admin/settings/payments/providers and assert WooPayments has no business_verification fields_error */'
```

Use browser automation against `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout` and compare with `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout`. Expected: provider list loads, WooPayments appears once, test badge/details remain visible, no `fields_error`, and no fresh console/network/PHP warnings.
