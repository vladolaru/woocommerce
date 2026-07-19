# SC-14 — LPM wave-1 checkout · HYBRID (D + A)

Native must preserve the WooPayments extension's first wave of local payment methods: `sepa_debit`, `ideal`, `bancontact`, `klarna`, `affirm`, and `afterpay_clearpay`.

## Fixtures

- Use the same connected reference and target stores.
- Configure each method with the fixture currency/country published by `tools/woopayments-merge/lpm-checkout-gate.sh --print-plan`.
- Run classic checkout first; Blocks checkout is a follow-up layer once the submit-capable driver is ready.

## Layer D

Run `lpm-checkout-gate.sh` for the full wave-1 method list. The direct Playwright driver must submit real orders; missing browser or order evidence leaves the flow BLOCKED rather than assumed-pass.

Required deterministic evidence:

- Reference and target browser evidence for each method.
- `gateway_id` and `stripe_payment_method_type` match the method fixture.
- Resulting order uses the method-specific WooPayments gateway, not the base card gateway.

## Layer A

An agent must compare reference and target checkout UX for every method. The method must appear only for its valid currency/country, the checkout label/logo must be discoverable, and redirects or BNPL handoffs must reach the same decisive state as the reference store.

## Verdict

BLOCKED until `lpm-checkout-gate.sh` records submit-capable reference and target evidence for all six wave-1 methods.
