# MS-06 — Renew manually (admin) · HYBRID (A + D)

Guards the merchant's ability to trigger an off-schedule renewal payment from the subscription edit screen. The regression to catch: under native the "Process renewal" order action disappears, runs without charging through the saved WooPayments method, creates duplicate renewal state, or alters the Subscriptions-owned schedule differently from the reference. For this flow's future-dated fixture, WooCommerce Subscriptions intentionally preserves the existing next-payment date after the immediate charge.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments with a saved token (seed via SS-01). Record the current clock, effective Subscriptions activation threshold, and next-payment timestamp; the next payment must be beyond that effective threshold or the flow stops `BLOCKED`.
- Pre-action inventories of related renewal-order IDs, provider PaymentIntent/Charge IDs relevant to the subscription/customer, and provider PaymentMethod IDs.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Subscriptions → open the seeded subscription (Edit).
2. Open the Subscription actions (order actions) dropdown. **"Process renewal" is offered.**
3. Select "Process renewal" and click Update. **No error notice; the page reloads on the subscription.**
4. **A new renewal order appears in the Related Orders box, marked as Renewal Order, with a paid status (Processing/Completed).**
5. Open that renewal order. **An order note records a successful WooPayments charge (amount + intent reference), not a pending/failed payment.**
6. Back on the subscription: **status remains Active and Billing Schedule preserves the exact future next-payment date recorded before the action.**

End state: one paid renewal order related to the subscription; subscription active with its previously scheduled future anniversary unchanged.

## Layer D — deterministic state assertion

After the admin-triggered renewal:

- Before invoking the action, assert the recorded next-payment timestamp is beyond the effective activation threshold at the recorded current clock; otherwise stop `BLOCKED`.
- Compare pre/post identities and assert exactly one new related renewal order, exactly one matching succeeded PaymentIntent and captured Charge, and no other payment attempt attributable to this flow. Bind the order, intent, charge, amount, currency, customer, and saved payment method.
- Assert the charge used the subscription's saved token and the provider PaymentMethod inventory is unchanged.
- Assert subscription status `active` and `_schedule_next_payment` exactly equals its recorded pre-renewal future value.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-06-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Scenario boundary

This flow covers admin **Process renewal** on an active subscription whose recorded next payment is proven beyond the effective, filterable Subscriptions activation threshold at the captured current clock. It does not cover recovery of a missing or past-due scheduled renewal, and it does not cover the separately marked customer early-renewal path that advances a future schedule. Those scenarios need distinct fixtures and de-duplication safeguards.

## 2026-07-21 contract correction

- Accepted run `20260718T163605Z-37081-partial` and its historical shared `FAIL — functional` verdict remain unchanged; the runner evaluated the former future-date advancement assertion.
- That run proved one paid Processing USD 20 renewal and one succeeded/captured provider charge through the existing saved Visa per store, with no new PaymentMethod and the future next-payment dates unchanged.
- The unchanged dates match the Subscriptions-owned admin action. Advancing a future schedule belongs to the distinct marked early-renewal contract, not this fixture.
- The accepted artifacts do not record the exact WooCommerce Subscriptions runtime version; ownership was source-verified across inspected 8.7.1–8.8.1 tags, and provider comparison covered a fixed 100-charge window rather than all history.
- The row remains `PENDING` because Layer D is unwired. A future run must prove the effective threshold and exact one-order/one-payment deltas; the accepted evidence does not establish eventual scheduled execution, past-due recovery, customer early-renewal parity, or all provider history.
