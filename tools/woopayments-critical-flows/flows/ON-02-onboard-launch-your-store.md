# ON-02 — Onboard via Launch Your Store (NOX) · AGENT

Guards onboarding started from the Launch Your Store / core-profiler task list instead of Settings → Payments: the payments task must hand off into the same native NOX onboarding surface, leave the account ready with methods enabled, and mark the LYS task complete on return. The Jetpack/WordPress.com connection dependency and test-mode onboarding path apply exactly as in ON-01. KYC honesty: local test accounts cannot complete real KYC, so only the test-mode account half is locally exercisable; the README marks this row PENDING (KYC) and the runner keeps it BLOCKED until a manual/live pass is recorded — never assume-pass.

## Fixtures (both stores)

- WooPayments reset to a not-onboarded state (WCPay Dev Tools: disconnect/clear account cache), aligned across stores per HARNESS.md store-config discipline.
- A fresh-enough store state that the Launch Your Store task list still shows the payments setup task (reset the task if previously dismissed/completed), same on both stores.
- Local Transact platform reachable; Jetpack connection available or bypassed identically on both stores.
- Admin user with `manage_woocommerce`.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce (Home) → Launch Your Store task list. **Confirm the payments setup task renders** and names WooPayments as the recommended provider.
2. Open the task. **Confirm it hands off into the WooPayments NOX onboarding surface** (not a broken or generic settings link).
3. Complete Jetpack/WordPress.com connection if prompted; take the test-mode onboarding path. **Confirm the NOX flow completes without KYC.**
4. **Confirm the account is ready and payment methods are enabled** (Settings → Payments shows WooPayments active with the card method on).
5. Return to the task list. **Confirm the payments task is marked complete.**
6. The live-account half of this row cannot be exercised locally — record it BLOCKED (real KYC needs a live account).

End state: test-mode account onboarded via the LYS task on both stores, task marked complete; the row stays BLOCKED overall pending a manual/live KYC pass.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
