# SC-07 — Provider amount-limit handling · HYBRID (D + A)

Payment maxima vary by payment method, card network, currency, account region, and operation. This flow verifies a specific provider-owned rejecting boundary only when that contract and fixture are established before execution. A rejected checkout must leave no paid order or captured charge and must surface safe, actionable feedback at the WooPayments method. A provider-supported control checkout must still complete, proving the rejection is limit-specific rather than general gateway failure. The flow must report `BLOCKED` instead of inferring a universal threshold when no reliable rejecting fixture is available.

## Fixtures (both stores)

- Connected test accounts in equivalent test mode with the same payment method enabled; record each account's region and relevant capabilities.
- A documented provider test combination per store that names the payment method, network when applicable, currency, account region, operation, exact rejecting amount, expected provider error code/semantic, and expected provider-object topology.
- A boundary product and a provider-supported control product per store. Use matching amounts only when both accounts share the documented boundary; otherwise record the owner-specific amounts.
- Provider test payment details appropriate to that exact contract.

## Layer A — agent-driven browser

BOTH stores (Classic; repeat on Blocks if both surfaces are active):

1. Record the exact provider-owned rejecting contract, expected rejection signal/object topology, and each account's applicability before adding its boundary product to the cart. If the contract lacks a stable provider rejection signal or does not apply to that account, stop `BLOCKED` without submitting payment.
2. Enter the exact rejecting payment fixture and confirm the WooPayments method is otherwise ready for submission.
3. Attempt Place order exactly once. **Confirm checkout is blocked, the shopper stays on checkout, no order reaches a paid state, and an error below/at the WooPayments method** states that the amount exceeds the applicable maximum and gives the shopper a safe next action.
4. Control: empty the cart, add the supported-amount product, and pay with the matching supported fixture. **Functional:** the control order completes normally.

## Layer D — deterministic state assertion

- Provider-rejected attempt: assert the exact documented provider error code/semantic and object topology for that store, bound to the exact attempted amount. Rule out succeeded, authorized/`requires_capture`, captured, or otherwise money-moving provider state; any local failed order or intent must bind to that same rejected attempt.
- Control order: paid, `_intent_id`/`_charge_id` present, and amount/currency match its cart plus applicable costs.
- Compare ref vs target end-state.

The shopper-facing safe error is a separate projection of the bound provider rejection; a matching local message without the expected provider signal cannot pass this flow.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-07-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## 2026-07-15 Layer A result

- Reference and native both fail the functional contract on classic and Blocks checkout. Each $1,000,000 Visa 4242 submission reached order received and persisted as a paid `processing` order backed by a succeeded, fully captured provider charge for 100,000,000 cents. No amount-too-high error rendered, and the shopper did not remain on checkout.
- The $100 controls passed on all four store/surface combinations. Each reached order received and reconciled to a paid order plus succeeded/captured intent and charge, isolating the failure to the missing amount ceiling rather than general checkout health.
- All eight browser confirmations were inspected at original resolution. Fixture, ordered browser-journey, order/provider, debug-window, screenshot, secret, and provenance checks pass. Target diagnostics contain only the exact allowlisted WooCommerce placeholder-image 404 pairs.
- Runner ingest recorded 0 PASS, 2 FAIL, 0 BLOCKED, and 0 queued in `tools/woopayments-critical-flows/evidence/runs/20260715T103633Z-75383-partial/`. Both rollup rows bind the accepted result as `sha256:ebac518f3ca22063fcf966489834289c08af2c7e8cd869600372d77f9c32ef04`.
- This is a shared reference/native contract failure, not a native parity regression. The matrix remains `PENDING`; Layer D is still unwired, and Layer A failed independently.

## 2026-07-21 contract correction

- The accepted `$1,000,000` Visa USD successes and historical `FAIL` verdicts remain unchanged. They accurately record evaluation against the former written oracle.
- The universal `$1M` rejection premise is stale. The retained Visa result is compatible with the current card-specific provider contract and does not establish a native or shared payment defect.
- Future SC-07 evidence must bind an exact method/network/currency/account/operation boundary, provider rejection signal, and non-money-moving object state for each account. It must not use the former Visa USD fixture or a guessed universal numeric ceiling.
- The row remains `PENDING`: Layer D is unwired, and no corrected provider-specific rejecting journey has been executed.
