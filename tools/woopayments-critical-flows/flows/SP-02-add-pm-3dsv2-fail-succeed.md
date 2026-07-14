# SP-02 — Add payment method: 3DSv2 challenge fail→succeed (`4000000000003220`) · AGENT

Guards the 3DS wiring on the add-payment-method SetupIntent path. Card `4000000000003220` always forces a 3DSv2 challenge: failing the challenge must surface an authentication-failure error and save nothing; completing it must save the card. A native build whose add-PM form never mounts the 3DS modal, swallows the auth failure, or saves a token despite a failed challenge is a regression. This flow is judgment-heavy modal UI, so it is agent-layer only.

## Fixtures (both stores)

- Connected test account; saved cards enabled.
- A registered customer (same credentials on both stores) with zero saved payment methods.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Payment methods → **Add payment method**.
2. Enter `4000 0000 0000 3220`, expiry `12/34`, CVC `123`; submit.
3. **The 3DSv2 challenge modal appears** (Stripe test challenge frame with Complete/Fail choices).
4. Choose **Fail authentication**. **An authentication-failure error notice renders** (e.g. "We are unable to authenticate your payment method…") and the card is NOT added — Payment methods list stays empty.
5. Repeat steps 1–3, this time choose **Complete authentication**.
6. **Success notice renders** and **the card ending `3220` is listed** in Payment methods.
7. **Functional end-state:** after the fail→succeed sequence, exactly one saved card (ending `3220`) exists for the customer; the failed attempt left no phantom entry.

### Regression guard

The flow fails when the 3DS challenge never mounts (form spins or submits without a challenge), when a failed challenge yields no visible auth-failure message, when a token appears in Payment methods despite the failed challenge, or when the success path completes the challenge but does not list the card. Judge each store on its own sequence, then compare: reference behavior is the oracle for what feedback the shopper must receive at each step.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
