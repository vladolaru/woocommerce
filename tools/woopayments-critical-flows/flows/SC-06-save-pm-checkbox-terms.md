# SC-06 — Save-PM checkbox + terms behavior (subscription vs regular) · A

Pure UI-logic flow, browser-judged. The reusable-payment mandate/terms copy must track when saving actually applies: for a regular cart the save checkbox is offered and the mandate renders only while it is checked; for a subscription cart the checkbox is hidden (saving is forced) and the mandate renders unconditionally. Regressions guarded: a missing checkbox strands returning shoppers, a missing mandate is a compliance gap, and a save checkbox on subscription checkout is a false affordance.

## Fixtures (both stores)

- Connected test account; `saved_cards = yes`; WC Subscriptions active.
- One simple product and one subscription product.
- Logged-in customer; both checkout surfaces (classic + Blocks) reachable.

## Layer A — agent-driven browser

BOTH stores, on classic and Blocks:

1. Cart = regular product only → checkout.
2. **Confirm the save-payment-method checkbox renders** under the card fields, unchecked by default, with **no mandate/terms copy while unchecked**.
3. Check the box. **Confirm the mandate/terms copy appears** (consent to future off-session use of the card).
4. Uncheck the box. **Confirm the mandate copy disappears.**
5. Cart = subscription product → checkout.
6. **Confirm the save checkbox is hidden** (no opt-out offered) and **the mandate/terms copy renders unconditionally**.
7. **Functional:** all four states (regular unchecked/checked, toggle-back, subscription) match the reference store's behavior on both surfaces.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
