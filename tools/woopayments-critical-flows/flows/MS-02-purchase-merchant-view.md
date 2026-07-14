# MS-02 — Subscription purchase, merchant view · DETERMINISTIC

Guards the merchant-side record of a shopper's subscription purchase. The regression to catch: the purchase completes but the admin record is wrong or missing under native — no `shop_subscription` row in WooCommerce → Subscriptions, a parent order without WooPayments intent/charge metadata, or a subscription the customer's My Account data source cannot return. Functional acceptance is the stored record being visible in admin AND attributable to the customer, so No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One completed subscription purchase seeded via the SS-01 exerciser (known customer, `$10/month` product, WooPayments card token saved).

## Layer D — deterministic state assertion

After the seeded purchase, on BOTH stores:

- Assert exactly one `shop_subscription` exists for the seeded customer with status `active`.
- Assert its parent order is `processing`/`completed` and carries `_intent_id` and `_charge_id` meta (the admin record binds to a real provider charge).
- Assert the subscription's payment method is `woocommerce_payments` and a saved-token relationship is persisted (the record is billable, not just visible).
- Assert the subscription schedule is populated: `_schedule_next_payment` is set and later than the parent order date.
- Assert `wcs_get_users_subscriptions( $customer_id )` returns the subscription — this is the exact data source behind My Account → Subscriptions, so the shopper-visible listing is covered without a browser.
- Assert the admin list query (`wcs_get_subscriptions` filtered by customer) also returns it — the data source behind WooCommerce → Subscriptions.
- Compare ref vs target end-state: same statuses, same schedule shape, both orders carrying provider metadata.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-02-*.sh.
