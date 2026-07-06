---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-G
created: 2026-06-22 22:55
tool: pirategoat-tools:full-code-review (follow-up parity check)
target: settings + customer service — WooPay custom message XSS, account-field sanitization, settings option RMW race, customer dedup race, referrer Tracks CSRF
reconciles:
  - ../README.md
status: final
---

# Parity G — Settings + Customer Service

Oracle: WooPayments client v10.8.0 (`develop`) at `/Users/vladolaru/Work/a8c/woocommerce-payments`.
CORE: `/Users/vladolaru/Work/a8c/woocommerce-develop-2`, files under
`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/` (service layer) and
`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php` (REST boundary).

Note: the prompt's file paths (`.../Settings/PaymentProviders/WooPayments/...`) are stale; the
services actually live under `.../Internal/Payments/Providers/WooPayments/`. Line numbers shifted
accordingly but the constructs match the descriptions.

---

## Finding 1 — 6a3e12e5 (MED): WooPay `platform_checkout_custom_message` stored without HTML sanitization

**CORE** `WooPaymentsSettingsService.php:530-534`:

```php
if ( array_key_exists( 'woopay_custom_message', $params ) ) {
    $custom_message                               = is_scalar( $params['woopay_custom_message'] ) ? (string) $params['woopay_custom_message'] : '';
    $custom_message                               = str_replace( '[terms_of_service_link]', '[terms]', $custom_message );
    $custom_message                               = str_replace( '[privacy_policy_link]', '[privacy_policy]', $custom_message );
    $settings['platform_checkout_custom_message'] = $custom_message;
}
```

REST boundary `WooPaymentsRestController.php:793-818` registers `woopay_custom_message` via
`get_typed_arg('string')` → `validate_callback => 'rest_validate_request_arg'`, **no `sanitize_callback`**.
So neither the REST layer nor the service applies `wp_kses`/`sanitize_*`.

### Verdict: PARITY (high confidence)

The CLIENT does the same thing, field-for-field:

- REST schema `class-wc-rest-payments-settings-controller.php:292-296` — `woopay_custom_message`
  declared `type => string`, `validate_callback => 'rest_validate_request_arg'`, **no `sanitize_callback`**.
- Handler `class-wc-rest-payments-settings-controller.php:1145-1155`:

```php
private function update_woopay_custom_message( WP_REST_Request $request ) {
    if ( ! $request->has_param( 'woopay_custom_message' ) ) {
        return;
    }
    $woopay_custom_message = $request->get_param( 'woopay_custom_message' );
    $woopay_custom_message = str_replace( '[terms_of_service_link]', '[terms]', $woopay_custom_message );
    $woopay_custom_message = str_replace( '[privacy_policy_link]', '[privacy_policy]', $woopay_custom_message );
    $this->wcpay_gateway->update_option( 'platform_checkout_custom_message', $woopay_custom_message );
}
```

- Gateway `update_option` (`class-wc-payment-gateway-wcpay.php:2969-2974`) only remaps the `enabled`
  key and delegates to the WC core `WC_Settings_API::update_option` — no sanitization of the message.

Identical port: same two `str_replace` shortcode normalizations, raw store, no `wp_kses`. The stored-XSS
exposure (a shop manager / anyone with `manage_woocommerce` writing markup that renders on the WooPay
checkout) is a pre-existing WooPayments property, not introduced by the core merge. CORE is marginally
stricter on the input edge (`is_scalar(...) ? (string) ... : ''` guard) but that doesn't change the XSS class.

client_file: `includes/admin/class-wc-rest-payments-settings-controller.php:1154` (+ schema :292, gateway :2969)

---

## Finding 2 — deb2d8c9 (MED): ACCOUNT_SETTING_MAP fields written to gateway options without per-type sanitization

**CORE** `WooPaymentsSettingsService.php:577-581`:

```php
foreach ( array_keys( self::ACCOUNT_SETTING_MAP ) as $request_key ) {
    if ( array_key_exists( $request_key, $params ) ) {
        $settings[ $request_key ] = $params[ $request_key ];   // raw request param into local option
    }
}
```

ACCOUNT_SETTING_MAP (`:162-179`) covers business name/url, support address/email/phone, branding
logo/icon/primary+secondary color, communications email, statement descriptors, deposit schedule.
REST boundary registers all of these as plain `string` args (`WooPaymentsRestController.php:800-811`,
`account_business_support_address` as `object` at :772) — `validate_callback => 'rest_validate_request_arg'`,
**no `sanitize_callback`, no field-specific validators**.

CORE also forwards the changed fields to the server first via
`update_provider_backed_settings()` → `api_client->update_account()` (`:1092-1112`), then on success
**also** writes the raw params into the **local** `wcpay` settings option (the :577-581 loop). That
local copy is what `:579` does without sanitization.

### Verdict: BC-RISK / partial-REGRESSION (medium confidence)

This is the one finding in the batch where CORE and CLIENT genuinely diverge.

The CLIENT does **not** write these account fields into a persisted local gateway option at all. Its
`update_account()` (`class-wc-rest-payments-settings-controller.php:915-947`) filters the request to
mapped account keys and hands them to `WC_Payment_Gateway_WCPay::update_account_settings()`
(`class-wc-payment-gateway-wcpay.php:3074-3089`), which calls `$this->update_account(...)` — a **server**
(Transact/Stripe) call. On success it caches only the **server-returned** values via
`update_cached_account_data()` (`:939-941`). The server is the source of truth and the validator; the
client never persists the raw inbound request param as an authoritative local option.

The CLIENT also validates several of these fields at the REST edge that CORE does not:

- `account_statement_descriptor` → `validate_callback => [ $this, 'validate_statement_descriptor' ]` (`:182`)
- `account_business_support_address` → `validate_business_support_address` (`:195`)
- `account_business_support_email` → `validate_business_support_email_address` (`:200`)
- `account_business_support_phone` → `validate_business_support_phone` (`:205`)
- `account_communications_email` → `validate_account_communications_email` (`:226`)

(`account_business_name`, `account_business_url`, branding logo/icon/colors carry no validator in the
client either — those are PARITY.)

So two deltas vs. the oracle:

1. **New local persistence of raw params** — CORE's :577-581 loop writes the unsanitized request value
   into the local `wcpay` settings option in addition to the server round-trip. The CLIENT keeps only a
   cache of server-validated values. This is the storage-sink the finding flags, and it is **new in core**.
2. **Dropped field validators** — CORE's REST schema lost the per-field `validate_callback`s the client
   has on descriptor/address/email/phone (CORE registers them all as generic `string`/`object` args). So
   malformed values that the client would reject at the boundary can reach both the server call and the
   new local option.

Practical XSS severity is bounded: these are mostly tightly-formatted fields (descriptors, hex colors,
emails, phones) and the server still validates on its side, so a bad value is likely rejected before the
local write commits — but the local write happens after the server call returns OK, persisting whatever
raw param was sent for the fields the server accepts loosely (e.g. `business_name`, `business_url`). The
net effect is a small new local sink + relaxed boundary validation relative to the client. Classify as
BC-RISK leaning REGRESSION for the persistence + validator-drop, not a clean PARITY.

client_file: `includes/admin/class-wc-rest-payments-settings-controller.php:933` (server-only path; schema validators :175-226) + gateway `includes/class-wc-payment-gateway-wcpay.php:3074`

---

## Finding 3 — a9e93194 (MED): update_settings() read-modify-writes the whole settings option with no lock

**CORE** `WooPaymentsSettingsService.php:504-583`: reads the full option once
(`$settings = $this->get_gateway_settings();` :505), mutates many keys in memory, then a single
`update_option( self::SETTINGS_OPTION, $settings );` (:583). No lock / CAS around the read→write window.

### Verdict: PARITY (high confidence) — CORE arguably IMPROVED on write count

The CLIENT has the same unlocked read-modify-write of the whole option, and in fact does it **worse**
(N writes per request instead of 1):

- Controller `update_settings()` (`class-wc-rest-payments-settings-controller.php:613-646`) is a flat
  sequence of unguarded per-field `update_*` helpers (`update_is_wcpay_enabled`,
  `update_woopay_custom_message`, `update_is_saved_cards_enabled`, …) — no lock, no transaction.
- Each helper calls gateway `update_option('<key>', ...)`, and the WC core base
  `WC_Settings_API::update_option` (`plugins/woocommerce/includes/abstracts/abstract-wc-settings-api.php:190-198`)
  is itself a read-modify-write of the **entire** option:

```php
public function update_option( $key, $value = '' ) {
    if ( empty( $this->settings ) ) {
        $this->init_settings();
    }
    $this->settings[ $key ] = $value;
    return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
}
```

So the client serializes the whole `$this->settings` array on every single field write — the same
clobber-on-concurrent-save TOCTOU, multiplied across the dozen+ `update_*` calls in one request. CORE's
batch-then-single-write is the same no-lock pattern but reduces the number of write windows from many to
one. The race the finding describes (two admins saving concurrently lose each other's changes) is
inherited from WC core's settings API and present in both repos.

client_file: `includes/admin/class-wc-rest-payments-settings-controller.php:613` (per-field RMW via WC core `abstract-wc-settings-api.php:190`)

---

## Finding 4 — 61a68295 (MED): concurrent checkout can create duplicate Stripe customers (no lock around get-or-create)

**CORE** `WooPaymentsCustomerService.php:163-180` (`get_or_create_customer_id_for_order`): get
`get_customer_id_by_user_id()` → if null, `api_client->create_customer(...)` then `persist_customer_id(...)`.
No lock between the read and the create.

### Verdict: PARITY (high confidence)

The CLIENT has the identical unguarded get-then-create with no dedup lock:

- `get_customer_id_by_user_id()` (`class-wc-payments-customer-service.php:118-136`) — plain
  `get_user_option` / session read, no lock.
- `get_or_create_customer_id_from_order()` (`:176-187`):

```php
$customer_id   = $this->get_customer_id_by_user_id( $user_id );
$customer_data = self::map_customer_data( $order, new WC_Customer( $user_id ?? 0 ) );
$user          = null === $user_id ? null : get_user_by( 'id', $user_id );
if ( null !== $customer_id ) {
    $this->update_customer_for_user( $customer_id, $user, $customer_data );
    return $customer_id;
}
return $this->create_customer_for_user( $user, $customer_data );
```

- `get_customer_id_for_order()` (`:431-446`) — same null-check-then-`create_customer_for_user` shape.
- `create_customer_for_user()` (`:148-165`) calls the server then `update_user_customer_id` /
  session set — no compare-and-swap, no transient lock, no `wp_cache_add` mutex.

Two concurrent checkouts for the same user both read null and both POST `create_customer`, yielding
duplicate Stripe customers — exactly the race in the finding. CORE is a faithful port of this; the race
is pre-existing in WooPayments, not introduced by the merge.

client_file: `includes/class-wc-payments-customer-service.php:176` (and :431, :118, :148)

---

## Finding 5 — 7522b93d (MED): nonce-less admin_init handler records Tracks event from GET params with capability-only check

**CORE** `WooPaymentsOperationalQueueService.php:494-517`
(`handle_wcpay_post_kyc_activation_email_cta`): `phpcs:disable WordPress.Security.NonceVerification.Recommended`,
reads `$_GET['wcpay_referrer']` / `$_GET['wcpay_referrer_stage']`, gate is only
`current_user_can( 'manage_woocommerce' )`, validates `$stage` against `POST_KYC_STAGE_DAYS`, fires
`WC_Tracks::record_event('wcpay_post_kyc_activation_email_cta_clicked', ...)`, then `wp_safe_redirect`.
No nonce verification.

### Verdict: PARITY (high confidence) — verbatim port

The CLIENT handler is line-for-line identical:
`class-wc-payments-post-kyc-activation-email-service.php` registers it on `admin_init` (`:80`,
`add_action( 'admin_init', [ $this, 'maybe_track_cta_click' ] )`) and:

```php
public function maybe_track_cta_click(): void {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['wcpay_referrer'] ) || 'post_kyc_email' !== $_GET['wcpay_referrer'] ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    $stage = isset( $_GET['wcpay_referrer_stage'] ) ? (int) $_GET['wcpay_referrer_stage'] : 0;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if ( ! in_array( $stage, self::STAGE_DAYS, true ) ) {
        return;
    }
    if ( class_exists( 'WC_Tracks' ) ) {
        WC_Tracks::record_event( 'wcpay_post_kyc_activation_email_cta_clicked', [ 'stage' => $stage ] );
    }
    wp_safe_redirect( remove_query_arg( [ 'wcpay_referrer', 'wcpay_referrer_stage' ] ) );
    exit;
}
```

(`class-wc-payments-post-kyc-activation-email-service.php:218-241`)

Same `phpcs:disable` annotation, same capability-only gate, same `$stage` whitelist, same Tracks event,
same redirect, no nonce. The CSRF-polluted-analytics property (a crafted link to an admin records a Tracks
event) is identical in both. The handler is a 1:1 port into core's operational-queue service.

client_file: `includes/class-wc-payments-post-kyc-activation-email-service.php:236` (handler :218, hook :80)

---

## Summary

| id | finding | classification | confidence | client anchor |
|----|---------|----------------|-----------|---------------|
| 6a3e12e5 | WooPay custom message no `wp_kses` | PARITY | high | settings-controller.php:1154 |
| deb2d8c9 | account fields written to options unsanitized | BC-RISK (leaning REGRESSION) | medium | settings-controller.php:933 (server-only in client) |
| a9e93194 | settings option RMW, no lock | PARITY (CORE improved write count) | high | abstract-wc-settings-api.php:190 |
| 61a68295 | duplicate-customer race, no lock | PARITY | high | customer-service.php:176 |
| 7522b93d | nonce-less referrer Tracks handler | PARITY | high | post-kyc-activation-email-service.php:236 |

Four of five are faithful ports of pre-existing WooPayments behavior. The lone divergence is **deb2d8c9**:
CORE persists the raw account-field request params into a local `wcpay` settings option (the
`:577-581` loop) *in addition to* the server round-trip, and drops the per-field `validate_callback`s the
client enforces at the REST edge (statement descriptor, support address/email/phone, communications email).
The client keeps only a cache of server-validated values and never persists the raw inbound param — so the
local unsanitized sink + relaxed boundary validation are genuinely new in core.
