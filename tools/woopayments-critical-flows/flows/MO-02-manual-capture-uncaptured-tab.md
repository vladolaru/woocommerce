# MO-02 — Manual capture from the Uncaptured tab · HYBRID (A + D)

Guards the merchant's list-level capture surface. With manual capture enabled, Payments → Transactions grows an "Uncaptured" tab listing every authorized-not-captured charge with amount, order link, and time left before the authorization expires (~7 days). The regression to catch: under native the tab is missing or empty despite eligible authorizations (merchant can't see money waiting to be captured), or the row-level Capture action fails to capture / fails to remove the row after capturing.

## Fixtures (both stores)

- Connected test account; manual capture enabled (MO-01 fixture).
- At least one authorized-not-captured order (test checkout with `4242 4242 4242 4242` while manual capture is on).

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Payments → Transactions. **An "Uncaptured" tab is present and shows a non-zero count/badge.**
2. Open the Uncaptured tab. **The authorization is listed as a row with its amount, the linked order number, and an authorized/expiry signal (capture-by date or countdown).**
3. Use the row's **Capture** action and confirm. **A success signal renders; no error snackbar.**
4. **The row leaves the Uncaptured tab** (refresh if needed; the tab count decrements).
5. Switch to the main Transactions list: **the charge now appears as a captured/paid transaction.**
6. WooCommerce → Orders → the linked order: **status Processing with a capture order note.**

End state: authorization captured from the list; Uncaptured tab no longer lists it; order paid.

## Layer D — deterministic state assertion

- Pre-capture: assert the authorizations data source (WooPayments authorizations REST endpoint backing the Uncaptured tab) includes the seeded `_charge_id`; order `on-hold` with intent `requires_capture`.
- Post-capture: assert the authorizations endpoint no longer returns that charge; order `processing`; intent `succeeded`; captured amount equals order total.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MO-02-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
