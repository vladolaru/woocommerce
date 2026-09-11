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

## Latest runner evidence (2026-07-15)

- Fresh context `a4f14f96-9369-44b4-b490-f4c627299208` binds committed source `c324b86f87ccafce367277decdfba0d936e12ee4`, reference subscription `1283`, target subscription `874`, and the expected plugin/native runtime owners.
- Reference `PASS`: fresh customer and product fixtures exercised all four states on classic and Blocks. Regular checkout rendered an unchecked save checkbox without the mandate, rendered the exact future-charge mandate only while checked, and removed it after toggle-back. Subscription checkout hid the save affordance and rendered the mandate unconditionally.
- Target `FAIL — UX`: classic subscription checkout renders the unconditional mandate but incorrectly leaves an unchecked save affordance visible. Blocks regular checkout checks and unchecks the save affordance, but does not render the mandate while checked. Target classic regular and Blocks subscription states match the reference contract.
- Runner ingest recorded 1 PASS, 1 FAIL, 0 BLOCKED, and 0 queued in `tools/woopayments-critical-flows/evidence/runs/20260715T093647Z-17327-partial/`. Both rollup rows bind the exact accepted result bytes as `sha256:29903f387f04b6fe0813a46bf17718e19b114357a1c63e098c12f7f42ddb6e73`.
- This flow is Agent-only, so the target failure is independently decisive. The maintained README and matrix therefore remain `PENDING`.
