# SP-05 — Saved-PM management / set default (My Account) · HYBRID (D + A)

Guards saved-method management: with multiple cards on file, the Payment methods list must expose a working set-default control, the chosen card must become the single default (badge/marker moves, exactly one default in store state), and checkout must preselect it. A native build with no default control, a default marker that does not move, or two tokens flagged default is a regression.

## Fixtures (both stores)

- Connected test account; saved cards enabled.
- A registered customer with TWO saved cards, seeded via SP-01: Visa `4242…4242` (current default) and Mastercard `5555 5555 5555 4444`.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → **Payment methods**.
2. **Both cards are listed; the Visa is marked as default; the Mastercard row exposes a "Make default" (set-default) control.**
3. Click **Make default** on the Mastercard ending `4444`.
4. **A confirmation notice renders and the default marker moves** to the Mastercard row; the Visa row loses it.
5. Reload the page — **the new default persists**.
6. **Functional end-state:** at a subsequent checkout the Mastercard ending `4444` is the preselected saved method.

## Layer D — deterministic state assertion

- Assert the Mastercard token has `is_default` true via `WC_Payment_Tokens::get_customer_tokens()` / `get_customer_default_token()`.
- Assert exactly ONE default token exists for the customer for the WooPayments gateway (the Visa's default flag was cleared).
- Assert both tokens still exist with provider `pm_…` meta intact (set-default must not mutate/detach either).
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SP-05-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
