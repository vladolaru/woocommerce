---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 17:57
status: draft
---

# A3g Native WooPayments Account Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make native WooPayments checkout registration and browser config depend on Core-owned account, key, and mode readiness instead of the legacy WooPayments gateway runtime.

**Architecture:** Add a small provider-owned account service that reads the preserved WooPayments account cache and gateway mode settings through Core/WP APIs, then use it as the source of truth for native provider readiness, Stripe publishable key/account ID config, and test/live mode selection in native money paths. The legacy runtime remains only for explicitly transitional plugin surfaces such as plugin-provided prepared customer data and UPE method selection while those are still being ported. Native remains guarded by `NativePaymentsRuntimeArbiter`, `WooPaymentsApiClient::is_available()`, and account cache readiness; WPCOM and WooPayments plugin code stay read-only.

**Tech Stack:** WooCommerce Core PHP DI classes under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS changed-file checks, and the existing session scratchpad logs.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php` to normalize `wcpay_account_data`, account ID, publishable keys, payments-enabled/account-submitted flags, and test/live mode.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php` to inject `WooPaymentsApiClient` and `WooPaymentsAccountService`, and make `can_process_payments()` fail closed on native transport and account readiness instead of legacy gateway `is_available()`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php` to get `publishableKey` and `accountId` from `WooPaymentsAccountService`; keep `WooPaymentsLegacyRuntime` for UPE method IDs and prepared customer data only.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`, `WooPaymentsCustomerService.php`, and `WooPaymentsCheckoutAjaxController.php` to use `WooPaymentsAccountService::is_test_mode_enabled()` or `get_mode()` instead of duplicated legacy-runtime/option fallbacks.
- Modify tests in `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php`, `WooPaymentsCheckoutBridgeTest.php`, `WooPaymentsProviderGatewayAdapterTest.php`, `WooPaymentsCustomerServiceTest.php`, and `WooPaymentsCheckoutAjaxControllerTest.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`.
- Add `plugins/woocommerce/changelog/add-native-payments-a3g-account-readiness`.

## Task 1: Core Account Service

- [ ] **Step 1: Write failing account-service tests.**

Add `WooPaymentsAccountServiceTest` with cases for valid cache `{ data: { account_id: 'acct_123', test_publishable_key: 'pk_test_123', live_publishable_key: 'pk_live_123', payments_enabled: true, details_submitted: true } }`, invalid cache payloads, mode fallback from `woocommerce_woocommerce_payments_settings['test_mode']`, onboarding test mode option `wcpay_onboarding_test_mode`, and `wcpay_test_mode` filter override. Expected red: class does not exist.

- [ ] **Step 2: Implement the minimal account service.**

Create `WooPaymentsAccountService` with `init( LegacyProxy $legacy_proxy ): void`, `get_cached_account_data(): array`, `get_account_id(): string`, `get_publishable_key(): string`, `is_test_mode_enabled(): bool`, `get_mode(): string`, and `can_process_payments(): bool`. `can_process_payments()` returns true only when account ID, the mode-specific publishable key, `payments_enabled`, and `details_submitted` are truthy. Mode logic follows WooPayments: onboarding test mode wins, otherwise `woocommerce_woocommerce_payments_settings['test_mode'] === 'yes'`, then `wcpay_test_mode` filter applies.

- [ ] **Step 3: Run the focused red/green tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAccountServiceTest'`. Expected after implementation: all account service tests pass.

## Task 2: Readiness and Checkout Config Wiring

- [ ] **Step 1: Write failing provider and checkout bridge tests.**

Update `WooPaymentsProviderTest` so `can_process_payments()` expects native transport availability and account readiness, not `WooPaymentsProviderGatewayAdapter::is_available()`. Add a source-boundary assertion that `WooPaymentsProvider.php` does not call `is_available()` on the gateway adapter. Update `WooPaymentsCheckoutBridgeTest` so publishable key and account ID come from `WooPaymentsAccountService` while UPE IDs and prepared customer data still come from `WooPaymentsLegacyRuntime`. Expected red: constructor/init signatures and behavior still use legacy gateway/runtime key helpers.

- [ ] **Step 2: Wire provider readiness and bridge config.**

Change `WooPaymentsProvider::init()` to accept `WooPaymentsProviderGatewayAdapter`, `WooPaymentsApiClient`, and `WooPaymentsAccountService`, and change `can_process_payments()` to `return $this->api_client->is_available() && $this->account_service->can_process_payments();`. Change `WooPaymentsCheckoutBridge::init()` to accept `WooPaymentsLegacyRuntime` and `WooPaymentsAccountService`, and use account service for `should_expose_checkout_surface()`, `publishableKey`, and `accountId`.

- [ ] **Step 3: Run focused provider/bridge tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderTest|WooPaymentsCheckoutBridgeTest|NativePaymentsGatewayRegistryTest|WooPaymentsOnboardingAdapterTest'`. Expected: provider readiness still drives registry/onboarding behavior, and bridge config no longer requires plugin account service for keys.

## Task 3: Mode Consumer Cleanup

- [ ] **Step 1: Write failing mode-consumer tests.**

Update adapter/customer/AJAX tests so the new account service is injected and the order meta/customer-key mode comes from `WooPaymentsAccountService`, including a test where the legacy runtime is absent but persisted gateway settings put native WooPayments in test mode. Expected red: production code still reads mode through legacy runtime or direct options.

- [ ] **Step 2: Rewire mode consumers.**

Inject `WooPaymentsAccountService` into `WooPaymentsProviderGatewayAdapter`, `WooPaymentsCustomerService`, and `WooPaymentsCheckoutAjaxController`. Replace private `is_test_mode_enabled()` fallbacks and direct `get_option( 'wcpay_test_mode' )` reads with the account service. Keep existing legacy-runtime dependencies where they still serve other plugin surfaces.

- [ ] **Step 3: Run focused mode regression tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderGatewayAdapterTest|WooPaymentsCustomerServiceTest|WooPaymentsCheckoutAjaxControllerTest|WooPaymentsAccountServiceTest'`. Expected: existing A3c-A3f behavior stays green with native mode source centralized.

## Task 4: Review, Verification, Logs, Commit

- [ ] **Step 1: Run verification gates.**

Run focused PHPUnit for all touched WooPayments native payments tests, `php -l` on touched PHP files, PHPStan on touched production PHP files, `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`, `git diff --check`, and `pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 2: Run review gates.**

Dispatch a read-only spec/integration reviewer against the A3g diff and a code-quality reviewer against the touched PHP. Resolve blocking findings, rerun focused regressions, and record any intentionally blocked browser/e2e harness gate because `tools/woopayments-merge/verify.sh` and `HARNESS.md` are absent locally.

- [ ] **Step 3: Changelog, logs, and commit.**

Create `plugins/woocommerce/changelog/add-native-payments-a3g-account-readiness` with a patch/dev entry. Append evidence to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` above `IMPLEMENTATION_LOG_APPEND_POINT` and to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`. Commit source/tests/changelog only; do not add scratchpad docs or generated assets.

## Self-Review

- Spec coverage: This advances A3 native processing readiness, preserves gateway ID/settings, keeps the account cache as a Bucket-E data contract, and removes a legacy gateway runtime dependency from native registration. It does not claim full browser/e2e parity, express checkout, WooPay, non-card UPE methods, or complete A5 cutover.
- Placeholder scan: no `TBD`, `TODO`, or unspecified test steps remain.
- Type consistency: method names and paths match the current Core code; the only new production type is `WooPaymentsAccountService`.
