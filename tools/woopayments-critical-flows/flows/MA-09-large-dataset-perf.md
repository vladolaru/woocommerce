# MA-09 — Large-dataset perf · DETERMINISTIC

Guards admin responsiveness at realistic merchant scale: with a large transaction dataset, the Transactions list must still load and filter without timeouts or pathological slowdown on native. The regression risk is the native data layer regressing to unpaginated or N+1 access patterns that only surface past a few hundred rows. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account seeded with a large dataset: at least 500 mock transactions per store via the WCPay Dev Tools Test Lab (WP-CLI bulk generation preferred for repeatability; record the exact seeded count).
- Same seeded count on reference and target so timings are comparable.

## Layer D — deterministic state assertion

On BOTH stores, via internal REST (`wp --user=1 eval` + `rest_do_request`), timing each call:

1. Fetch page 1 of the transactions list (default page size). Assert HTTP 200 with a full page of rows and a total count ≥ the seeded count.
2. Fetch a deep page (e.g. page 20). Assert correct rows, no duplicate/missing pagination artifacts.
3. Apply a type filter and a date-range filter over the full dataset. Assert the filtered counts are exact (spot-check against the seed ledger) — filters must not degrade to client-side truncation.
4. Performance bound: record wall-clock per call over 3 runs (discard the first as cache warm-up). Assert every target-store call completes within 2× the reference store's median for the same call, and no call exceeds 10 seconds absolute.
5. Assert the store debug log stays clean during the runs (no PHP timeouts, memory exhaustion, or slow-query warnings introduced by the probes).

Reference vs target comparison IS the verdict here: the reference plugin at the same dataset size is the responsiveness oracle; target exceeding the 2× bound or erroring on deep pagination/filtering is a FAIL, and an unseeded store is BLOCKED, never assumed-pass.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-09-*.sh.
