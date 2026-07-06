---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-C
created: 2026-06-22 22:55
target: woopay session
status: final
---

# Parity analysis C — WooPay session controller

REGRESSION-vs-PARITY analysis for the WooPayments → WooCommerce-core merge.

- CORE: `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWooPaySessionController.php`
- CLIENT (oracle): `/Users/vladolaru/Work/a8c/woocommerce-payments` v10.8.0 (develop)

The CORE `/payments/woopay/session` REST route is the direct port of the client's `WC_REST_WooPay_Session_Controller` (same namespace `payments/woopay`, same `session` base, same `READABLE` method, same `check_permission` gate). That client file is the correct oracle for both findings.

---

## Finding 1 — c17bc8f3 (CRITICAL): `wcpay_woopay_is_signed_with_blog_token` filter bypasses auth

**CORE** `WooPaymentsWooPaySessionController.php:135`

```php
if ( ! (bool) apply_filters( 'wcpay_woopay_is_signed_with_blog_token', $signed, $request ) ) {
    return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
}
return true;
```

`$signed` = `Rest_Authentication::is_signed_with_blog_token()` (CORE lines 123-125). Any plugin returning `true` from this filter bypasses the Jetpack blog-token signature check entirely and is granted the session (which exposes `session_nonce`, `store_api_token`, and cart — see client `class-woopay-session.php:829-838`).

### VERDICT: PARITY

The filter exists in the CLIENT, applied around the identical auth check, and is the source the CORE port copied from. Grep results (`includes/`):

```
includes/admin/class-wc-rest-woopay-session-controller.php:97
includes/woopay/class-woopay-session.php:864
```

**Decisive client line** — `class-wc-rest-woopay-session-controller.php:89-98` (the `has_valid_request_signature()` used by this REST route's `check_permission`):

```php
private function has_valid_request_signature(): bool {
    /**
     * Filters whether the current request is signed with the store's blog token.
     *
     * @since 5.9.0
     *
     * @param bool $is_signed Whether the request signature was verified against the blog token.
     */
    return apply_filters( 'wcpay_woopay_is_signed_with_blog_token', Rest_Authentication::is_signed_with_blog_token() );
}
```

The client filter is **pre-existing since 5.9.0** and wraps the exact same `Rest_Authentication::is_signed_with_blog_token()` result. Core did not add the filter — it carried the client's filter forward. So this is not a merge regression.

Notable deltas (neither is a regression, but worth recording):

1. **CORE adds a 2nd filter arg.** Client passes only `$is_signed` (1 arg). Core passes `$signed` plus `$request` (2 args, line 92). This is a strict superset — existing 1-arg client listeners still work, and the new arg gives core-side listeners request context. Classify the arg change itself as IMPROVED / additive; it does not weaken the gate.
2. **Auth-bypass severity is real but identical in both.** A malicious/misguided plugin returning `true` bypasses signature auth in both client and core. The pre-condition `'WooPay' === user_agent` (CORE line 119 / client `is_request_from_woopay()` line 106-108) is a trivial spoofable header, so the blog-token filter is the only real gate. Hardening recommendation below applies to both codebases equally.

**Should core harden beyond the client?** Yes, ideally — but that is a forward-looking hardening request, not a merge-parity defect. Options: drop the filter (it has no known legitimate consumer in this repo), or constrain it so it can only ever *tighten* (AND with the real check), never loosen it. Because the client ships the identical bypass surface, removing/constraining it in core would be a **behavior change vs. the oracle**, so it belongs in a follow-up hardening ticket, not in the merge-parity pass.

**Confidence: HIGH.** Filter name, the wrapped expression, and the REST route mapping all match the oracle exactly.

---

## Finding 2 — 5076c7dc: `get_session()` catches `Throwable` and returns `WP_Error` without logging

**CORE** `WooPaymentsWooPaySessionController.php:149-160`

```php
public function get_session( WP_REST_Request $request ) {
    try {
        $email = $request->get_param( 'email' );
        return new WP_REST_Response(
            $this->session_service->get_session_data( is_scalar( $email ) ? sanitize_email( (string) $email ) : null, $request ),
            200
        );
    } catch ( Throwable $exception ) {
        return new WP_Error( 'wcpay_server_error', __( 'Unable to get WooPay session data.', 'woocommerce' ), array( 'status' => 400 ) );
    }
}
```

The caught `$exception` is discarded — never logged. The CORE session service (`WooPaymentsWooPaySessionService.php`) contains **no logging at all** (grep for `Logger|->log|error_log|wc_get_logger` returns nothing), so nothing logs deeper in the stack either. On failure the merchant gets an opaque 400 with no diagnostic trail.

### VERDICT: REGRESSION (observability)

The CLIENT logs on this exact failure path. **Decisive client line** — `class-wc-rest-woopay-session-controller.php:62-73`:

```php
public function get_session_data( WP_REST_Request $request ): object {
    try {
        $response = WooPay_Session::get_init_session_request( null, null, null, $request );
        return rest_ensure_response( $response );
    } catch ( Exception $e ) {
        $error = new WP_Error( 'wcpay_server_error', $e->getMessage(), [ 'status' => 400 ] );
        Logger::log( 'Error validating cart token from WooPay request: ' . $e->getMessage() );  // <-- line 69
        return rest_convert_error_to_response( $error );
    }
}
```

Two observability behaviors are lost vs. the oracle:

1. **No log line.** Client emits `Logger::log( 'Error validating cart token from WooPay request: ' . $e->getMessage() )` (line 69). Core emits nothing.
2. **Error message no longer surfaced.** Client returns `$e->getMessage()` to the caller (line 68). Core returns a static, generic string (`'Unable to get WooPay session data.'`). The static message is arguably *better* for the public response surface (avoids leaking internals to WooPay), but combined with the missing log it means the real reason is captured nowhere.

Additional client logging on the same WooPay session path confirms the client treats this flow as log-worthy: `class-woopay-session.php:112` (`'WooPay request is not signed correctly.'`) and `class-woopay-session.php:715` (logs the response body before `wp_send_json`).

**Caveat on catch breadth:** CORE catches `Throwable` (broader than client's `Exception`) — that part is IMPROVED (a fatal `Error` in the service now degrades to a 400 instead of a 500). The regression is strictly the *dropped logging*, not the catch.

**Recommended fix:** add a log call inside the catch before returning, e.g. route through the same logging facility the rest of native payments uses (or `wc_get_logger()`), preserving `$exception->getMessage()` server-side while keeping the generic public message. This restores parity without re-leaking internals to the WooPay caller.

**Confidence: HIGH.** Client logs on the identical failure path (line 69); CORE controller and service contain zero logging (verified by grep).

---

## Summary

| id | classification | confidence | client_file:line | reason |
|----|----------------|------------|-------------------|--------|
| c17bc8f3 | PARITY | HIGH | `includes/admin/class-wc-rest-woopay-session-controller.php:97` | Filter `wcpay_woopay_is_signed_with_blog_token` exists in client (since 5.9.0), wraps the same `is_signed_with_blog_token()`; core carried it forward (adds harmless 2nd `$request` arg). Real bypass risk, but identical in both — harden in a follow-up, not the merge. |
| 5076c7dc | REGRESSION | HIGH | `includes/admin/class-wc-rest-woopay-session-controller.php:69` | Client `Logger::log()`s the exception on this exact catch; core discards the `Throwable` with no logging anywhere in controller or service. Broader `Throwable` catch is an improvement; the lost log is the regression. |
