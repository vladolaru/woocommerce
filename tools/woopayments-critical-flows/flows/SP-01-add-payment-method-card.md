# SP-01 — Add payment method: regular card (My Account) · HYBRID (D + A)

Guards the shopper's out-of-checkout card-save path: My Account → Payment methods → Add payment method must create a reusable WooPayments token through a SetupIntent (no charge, no order), surface a success notice, and list the card so it is selectable at the next checkout. A native build that saves the token silently, lists nothing, or charges instead of setting up is a regression.

## Fixtures (both stores)

- Connected test account; saved cards enabled.
- A registered customer (same credentials on both stores) with ZERO saved payment methods.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Payment methods → **Add payment method**.
2. **Card fields render** (number/expiry/CVC) on the add-payment-method form.
3. Enter `4242 4242 4242 4242`, expiry `12/34`, CVC `123`; submit.
4. **Success notice renders** (e.g. "Payment method successfully added.").
5. **The card is listed** in Payment methods with brand + last-4 (`Visa ending in 4242`) and expiry.
6. **Functional end-state:** open the checkout for any product and confirm the saved card is offered as a selectable option (usable, not just listed).

## Layer D — deterministic state assertion

- Assert `WC_Payment_Tokens::get_customer_tokens()` for the customer contains exactly ONE WooPayments token: last-4 `4242`, a provider `pm_…` PaymentMethod ID in token meta.
- Assert the token is bound to the store's WCPay customer mapping for that user.
- Assert NO order and NO charge were created by the add-PM action (setup, not payment).
- Assert debug log clean for the run.
- Compare ref vs target end-state.

Deterministic exerciser: `flows/SP-01-add-payment-method-card.sh`, backed by `flows/class-woopaymentscriticalflowssp01driver.php`. The driver creates a fresh customer and connected-account Visa fixture, executes the active runtime's SetupIntent and token services, and returns fail-closed provider/local state for the shared log assertion and rollup.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
