# MS-04 — Promote a subscription with a coupon · DETERMINISTIC

Guards coupon-discounted subscription billing. The regression to catch: a recurring-discount coupon applies at checkout but the subscription's schedule/totals don't carry it under native, so WooPayments charges the full undiscounted amount on renewal — a silent overcharge. Acceptance is entirely about stored totals and the amount actually charged on a driven renewal, so No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- Subscription product at `$10/month` (MS-01 fixture shape).
- Coupon `ms04-recurring-50`: WC Subscriptions type "Recurring Product Discount", 50%.
- Seed a subscription purchase by a known customer WITH the coupon applied (SS-01 exerciser + coupon).

## Layer D — deterministic state assertion

After the seeded coupon purchase, on BOTH stores:

- Assert the subscription carries the coupon: `ms04-recurring-50` present in the subscription's used coupons, and the subscription (recurring) total is `$5.00`, not `$10.00` — the schedule reflects the coupon.
- Assert the parent order total is `$5.00`, status `processing`/`completed`, with `_intent_id`/`_charge_id`, and the provider-charged amount equals the discounted total.
- Drive a renewal (`WC_Subscriptions_Manager::process_renewal`); assert the renewal order total is `$5.00`, status `processing`/`completed`, with fresh `_intent_id`/`_charge_id` — the recurring discount survives into renewals and the charge matches it.
- Assert the coupon line item is present on the renewal order (the discount is itemized, not just a mutated total).
- Compare ref vs target end-state: same subscription total, same parent and renewal charge amounts.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-04-*.sh.
