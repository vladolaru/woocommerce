# SS-03 — Change subscription payment method to a saved card · HYBRID (A + D)

Regression test for B2 (saved-card selection surface, subscriber side). Both stores must offer the saved card in the change-payment flow, persist the chosen token on the subscription, and renew against it.

Prior evidence: `evidence/SS-03/` holds Phase-2 ad-hoc observations (target browser confirmed; reference NOT driven; after-save persistence blocked by fixture state; renewal drive failed on both stores for fixture reasons). That narrative is an observation ledger, not runner evidence — a current verdict requires runner-ingested reference+target results for both layers below.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments (seed via SS-01).
- A second saved card on the same customer (so change-payment has a saved token to select).

## Layer A — agent-driven browser (primary; the failure was in the shopper UI)

BOTH stores:

1. My Account → Subscriptions → open the subscription → **Change payment**.
2. **Confirm the saved card is discoverable and selectable** (radio/list entry present, not a forced new-card form).
3. Select the saved card, submit.
4. **UX:** success feedback renders; the subscription's payment method row reflects the saved card.
5. **Functional:** the change persists across a reload of the subscription view.

## Layer D — deterministic state assertion (overlap)

After the browser change + a driven renewal:

- Assert the subscription's token/payment meta points at the CHOSEN saved token (not a newly-created PaymentMethod).
- Drive a renewal (`WC_Subscriptions_Manager::process_renewal` or scheduled action) and assert the renewal order is `processing`/`completed` with `_intent_id`/`_charge_id` present.
- Assert the renewal charged the chosen token's payment method fingerprint (not a new card).
- Compare ref vs target end-state.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
