# SS-08 — Free-trial subscription ($0 initial, charge at trial end) · HYBRID (D + A)

Guards the free-trial purchase path, which exercises the setup-intent branch: a $15.00/month subscription with a 7-day free trial must check out with a **$0.00 initial total**, still collect and save the card (SetupIntent — no charge), set next payment to trial end, and take the FIRST real charge at that renewal. A native build that charges at signup, fails to save a token off a $0 order (leaving the trial-end renewal unbillable), or mis-sets the trial schedule is a regression.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; saved cards enabled.
- Subscription product: **$15.00 / month with a 7-day free trial**, no sign-up fee.
- A registered customer with no saved payment methods.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; add the trial product to the cart; go to checkout.
2. **Trial messaging renders** ("$15.00 / month with 7 days free trial" and/or "First renewal: \<date\>") and **the order total is $0.00**.
3. **Card fields still render and are required** despite the $0 total; enter `4242 4242 4242 4242`, `12/34`, `123` — **mandate copy renders** (future-payments authorization).
4. Place order → **order-received page shows a $0.00 order**.
5. **Functional end-state:** My Account → Subscriptions shows the subscription Active with next payment ≈ 7 days out; My Account → Payment methods lists the card ending `4242` (saved without a charge).

## Layer D — deterministic state assertion

- Assert the parent order total is **$0.00** and paid/completed WITHOUT a `_charge_id` (setup-intent path — no money moved at signup).
- Assert a reusable WooPayments token (provider `pm_…` meta) was saved and attached to the subscription's payment meta.
- Assert subscription `trial_end` and `next_payment` ≈ purchase date + 7 days, recurring total $15.00.
- Drive the trial-end renewal (`WC_Subscriptions_Manager::process_renewal`); assert the renewal order totals **$15.00**, is `processing`/`completed`, and carries `_intent_id`/`_charge_id` charging the saved token.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-08-*.sh.

## Latest runner evidence

Layer A is runner-verified `PASS` on both stores in partial run
`20260718T131104Z-54077-partial`. Each store rendered the $15/month,
seven-day-free-trial contract, card fields, future-payment mandate, and $0 signup
total. One ordinary trusted checkout submission per store created a zero-total
processing order, an active subscription with next payment at the seven-day trial
end, one pending renewal action, and a default saved WooPayments Visa 4242 token.

Reference ended with order/subscription `2173`/`2174`, token `104`, customer
`cus_UuMT3QJ1oLrapV`, PaymentMethod `pm_1TuXkFJCMHkg3Y1tlM8967w3`, and succeeded
SetupIntent `seti_1TuXkHJCMHkg3Y1tZds2AwEp`. Target ended with
order/subscription `1587`/`1588`, token `70`, customer
`cus_UuMWFZ2v0Z2Apg`, PaymentMethod `pm_1TuXngBzWlxcwgpP0mdvZnvb`, and succeeded
SetupIntent `seti_1TuXniBzWlxcwgpP91MZTqnm`. Independent provider reads bind
each SetupIntent to the exact customer, PaymentMethod, local token, order, and
subscription. Neither store's order recorded a PaymentIntent or charge ID. The
captured provider charge projections are empty, and both connected-account charge
windows are unchanged.

The reference browser classified delayed Blocks totals before they finished
rendering. A hash-bound OCR artifact from that same mutation's checkout screenshot
proves the monthly/trial wording, $0 due today, and first-payment date; only that
exact classifier signature is accepted. The target's broken product placeholder
and seven narrowly classified placeholder 404s are a visual divergence. Functional
checkout, order, account, schedule, token, and provider outcomes match.

Accepted result digest:
`sha256:ff19808a448cc5badba562ebfc83d5df16108db4cfb691ddf84cb8efecd75ed9`.
The matrix row remains `PENDING` until a separate deterministic exerciser drives
and verifies the first renewal through Layer D.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
