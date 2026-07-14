# SC-05 — Pay for order (My Account), new card + save, 3DS · HYBRID (D + A)

The order-pay endpoint is a distinct payment surface from checkout (used for pending/admin-created orders and failed-payment retries), with its own form mount and its own save-payment-method path. Functional acceptance: the pending order gets paid; the card is optionally saved as a reusable PM. UX checkpoints: the "Pay" affordance on the order row, the save checkbox on the pay form, and the PM appearing in My Account → Payment methods afterward.

## Fixtures (both stores)

- Connected test account; `saved_cards = yes`; "Credit/Debit card" enabled.
- A logged-in customer with TWO orders in **pending payment** (e.g. admin-created orders assigned to the customer).
- Cards: `4242 4242 4242 4242`; `4000002500003155` for the 3DS variant.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Orders.
2. **Confirm the "Pay" affordance renders** on a pending order row; click it → the order-pay page mounts the WooPayments card fields.
3. **Confirm the save-payment-method checkbox renders**; tick it.
4. Enter `4242 4242 4242 4242`; Pay for order. **Functional:** order-received page renders; the order is paid.
5. My Account → Payment methods: **confirm the saved card now appears in the list**.
6. Repeat on the second pending order with `4000002500003155` (save unchecked): **confirm the 3DS modal renders**, Complete authentication → order paid.

## Layer D — deterministic state assertion

- Both orders `processing`/`completed`; `_intent_id`/`_charge_id` present; amounts match the seeded order totals.
- A WooPayments token row exists for the customer for the step-4 card (saved and reusable); no token added by the step-6 unsaved payment.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-05-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
