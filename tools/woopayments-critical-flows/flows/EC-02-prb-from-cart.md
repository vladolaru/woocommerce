# EC-02 — PRB from cart · AGENT (A)

Guards the regression where the native gateway loses the express Payment Request surface on the cart page: the flow fails if the PRB is missing from the cart, the wallet sheet does not reflect the cart contents/total, or the order is not paid via PRB. Locally BLOCKED: Payment Request/Apple Pay/Google Pay sheets need a real browser wallet + real card; manual/live pass only — the runner keeps this row queued/BLOCKED, never assume-pass. The implementor's a4aq checkout browser gate (`tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs`, `blocks-cart-express` route) already captures express-surface structural evidence — corroboration for button presence, not sheet completion.

## Fixtures (both stores)

- Connected test account with express checkout enabled in WooPayments settings, cart location checked.
- Simple physical product "PRB Tee" at $18.00, quantity 2 in the cart ($36.00 subtotal); shipping zone with one $5.00 flat rate for the wallet card's country.
- Browser with a provisioned wallet (Chrome + Google Pay real card, or Safari + Apple Pay); both classic cart and Cart block pages published.
- Test mode on: the real card only unlocks the wallet sheet; charges stay simulated — no live money moves.

## Layer A — agent-driven browser

BOTH stores:

1. In the wallet-provisioned browser, add 2 × "PRB Tee" to the cart and open the cart page.
2. **Confirm the PRB renders on the cart page** (Cart block; repeat on the classic cart if published), above/near proceed-to-checkout.
3. Click the PRB and **confirm the wallet sheet reflects the cart: 2 × line quantity, shipping option, total $41.00**.
4. Authorize with the real wallet card and complete the sheet.
5. **Confirm order-received shows the paid $41.00 total** with no error notice.
6. In admin, open the order and **confirm both line quantity and the wallet-provided shipping details landed on the order**.

End state: one order per store, `processing`/`completed`, paid via the WooPayments express/PRB path, quantity 2 and $41.00 total intact, transaction recorded.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
