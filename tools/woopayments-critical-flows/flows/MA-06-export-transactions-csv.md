# MA-06 — Export transactions CSV · DETERMINISTIC

Guards the transactions CSV export: the export must be triggerable, must produce a downloadable CSV, and the CSV's contents must match the transactions list (row count, amounts, no truncation). The regression risk is a native export affordance that fails silently, emits an empty/partial file, or mangles amount columns the merchant feeds into accounting. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account; at least 5 transactions per store with known amounts and types (charges + at least one refund), seeded via driven checkouts or the WCPay Dev Tools Test Lab.
- A working mail catcher on each store if the export is delivered asynchronously via an emailed download link (the plugin's large-export path).

## Layer D — deterministic state assertion

On BOTH stores:

1. Trigger the export via the internal REST export endpoint (`wp --user=1 eval` + `rest_do_request` on the transactions download/export route).
2. Resolve the CSV: direct response body, or the download URL from the export email in the mail catcher for the async path. A trigger that reports success but yields no retrievable CSV within the polling window is a FAIL, not a pass-by-assumption.
3. Assert CSV integrity:
   - header row present with the transaction columns (date, type, amount, fees, net, order/customer reference);
   - data row count equals the transactions-list count for the same unfiltered query;
   - each seeded transaction's amount and type in the CSV matches the list endpoint's values;
   - refund rows carry negative/refund semantics consistently with the list.
4. Assert the export requires `manage_woocommerce` (a customer-user trigger must be rejected — corroborates MA-01 on this endpoint).
5. Compare reference vs target: same columns present, same per-store row fidelity. Column ORDER may differ; missing columns or missing rows on target is a FAIL.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-06-*.sh.
