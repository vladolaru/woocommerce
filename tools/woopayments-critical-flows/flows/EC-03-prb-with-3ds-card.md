# EC-03 — PRB with 3DS card · AGENT (A)

Guards the regression where the native gateway cannot complete SCA inside the express Payment Request flow: the flow fails if the 3DS challenge never appears after wallet authorization, cannot be completed, or the order is not paid after the challenge. Locally BLOCKED: Payment Request/Apple Pay/Google Pay sheets need a real browser wallet + real card (a wallet-enrolled card that triggers 3DS); manual/live pass only — the runner keeps this row queued/BLOCKED, never assume-pass. The implementor's a4aq checkout browser gate (`tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs`) already captures express-surface structural evidence — corroboration for button presence, not sheet/3DS completion.

## Fixtures (both stores)

- Connected test account with express checkout enabled (product + cart + checkout locations).
- Simple physical product "PRB Tee" at $18.00; shipping zone with one $5.00 flat rate.
- Browser wallet provisioned with a real card whose issuer enforces 3DS/SCA (e.g. an EU-issued card in Google Pay/Apple Pay).
- Test mode on: the real card only unlocks the wallet sheet; charges stay simulated — no live money moves.

## Layer A — agent-driven browser

BOTH stores:

1. In the wallet-provisioned browser, open the "PRB Tee" product page and click the PRB.
2. Authorize the sheet with the 3DS-enrolled wallet card.
3. **Confirm the 3DS challenge surfaces after wallet authorization** (issuer modal/redirect inside or immediately after the PRB flow) instead of a silent failure.
4. Complete the challenge; separately, cancel the challenge once and **confirm a clear payment-failed error is shown and no order is paid**.
5. Retry and complete: **confirm order-received shows the paid total** after successful authentication.
6. In admin, open the order and **confirm it is paid once — a single charge, no duplicate from the cancelled attempt**.

End state: one order per store, `processing`/`completed`, paid via PRB with SCA satisfied; the cancelled attempt leaves no paid order and no charge.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
