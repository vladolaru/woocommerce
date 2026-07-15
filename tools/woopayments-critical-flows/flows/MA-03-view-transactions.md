# MA-03 — View transactions · HYBRID (A + D)

Guards the Transactions list, the merchant's primary money ledger: the list must populate with real transaction rows, and each row must open its detail view. The regression risk is the native Transactions surface rendering empty, dropping columns, or breaking the row → detail navigation the plugin provides.

## Fixtures (both stores)

-   Connected test account; at least 5 transactions per store spanning charge and refund types (drive card checkouts + one refund, or seed via the WCPay Dev Tools Test Lab mock transactions).
-   Record the seeded transaction IDs/order numbers for cross-checking.

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/transactions`, target `admin.php?page=wc-settings&tab=checkout&path=/woopayments/transactions`):

1. Log in as admin and open Payments → Transactions.
2. **Confirm the table is populated** with the seeded transactions: each row shows date, type (charge/refund), amount, fees, net, and payment-method column — no empty state, no infinite loading.
3. **Confirm row → detail navigation**: click a known charge row and verify it opens that transaction's details page (correct amount and associated order).
4. Navigate back; confirm the list state survives the round trip (rows still listed).
5. End state: read-only — the details page of a known seeded charge was reached on both stores.

## Layer D — deterministic state assertion

-   Fetch the transactions list via internal REST (`wp --user=1 eval` + `rest_do_request`) on each store; assert the response contains at least the seeded transaction count.
-   Assert each seeded transaction row carries non-empty `amount`, `fees`, `net`, `type`, and a resolvable order/charge reference matching the fixture ledger.
-   Assert list totals/summary are consistent with the sum of the rows.
-   Compare reference vs target: same fields present, same per-store fixture rows resolvable.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-03-\*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Runner-verified result (2026-07-15)

-   **Reference: PASS.** The plugin list rendered five API-bound charge/refund fixtures with Date/Time, Type, Amount, Fees, Net, and Payment Method values. The known charge opened the matching `$20.00` / order `2006` detail, the same charge rows remained after returning to the list, and pre/post state was identical.
-   **Target: FAIL — UX.** The native list rendered the five exact fixtures and its known charge opened the matching `US$20.00` / order `1514` detail, but the list exposed only Date, Type, Customer, and Amount. The underlying API and detail page retained Fees, Net, Payment Method, and order context, localizing the failure to list presentation. The round trip and read-only checks passed.
-   **Parity: FAIL — UX.** The native list drops three required money-ledger fields that remain visible in the reference list even though the native transaction data still contains them.
-   Authoritative runner archive: `evidence/runs/20260715T140147Z-19663-partial/` (1 PASS / 1 FAIL / 0 BLOCKED / 0 queued; result digest `sha256:720a619643d9eb5093612946a0032ce936eea1bc200dc35bd56e1c63d51879a2`).
-   Maintained status remains `PENDING`: Layer A fails on the target and Layer D is still unwired.
