# SC-08 — WooPay signup + checkout · A

WooPay is the hosted express-checkout: first purchase creates a WooPay account (email + SMS OTP), later purchases reuse the saved PM and address via the WooPay redirect. Functional acceptance: account created, order paid, PM + address reusable on the next checkout. UX checkpoints: the WooPay button renders, the OTP prompt works, and the redirect/return round-trip lands the shopper back on order-received. This flow is locally BLOCKED: WooPay signup/login requires a real SMS OTP the local Transact environment cannot deliver; manual/live pass only — the runner keeps this row queued/BLOCKED, never assume-pass.

## Fixtures (both stores)

- Connected test account with WooPay enabled; express-checkout locations include product, cart, and checkout.
- A simple in-stock product.
- A live tester with an email new to WooPay and a phone that can receive the WooPay SMS OTP.

## Layer A — agent-driven browser (manual/live run)

BOTH stores:

1. Add the product; go to checkout. **Confirm the WooPay button renders** in the express-checkout area (also spot-check product and cart placements).
2. First run (signup): enter the new email; opt in to WooPay; complete checkout entering card + address and providing the phone number.
3. **Confirm the SMS OTP prompt renders**; enter the received code.
4. **Functional:** order paid; a WooPay account now exists for that email.
5. Second run (reuse): start a new order with the same email. **Confirm the redirect to the WooPay-hosted checkout with the saved PM and address prefilled/reusable**; confirm the purchase.
6. **Functional:** second order paid without re-entering card or address; the shopper is returned to the store's order-received page.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
