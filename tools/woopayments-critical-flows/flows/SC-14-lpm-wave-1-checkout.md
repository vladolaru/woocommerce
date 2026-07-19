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

PENDING. Supervisor run `20260719T104733Z-70230-partial` ingested context-bound direct-Playwright evidence rather than a parity pass:

- Reference iDEAL, Bancontact, Affirm, and Afterpay/Clearpay each completed checkout with a method-specific gateway, order-received page, successful order/intention state, and matching provider payment-method type. Reference Klarna persisted the method-specific pending order and provider identity before blocking honestly on external customer authorization.
- SEPA Debit blocked before browser work on both connected accounts because `sepa_debit_payments` is `unrequested`; the gate did not fabricate capability readiness.
- Target iDEAL, Bancontact, Klarna, Affirm, and Afterpay/Clearpay exposed and selected the correct split gateway but all failed before order creation. The method Payment Element remained empty because native `woopayments-checkout.js` dereferenced the missing `wc.wcpay.appearance.getCachedAppearance` dependency; checkout returned the generic refresh error and produced no order or provider intent.
- All 12 role/method fixture snapshots restored 18/18 option rows with no mismatches or errors, and both owned temporary products were deleted. The stamped result is `sha256:ca9dc8dd38c9691c9fce2a30801b9c58d80f3724816e40733d8ce8e58da8fe97`.

The reference verdict is BLOCKED, the native target verdict is FAIL — functional, and overall parity is FAIL — functional. Re-run all six methods after fixing the native appearance-script dependency and provisioning SEPA-capable local accounts; a PASS still requires submit-capable evidence from both stores for every method.
