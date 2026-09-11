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

Deterministic exerciser: wired by `MA-01-open-admin-as-non-admin.sh`, `class-woopaymentscriticalflowsma01driver.php`, and `ma01-evidence.py`.

## Latest runner result

`PASS` on 2026-07-18. On both stores, the idempotent `ma01-customer` fixture was truthfully recorded as pre-existing, had the exact `customer` role without `manage_woocommerce`, and did not require another insert; administrator user 1 retained the capability. The customer received exact 403 standard-error envelopes with no financial-list payload from the transactions, deposits, and disputes routes; the administrator received well-formed 200 list envelopes from all three routes.

Authenticated HTTP probes denied the customer access to each owner-specific Payments page by redirecting to the authenticated My Account surface. The final pages carried logged-in and logout markers, omitted login and Payments-app markers, and stayed within the browser-facing store origin. The exact temporary session tokens were destroyed, the customer fixture on each store had zero retained sessions after the run, and both marker-bounded logs were clean.

The append-only, manifest-bound archive is `evidence/runs/20260718T193353Z-30156-partial/` (2 PASS / 0 FAIL / 0 BLOCKED). The runner validated both final-verdict HMAC witnesses before attaching the manifest paths and SHA-256 digests to the rollup; a second validation in the still-keyed run process matched both rollup digests.
