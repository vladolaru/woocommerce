---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 19:27
target: H16/A3 native WooPayments checkout and WooPay Tracks continuity
reconciles:
  - review-agent-findings.md
  - .agents/scratchpad/sessions/2026-06-17-woopayments-tracks-continuity/analysis.md
status: final
last_updated: 2026-06-17 20:30
---

# H16 Tracks Continuity Analysis

## Decision

Native WooPayments checkout/WooPay shopper telemetry must preserve the reference WooPayments `recordUserEvent()` contract, not the generic Core admin Tracks path. The reference shopper helper posts `action=platform_tracks`, `tracksNonce`, `tracksEventName`, and JSON `tracksEventProp` to `admin-ajax.php`; the server prefixes emitted events with `wcpay_` and applies `wcpay_tracks_event_properties`. Core's generic JS Tracks path uses `window.wcTracks.recordEvent`, prefixes `wcadmin_`, and is defined through the admin footer. Using it directly would drift the event namespace and delivery contract for shopper checkout events.

The native implementation should therefore add a WooPayments-provider-owned tracking bridge in `src/Internal/Payments/Providers/WooPayments/`, plus thin frontend emitters in the existing WooPayments checkout/WooPay bundles. This keeps the code provider-scoped, preserves the `wcpay_` shopper namespace, and avoids shipping WooPay-specific weight when WooPay is disabled.

## Source Anchors

- Reference JS shopper helper: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/tracks/index.ts` sends `platform_tracks` and documents the `wcpay_` shopper prefix.
- Reference PHP receiver: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-woopay-tracker.php` registers `wp_ajax_platform_tracks`, `wp_ajax_nopriv_platform_tracks`, `wp_ajax_get_identity`, and `wp_ajax_nopriv_get_identity`; it prefixes non-`_aliasUser` events with `wcpay_`, applies `wcpay_tracks_event_properties`, gates tracking, and records through a Tracks pixel path.
- Core generic browser helper: `plugins/woocommerce/includes/tracks/class-wc-site-tracking.php` defines `window.wcTracks.recordEvent()` in `admin_footer` and prefixes event names with `WC_Tracks::PREFIX` (`wcadmin_`), so it is not equivalent for shopper WooPayments events.
- Core Tracks server primitive: `plugins/woocommerce/includes/tracks/class-wc-tracks-client.php` can record `WC_Tracks_Event` instances. The final native identity path intentionally uses a provider-local Jetpack-compatible adapter instead of generic `WC_Tracks_Client::get_identity()` so shopper identity does not drift from the reference WooPayments contract.
- Native configs already provide the required client data: `WooPaymentsCheckoutBridge` includes `ajaxUrl` and `platformTrackerNonce`; `WooPaymentsWooPaySessionService` includes `platformTrackerNonce` and `nonce.platform_tracker`.

## Surviving Events In Scope

These reference emitters map to surviving native A3 checkout/WooPay surfaces and should be restored now:

| Event | Reference trigger | Native trigger |
| --- | --- | --- |
| `checkout_place_order_button_click` | Blocks/classic place-order attempt while WooPayments is selected | Blocks `onPaymentSetup` and classic `checkout_place_order_woocommerce_payments` after selected-gateway/resubmit guards |
| `woopay_button_load` | WooPay express button render | Blocks WooPay button mount and classic button render |
| `woopay_button_click` | WooPay express button click | Blocks/classic WooPay init click after duplicate-click guard |
| `checkout_woopay_save_my_info_offered` | Save-my-info offer becomes visible | Blocks and classic save-my-info render |
| `checkout_save_my_info_click` | Save-my-info checkbox state changes, with `status: checked|unchecked` | Blocks and classic checkbox change |

The reference `checkout_email_address_woopay_check` and `woopay_skipped` events are tied to the standalone plugin's email-input iframe/skip flow. No equivalent native frontend surface currently exists in the Core-owned WooPay implementation. They should remain tracked as a future parity item if that surface is introduced, not faked on unrelated native flows.

Apple Pay / Google Pay load/click events are also out of this slice because native currently lacks the non-WooPay express checkout implementation. They remain part of the broader A3 express-PM parity blocker already recorded in the baseline.

## Implementation Shape

- Add `WooPaymentsFrontendTrackingController` implementing `RegisterHooksInterface`.
- Register it from `plugins/woocommerce/includes/class-woocommerce.php` only when native owns the WooPayments runtime.
- Preserve `platform_tracks` and `get_identity` AJAX action names for logged-in and guest shoppers.
- Verify `tracksNonce` with `platform_tracks_nonce`; invalid nonce and missing event name should return JSON errors with the reference statuses.
- Prefix non-`_aliasUser` events with `wcpay_` server-side.
- Apply `wcpay_tracks_event_properties` to preserve reference filter semantics.
- Use WooCommerce `WC_Tracks_Client`/`WC_Tracks_Event` primitives and fail open/no-op when tracking is disabled by `woocommerce_allow_tracking`, `wcpay_shopper_tracking_enabled`, native account readiness, opt-out cookie, admin context, or unit-test user capabilities.
- Add a filter seam only for testability and future provider cleanup if needed; do not create a generic Core tracking abstraction for this one provider-specific contract.
- Add a tiny shared Blocks helper in the WooPayments payment-method folder and a tiny legacy helper in each IIFE bundle. The helpers should post the same payload shape as the reference and no-op if nonce, AJAX URL, `fetch`, or tracking-enabled config is missing.

## Verification Notes

The minimum RED/GREEN coverage should include PHP hook registration, nonce/error handling, prefix/filter behavior, Blocks card place-order/save-info events, Blocks WooPay load/click events, classic card place-order, and classic WooPay load/click/save-info. Browser/Chrome DevTools parity should then confirm the target checkout emits `platform_tracks` requests for the restored native surfaces without introducing global JS errors or new PHP notices.

## Implementation Result

The implemented bridge follows the provider-owned shape above. `WooPaymentsFrontendTrackingController` owns the AJAX actions only when the native runtime owns WooPayments, preserves the `platform_tracks`/`get_identity` action names, verifies `platform_tracks_nonce`, prefixes non-`_aliasUser` shopper events with `wcpay_`, applies `wcpay_tracks_event_properties`, keeps the reference shopper-tracking guardrails that native can evaluate, and records through the Core Tracks pixel primitive. Frontend emitters were added only to WooPayments card/WooPay bundles: Blocks card, Blocks WooPay, classic card, and classic WooPay.

The final implementation also preserves the reference identity and frontend guardrail details. Native does not use generic `WC_Tracks_Client::get_identity()` for WooPayments shopper events; it uses a provider-local Jetpack-compatible identity adapter that prefers `jetpack_tracks_wpcom_id` for connected users and `jetpack_tracks_anon_id` / generated `jetpack:` anonymous IDs otherwise. Card and WooPay configs expose `isShopperTrackingEnabled` / `is_shopper_tracking_enabled` from the same provider tracking controller so disabled tracking suppresses avoidable frontend requests while the server remains authoritative.

Browser evidence: Chrome DevTools MCP initially confirmed the target Blocks checkout loads the native WooPayments card and WooPay bundles plus `woocommerce_payments_data` with `ajaxUrl`, `platformTrackerNonce`, WooPay enabled, and network saved cards enabled, and captured `woopay_button_load` through `platform_tracks`. Chrome DevTools MCP then timed out on later calls, so a minimal Playwright system-Chrome probe was used without adding repo test infrastructure. The probe added product `37`, loaded `http://store8889.localhost:8889/checkout/`, clicked `.wc-block-components-express-payment button[class*="woopay"]`, and captured real `admin-ajax.php` requests for `woopay_button_load`, `checkout_woopay_save_my_info_offered`, and `woopay_button_click`, each with `action=platform_tracks`, a nonce, and checkout source where applicable.

Residual scope remains unchanged: no Apple Pay / Google Pay events until non-WooPay express checkout exists natively, and no `checkout_email_address_woopay_check` or `woopay_skipped` until an equivalent native email iframe/skip surface exists.

Committed result: source/tests `04dc96da44` (`fix(payments): preserve native woopayments tracks continuity`) and changelog `91b2d3f940` (`chore(payments): add tracks continuity changelog`). Git range: `76c038f3ffef421d9700063fd79034a84cd6cd73...91b2d3f940`.
