# SC-02 — Card checkout, Blocks (new card) · HYBRID (D + A)

Blocks twin of SC-01: the WooPayments payment integration must mount its Payment Element inside the Checkout block — a different integration surface than the classic form and historically the riskier one on native (cf. the B2 Blocks `savedTokenComponent` gap). Functional acceptance: order paid, transaction recorded. UX checkpoints: Payment Element mounts, test-mode badge renders, card errors surface through the Blocks error UI.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled.
- Checkout page using the **Checkout block** (not the shortcode).
- A simple in-stock product with a known price.
- Card: `4242 4242 4242 4242`, any future expiry, any CVC/ZIP.

## Layer A — agent-driven browser

BOTH stores:

1. Add the product to the cart; open the Blocks checkout.
2. **Confirm the WooPayments Payment Element mounts** in the payment step — card fields interactive, no stuck loading placeholder, no mount errors in the console.
3. **Confirm the test-mode badge/notice renders** for the card method.
4. Fill address; attempt Place Order with the card fields empty/incomplete. **Confirm an actionable card error renders** (inline on the Element or in the Blocks checkout error area) and no paid order is created.
5. Enter `4242 4242 4242 4242` with valid expiry/CVC; Place Order.
6. **Functional:** the order-received page renders; the order is paid with the correct total.

## Layer D — deterministic state assertion

- Run one anonymous Cart-Token sequence through `/wc/store/v1/*`, ending at the
  public checkout endpoint with `woocommerce_payments` and the shared
  `wcpay-payment-method` payment-data field.
- Require an initially empty cart, the fixed USD 25 product, one selected free
  shipping rate, one unbroken rotating Cart-Token lineage, no redirects, and a
  successful paid checkout response.
- Load only the response order ID. Require guest `store-api` creation, status
  `processing`/`completed`, the fixed line item and total, and persisted
  transaction, `_intent_id`, and `_charge_id` linkage.
- Observe only those exact Stripe PaymentIntent and Charge objects through
  bounded read-only requests; compare their success, amount/currency, and
  identity linkage across the plugin reference and native target runtimes.
- Bind preflight, HTTP, post-state, parity, log, and final verdict evidence to
  the current private runner context. The runner independently recomputes the
  result and manifest before accepting a rollup row.

Deterministic exerciser: WIRED —
`flows/SC-02-blocks-card-checkout.sh` coordinates the local-only HTTP client,
WP-CLI collector, evidence oracle, and exact stage manifest. It invokes checkout
at most once per store and never finds an order by recency.

Layer D does not prove that the Payment Element mounted, browser card fields
were usable, the test-mode badge rendered, or incomplete-card feedback reached
the Blocks error UI. Those remain Layer A responsibilities.

## Latest runner evidence

Layer A is runner-verified `PASS` on both stores in partial run
`20260718T113300Z-51058-partial`. From an explicitly empty cart, each store
rendered an interactive Payment Element, a distinct Test Mode badge, the Visa
4242 instruction, and an actionable incomplete-card error without creating an
order. One trusted complete submission then created a paid USD 25 Store API
order (`2166` reference, `1580` target) whose status, line item, customer,
WooCommerce transaction ID, PaymentIntent, PaymentMethod, and captured charge
joined exactly.

The target HTTP 400 from `20260715T061833Z-39338-partial` did not reproduce on
the current committed tree. A reference USD 50 quantity-two control caused by
harness persistent-cart contamination was fully reconciled, explicitly
excluded, and replaced with a fresh zero-state customer after adding a
fail-closed empty-cart precondition.

The first one-shot Layer D run, `20260718T221303Z-46945-partial`, was
runner-verified `BLOCKED` on both stores without creating an order. Reference
preflight passed, but its sole shipping rate cost USD 20 and made the fixed cart
USD 45, so the client stopped after `update-customer` and before checkout. The
target stopped at preflight because `pnpm wp-env run` progress output surrounded
the collector JSON and independently blocked log observation. The harness now
uses the explicitly approved target container as a direct WP-CLI transport;
that post-run correction is hermetically covered, but the live flow was not
repeated. Both accepted manifest hashes and the exact outcome are preserved in
the run archive.

The matrix row remains `PENDING` until a new explicitly authorized run starts
with the required free-shipping fixture and earns passing Layer D parity.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
