# MA-02 — View account balances · HYBRID (A + D)

Guards the Payments Overview balance surface: each store's Balance card must show exact REST-backed Total and Available figures, while native's adjacent Payouts card must also show its deliberately exposed Pending figure. Parity is shared financial correctness, not identical card topology. Local prerequisite: each store's test account must have balance data (seed charges via the WCPay Dev Tools Test Lab, `admin.php?page=wcpaydev-test-lab`, or prior checkout flows); if an account reports no balance object, the runner honestly keeps this `BLOCKED` rather than asserting on empty cards.

## Fixtures (both stores)

- Connected test account proxied to the local Transact Platform.
- At least one settled charge and one recent (pending) charge per store, so available and pending are both populated (Test Lab mock transactions are acceptable).

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/overview`, target `admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview`):

1. Log in as admin and open Payments → Overview.
2. **On both stores, confirm the Balance card renders currency-formatted Total and Available figures** (not `—`, not a perpetual loading skeleton). **On native, additionally confirm the adjacent Payouts card renders its currency-formatted Pending figure.** The pinned plugin does not require a separate Pending row.
3. Cross-check each displayed amount against the store's own account state (Layer D step 1 output): Total equals Available plus Pending; displayed Available matches REST; native's displayed Pending matches REST. Amounts differ BETWEEN stores (separate test accounts) — parity is per-store financial correctness, not equal numbers or identical card layout.
4. **Confirm the currency formatting matches the account's default currency** and that no error notice or console crash replaces the cards.
5. End state: read-only — no store mutation; Overview remains loaded with populated cards on both stores.

## Layer D — deterministic state assertion

- On each store, fetch the account/overview balances via the internal REST endpoint (`wp --user=1 eval` + `rest_do_request` on the payments overview/balance route) and record `available` and `pending` amounts + currency.
- Assert both fields are present, numeric, and denominated in the account's default currency.
- Assert Total equals Available plus Pending for each store and each Balance card's displayed Total and Available equal its REST state.
- Assert native's additional displayed Pending equals its REST state; do not require the pinned plugin to expose a separate Pending row.
- Compare reference vs target: identical payload SHAPE (fields present, currency semantics) and per-store amount correctness, allowing the deliberate presentation difference.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-02-*.sh.

Agent oracle mode: comparable for the shared Total/Available correctness contract, with the explicitly additive native Pending assertion above.

## Runner-verified Layer A result (2026-07-15)

- Reference: **FAIL — UX**. The plugin Overview loaded the exact REST-backed USD Total (`$3,884,690.42`) and Available funds (`$0.00`) figures, but it omitted the explicit currency-formatted Pending figure required by MA-02. Pending was only implicit in Total minus Available.
- Native target: **PASS**. The Payments menu's Overview loaded exact REST-backed USD Total (`US$1,942,288.30`), Available (`US$0.00`), and Pending (`US$1,942,288.30`) figures. The two relevant cards settled without a loading indicator, error notice, fatal console diagnostic, or read-only state divergence.
- Comparable parity: **FAIL — UX** because the written contract requires both stores to expose the explicit Pending figure. This is a written-contract/reference-oracle mismatch, not a native product regression.
- Runner archive: `evidence/runs/20260715T131642Z-64666-partial/` (0 PASS, 2 FAIL, 0 BLOCKED, 0 queued). Accepted result digest: `sha256:68715b36a534487bdedbaacaf57cd16ac320d66d3bd00f0e47f66514788da7a0`.
- The maintained README and matrix status remains `PENDING`: comparable Layer A failed and Layer D is still unwired.

## 2026-07-21 contract correction

- The accepted run and its historical reference `FAIL — UX`, target `PASS`, and comparable `FAIL — UX` verdicts remain unchanged; they evaluated the former requirement that both owners show an explicit Pending figure.
- Pinned WooPayments and its published contract deliberately present Total plus Available on the Balance card. Native presents the same pair and additionally exposes Pending on the adjacent Payouts card.
- The accepted native observation verifies exact REST-backed Total, zero Available, and positive Pending in USD. It does not verify positive-Available, other-currency, negative-balance, multi-currency, or transition behavior.
- The row remains `PENDING` because Layer D is unwired and the intended positive-Available plus positive-Pending fixture has not been rerun under the corrected asymmetric contract.
