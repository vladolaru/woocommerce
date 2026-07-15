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

## Latest runner evidence (2026-07-15)

- Fresh context `fd2f64c2-fdce-4bb3-9645-355b614a1c60` binds committed source `459d3f895e73ea92077f1d16ccd098e86e39d388`, reference subscription `1283`, target subscription `874`, and the expected plugin/native runtime owners.
- Reference `PASS`: admin-created orders `1978` and `1979` are processing with succeeded, paid, fully captured USD 20 and USD 40 intent/charge pairs. The first payment saved exactly one reusable Visa 4242 token; the second completed the explicit 3DS challenge with unsaved Visa 3155 and left the token count at one.
- Target `FAIL — functional`: both Pay affordances and the native Payment Element render for orders `1491` and `1492`, but an ordinary enabled Pay for order click with valid Visa 4242 emits 15 external Stripe/hCaptcha POSTs and no same-origin payment POST or navigation for 120 seconds. Both orders remain pending with no intent, charge, paid timestamp, token, customer mapping, provider object, charge delta, or marker-bounded debug-log byte. The dependent unsaved 3DS payment is unreachable.
- Runner ingest recorded 1 PASS, 1 FAIL, 0 BLOCKED, and 0 queued in `tools/woopayments-critical-flows/evidence/runs/20260715T082824Z-59646-partial/`. Both rollup rows bind the exact accepted result bytes as `sha256:383134392902fa7acd75364cc58bff37330ff659b8e83347b5e99fc4711f66d5`.
- Layer D remains unwired, and the target Layer-A failure is independently decisive. The maintained README and matrix therefore remain `PENDING`.
