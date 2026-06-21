---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 19:27
status: final
last_updated: 2026-06-17 20:30
---

# Tracks Continuity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments shopper Tracks continuity on surviving checkout and WooPay surfaces without routing shopper events through Core's generic admin Tracks helper.

**Architecture:** Add a provider-owned native WooPayments frontend tracking bridge that preserves the reference `platform_tracks` AJAX contract and server-side `wcpay_` prefixing. Add thin emitters to the existing WooPayments card and WooPay bundles only; do not add global checkout telemetry or tie card tracking to WooPay implementation details.

**Tech Stack:** WooCommerce Core PHP `RegisterHooksInterface`, `WC_Tracks_Client`/`WC_Tracks_Event`, PHPUnit, Blocks React/Jest, classic frontend Jest, normal WooCommerce Blocks/classic build workflows, Chrome DevTools MCP and a minimal Playwright system-Chrome probe for browser proof.

---

## Files

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFrontendTrackingController.php` for native `platform_tracks` and `get_identity` AJAX handling plus provider-local tracking enablement.
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsFrontendTrackingControllerTest.php` for RED/GREEN backend contract coverage.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php` to register the new provider controller with other native WooPayments hook registrars.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionService.php` to expose `ajaxUrl` beside the existing WooPay `platformTrackerNonce` so standalone WooPay surfaces can post to `platform_tracks`.
- Create: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/tracks.js` as the Blocks-side `platform_tracks` helper.
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` for Blocks card place-order and save-info emitters.
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/index.js` for Blocks WooPay button load/click emitters.
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` for classic card place-order emitter.
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-woopay.js` for classic WooPay button and save-info emitters.
- Modify: the four adjacent Jest suites to prove the emitters post the reference `platform_tracks` payload shape.
- Update session docs and add a changelog before committing.

## Tasks

### Task 1: RED Backend Tracking Bridge Tests

- [x] Add `WooPaymentsFrontendTrackingControllerTest` with `tearDown()` removing `wp_ajax_platform_tracks`, `wp_ajax_nopriv_platform_tracks`, `wp_ajax_get_identity`, `wp_ajax_nopriv_get_identity`, `wcpay_tracks_event_properties`, `wcpay_shopper_tracking_enabled`, and any `pre_http_request` hooks installed by tests.
- [x] Add `test_registers_platform_tracks_ajax_hooks_when_native_owns_runtime()` creating an arbiter mock returning `true`, initializing the controller, calling `register()`, and asserting all four AJAX hooks are registered.
- [x] Add `test_does_not_register_ajax_hooks_when_native_runtime_is_inactive()` with the arbiter returning `false`, then assert those four hooks are absent.
- [x] Add `test_tracks_response_rejects_invalid_nonce()` calling a testable response builder with `tracksNonce => 'bad'` and asserting `success=false`, `status_code=403`, and the authorization message.
- [x] Add `test_tracks_response_requires_event_name()` with a valid `wp_create_nonce( 'platform_tracks_nonce' )` and no `tracksEventName`, then assert `success=false` and `status_code=403`.
- [x] Add `test_records_prefixed_wcpay_event_and_applies_reference_filter()` with valid nonce, `tracksEventName => 'woopay_button_click'`, and JSON props `{"source":"checkout"}`. Install `wcpay_tracks_event_properties` to add `filtered_prop => 'yes'`, install `pre_http_request` to capture the pixel URL and return a fake 200 response, then assert the response succeeds and the decoded pixel args contain `_en=wcpay_woopay_button_click`, `source=checkout`, `filtered_prop=yes`, and `test_mode=1`.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsFrontendTrackingControllerTest'`.
- [x] Expected RED confirmed: the class/test target did not exist yet before implementation.

### Task 2: GREEN Backend Tracking Bridge

- [x] Implement `WooPaymentsFrontendTrackingController implements RegisterHooksInterface` with `init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service ): void`.
- [x] In `register()`, return early unless `$this->arbiter->should_native_register()` is true, then register `wp_ajax_platform_tracks`, `wp_ajax_nopriv_platform_tracks`, `wp_ajax_get_identity`, and `wp_ajax_nopriv_get_identity`.
- [x] Add `handle_tracks(): void` and `handle_tracks_identity(): void` wrappers that call testable response builders and emit with `wp_send_json_success()` / `wp_send_json_error()` preserving the status code.
- [x] Add `get_tracks_response( array $request ): array` that verifies `tracksNonce` against `platform_tracks_nonce`, requires scalar `tracksEventName`, decodes object JSON from `tracksEventProp`, sanitizes the event name, records the event, and returns `array( 'success' => true, 'status_code' => 200, 'data' => array() )`.
- [x] Add `get_tracks_identity_response(): array` returning a provider-local Jetpack-compatible Tracks identity in a success envelope, matching the reference `get_identity` behavior without falling back to generic WooCommerce `woo:` identities.
- [x] Add `record_user_event( string $event_name, array $properties = array() )` that prefixes non-`_aliasUser` events with `wcpay_`, applies `wcpay_tracks_event_properties`, merges `WC_Tracks::get_server_details()`, provider-local Jetpack-compatible identity data, `WC_Tracks::get_blog_details()`, `test_mode`, `wcpay_version`, `_en`, and `_ts`, then records through `WC_Tracks_Event::build_pixel_url()` and `WC_Tracks_Client::record_pixel()` to preserve the reference pixel path while satisfying Core's current typed APIs.
- [x] Add `is_shopper_tracking_enabled(): bool` with the reference guardrails native can support today: `wcpay_shopper_tracking_enabled`, `woocommerce_allow_tracking`, native `WooPaymentsAccountService::can_process_payments()`, US store country, `tk_opt-out`, no non-AJAX admin request, and no logged-in administrator shopper.
- [x] Register the controller in `plugins/woocommerce/includes/class-woocommerce.php` beside the other native WooPayments controllers.
- [x] Run the focused PHP test again and confirm GREEN.

### Task 3: RED/GREEN Blocks Emitters

- [x] Add `tracks.js` exporting `recordWooPaymentsUserEvent( settings, eventName, eventProperties = {} )`. It no-ops only when tracking is explicitly disabled, the nonce/AJAX URL/fetch dependency is missing, or the event name is empty; otherwise it posts `FormData` with `tracksNonce`, `action=platform_tracks`, `tracksEventName`, and `tracksEventProp`.
- [x] Add RED tests in `woopayments/test/index.js` proving Blocks card setup calls `platform_tracks` with `checkout_place_order_button_click`, save-info render calls `checkout_woopay_save_my_info_offered`, and checkbox change calls `checkout_save_my_info_click` with `status: unchecked|checked`.
- [x] Add RED tests in `woopay/test/index.js` proving rendering the button posts `woopay_button_load` with `{ source: 'checkout' }` and clicking posts `woopay_button_click` before `wcpay_init_woopay`.
- [x] Run `pnpm --filter='@woocommerce/block-library' test:js --runTestsByPath assets/js/extensions/payment-methods/woopayments/test/index.js assets/js/extensions/payment-methods/woopayments/woopay/test/index.js` and confirm the new assertions fail for missing emitters.
- [x] Implement the Blocks emitters in `index.js` and `woopay/index.js`, importing the helper only inside WooPayments entry bundles.
- [x] Re-run the focused Blocks Jest command and confirm GREEN.

### Task 4: RED/GREEN Classic Emitters

- [x] Add a guarded `recordWooPaymentsUserEvent( eventName, eventProperties )` helper to `woopayments-checkout.js` and `woopayments-woopay.js` that posts the same `platform_tracks` `FormData` payload through `window.fetch`, using `config.ajaxUrl` or `config.ajax_url`, and no-ops when `config.isShopperTrackingEnabled === false`.
- [x] Add RED tests in `woopayments-checkout.js` Jest proving the classic checkout handler records `checkout_place_order_button_click` when the WooPayments gateway is selected, matching the reference click-level trigger.
- [x] Add RED tests in `woopayments-woopay.js` Jest proving button render posts `woopay_button_load`, button click posts `woopay_button_click`, save-info render posts `checkout_woopay_save_my_info_offered`, and checkbox change posts `checkout_save_my_info_click` with the checked state.
- [x] Run `pnpm --filter='@woocommerce/classic-assets' test:js -- woopayments-checkout woopayments-woopay` and confirm the new assertions fail before implementation.
- [x] Implement the classic emitters and re-run the focused classic Jest command until GREEN.

### Task 5: Focused Gates, Browser Proof, Reviews, and Commit

- [x] Run focused PHP: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsFrontendTrackingControllerTest|WooPaymentsCheckoutBridgeTest|WooPaymentsWooPaySessionServiceTest'`.
- [x] Run focused JS: Blocks WooPayments suites and classic WooPayments suites.
- [x] Run PHP syntax for changed/new PHP files, PHPStan for changed production PHP, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, branch PHP lint, focused JS lint for touched Blocks/classic files, `git diff --check`, normal `@woocommerce/plugin-woocommerce build:blocks`, and normal `@woocommerce/plugin-woocommerce build:classic-assets`.
- [x] Use browser automation on target checkout to prove native WooPay checkout emits `platform_tracks` requests with expected event names and source. Chrome DevTools MCP proved the runtime config and `woopay_button_load`; after DevTools MCP timed out, a minimal Playwright system-Chrome probe proved real `woopay_button_load`, `checkout_woopay_save_my_info_offered`, and `woopay_button_click` requests with `action=platform_tracks`, nonce, and `{ "source": "checkout" }`.
- [x] Dispatch at least one focused review subagent for API/telemetry contract and one code-quality/architecture reviewer if slots are available. Persist findings in `review-agent-findings.md`.
- [x] Add a WooCommerce changelog entry, commit source/tests, commit changelog separately, run branch lint, and update `implementation-log.md`, `staging-log.md`, and this plan to final with the git range and residual scope boundaries.

## Final Result

H16 is closed in source/tests commit `04dc96da44` and changelog commit `91b2d3f940`. Git range: `76c038f3ffef421d9700063fd79034a84cd6cd73...91b2d3f940`. Residual scope remains explicit: non-WooPay express Tracks events wait for the native Apple Pay / Google Pay / Amazon Pay slice, and the email-iframe `checkout_email_address_woopay_check` / `woopay_skipped` events wait for an equivalent native surface.

## Self-Review

- Spec coverage: preserves the Bucket-E Tracks event contract for surviving native card/WooPay checkout surfaces and explicitly leaves non-surviving email-iframe/skip events and not-yet-native Apple/Google Pay events out of scope.
- Architecture check: provider-local bridge avoids generic Core analytics abstraction and avoids `wcadmin_` shopper event drift.
- Bundle check: emitters live only in WooPayments card/WooPay bundles that already load for those enabled surfaces.
