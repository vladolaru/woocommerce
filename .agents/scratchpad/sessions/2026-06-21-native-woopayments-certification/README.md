# 2026-06-21 — Native WooPayments certification

> **Prompt:** "Yes. I want you to certify it. Create a workflow for this."

Independent certification of the native WooPayments-in-core implementation on `exp/core-native-payments`, via a 10-dimension verify→falsify workflow (21 agents) plus supervisor re-confirmation of the verdict-driving findings.

This is **supervisor output** — kept in its own session folder, deliberately separate from the implementor's `2026-06-15-core-native-payments` working session.

## Artifacts
- [certification.md](certification.md) — the certification: two-scope verdict (dark launch vs enablement), per-dimension table, blockers B1–B3, confidence boundaries.
- **Critical-flows parity suite** → `tools/woopayments-critical-flows/` (separate folder, repo root, git-excluded like the harness). The full WooPayments critical-flows matrix run as a hybrid suite: deterministic scripts (WP-CLI/API/playwright-cli + store-state assertions) + AI-agent-driven browser testing, deliberately overlapping, dual-store against the `:8082` reference oracle. Pre-seeds B2/B3 as acceptance-test rows. See its `README.md`.

## Headline
- **Dark launch (ship behind off-by-default flags): CERTIFIED, conditional on B1** (a one-line CI-red test fix).
- **Enablement (turn the flag on): NOT CERTIFIED** — B2 (saved-card checkout regression, slipped the implementor's gate) must be fixed + runtime-confirmed; B3 (subscriptions admin change-method) dispositioned; plus production rollout evidence (separate release-owner decision).
