---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-F1
created: 2026-06-22 22:55
target: REST account+capital
---

# Parity F1 — REST: Account Session + Capital

Regression-vs-parity analysis of two ported CORE REST controllers against their CLIENT (oracle) equivalents.

- CORE account session: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php`
- CORE capital: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php`
- CLIENT (v10.8.0 develop): `/Users/vladolaru/Work/a8c/woocommerce-payments`

Note on paths: the controllers actually live under `Internal/Payments/Providers/WooPayments/`, NOT `Internal/Admin/Settings/PaymentProviders/WooPayments/` as the dispatch prompt stated. The dispatch line numbers (e.g. `:96`, `:97`, `:280`, `:208`) also differ slightly from the live files; verdicts below cite the live CORE lines I read.

---

## Finding 1 — 6ea1b4a8 (HIGH): `create_embedded_account_session` swallows exception without logging

**Verdict: PARITY** (confidence: HIGH)

CORE `WooPaymentsAccountSessionRestController.php:91-105` catches `Throwable`, runs `unset( $exception )` (no log), and returns a `WP_Error` with `status => 500`.

The true CLIENT equivalent is **not** the onboarding controller (that handles the *KYC* session and does log). It is the dedicated accounts controller, which delegates to the account service. The service is where the catch lives:

CLIENT `includes/class-wc-payments-account.php:3255-3274`:

```php
public function create_embedded_account_session(): array {
    if ( ! $this->payments_api_client->is_server_connected() ) {
        return [];
    }

    try {
        $account_session = $this->payments_api_client->create_embedded_account_session();
    } catch ( API_Exception $e ) {
        // If we fail to create the session, return an empty array.
        return [];
    }
    ...
}
```

The client catches `API_Exception` and returns `[]` with **no logging** — only a comment. The REST controller (`includes/admin/class-wc-rest-payments-accounts-controller.php:96-104`) never sees the exception; it just wraps whatever the service returns.

So the "no log on failure" behavior is faithful to the client: neither side logs the embedded-account-session failure. CORE is arguably slightly more honest to the caller (returns a `WP_Error`/500 instead of a silent empty array), but the logging gap is pre-existing client behavior, not a regression introduced by the port.

Caveat: the dispatch note said the catch is in the controller and unsets `$exception`. That's accurate for CORE. On the client the catch is one layer down (the service), and the variable is simply unused. Behaviorally equivalent: silent swallow, no log.

---

## Finding 2 — 3eece18f (MED): account-session route registered as READABLE (GET) though it mints a token

**Verdict: PARITY** (confidence: HIGH)

CORE `WooPaymentsAccountSessionRestController.php:71-73` registers `/payments/accounts/session` via `get_readable_route(...)`, i.e. `WP_REST_Server::READABLE` (GET).

CLIENT `includes/admin/class-wc-rest-payments-accounts-controller.php:36-44`:

```php
register_rest_route(
    $this->namespace,
    '/' . $this->rest_base . '/session',   // 'payments/accounts/session'
    [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [ $this, 'create_embedded_account_session' ],
        'permission_callback' => [ $this, 'check_permission' ],
    ]
);
```

The client registers the same route with the same `WP_REST_Server::READABLE` (GET) method. CORE matches the oracle exactly.

The dispatch prompt's concern (GET minting a token "should be CREATABLE/POST") is a legitimate design critique, but it is a **pre-existing client design decision**, faithfully preserved. Not a regression. The contrast with the onboarding controller's `/kyc/session` route (which IS `CREATABLE`/POST, `class-wc-rest-payments-onboarding-controller.php:51-101`) shows the client itself is internally inconsistent here — but the ported route mirrors its direct counterpart, the accounts controller, not the onboarding one.

---

## Finding 3 — 24a6f21d (HIGH): `get_loan_offer_redirect_url` swallows `WooPaymentsApiException` without logging

**Verdict: PARITY** (confidence: HIGH)

CORE `WooPaymentsCapitalRestController.php:269-285` catches `WooPaymentsApiException`, runs `unset( $exception )` (no log), and falls through to the loan-offer error URL.

The CLIENT equivalent of this redirect flow is `WC_Payments_Redirect_Service::redirect_to_capital_view_offer_page()` (the client capital *REST* controller has no loan-offer endpoint at all — it only exposes `active_loan_summary` and `loans`).

CLIENT `includes/class-wc-payments-redirect-service.php:84-102`:

```php
public function redirect_to_capital_view_offer_page(): void {
    $return_url  = WC_Payments_Account::get_overview_page_url();
    $refresh_url = add_query_arg( [ 'wcpay-loan-offer' => '' ], admin_url( 'admin.php' ) );

    try {
        $request = Get_Account_Capital_Link::create();
        ...
        $capital_link = $request->send();
        Tracker::track_admin( 'wcpay_capital_view_offer_redirect' );
        $this->redirect_to( $capital_link['url'] );
    } catch ( Exception $e ) {

        $this->redirect_to_overview_page_with_error( [ 'wcpay-loan-offer-error' => '1' ] );
    }
}
```

The client catches `Exception $e` and redirects to the same error page **without logging** `$e`. Identical swallow-and-redirect behavior. The legacy GET-flag entry point (`wcpay-loan-offer`) is dispatched from `includes/class-wc-payments-account.php:822-824`, which CORE re-implements as `redirect_loan_offer_request()` (`WooPaymentsCapitalRestController.php:154-172`). PARITY: the missing log on the loan-offer failure path is pre-existing client behavior.

---

## Finding 4 — 4be7ec7c/4be7ee7c (MED): capital error responses omit HTTP status in WP_Error data

**Verdict: PARITY** (confidence: HIGH)

CORE `WooPaymentsCapitalRestController.php:201-212` (`api_exception_to_wp_error`):

```php
return new WP_Error(
    '' !== $exception->get_error_code() ? $exception->get_error_code() : 'wcpay_api_error',
    $exception->getMessage()
);
```

No `array( 'status' => ... )` third arg — the HTTP status is omitted from the error data. The dispatch note flags this as inconsistent with the account-session controller, which DOES pass `array( 'status' => 500 )`. That intra-CORE inconsistency is real.

But against the oracle it is faithful. The client capital controller (`includes/admin/class-wc-rest-payments-capital-controller.php`) doesn't build WP_Errors itself — both `get_active_loan_summary` and `get_loans` return `$request->handle_rest_request()`. That method is where the error is shaped:

CLIENT `includes/core/server/class-request.php:367-380`:

```php
final public function handle_rest_request() {
    try {
        $data = $this->send();
        ...
        return $data;
    } catch ( API_Exception $e ) {
        return new WP_Error( $e->get_error_code(), $e->getMessage() );
    }
}
```

The client also builds the capital error as `new WP_Error( code, message )` with **no status in the data array**. CORE reproduces this exactly (it even adds the `'wcpay_api_error'` fallback code, which is an IMPROVEMENT in robustness — the client passes the raw `get_error_code()` even when empty).

So: the omitted-status structure is genuine parity with the client capital path. The structural inconsistency the finding describes is *internal to CORE* (capital vs account-session controllers diverge), not a deviation from the oracle. If CORE wants self-consistency it could add a status to the capital path — but doing so would be an enhancement beyond the ported behavior, not a regression fix.

---

## Summary table

| id | classification | confidence | client_file:line | reason |
|----|---------------|------------|-------------------|--------|
| 6ea1b4a8 | PARITY | HIGH | `class-wc-payments-account.php:3262-3265` | Client also catches and returns `[]` with no log; CORE returns 500 instead of empty array but logging gap is pre-existing |
| 3eece18f | PARITY | HIGH | `class-wc-rest-payments-accounts-controller.php:40` | Client `/payments/accounts/session` is also `WP_REST_Server::READABLE` (GET); CORE mirrors it exactly |
| 24a6f21d | PARITY | HIGH | `class-wc-payments-redirect-service.php:98-101` | Client loan-offer redirect catches `Exception` and redirects to error page without logging too |
| 4be7ec7c | PARITY | HIGH | `class-request.php:377-378` | Client capital errors are `new WP_Error(code, message)` with no status either; CORE even adds a fallback code (slight IMPROVED) |
