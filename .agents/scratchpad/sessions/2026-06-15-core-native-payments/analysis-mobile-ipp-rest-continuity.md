---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 05:40
target: WooPayments mobile and IPP REST continuity
reconciles:
  - README.md
  - implementation-log.md
status: draft
---

# Mobile and IPP REST Continuity

## Trigger

The B3ae completion note left WooPayments mobile and In-Person Payments REST continuity as the next hard Bucket E contract. The user also explicitly called out that WooCommerce > Settings > Payments is generic WooCommerce UX and must not regress when WooPayments becomes a Core-owned provider.

## Settings Payments Finding

The `store8889.localhost:8889` Settings Payments provider-list failure is not being treated as a local-env limitation. B3ae already proved the generic provider-list path can load on the target store, and the root cause was native Core wiring gaps in WooPayments provider capabilities and onboarding/provider metadata. The proper architectural line is that WooPayments contributes a normal provider row through the existing WooCommerce provider model; Core-owned WooPayments should expose the same gateway supports, state, onboarding fields, and add-payment/subscription capability metadata as the reference plugin, without special-casing the Settings Payments frontend.

## Reference Route Surface

- `POST /wc/v3/payments/connection_tokens` is implemented by WooPayments `WC_REST_Payments_Connection_Tokens_Controller`, forwards to WPCOM `terminal/connection_tokens`, requires `manage_woocommerce`, and appends `test_mode` from the current WooPayments mode to the response body.
- `POST /wc/v3/payments/orders/(?P<order_id>\w+)/capture_terminal_payment` captures a prepared terminal payment, rejects missing/refunded/mismatched/uncapturable orders, attaches WooPayments payment intent data to the order, writes `receipt_url`, completes the order through the terminal-payment path, handles already-succeeded intents, and returns `{ status, id }`.
- `POST /wc/v3/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment` validates the order and payment intent ID, rejects refunded orders, forwards to `terminal/reader/collect` semantics through the reference API request object, and returns the WPCOM response payload.
- `POST /wc/v3/payments/orders/(?P<order_id>\w+)/create_terminal_intent` creates a card-present payment intent for the order total, lower-case currency, optional customer ID, metadata plus `order_number`, allowed IPP payment methods, and manual/automatic capture mode, then returns `{ id }`.
- `GET /wc/v3/payments/readers`, `POST /wc/v3/payments/readers`, `GET /wc/v3/payments/readers/charges/(?P<transaction_id>\w+)`, `POST /wc/v3/payments/readers/receipts/preview`, and `GET /wc/v3/payments/readers/receipts/(?P<payment_intent_id>\w+)` are implemented by WooPayments `WC_REST_Payments_Reader_Controller`. Readers are cached in transient `wcpay_store_terminal_readers` for two hours and include `id`, `livemode`, `device_type`, `label`, `location`, `metadata`, `status`, and `is_active` when listing.
- `GET /wc/v3/payments/terminal/locations/store`, `GET/POST /wc/v3/payments/terminal/locations`, and `GET/POST/DELETE /wc/v3/payments/terminal/locations/(?P<location_id>\w+)` are implemented by WooPayments `WC_REST_Payments_Terminal_Locations_Controller`. Locations are cached in transient `wcpay_store_terminal_locations` for one day and expose `id`, `address`, `display_name`, and `livemode`.
- `POST /wc/v3/payments/orders/(?P<order_id>\d+)/create_customer` is adjacent mobile/subscription compatibility surface already listed in the BC extraction and should be preserved in this chunk if it is still absent natively, because mobile clients and renewal/payment-method flows can still depend on it.

## Native Gap

Core currently registers the native WooPayments webhook route but not the mobile/IPP `wc/v3/payments/*` routes above. `WooPaymentsApiClient` has the right transport model and WPCOM ownership behavior, but it lacks explicit methods for terminal connection tokens, reader CRUD/summary, terminal locations, terminal payment intent creation/preparation, and terminal payment capture support. `WooPaymentsOrderDataService` and `WooPaymentsProviderGatewayAdapter` contain shared order and capture primitives but do not yet expose terminal-specific order completion, `receipt_url` handling, IPP channel metadata, or receipt rendering helpers.

## Implementation Principle

The native route layer should be registered only when native WooPayments owns the runtime, preserve the reference route namespace and permission behavior, and route all WPCOM reads/writes through the Core-owned `WooPaymentsApiClient`. It should not mask implementation defects in the harness or frontend: Settings Payments remains generic WooCommerce provider UX, checkout test-mode behavior remains account-backed, and IPP/mobile clients should see the same REST shapes they saw from the plugin.
