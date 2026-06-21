---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 08:21
tool: writing-plans
target: B3ah native WooPay runtime session continuity
reconciles:
  - ../analysis-b3ah-woopay-runtime-continuity.md
  - ../analysis-settings-payments-provider-list.md
status: draft
---

# B3ah Native WooPay Runtime Session Continuity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve the WooPay server/session contract in Core-owned native WooPayments without depending on the standalone WooPayments plugin runtime.

**Architecture:** Add a native WooPay session service for shared signatures, encrypted payloads, WooCommerce session persistence, WooPay URL construction, and init/minimum session payloads. Add a native controller that registers the preserved WooPay REST route and AJAX action names only when native WooPayments owns runtime and WooPay is enabled through the Core-owned WooPayments gateway settings. Expose WooPay session nonces and URL/session metadata through the existing WooPayments checkout bridge so Blocks and classic checkout keep using normal WooCommerce asset/build workflows.

**Tech Stack:** WooCommerce Core PHP services/controllers under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, WordPress REST/AJAX hooks, WooCommerce sessions, PHPUnit via wp-env, existing Blocks/classic WooPayments checkout assets and browser gates.

---

## Files

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionService.php`: WooPay URL helpers, Jetpack blog credential reads, request signature generation, AES/HMAC encrypted payloads, minimum/full init session request builders, WooCommerce session persistence for `woopay-user-data`, appearance option persistence, and filterable/preemptable init-session transport.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionController.php`: native runtime gated route/action registration for `GET /payments/woopay/session`, `wc_ajax_wcpay_init_woopay`, `wc_ajax_wcpay_get_woopay_session`, `wc_ajax_wcpay_set_woopay_phone_number`, `wc_ajax_wcpay_get_woopay_signature`, `wc_ajax_wcpay_get_woopay_minimum_session_data`, `wp_ajax_wcpay_admin_set_woopay_appearance`, and `wc_ajax_wcpay_shopper_set_woopay_appearance`.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register `WooPaymentsWooPaySessionController` alongside the other native WooPayments hook controllers.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php`: inject the WooPay session service and expose WooPay nonces, host URL, session endpoint names, and minimum encrypted session data through the existing checkout config when checkout is available.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php`: prove checkout config exposes WooPay runtime data while preserving card/test-mode config.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionServiceTest.php`: service contract coverage for WooPay URL, signatures, encrypted minimum data, stored phone/session data, appearance persistence, and transport preemption.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionControllerTest.php`: route/action registration, REST permission behavior, REST session response, AJAX response builders, and runtime/WooPay-enabled gates.
- Add `plugins/woocommerce/changelog/fix-native-payments-b3ah-woopay-session-continuity`.

## Tasks

### Task 1: Red Tests For Service-Level WooPay Session Contracts

- [ ] Add `WooPaymentsWooPaySessionServiceTest` with strict types, namespace `Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments`, and `WC_Unit_Test_Case`.
- [ ] Add a RED test named `test_builds_woopay_rest_urls_from_default_or_constant_host()` that expects `get_woopay_url()` to return `https://pay.woo.com` by default and `get_woopay_rest_url( 'init' )` to return `https://pay.woo.com/wp-json/platform-checkout/v1/init`.
- [ ] Add a RED test named `test_generates_reference_request_signature()` using a fixed blog ID/token through filters or a service test seam and assert `hash_hmac( 'sha512', '12345' . floor( time() / 30 ), 'blog-token' )`.
- [ ] Add a RED test named `test_encrypts_minimum_session_data_with_reference_shape()` that calls `get_encrypted_minimum_session_data()` and asserts the response has `blog_id`, `data.session`, `data.iv`, and `data.hash`, all base64-decodable strings, with a non-empty encrypted session payload.
- [ ] Add a RED test named `test_persists_and_clears_woopay_phone_session_data()` that stores `save_user_in_woopay`, `woopay_source_url`, `woopay_is_blocks`, `woopay_viewport`, and `phone_number`, then asserts `WC()->session->get( 'woopay-user-data' )` matches the preserved keys and that the clear path removes the session value.
- [ ] Add a RED test named `test_stores_admin_and_shopper_appearance_in_preserved_option()` that saves an appearance array and asserts `get_option( 'wcpay_woopay_checkout_appearance' )` matches it.
- [ ] Add a RED test named `test_forwards_init_session_request_through_filterable_transport()` that preempts the HTTP request with `pre_http_request`, calls `init_woopay_session()`, and asserts the outgoing URL is the WooPay `init` REST URL and the decoded body contains `store_data.account_id`, `store_data.test_mode`, `session_nonce`, and `store_api_token`.
- [ ] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWooPaySessionServiceTest'` and confirm the failures are missing class/methods rather than syntax/bootstrap errors.

### Task 2: Red Tests For Controller Route And AJAX Contracts

- [ ] Add `WooPaymentsWooPaySessionControllerTest` extending `WC_REST_Unit_Test_Case` with helper fakes for `NativePaymentsRuntimeArbiter`, `WooPaymentsAccountService`, and `WooPaymentsWooPaySessionService` only where direct service state would make controller tests brittle.
- [ ] Add a RED test named `test_registers_woopay_route_and_ajax_hooks_when_native_owns_runtime_and_woopay_is_enabled()` that initializes the controller with native ownership and `platform_checkout=yes`, calls `register()`, fires `rest_api_init`, and asserts the route `/payments/woopay/session` plus all preserved AJAX hook callbacks are registered.
- [ ] Add a RED test named `test_registers_no_hooks_when_native_does_not_own_runtime()` that asserts no REST or AJAX hooks are added when the arbiter returns false.
- [ ] Add a RED test named `test_registers_no_hooks_when_platform_checkout_is_disabled()` that asserts no REST or AJAX hooks are added when WooPayments gateway setting `platform_checkout` is not `yes`.
- [ ] Add a RED test named `test_woopay_rest_route_requires_woopay_user_agent_and_signed_request()` that dispatches requests missing `User-Agent: WooPay` or the `wcpay_woopay_is_signed_with_blog_token` approval filter and expects authorization failure, then adds the filter and expects 200.
- [ ] Add a RED test named `test_rest_session_route_returns_session_data_for_signed_woopay_request()` that calls the route with `email=shopper@example.com` and asserts the service receives that email and the response contains the service session payload.
- [ ] Add RED tests for response builder methods so they can be exercised without catching `wp_die`: `get_init_woopay_response()`, `get_encrypted_session_response()`, `get_phone_session_response()`, `get_signature_response()`, `get_minimum_session_response()`, and `get_appearance_response()`.
- [ ] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWooPaySessionControllerTest'` and confirm expected missing class/method failures.

### Task 3: Red Test For Checkout Config Exposure

- [ ] Update `WooPaymentsCheckoutBridgeTest::test_get_payment_fields_js_config_preserves_card_checkout_shape()` so the bridge is initialized with a WooPay session service mock and asserts the config includes `woopayHost`, `woopayInitNonce`, `woopaySessionNonce`, `woopaySignatureNonce`, `woopayMinimumSessionData`, and `wcAjaxUrl`.
- [ ] Add a RED test named `test_get_payment_fields_js_config_omits_encrypted_woopay_payload_when_checkout_surface_is_unavailable()` so unavailable checkout still exposes stable card metadata but does not create WooPay nonces or encrypted payloads.
- [ ] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCheckoutBridgeTest'` and confirm the new assertions fail because the bridge does not yet expose WooPay runtime data.

### Task 4: Implement WooPay Session Service

- [ ] Create `WooPaymentsWooPaySessionService` with `@since 11.0.0` and `@internal`, constants for `WOOPAY_SESSION_KEY`, `WOOPAY_DEFAULT_URL`, `WOOPAY_REST_NAMESPACE`, and `APPEARANCE_OPTION`, and an `init( WooPaymentsAccountService $account_service )` dependency.
- [ ] Implement `is_woopay_enabled()` by reading `$this->account_service->get_gateway_setting( 'platform_checkout', 'no' ) === 'yes'` and filtering the result through the existing WooPayments-compatible `wcpay_platform_checkout_enabled` hook if that hook exists in the reference call sites; do not require standalone plugin classes.
- [ ] Implement `get_woopay_url()` with `PLATFORM_CHECKOUT_HOST` override and default `https://pay.woo.com`, and `get_woopay_rest_url( string $endpoint )` as `<host>/wp-json/platform-checkout/v1/<endpoint>`.
- [ ] Implement `get_store_blog_id()` and `get_store_blog_token()` with Jetpack option reads when `Automattic\Jetpack\Options::get_option()` or `Jetpack_Options::get_option()` is available, fallback option reads where safe, and filters for tests/local dev; fail closed with empty values instead of notices when Jetpack is absent.
- [ ] Implement `get_woopay_request_signature()` with the preserved `hash_hmac( 'sha512', blog_id . floor( time() / 30 ), blog_token )` algorithm and return an empty string when credentials are missing.
- [ ] Implement `encrypt_and_sign_data( array $data )` with AES-256-CBC, random IV, `hash_hmac( 'sha256', $encrypted_session, $blog_token )`, base64-encoded `session`, `iv`, and `hash`, and `blog_id` as the top-level value.
- [ ] Implement `get_minimum_session_data()` with `wcpay_version`, `blog_id`, `blog_rest_url`, `blog_checkout_url`, `session_nonce`, and `store_api_token`.
- [ ] Implement `get_init_session_request( ?string $email = null, ?array $user_session = null )` with `wcpay_version`, current user/customer IDs, `session_nonce`, `store_api_token`, `email`, `user_session`, `appearance`, `preloaded_requests`, and `store_data` including store name, blog ID, URL, checkout URL, shop URL, account ID, test mode, capture method, currency, and Store API URL.
- [ ] Implement WooCommerce session persistence for `set_woopay_phone_session_data( array $request )`, `clear_woopay_session_data()`, and `get_woopay_session_data()` without creating notices when `WC()->session` is absent.
- [ ] Implement appearance save/read helpers backed by `wcpay_woopay_checkout_appearance`.
- [ ] Implement `init_woopay_session( array $request )` using `wp_remote_post()` with JSON body and the WooPay `init` URL so tests can preempt with `pre_http_request`; return decoded arrays and `array( 'result' => 'failure' )` for WP errors or invalid JSON.
- [ ] Re-run `WooPaymentsWooPaySessionServiceTest` and keep fixing until it passes without PHP notices.

### Task 5: Implement WooPay Session Controller And Register It

- [ ] Create `WooPaymentsWooPaySessionController` with `RegisterHooksInterface`, `NativePaymentsRuntimeArbiter`, and `WooPaymentsWooPaySessionService` dependencies.
- [ ] Implement `register()` so it returns early unless native runtime owns registration and the service reports WooPay enabled; when active, register `rest_api_init` and all preserved AJAX action names once.
- [ ] Implement `register_routes()` for `GET /payments/woopay/session` with a permission callback that requires `User-Agent: WooPay` and `apply_filters( 'wcpay_woopay_is_signed_with_blog_token', $default, $request )`.
- [ ] Implement the default signature permission path by delegating to `Rest_Authentication::is_signed_with_blog_token()` only when that class/method exists; otherwise default false so local tests must opt in through the filter.
- [ ] Implement route callback `get_session()` so it passes request email/session context to the service and returns `WP_REST_Response` on success or `WP_Error( 'wcpay_server_error', ..., array( 'status' => 400 ) )` on exceptions.
- [ ] Implement public response builder methods for each AJAX action, each taking an `array $request` and returning a plain array so PHPUnit can exercise behavior without `wp_die`.
- [ ] Implement AJAX wrappers that call the response builders and use `wp_send_json()`/`wp_send_json_success()`/`wp_send_json_error()` with preserved success/failure shapes.
- [ ] Add the controller to `plugins/woocommerce/includes/class-woocommerce.php` next to `WooPaymentsCheckoutAjaxController`, `WooPaymentsWebhookRestController`, and `WooPaymentsMobileRestController`.
- [ ] Re-run `WooPaymentsWooPaySessionControllerTest` and keep fixing until it passes without PHP notices.

### Task 6: Extend Checkout Bridge With WooPay Runtime Data

- [ ] Change `WooPaymentsCheckoutBridge::init()` to accept `WooPaymentsWooPaySessionService $woopay_session_service` and store it in a new property, with lazy fallback through `wc_get_container()` matching existing bridge dependency style.
- [ ] In `get_payment_fields_js_config()`, keep the existing card/test-mode data intact and add WooPay data only when `should_expose_checkout_surface()` and `woopay_session_service->is_woopay_enabled()` are true.
- [ ] Add `woopayHost`, `woopayInitNonce`, `woopaySessionNonce`, `woopaySignatureNonce`, `woopayMinimumSessionData`, and stable endpoint metadata using the preserved WooPay nonce action names: `wcpay_init_woopay_nonce`, `woopay_session_nonce`, and `woopay_signature_nonce`.
- [ ] Re-run `WooPaymentsCheckoutBridgeTest` and then the three focused tests together: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWooPaySessionServiceTest|WooPaymentsWooPaySessionControllerTest|WooPaymentsCheckoutBridgeTest'`.

### Task 7: Related Verification And Static Gates

- [ ] Run related native WooPayments PHPUnit: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWooPaySessionServiceTest|WooPaymentsWooPaySessionControllerTest|WooPaymentsCheckoutBridgeTest|WooPaymentsMobileRestControllerTest|WooPaymentsCheckoutAjaxControllerTest|WooPaymentsAccountServiceTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest'`.
- [ ] Run syntax checks for every touched PHP file, including new tests.
- [ ] Run explicit PHPCS for the new source/test files and modified source/test files.
- [ ] Run production PHPStan for `WooPaymentsWooPaySessionService.php`, `WooPaymentsWooPaySessionController.php`, and `WooPaymentsCheckoutBridge.php`.
- [ ] Run `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes` and `git diff --check`.
- [ ] Run focused Blocks/classic WooPayments JS tests only if config key changes require asset behavior changes; otherwise rely on browser checkout gates because this slice should not edit checkout JS.

### Task 8: Runtime, Browser, Harness, Logs, Review, Commit

- [ ] Run a target WP-CLI smoke probe against native owner state, `GET /payments/woopay/session` with `User-Agent: WooPay` and a temporary signature filter, and each preserved AJAX response builder through PHP methods or AJAX actions with valid nonces.
- [ ] Run the restored cross-store harness unchanged: `tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'`.
- [ ] Browser-check target Settings Payments provider list at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout` and confirm the generic provider list still loads with WooPayments, PayPal, and offline providers through normal WooCommerce assets.
- [ ] Browser-check target Blocks checkout and classic checkout and confirm test-mode details remain present: Blocks should show the Test Mode badge/details and test card copy UI; classic should preserve the reference-matching test-card guidance and Stripe iframe behavior.
- [ ] Compare reference checkout/settings only where target output looks ambiguous; reference is read-only and must not be mutated.
- [ ] Scan target and reference Docker/debug logs for fresh PHP/WP notices, warnings, deprecations, fatals, uncaught errors, stack traces, database errors, or self-caused probe warnings. Fix any product-caused entries before proceeding.
- [ ] Request focused code review for the native WooPay session controller/service, signature/encryption behavior, runtime gating, checkout config, and generic Settings Payments/checkout regression risk. Fix critical/high/medium findings with RED/GREEN regressions.
- [ ] Add changelog, stage only intended tracked files, run staged diff checks, commit locally with a conventional message if all gates pass, then update `implementation-log.md` and `staging-log.md` at their append markers.

## Self-Review

- Spec coverage: Covers the WooPay session REST route, preserved AJAX hook names, local session key, signature/encryption helpers, checkout config data, generic Settings Payments regression guard, Blocks/classic checkout test-mode guard, harness, browser interaction, log scan, and code review gates.
- Placeholder scan: No TBD/TODO placeholders remain.
- Scope check: This chunk intentionally covers the shared WooPay server/session contract in one package. Direct WooPay button rendering, adapted-extension checkout restoration, Store API extension data parity, and full appearance/font extraction remain separate follow-ups if browser or review evidence proves they are still missing.
