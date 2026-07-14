# MA-01 — Open admin as non-admin · DETERMINISTIC

Guards `manage_woocommerce` capability gating on every WooPayments admin surface. The regression risk is native menu registration or REST controllers shipping without the plugin's permission checks, exposing merchant financial data (transactions, payouts, disputes) to a logged-in customer. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account (WCPay Dev Tools proxy to local Transact Platform).
- User `admin` (ID 1, has `manage_woocommerce`).
- User `ma01-customer` with role `customer` (lacks `manage_woocommerce`); create idempotently via `wp user create ma01-customer ma01-customer@example.com --role=customer`.

## Layer D — deterministic state assertion

On BOTH stores (reference plugin REST namespace and native equivalent):

1. As `--user=ma01-customer`, dispatch internal REST `GET` requests for the transactions, payouts/deposits, and disputes list endpoints (`wp eval` with `WP_REST_Request` + `rest_do_request`). Assert each returns `401`/`403` (`rest_forbidden` family) with NO data payload.
2. Repeat the same requests as `--user=1`. Assert `200` with a well-formed list payload.
3. Assert `wp eval 'var_export( user_can( <ma01-customer-id>, "manage_woocommerce" ) );'` is `false` and the same check for user 1 is `true` (fixture sanity, so a false PASS cannot come from a mis-roled probe user).
4. Assert the wc-admin Payments page (reference `admin.php?page=wc-admin&path=/payments/overview`, target `.../path=/woopayments/overview`) is capability-gated: an authenticated HTTP request with the customer's cookies must NOT render the Payments app (expect a permissions error or redirect away).
5. Compare reference vs target: both must deny the customer and admit the admin; any target endpoint that returns data to the customer while the reference denies it is a release-blocking FAIL.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-01-*.sh.
