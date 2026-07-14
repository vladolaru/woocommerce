# ON-01 — Onboard via WC Settings (NOX), test→live · AGENT

Guards the merchant's primary onboarding journey: the incentive → NOX → KYC → task-complete progression started from WooCommerce → Settings → Payments. Native must preserve the WooPayments onboarding entry on that screen, the test-mode onboarding path (sandbox account, no KYC), and the Jetpack/WordPress.com connection dependency the flow rides on. KYC honesty: local test accounts cannot complete real KYC, so the test→live half requires a live-account pass; the README marks this row PENDING (KYC) and the runner keeps it BLOCKED until a manual/live pass is recorded — never assume-pass.

## Fixtures (both stores)

- WooPayments reset to a not-onboarded state (WCPay Dev Tools: disconnect/clear account cache), aligned across stores per HARNESS.md store-config discipline.
- Local Transact platform reachable via the dev-tools proxy; Jetpack connection available or bypassed identically on both stores.
- Admin user with `manage_woocommerce`.
- A simple in-stock product for the post-onboarding checkout check.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Settings → Payments. **Confirm the WooPayments onboarding entry renders** — the provider row with its Enable/Activate-payments call-to-action (and the incentive surface, when eligible).
2. Start onboarding. **Confirm the Jetpack/WordPress.com connection step is presented or already satisfied** — the flow must not dead-end when the connection is missing.
3. Take the test-mode onboarding path (test/sandbox account). **Confirm the NOX flow completes without KYC** and returns to Settings → Payments.
4. **Confirm the onboarding task reads complete and payment methods are configured** (card enabled; the methods list is populated).
5. Add the product to the cart and open checkout. **Confirm the WooPayments card method renders with the test-mode badge/notice** — the onboarded account is truthfully in test mode.
6. Attempt the test→live switch ("Activate payments" / "Switch to live"). **Confirm the live-KYC handoff surface renders**, then STOP — record this half BLOCKED: real KYC cannot complete on a local test account.

End state: a test-mode account is onboarded and configured on both stores; the live half stays BLOCKED pending a manual/live KYC pass. A local run of this flow is at most partial evidence — it may never flip the row to PASS on its own.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
