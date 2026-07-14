# SC-09 — Stripe Link save + reuse · A

Stripe Link enrollment and autofill live inside the card Element: the first checkout offers Link registration (email + phone + OTP), later checkouts recognize the email and offer the Link-saved payment details. Functional acceptance: order paid; the Link reuse UI appears on return. UX checkpoints: the enrollment prompt at checkout and the recognized-email autofill flow on the next purchase. This flow is locally BLOCKED: Link registration requires a real card in a real Chrome profile (test cards are rejected); manual/live pass only — the runner keeps this row queued/BLOCKED, never assume-pass.

## Fixtures (both stores)

- Connected test account with Stripe Link enabled alongside the card method.
- A simple in-stock product.
- A live tester in desktop Chrome with a real card, plus a phone/email able to complete Link OTP.

## Layer A — agent-driven browser (manual/live run)

BOTH stores:

1. Add the product; go to checkout; enter the tester's email. **Confirm the Link enrollment prompt renders** in/next to the card Element.
2. Register with Link: provide phone + card; complete the Link OTP.
3. Place order. **Functional:** order paid; the payment records as made through Link.
4. Start a second checkout with the same email. **Confirm Link recognizes the email and offers the saved payment details** (OTP then autofill UI — no re-typed card).
5. Pay with the Link-saved card. **Functional:** second order paid without re-entering card details.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
