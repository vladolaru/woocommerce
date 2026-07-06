---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-E
created: 2026-06-22 22:55
tool: manual regression-vs-parity diff (CORE vs CLIENT oracle)
target: API client (WooPaymentsApiClient.php ported from class-wc-payments-api-client.php)
reconciles:
  - ../README.md
status: final
---

# Parity E — API Client

REGRESSION-vs-PARITY analysis of 6 findings in the ported WooPayments API client.

- **CORE**: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- **CLIENT (oracle)**: `woocommerce-payments` v10.8.0 — `includes/wc-payment-api/class-wc-payments-api-client.php` and `includes/core/server/`

Verdict legend: REGRESSION (core worse than client) / PARITY (same behavior, bug pre-exists in client) / IMPROVED / NEW-IN-CORE / BC-RISK.

---

## Finding 1 (0175d50d, HIGH) — `get_disputes_summary()` wraps filters under key '0'

**Verdict: PARITY** · **Confidence: HIGH**

CORE (`WooPaymentsApiClient.php:997-999`):

```php
public function get_disputes_summary( array $filters = array() ): array {
	return $this->request( array( '0' => $filters ), self::DISPUTES_API . '/summary', 'GET' );
}
```

CLIENT (`class-wc-payments-api-client.php:629-631`):

```php
public function get_disputes_summary( array $filters = [] ): array {
	return $this->request( [ $filters ], self::DISPUTES_API . '/summary', self::GET );
}
```

`[ $filters ]` in the client is literally `['0' => $filters]` — the exact same nesting CORE wrote out explicitly as `array( '0' => $filters )`. Both clients hand the request layer a one-element list whose only key is `0`.

Both `request()` implementations serialize GET params the same way. CLIENT (`class-wc-payments-api-client.php:2684-2686`):

```php
if ( in_array( $method, [ self::GET, self::DELETE ], true ) ) {
	$url .= '?' . http_build_query( $params );
```

So `http_build_query( ['0' => ['match' => ...]] )` yields `0%5Bmatch%5D=...` (`0[match]=...`) in BOTH. The "filters never reach the platform flat" behavior is a faithful port, not a core-introduced regression. The contrast with sibling `get_disputes()` (which passes `$filters` flat in both core line 987 and client line 641) is identical on both sides too.

Note for triage: this is a genuine latent bug, but it is **pre-existing in the client** — fixing it is a shared-bug-fix opportunity, not a merge regression to block on.

---

## Finding 2 (6022b9aa, MED) — `validate_route_resource_id` uses `\w+` (rejects hyphenated Stripe IDs)

**Verdict: PARITY** · **Confidence: HIGH**

CORE (`WooPaymentsApiClient.php:1729-1734` route resource validator vs `1757-1762` document validator):

```php
private function validate_route_resource_id( string $id ): void {
	if ( ! preg_match( '/^\w+$/', $id ) ) {            // rejects hyphens
		throw new WooPaymentsApiException( ... 'wcpay_route_validation_failure', 400 );
	}
}
private function validate_document_id( string $id ): void {
	if ( '' === $id || ! preg_match( '/^[\w-]+$/', $id ) ) {   // allows hyphens
```

CLIENT has no centralized validator — it inlines the same regexes. Every resource-ID route uses `'/^\w+$/'`, e.g. `get_dispute()` (`class-wc-payments-api-client.php:652`):

```php
if ( ! preg_match( '/^\w+$/', $dispute_id ) ) {
	throw new API_Exception( ... 'wcpay_route_validation_failure', 400 );
}
```

Same `\w+` at client lines 245 (intent), 573 (transaction), 652/688/747/804 (dispute), 924/949 (file), 982 (id), 1401 (customer), 1469 (product), 1501 (price), 1526/1553/1578 (invoice), 1603/1627 (charge), etc. And the document route uses the hyphen-allowing variant — `get_document()` (`class-wc-payments-api-client.php:2133`):

```php
if ( ! preg_match( '/^[\w-]+$/', $document_id ) ) {
```

The `\w+`-vs-`[\w-]+` inconsistency is identical in both codebases. CORE merely centralized the inlined client regexes into two private methods, preserving each pattern exactly. Not a core regression. (If hyphenated Stripe-style IDs are a real concern, it is a shared pre-existing limitation.)

---

## Finding 3 (bd8596d0, MED) — VAT number interpolated into path without rawurlencode()

**Verdict: NEW-IN-CORE** · **Confidence: HIGH**

CORE (`WooPaymentsApiClient.php:1282-1289`):

```php
public function validate_vat( string $vat_number ): array {
	return $this->request_with_legacy_filter(
		array(),
		self::VAT_API . '/' . $vat_number,   // raw interpolation, no rawurlencode
		'GET',
		'wcpay_validate_vat_request'
	);
}
```

**The client has no equivalent `validate_vat()` method on the API client at all.** The only VAT method in `class-wc-payments-api-client.php` is `save_vat_details()` (line 2155), which is a POST that puts the VAT number in the **body**, never in the path:

```php
public function save_vat_details( $vat_number, $name, $address ) {
	$response = $this->request(
		[ 'vat_number' => $vat_number, 'name' => $name, 'address' => $address ],
		self::VAT_API,        // path is just 'vat', no interpolation
		self::POST
	);
```

The client's VAT *validation* lives in the REST controller layer (`includes/admin/class-wc-rest-payments-vat-controller.php:71` `validate_vat()`), which dispatches a server-request object rather than building the path string here. So the GET-VAT-into-path codepath — and therefore the missing-`rawurlencode` exposure — is **new in core**, introduced by the port, with no client precedent to compare encoding against. The risk is mild (VAT numbers are alphanumeric, `validate_route_resource_id` is NOT applied here so a `/` or space would corrupt the path) but it is a core-only addition, not a regression-from-client.

---

## Finding 4 (54b15aba, LOW) — retry backoff constant = 250 microseconds

**Verdict: PARITY** · **Confidence: HIGH**

CORE (`WooPaymentsApiClient.php:37-40`, used at `1894`):

```php
private const REQUEST_RETRIES_BACKOFF_MICROSECONDS = 250;
...
usleep( self::REQUEST_RETRIES_BACKOFF_MICROSECONDS * ( 2 ** $retries ) );
```

CLIENT (`class-wc-payments-api-client.php:42`, used at `2766`):

```php
const API_RETRIES_BACKOFF_MSEC = 250;
...
usleep( self::API_RETRIES_BACKOFF_MSEC * ( 2 ** $retries ) );
```

Same numeric value (250), same `usleep()` call (microseconds), same `2 ** $retries` exponential factor, same `REQUEST_RETRIES_LIMIT`/`API_RETRIES_LIMIT = 3`. Behavior is byte-for-byte equivalent: ~250µs/500µs/1ms backoffs — effectively negligible in both. Note the client's constant is *named* `_MSEC` but feeds `usleep()` (a microseconds API), so the client name is misleading; CORE's `_MICROSECONDS` name is actually more accurate. No behavioral regression — arguably a naming IMPROVEMENT, but the effective backoff is identical and equally tiny in both.

---

## Finding 5 (d3111113, LOW) — orphaned docblock for reporting API constant

**Verdict: REGRESSION (dropped constant)** · **Confidence: HIGH**

CORE (`WooPaymentsApiClient.php:137-143`):

```php
	/**
	 * WooPayments reporting API path.
	 */
	/**
	 * WooPayments authorizations API path.
	 */
	private const AUTHORIZATIONS_API = 'authorizations';
```

The "WooPayments reporting API path." docblock has **no constant following it** — it's immediately followed by a second docblock for `AUTHORIZATIONS_API`. The `REPORTING_API` constant was dropped in the port but its docblock left orphaned.

CLIENT HAS the constant (`class-wc-payments-api-client.php:58`):

```php
const REPORTING_API = 'reporting';
```

So the constant existed in the client and was lost during the port. Impact is low — CORE's reporting routes use `request_with_legacy_filter()` with paths built elsewhere and don't reference a `REPORTING_API` const (the constant is unused in core, hence it compiles), so there's no broken call site. But it is a genuine drop-during-port (dead docblock + a missing public-ish path constant that client extensions referencing `WC_Payments_API_Client::REPORTING_API` would no longer find on the native client). Classify as a minor REGRESSION; at minimum the orphaned docblock should be removed.

---

## Finding 6 (147606e9, MED) — `wcpay_list_transactions_request` filter passes object lacking `send()`

**Verdict: BC-RISK / REGRESSION** · **Confidence: HIGH**

CORE (`WooPaymentsTransactionsRestController.php:383-396`):

```php
$transactions_request = WooPaymentsTransactionsListRequest::from_rest_request( $request );
/**
 * @param WooPaymentsTransactionsListRequest $transactions_request Native transactions list request.
 */
$filtered_request = apply_filters( 'wcpay_list_transactions_request', $transactions_request );

if ( ! is_object( $filtered_request ) || ! method_exists( $filtered_request, 'get_params' ) ) {
	return $transactions_request->get_params();
}
$params = $filtered_request->get_params();
```

The object passed to the filter is `WooPaymentsTransactionsListRequest`, which `extends WooPaymentsPaginatedListRequest` (`WooPaymentsTransactionsListRequest.php:20`), an `abstract class` (`WooPaymentsPaginatedListRequest.php:18`). **Neither has a `send()` method** — grepping the entire native request tree, the only `send`-family method is `send_request()` on the API client itself. Core only ever calls `get_params()` on the filtered object.

CLIENT fires the same hook with a `List_Transactions` object (`includes/core/server/request/class-list-transactions.php:35`):

```php
protected $hook = 'wcpay_list_transactions_request';
```

`List_Transactions extends Paginated` → ... → base `Request` (`includes/core/server/class-request.php`), which provides:

```php
final public function send() {                       // class-request.php:336
	$hook = $this->get_hook();
	...
	return $this->format_response(
		$this->api_client->send_request( $this->apply_filters( $hook, ...$this->hook_args ) )
	);
}
```

In the client, the hook is dispatched *from inside* `Request::apply_filters()` (called by `send()` at line 353), and extensions that hook `wcpay_list_transactions_request` receive a full `Request` object on which `$request->send()`, `$request->set_*()`, `$request->get_params()` etc. are all valid. The client's own `from_rest_request()` and the request-builder pattern guarantee a `send()`-capable object.

CORE breaks that contract: it hands the filter a stripped-down native value object exposing only `get_params()` (and setters), with **no `send()`**. Any existing client extension that hooks `wcpay_list_transactions_request` and calls `$request->send()` (or relies on the `Request` API surface) would hit a fatal `Error: Call to undefined method` on CORE. This is a real backwards-incompatibility introduced by the port. Same issue applies to the sibling dispatch in `WooPaymentsReportsRestController.php:344` (`$fees_request`).

Classify as **BC-RISK / REGRESSION** — the hook name is preserved but the object contract behind it is silently narrowed.

---

## Summary table

| id | classification | confidence | client_file:line | reason |
|----|----------------|------------|-------------------|--------|
| 0175d50d | PARITY | HIGH | class-wc-payments-api-client.php:630 | client `[ $filters ]` == core `['0'=>$filters]`; both `http_build_query` to `0[match]=` — pre-existing client bug |
| 6022b9aa | PARITY | HIGH | class-wc-payments-api-client.php:652 | client inlines same `/^\w+$/` for resource IDs and `/^[\w-]+$/` for documents; core just centralized them |
| bd8596d0 | NEW-IN-CORE | HIGH | class-wc-payments-api-client.php:2155 | client has no GET `validate_vat()`; only POST `save_vat_details` (VAT in body); path-VAT codepath is core-only |
| 54b15aba | PARITY | HIGH | class-wc-payments-api-client.php:42 | identical 250 + `usleep()` + `2**$retries`; client name `_MSEC` is misleading, core `_MICROSECONDS` more accurate |
| d3111113 | REGRESSION | HIGH | class-wc-payments-api-client.php:58 | client has `const REPORTING_API='reporting'`; core dropped the constant, left orphaned docblock |
| 147606e9 | BC-RISK/REGRESSION | HIGH | class-request.php:336 | client filter object (`List_Transactions`→`Request`) HAS `send()`; core native request has only `get_params()`, no `send()` — extensions calling `$request->send()` fatal |
