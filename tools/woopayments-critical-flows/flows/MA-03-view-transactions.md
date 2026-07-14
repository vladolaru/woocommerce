# MA-03 — View transactions · HYBRID (A + D)

Guards the Transactions list, the merchant's primary money ledger: the list must populate with real transaction rows, and each row must open its detail view. The regression risk is the native Transactions surface rendering empty, dropping columns, or breaking the row → detail navigation the plugin provides.

## Fixtures (both stores)

- Connected test account; at least 5 transactions per store spanning charge and refund types (drive card checkouts + one refund, or seed via the WCPay Dev Tools Test Lab mock transactions).
- Record the seeded transaction IDs/order numbers for cross-checking.

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/transactions`, target `admin.php?page=wc-admin&path=/woopayments/transactions`):

1. Log in as admin and open Payments → Transactions.
2. **Confirm the table is populated** with the seeded transactions: each row shows date, type (charge/refund), amount, fees, net, and payment-method column — no empty state, no infinite loading.
3. **Confirm row → detail navigation**: click a known charge row and verify it opens that transaction's details page (correct amount and associated order).
4. Navigate back; confirm the list state survives the round trip (rows still listed).
5. End state: read-only — the details page of a known seeded charge was reached on both stores.

## Layer D — deterministic state assertion

- Fetch the transactions list via internal REST (`wp --user=1 eval` + `rest_do_request`) on each store; assert the response contains at least the seeded transaction count.
- Assert each seeded transaction row carries non-empty `amount`, `fees`, `net`, `type`, and a resolvable order/charge reference matching the fixture ledger.
- Assert list totals/summary are consistent with the sum of the rows.
- Compare reference vs target: same fields present, same per-store fixture rows resolvable.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
