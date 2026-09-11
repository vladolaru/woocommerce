# SS-07 — Subscription coupons: sign-up / one-off / recurring · DETERMINISTIC

Guards coupon math across the subscription lifecycle: each coupon type must discount the right component at the right cycle. Against a $10.00/month product with a $5.00 sign-up fee — a 50% **sign-up-fee discount** yields an initial total of $12.50 ($10.00 + $2.50) and full $10.00 renewals; a 20% **recurring product discount** yields $13.00 initially ($8.00 + $5.00) and $8.00 on EVERY renewal with the coupon line carried onto each renewal order; a 10% **one-off (standard product) discount** yields $14.00 initially ($9.00 + $5.00) and full $10.00 renewals with no discount line. A native build that drops the recurring discount on renewal, or leaks the one-off discount into renewals, moves the wrong amount of money. No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; a customer with a saved WooPayments card (seed via SP-01/SS-01).
- Subscription product: **$10.00 / month + $5.00 sign-up fee**, no trial.
- Three coupons: `SIGNUP50` (Sign Up Fee % Discount, 50%), `RECUR20` (Recurring Product % Discount, 20%), `ONEOFF10` (standard Percentage discount, 10%).

## Layer D — deterministic state assertion

For EACH coupon, purchase the subscription with the coupon applied (checkout exerciser on the saved card), then drive one renewal (`WC_Subscriptions_Manager::process_renewal`):

- `SIGNUP50`: initial order total **$12.50**, paid with `_intent_id`/`_charge_id`; renewal order total **$10.00**, paid, NO discount line.
- `RECUR20`: initial order total **$13.00**, paid; renewal order total **$8.00**, paid, with the `RECUR20` coupon/discount line ($2.00) present on the renewal order; assert a second driven renewal also totals $8.00.
- `ONEOFF10`: initial order total **$14.00**, paid; renewal order total **$10.00**, paid, NO discount line.
- For every case, assert the subscription's stored recurring total matches the expected renewal amount (discounted only for `RECUR20`) and the charge amount equals the order total exactly.
- Compare ref vs target end-state per coupon.

### Regression guard

The flow fails on any wrong total at any cycle: a recurring discount that evaporates at renewal (renewal charged $10.00 under `RECUR20`), a one-off or sign-up discount that leaks into renewals (renewal under $10.00 for `ONEOFF10`/`SIGNUP50`), or a charge amount that differs from the renewal order total. Run the three coupon cases against isolated fixtures (fresh subscription per coupon) so discount lines cannot bleed between cases.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-07-*.sh.
