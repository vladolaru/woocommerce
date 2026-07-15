# SC-13 — Shipping cost updates on method switch · HYBRID (D + A)

Totals-integrity guard: switching shipping methods at checkout must recompute the displayed total live ($20 → $40 → $20), and the amount WooPayments charges must equal the last total the shopper saw. A stale total is a money bug — the charged intent would differ from the displayed amount. Functional acceptance: totals recompute on each switch and the paid amount matches the final selection. UX checkpoint: the order-summary total updates live, no manual refresh.

## Fixtures (both stores)

- Connected test account; "Credit/Debit card" enabled.
- A shipping zone covering the test address with two flat rates: "Standard" $20.00 and "Express" $40.00.
- A simple in-stock product with a known price. Card: `4242 4242 4242 4242`.

## Layer A — agent-driven browser

BOTH stores (classic, and Blocks where both surfaces are active):

1. Add the product; go to checkout with an address inside the zone.
2. Note the displayed total with **Standard ($20)** selected.
3. Switch to **Express ($40)**. **Confirm the displayed total updates live** by exactly +$20, without a page reload.
4. Switch back to **Standard**. **Confirm the total returns** to the step-2 value.
5. Place the order with Standard selected, paying with `4242 4242 4242 4242`.
6. **Functional:** the order-received total equals product price + $20 shipping, and the order is paid for exactly that amount.

## Layer D — deterministic state assertion

- Order total = product price + $20; the shipping line is Standard/$20 (not Express).
- `_intent_id`/`_charge_id` present; the charged intent amount equals the order total (no stale $40-shipping charge).
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-13-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Runner-verified Layer A result (2026-07-15)

- Reference: **PASS**. Classic and Blocks each displayed `$20 → $40 → $20` in the same document, then created paid `$20` orders whose sole shipping line was Standard `$20` and whose provider intent/charge were succeeded and captured for exactly 2,000 cents.
- Native target: **FAIL — functional**. Classic passed the same contract. Blocks displayed the exact live total sequence with the `US`, `CA 94107` shipping address and “Use same address for billing” visibly retained, but card confirmation sent an empty `billing_details[address][country]` to Stripe. Stripe returned HTTP 400, the checkout displayed the provider error, and no Blocks order, intent, charge, token, or customer mapping was created.
- Runner archive: `evidence/runs/20260715T123137Z-9760-partial/` (1 PASS, 1 FAIL, 0 BLOCKED, 0 queued). Accepted result digest: `sha256:767bae8807a8300167122b8d52464bd47e129f8b2e275fd648bc647337a16536`.
- The maintained README and matrix status remains `PENDING`: Layer A failed on the target and Layer D is still unwired.
