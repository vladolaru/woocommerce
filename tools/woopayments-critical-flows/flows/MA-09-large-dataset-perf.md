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

Deterministic exerciser: wired by `MA-09-large-dataset-perf.sh`, `class-woopaymentscriticalflowsma09driver.php`, and `ma09-compare.py`.

## Latest runner result

`PASS` on 2026-07-16. Both connected test accounts were normalized to exactly 500 unique transactions. The reference fixture used one calibrated deterministic charge plus 124 successful Test Lab bulk charges; the target used one calibrated charge plus 16 successful guarded native charges. The gate reconciled the exhaustive 100-row ledger with all twenty 25-row pages, exact charge/date projections, stable pre/post ledger digests, and clean marker-bounded logs.

All eight measured native calls passed the relative bound at 0.79–0.93× the corresponding reference median, and every warmup/measured call was below 0.24 seconds. The append-only archive contains the normalized, run-bound, digest-bound reference/target payloads and comparison result: `evidence/runs/20260716T100225Z-76348-partial/`.
