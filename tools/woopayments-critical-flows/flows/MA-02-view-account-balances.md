# MA-02 — View account balances · HYBRID (A + D)

Guards the Payments Overview balance surface: the available and pending balances shown to the merchant must be correct for the connected account, and the Overview cards (the primary "how much money do I have" affordance) must render on native as they do on the reference plugin. Local prerequisite: each store's test account must have balance data (seed charges via the WCPay Dev Tools Test Lab, `admin.php?page=wcpaydev-test-lab`, or prior checkout flows); if an account reports no balance object, the runner honestly keeps this BLOCKED rather than asserting on empty cards.

## Fixtures (both stores)

- Connected test account proxied to the local Transact Platform.
- At least one settled charge and one recent (pending) charge per store, so available and pending are both populated (Test Lab mock transactions are acceptable).

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/overview`, target `admin.php?page=wc-admin&path=/woopayments/overview`):

1. Log in as admin and open Payments → Overview.
2. **Confirm the balance cards render**: an Available balance figure and a Pending balance figure, each with an explicit currency-formatted amount (not `—`, not a perpetual loading skeleton).
3. Cross-check each displayed amount against the store's own account state (Layer D step 1 output). Amounts differ BETWEEN stores (separate test accounts) — parity is card presence + per-store correctness, not equal numbers.
4. **Confirm the currency formatting matches the account's default currency** and that no error notice or console crash replaces the cards.
5. End state: read-only — no store mutation; Overview remains loaded with populated cards on both stores.

## Layer D — deterministic state assertion

- On each store, fetch the account/overview balances via the internal REST endpoint (`wp --user=1 eval` + `rest_do_request` on the payments overview/balance route) and record `available` and `pending` amounts + currency.
- Assert both fields are present, numeric, and denominated in the account's default currency.
- Assert the REST payload amounts equal what Layer A observed on the cards for the same store.
- Compare reference vs target: identical payload SHAPE (fields present, currency semantics), per-store amount correctness.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-02-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
