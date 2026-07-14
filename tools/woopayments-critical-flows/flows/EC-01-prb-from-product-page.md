# EC-01 — PRB from product page · AGENT (A)

Guards the regression where the native gateway drops the express Payment Request surface from single-product pages: the flow fails if the PRB does not render, the wallet sheet cannot complete, or the resulting order is not paid via PRB. Locally BLOCKED: Payment Request/Apple Pay/Google Pay sheets need a real browser wallet + real card; manual/live pass only — the runner keeps this row queued/BLOCKED, never assume-pass. The implementor's a4aq checkout browser gate (`tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs`, `blocks-checkout-express` route) already captures express-surface structural evidence — corroboration for button presence, not sheet completion.

## Fixtures (both stores)

- Connected test account with express checkout (Payment Request / Apple Pay / Google Pay) enabled in WooPayments settings, product page location checked.
- Simple physical product "PRB Tee" at $18.00; shipping zone covering the wallet card's billing country with one $5.00 flat rate.
- Browser with a provisioned wallet (Chrome profile with a real card in Google Pay, or Safari with Apple Pay) over HTTPS or localhost.
- Test mode on: the real card only unlocks the wallet sheet; charges stay simulated — no live money moves.

## Layer A — agent-driven browser

BOTH stores:

1. In the wallet-provisioned browser, open the "PRB Tee" product page as a guest shopper.
2. **Confirm the PRB ("Pay now" / Google Pay / Apple Pay button) renders on the product page** near add-to-cart, branded for the active wallet.
3. Click the PRB and **confirm the wallet sheet opens with the correct line item, shipping option, and total ($23.00)** — no prior add-to-cart required.
4. Authorize with the real wallet card and complete the sheet.
5. **Confirm the shopper lands on order-received with the paid total** and no error notice.
6. In admin, open the order and **confirm the shipping/contact details captured from the wallet sheet landed on the order**.

End state: one order per store, `processing`/`completed`, paid via the WooPayments express/PRB path with correct amount, and the transaction visible in Payments → Transactions.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
