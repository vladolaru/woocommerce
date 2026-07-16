# MA-08 — View payouts (+ details) · HYBRID (A + D)

Guards the Payouts (deposits) list and detail pages: the list must show each payout's amount, date, and status truthfully, and the detail page must break the payout down into its constituent transactions. The regression risk is a native payouts surface with wrong amounts/dates/status — the merchant's record of money actually leaving for their bank. Local prerequisite: the test account must have payout data (seed mock payouts via the WCPay Dev Tools Test Lab); if the account has no payouts, the runner honestly keeps this BLOCKED rather than passing on an empty list.

## Fixtures (both stores)

- Connected test account with at least 2 payouts per store (one `paid`, one `pending`/`in_transit` if seedable) with recorded amounts and dates from the Test Lab seed.

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/payouts`, target `admin.php?page=wc-settings&tab=checkout&path=/woopayments/payouts`):

1. Open Payments → Payouts as admin.
2. **Confirm the list is populated** with the seeded payouts, each row showing a currency-formatted amount, a date, and a status badge matching the seeded state.
3. **Confirm amounts and dates match the fixture ledger** (per-store; amounts differ between stores' accounts).
4. Click a payout row. **Confirm the payout details page opens** with the payout's total and its transaction breakdown/summary section.
5. End state: read-only — detail view reached for a known payout on both stores.

## Layer D — deterministic state assertion

- Fetch the payouts list via internal REST; assert the seeded payouts are present with `amount`, `date`, and `status` matching the fixture ledger.
- Fetch one payout's detail; assert its total is consistent with the payout's transaction summary (charges − fees − refunds arithmetic holds within the payload).
- Assert status values are from the expected vocabulary (`paid`, `pending`, `in_transit`, `failed`) — no blank/unknown statuses.
- Compare reference vs target: same fields and arithmetic integrity for each store's own payouts.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-08-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Runner-verified result (2026-07-16)

-   **Reference: PASS.** The plugin rendered 16 provider-backed payouts, including the selected `$3,884,690.42 USD` paid payout and a distinct `$11,730.26 USD` comparison row. Its detail view rendered all 19 constituent transactions; authenticated state reconciled `$4,000,720.00` gross − `$116,029.58` fees = `$3,884,690.42` net.
-   **Target: PASS.** Native rendered 18 provider-backed payouts, including the selected `US$1,942,288.30` paid payout and a distinct `US$9,831.98` comparison row. Its detail view rendered all 8 constituent transactions and the exact `US$2,000,300.00` gross − `US$58,011.70` fees = `US$1,942,288.30` net summary.
-   **Parity: PASS.** Both stores matched their own authenticated payout ledger for IDs, amounts, dates, statuses, and transaction order. Pre/post state was byte-identical, all browser and REST responses were successful, and the six screenshots were inspected at original resolution.
-   Authoritative Layer-A runner archive: `evidence/runs/20260716T091410Z-39036-partial/` (2 PASS / 0 FAIL / 0 BLOCKED / 0 queued; result digest `sha256:df6b4a0d6c48bf48cc89fa06f6c3f73d095fa541b26643996f88aea679b26064`).
-   Maintained status remains `PENDING`: Layer A passes, but Layer D is still unwired.
