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

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
