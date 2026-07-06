---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-F2
created: 2026-06-22 22:55
tool: pirategoat-tools:full-code-review (parity follow-up)
target: REST mobile+file (WooPaymentsMobileRestController, WooPaymentsRestController file route, WooPaymentsTransactionsListRequest)
reconciles:
  - README.md
  - parity-F1-rest-account-capital.md
status: final
---

# Parity batch F2 — REST: MOBILE + FILE + TIMEZONE

Oracle: WooPayments client at `~/Work/a8c/woocommerce-payments` (v10.8.0, `develop`).
Core under review: branch `exp/core-native-payments`.

Classification legend in session `README.md`. Verdicts below are grounded in client code read line-by-line.

## Summary table

| id | classification | confidence | client anchor |
|----|----------------|-----------|---------------|
| 1a302332 | PARITY | high | orders-controller.php:73-151 |
| 8965abd1 | PARITY | high | orders-controller.php:524, reader-controller.php:108-110 / 192-199 |
| 048c849b | PARITY | high | files-controller.php:56-64 + 83-106 |
| 8ad0a91a | PARITY | high | transactions-controller.php:275-296 |

Net result: **all four findings are faithful ports of the client. Zero regressions in this batch.** Three are genuine latent issues that exist identically in the shipping extension (1a302332, 8965abd1, 8ad0a91a); one (048c849b) is a *mislabeled* security finding — the route is intentionally pre-auth in both code-bases and is gated *inside the handler* by a public-purpose allowlist, so it is not actually unauthenticated file disclosure.

---

## 1a302332 (MED) — inconsistent `order_id` path regex (`\w+` vs `\d+`)

**Verdict: PARITY (high).**

CORE `WooPaymentsMobileRestController.php`: the terminal/order routes register `(?P<order_id>\w+)` while `create_customer` alone uses `(?P<order_id>\d+)`:

- `capture_terminal_payment` → `/payments/orders/(?P<order_id>\w+)/...` (line 123)
- `prepare_terminal_payment` → `\w+` (line 133)
- `create_terminal_intent` → `\w+` (line 143)
- `create_customer` → `\d+` (line 153)

All of them resolve the order through `get_order_from_request()` which does `absint( $request->get_param( 'order_id' ) )` then `wc_get_order()` (lines 795-797). A non-numeric id matched by `\w+` is silently coerced by `absint()` to `0`, `wc_get_order(0)` returns falsy, and the caller returns a 404 `wcpay_missing_order`. So the "inconsistency" is cosmetic at runtime: numeric-only ids are the only ones that can ever resolve to a real order.

**Client (oracle) — `includes/admin/class-wc-rest-payments-orders-controller.php:73-151`:**

```php
// capture_terminal_payment
$this->rest_base . '/(?P<order_id>\w+)/capture_terminal_payment',   // :75
// prepare_terminal_payment
$this->rest_base . '/(?P<order_id>\w+)/prepare_terminal_payment',   // :89
// capture_authorization
$this->rest_base . '/(?P<order_id>\w+)/capture_authorization',      // :108
// cancel_authorization
$this->rest_base . '/(?P<order_id>\w+)/cancel_authorization',       // :122
// create_terminal_intent
$this->rest_base . '/(?P<order_id>\w+)/create_terminal_intent',     // :136
// create_customer  ←  the one route that uses \d+
$this->rest_base . '/(?P<order_id>\d+)/create_customer',            // :145
```

The client has the **identical** split: every terminal/authorization route uses `\w+`, and `create_customer` alone uses `\d+`. Core reproduced this pattern faithfully, route-for-route. The downstream coercion also matches — client `prepare_terminal_payment` does `absint( $request['order_id'] )` (line 304) and the others pass the raw id straight to `wc_get_order()` (e.g. `create_terminal_intent` at line 517, `create_customer` at line 451). `wc_get_order()` of a non-numeric string returns false → 404. Same silent-404 behavior on both sides.

**Conclusion:** Not a regression. The inconsistency is a pre-existing client wart, ported verbatim. Worth tidying opportunistically (make all order routes `\d+` for honest 400-on-bad-input), but it is a no-op security/behavior-wise and changing it is a (tiny) behavioral change — a `\w+`→`\d+` tightening would turn today's 404 into a route-not-matched 404 as well, so observable behavior is unchanged. Leave for the merge's "do better than client" cleanup pass, not a blocker.

## 8965abd1 (MED) — user `metadata` forwarded unsanitized to provider API

**Verdict: PARITY (high).**

CORE forwards user-supplied `metadata` straight through in three places:

- `create_terminal_intent` (`WooPaymentsMobileRestController.php:353-366`): `$metadata = $request->get_param( 'metadata' ); $metadata = is_array($metadata) ? $metadata : array();` then `array_merge( $metadata, array( 'order_id'..., 'order_number'... ) )` into the `create_terminal_payment_intention` request. No key/value sanitization.
- `register_reader` (`:527-534`): `$metadata = $request->get_param( 'metadata' );` → `register_terminal_reader( ..., is_array($metadata) ? $metadata : null )`.
- `create_terminal_location` (`:729-735`): same pass-through into `create_terminal_location`.

**Client (oracle):**

`create_terminal_intent` — `includes/admin/class-wc-rest-payments-orders-controller.php:524`:

```php
$metadata                 = $request->get_param( 'metadata' ) ?? [];
$metadata['order_number'] = $order->get_order_number();
...
$wcpay_server_request->set_metadata( $metadata );   // :533
```

The client takes `metadata` directly from the request param, merges in `order_number`, and hands it to the server request unsanitized — exactly as core does. No `sanitize_callback`, no scrubbing.

`register_reader` — `includes/admin/class-wc-rest-payments-reader-controller.php`: the route declares only `'metadata' => [ 'type' => 'object' ]` (lines 108-110), no `sanitize_callback`, and the handler forwards it raw:

```php
$response = $this->api_client->register_terminal_reader(
    $request->get_param( 'location' ),
    $request->get_param( 'registration_code' ),
    $request->get_param( 'label' ),
    $request->get_param( 'metadata' )   // :198 — raw, unsanitized
);
```

Identical pattern to core.

**Conclusion:** Not a regression. Both code-bases forward `metadata` to the provider API without sanitization. The blast radius is bounded by `manage_woocommerce` (core `check_permission` at `:263-265`; client `check_permission`) and by the provider/Stripe API validating the metadata shape (≤50 keys, string values, length caps) on the server side. This is a faithful port of an accepted client behavior, not a new core exposure. If the merge wants to "do better than client", add a `sanitize_callback` / explicit `args` schema on the `metadata` param across these three core routes — but it is not a regression blocker.

## 048c849b (MED, security-critical check) — `GET /wc/v3/payments/file/{file_id}` permission_callback

**Verdict: PARITY (high). Finding is mislabeled — the route is intentionally pre-auth in BOTH, gated inside the handler.**

This is the security-critical parity check, so I am explicit about the client's permission_callback.

CORE `WooPaymentsRestController.php:732-744` — the bare file route:

```php
register_rest_route(
    'wc/v3',
    '/payments/file/(?P<file_id>\w+)',
    array(
        array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => fn( $request ) => $this->run( $request, 'get_native_public_settings_file' ),
            'permission_callback' => '__return_true',     // :739
            'args'                => $this->get_file_route_args(),
        ),
    ),
    $override
);
```

**Client (oracle) — `includes/admin/class-wc-rest-payments-files-controller.php:56-64`:**

```php
register_rest_route(
    $this->namespace,
    '/' . $this->rest_base . '/(?P<file_id>\w+)',
    [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [ $this, 'get_file' ],
        'permission_callback' => [],     // :62 — empty array
    ]
);
```

The client's `permission_callback` for the bare file GET route is **`[]` (an empty array)**. In WordPress an empty/missing permission_callback means *no permission check is run* — the route is effectively public, exactly equivalent to core's `'__return_true'`. So at the route level, both are pre-authentication. **Core did not loosen anything.**

The actual access control lives **inside the handler**, and core ports it faithfully:

Client `get_file()` — `files-controller.php:83-106`:

```php
$file_id    = $request->get_param( 'file_id' );
$purpose    = get_transient( ... );           // cached purpose, else forward to API
...
if ( ! $file_service->is_file_public( $purpose ) && ! $this->check_permission() ) {
    return new WP_Error( 'rest_forbidden', ..., [ 'status' => rest_authorization_required_code() ] );
}
```

Public-purpose allowlist — `includes/class-wc-payments-file-service.php:16-33`:

```php
const FILE_PURPOSE_PUBLIC = [ 'business_logo', 'business_icon' ];
public function is_file_public( string $purpose ): bool {
    return in_array( $purpose, static::FILE_PURPOSE_PUBLIC, true );
}
```

Core `get_native_public_settings_file()` — `WooPaymentsRestController.php:1484-1512`:

```php
$purpose = $this->get_cached_file_purpose( $file_id, $as_account );
if ( '' === $purpose ) { /* forward to provider, cache purpose */ }
if ( ! $this->is_public_file_purpose( $purpose ) ) {
    $permission = $this->check_permissions( $request );
    if ( true !== $permission ) {
        return ... new WP_Error( 'rest_forbidden', ..., [ 'status' => rest_authorization_required_code() ] );
    }
}
```

Core's public allowlist (`WooPaymentsRestController.php:30-33`) is **identical**:

```php
private const PUBLIC_FILE_PURPOSES = array( 'business_logo', 'business_icon' );
```

So in both code-bases: the route is open, the handler fetches the file's `purpose` (from a transient cache, or one upstream API call per cache miss), and only files whose purpose is `business_logo`/`business_icon` are served to unauthenticated callers — everything else requires `manage_woocommerce`. The purpose-cache means the "upstream API per cache miss" cost noted in the finding is bounded to one call per `(file_id, as_account)` per `FILE_PURPOSE_CACHE_TTL` window, and exists identically in the client (client caches under `CACHE_KEY_PREFIX_PURPOSE`).

**Conclusion:** PARITY, and the finding's "unauthenticated; security-critical" framing does not hold against either code-base — the public exposure is deliberately scoped to two cosmetic asset purposes via an in-handler allowlist that core copied byte-for-byte (same two purposes, same `rest_authorization_required_code()` 401/403 on private files). Core's `__return_true` is the documented WP-idiomatic equivalent of the client's empty `[]`. No regression, no net-new exposure. (Minor nit for a future hardening pass: a request-flooding amplification exists in both — a non-public/unknown `file_id` from an anon caller still triggers one provider lookup before the permission gate; the client has the same amplification. Not a merge blocker, and not introduced here.)

## 8ad0a91a (LOW) — `user_timezone` → `DateTimeZone` without try/catch

**Verdict: PARITY (high).**

CORE `WooPaymentsTransactionsListRequest.php:164-180`, `format_transaction_date_by_timezone()`:

```php
$blog_time = new DateTime( $transaction_date );
$blog_time->setTimezone( new DateTimeZone( wp_timezone_string() ) );

$local_time = new DateTime( $transaction_date );
$local_time->setTimezone( new DateTimeZone( $user_timezone ) );   // :173 — unguarded
```

An invalid `user_timezone` string makes `new DateTimeZone()` throw an uncaught `\Exception`. The finding contrasts this with a guarded version elsewhere in core (`ReportsRestController`).

**Client (oracle) — `includes/admin/class-wc-rest-payments-transactions-controller.php:275-296`, `format_transaction_date_with_timestamp()`:**

```php
if ( is_null( $transaction_date ) || is_null( $user_timezone ) ) {
    return $transaction_date;
}
// Get blog timezone.
$blog_time = new DateTime( $transaction_date );
$blog_time->setTimezone( new DateTimeZone( wp_timezone_string() ) );
// Get local timezone.
$local_time = new DateTime( $transaction_date );
$local_time->setTimezone( new DateTimeZone( $user_timezone ) );   // :286 — unguarded
// Compute time difference in minutes.
$time_difference = ( strtotime( $local_time->format( 'Y-m-d H:i:s' ) ) - strtotime( $blog_time->format( 'Y-m-d H:i:s' ) ) ) / 60;
$formatted_date = new DateTime( $transaction_date );
date_modify( $formatted_date, $time_difference . 'minutes' );
return $formatted_date->format( 'Y-m-d H:i:s' );
```

This is the literal source of core's method — same variable names, same `wp_timezone_string()` blog-time anchor, same unguarded `new DateTimeZone( $user_timezone )`, same `strtotime`-diff-then-`date_modify` algorithm, same `'Y-m-d H:i:s'` output. The client does **not** wrap the `DateTimeZone` construction in try/catch either; an invalid `user_timezone` throws identically.

**Conclusion:** Not a regression — faithful port, including the missing guard. The contrast in the finding is *internal to core* (the `ReportsRestController` path is guarded; this transactions-list path is not), and that exact internal inconsistency also exists in the client, where the **reports** controllers and the **transactions** controller are separate files with separate timezone-formatting helpers. For "do better than client", wrap the `DateTimeZone( $user_timezone )` construction in a try/catch (fall back to the blog timezone or return the unformatted date) — a small, safe improvement — but it is a LOW with the same failure mode as the shipping extension, not a blocker.

---

## Cross-cutting note

All four findings in this batch trace to the same merge strategy: the mobile/IPP REST surface and the transactions-list timezone helper were ported into core to preserve byte-level behavioral compatibility with the WooPayments mobile app and existing clients. That choice is *why* none of these are regressions — and also why three of them (regex inconsistency, unsanitized metadata, unguarded timezone) are latent issues inherited wholesale. The file-route finding (048c849b) is the one to push back on in the review writeup: it is not an unauthenticated-disclosure regression, it is an intentionally-public asset route with an in-handler allowlist that core copied exactly. Recommend downgrading/closing 048c849b as "by design, parity with client" and keeping the other three as opportunistic "do better than client" hardening, not merge blockers.
