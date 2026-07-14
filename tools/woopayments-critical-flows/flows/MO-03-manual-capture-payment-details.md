# MO-03 — Manual capture from the payment-details page · HYBRID (A + D)

Guards the third capture surface: the per-transaction payment-details page (Payments → Transactions → Uncaptured → open a row). For an uncaptured intent this page must show the authorized state and offer a Capture action; capturing from here must pay the order and clear the authorization from the Uncaptured tab. The regression to catch: under native the detail page renders the authorization without any capture affordance (dead end for a merchant investigating a specific charge), or the detail-level capture completes in UI but the order/intent state never transitions.

## Fixtures (both stores)

- Connected test account; manual capture enabled (MO-01 fixture).
- One authorized-not-captured order (test checkout with `4242 4242 4242 4242` while manual capture is on).

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Payments → Transactions → Uncaptured tab → click the authorization's row to open its payment-details page.
2. **The page identifies the payment (amount, order link) and shows an authorized/uncaptured status chip.**
3. **A Capture action (button) is present on the detail page.** Click it and confirm.
4. **A success signal renders and the on-page status flips to a captured/paid state; the Capture action is no longer offered.**
5. Return to Payments → Transactions → Uncaptured: **the row is gone.**
6. WooCommerce → Orders → the linked order: **status Processing with a capture order note for the full amount.**

End state: intent captured from the detail page; Uncaptured tab cleared; order paid.

## Layer D — deterministic state assertion

- Pre-capture: assert order `on-hold`, `_intent_id` with status `requires_capture`, `_charge_id` present and listed by the authorizations endpoint.
- Post-capture: assert intent `succeeded`, order `processing`, captured amount equals order total, authorization gone from the authorizations endpoint, capture note on the order.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MO-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
