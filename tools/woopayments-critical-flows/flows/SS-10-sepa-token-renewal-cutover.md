# SS-10 — SEPA-token renewal after cutover · HYBRID (D + A)

This flow proves that a WooPayments extension-created SEPA token survives native cutover and can pay a subscription renewal through `woocommerce_payments_sepa_debit`.

## Fixtures

- Use the reference store to confirm the extension's saved-token renewal behavior for the same customer/subscription shape.
- Target starts plugin-active with a connected account and SEPA Debit enabled.
- A customer saves a SEPA token of type `wcpay_sepa`.
- A subscription is tied to that token before native cutover.

## Layer D

Run `token-continuity-gate.sh` with the saved customer and subscription IDs, then run `subscriptions-renewal-gate.sh` for the same renewal shape. These gates are fail-closed: missing browser token-save evidence, missing token-list visibility, or missing renewal IDs leaves the flow BLOCKED/FAIL.

Required deterministic evidence:

- Native `wc payment_token list` still includes the saved SEPA token.
- Renewal request uses `woocommerce_payments_sepa_debit` and `payment_method_types=["sepa_debit"]`.
- Renewal order reaches `processing` or `completed` with WooPayments intent and charge meta.

## Layer A

An agent must verify My Account payment methods on the target after cutover. The SEPA token row must be visible and understandable to the shopper, not just present in the database.

## Verdict

BLOCKED until `token-continuity-gate.sh` records plugin-side token save, native-side token rendering, and SEPA renewal evidence.
