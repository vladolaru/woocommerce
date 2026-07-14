# SP-03 — Add payment method: declined card (`4000000000000002`) · HYBRID (D + A)

Guards the rejection path of add-payment-method. Card `4000000000000002` is always declined at setup: the save must be rejected, the shopper must see the decline error (e.g. "Your card was declined."), and NO token may persist. A native build that shows a generic/blank failure, or worse saves a token for a declined instrument, is a regression.

## Fixtures (both stores)

- Connected test account; saved cards enabled.
- A registered customer (same credentials on both stores); record their saved-token count before the attempt (ideally zero).

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Payment methods → **Add payment method**.
2. Enter `4000 0000 0000 0002`, expiry `12/34`, CVC `123`; submit.
3. **A decline error renders on the form** ("Your card was declined." or equivalent card-declined copy — not a generic "something went wrong").
4. **The shopper stays on the add-payment-method form** (can correct and retry) and the Payment methods list gains NO new entry.
5. **Functional end-state:** the save was rejected; the customer's saved-methods list is unchanged.

## Layer D — deterministic state assertion

- Assert the customer's `WC_Payment_Tokens::get_customer_tokens()` count is UNCHANGED from the pre-attempt snapshot.
- Assert no token with last-4 `0002` exists for the customer.
- Assert no order/charge artifact was created by the attempt.
- Assert debug log clean (a handled decline must not log fatals/uncaught exceptions).
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SP-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
