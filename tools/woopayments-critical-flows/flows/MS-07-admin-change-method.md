# MS-07 — Admin change payment method on a subscription · HYBRID (A + D) · **KNOWN-FAIL (B3)**

Acceptance test for B3. Must flip PASS on both stores after N13's B3 fix (full parity — implement the `woocommerce_subscription_payment_meta` family; do NOT drop the supports flag).

## Fixtures (both stores)
- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments (seed via SS-01).
- A second saved card on the customer (so there is a token to select/set).

## Layer A — agent-driven browser (primary; the failure is in admin UI)
BOTH stores:
1. WP Admin → WooCommerce → Subscriptions → open the subscription → Edit.
2. In Billing, confirm WooPayments is selectable as the payment method.
3. **Confirm editable gateway meta fields render** (Stripe customer id / source / token) — the reference renders these via `woocommerce_subscription_payment_meta`.
4. Set/correct the WooPayments token, Save.
5. **Functional:** the subscription persists a valid token; trigger "Process renewal" (or wait for the scheduled renewal) and confirm it charges the chosen token and the renewal order completes.
6. **UX:** the admin can actually configure the instrument — not just select WooPayments with no fields.

## Layer D — deterministic state assertion (overlap)
After the admin save + a driven renewal:
- Assert subscription meta `_payment_method_id` / `_stripe_customer_id` set to the chosen token (not empty).
- Assert the renewal order is `processing`/`completed` with `_intent_id`/`_charge_id`.
- Compare ref vs target end-state.

### Current native behavior (the regression — B3)
WooPayments appears selectable in the admin dropdown (it advertises `subscription_payment_method_change_admin`) but renders NO editable token fields (no `woocommerce_subscription_payment_meta` hook); a save persists no token meta → next renewal finds no token → fails (fail-closed). Reference renders the fields and the flow works.

**Verdict today: FAIL (UX) on target, PASS on reference.** Re-run after B3 full-parity fix.
