# MA-04 — Filter transactions · HYBRID (A + D)

Guards the Transactions filter controls: applying a filter must narrow the list to exactly the matching criteria, and clearing it must restore the full list. The regression risk is native filters that render but do not constrain the query (silently showing everything) or that error out, leaving the merchant unable to isolate refunds or a date range.

## Fixtures (both stores)

- Connected test account; a mixed transaction set per store: at least 3 charges and 2 refunds, with at least one transaction dated outside the last 7 days (Test Lab mock transactions or driven checkouts + refunds).
- Record the per-type counts for the fixture window.

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/transactions`, target `admin.php?page=wc-admin&path=/woopayments/transactions`):

1. Open Payments → Transactions as admin; note the unfiltered row count.
2. **Confirm the filter controls are discoverable** (type and date filters reachable from the list toolbar).
3. Apply a Type = Refund filter. **Confirm the list narrows to only refund rows** and the row count equals the seeded refund count — no charge rows leak through.
4. Apply a date-range filter covering only the last 7 days. **Confirm the older seeded transaction disappears** from the results.
5. Clear all filters. **Confirm the full unfiltered list returns.**
6. End state: read-only — full list restored on both stores.

## Layer D — deterministic state assertion

- Fetch the transactions list via internal REST with the type filter parameter (refunds only); assert every returned row has `type = refund` and the count matches the fixture ledger.
- Repeat with a date-range parameter excluding the older fixture transaction; assert it is absent and in-window rows are all present.
- Assert the unfiltered call returns the full seeded set (filter application is not sticky server-side).
- Compare reference vs target: identical narrowing semantics for the same fixture shape.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-04-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
