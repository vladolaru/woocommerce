# SS-10 — SEPA-token renewal after cutover · HYBRID (D + A)

This target-only flow proves that a WooPayments extension-created SEPA token survives native cutover and can pay a subscription renewal through `woocommerce_payments_sepa_debit`. It does not claim cross-store parity: WooPayments 10.8 does not register an equivalent scheduled-renewal callback for the split SEPA gateway.

**Agent oracle mode: target-only.** Do not drive the reference store for this flow. A successful target run leaves cross-store parity `BLOCKED`/not comparable.

## Fixtures

- The reference store remains unchanged; it is not an oracle for split-SEPA scheduled renewal.
- Target starts plugin-active with an eligible connected test account and SEPA Debit enabled.
- The source token uses the `provider_setup_intent` flow in `token-continuity-gate.sh`: create a reusable SEPA PaymentMethod through local WooPayments/WPCOM, persist it through the plugin token service as `wcpay_sepa`, and verify the PaymentMethod is listed for the WCPay customer before cutover.
- A subscription renewal fixture is tied to that token before native cutover.

## Layer D

Run `token-continuity-gate.sh --source-flow provider-setup-intent` with the saved customer ID and renewal product fixture. Do not run `subscriptions-renewal-gate.sh --payment-family sepa` as a cross-store SS-10 comparison; no equivalent WooPayments 10.8 reference callback exists. The target gate is fail-closed: missing reusable source-token evidence, native-loader evidence, browser visibility, or renewal evidence leaves the target result BLOCKED/FAIL.

Required deterministic evidence:

- The native state driver loads the saved row through `WC_Payment_Tokens::get()` with the expected customer, gateway, token type, native SEPA class, and provider PaymentMethod ID. WooCommerce does not expose a public `wp wc payment_token list` command.
- Renewal request uses `woocommerce_payments_sepa_debit` and `payment_method_types=["sepa_debit"]`.
- Renewal order records WooPayments intent and charge meta. For SEPA, async processing evidence is acceptable only when the renewal intent status is `processing` or `succeeded`, the renewal/subscription statuses match the SEPA async policy, and token/customer evidence is present.

## Layer A

Playwright must verify My Account payment methods on the target after cutover. The SEPA token row must be visible and understandable to the shopper, not just present in the database.

## Verdict

The target result can PASS when `token-continuity-gate.sh` records provider setup-intent source-token persistence, native loading, My Account rendering, and SEPA renewal evidence. The cross-store parity verdict remains BLOCKED/not comparable and requires an explicit target-only or manual-testing disposition. Account-country, business-type, and capability restrictions are environment prerequisites, not Core failures.
