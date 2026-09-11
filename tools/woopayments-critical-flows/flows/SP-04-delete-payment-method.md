# SP-04 — Delete payment method (My Account) · HYBRID (D + A)

Guards saved-card removal: the Payment methods list must expose a working Delete affordance per row, deleting must remove the token from the store (and detach it from the WCPay customer), confirm with a notice, and update the list in place. A native build where Delete is missing, deletes the wrong row, or leaves the token queryable is a regression.

## Fixtures (both stores)

- Connected test account; saved cards enabled.
- A registered customer with TWO saved cards, seeded via SP-01: `4242…4242` (Visa) and `5555 5555 5555 4444` (Mastercard) — so deletion of one can be verified against survival of the other.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → **Payment methods**.
2. **Both cards are listed, each row with a visible Delete affordance.**
3. Click **Delete** on the Mastercard ending `4444` (confirm if prompted).
4. **A deletion notice renders** (e.g. "Payment method deleted.").
5. **The list updates:** the `4444` row is gone; the Visa ending `4242` row remains intact.
6. **Functional end-state:** at a subsequent checkout only the surviving Visa is offered as a saved option.

## Layer D — deterministic state assertion

- Assert the deleted token no longer appears in `WC_Payment_Tokens::get_customer_tokens()` for the customer.
- Assert the surviving token (last-4 `4242`) is still present with its provider `pm_…` meta intact.
- Assert the deletion detached the PaymentMethod from the WCPay customer (provider-side detach recorded / token meta gone), not merely hidden in the UI.
- Assert debug log clean for the run.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SP-04-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
