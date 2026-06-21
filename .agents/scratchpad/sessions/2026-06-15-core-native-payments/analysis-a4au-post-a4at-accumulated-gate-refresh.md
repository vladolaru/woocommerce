---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 20:10
last_updated: 2026-06-20 20:27
reconciles:
  - analysis-a4at-next-slice-selection.md
  - staging-log.md
  - implementation-log.md
  - review-a4au-settings-inventory.md
status: final
---

# A4au Post-A4at Accumulated Gate Refresh

> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

## Decision

A4au is a gate-refresh slice, not a new product parity rewrite. A4at landed after the A4ar full accumulated gate and changed provider route reachability, Settings > Payments fragments, legacy `/payments/*` aliases, gateway-disabled admin reachability, and the multi-currency admin-note URL. The next reliable high-throughput move is to fold A4at into the accumulated A4/N12 evidence by rerunning the accumulated gate and the A4at route-alias browser proof, then fix any source-backed regression the gates expose.

## Source-Backed Context

The current scratchpad state records A4ar as the latest full accumulated A4/N12 gate with no failures and no incomplete checks, A4as as the explicit admin-surface readiness decision after that gate, A5g as fail-closed rollout-default preservation, and A4at as the later provider route reachability product patch. That ordering means A4at is not yet part of the full A4 accumulated baseline, even though its focused PHPUnit, Playwriter route matrix, target debug log, Docker log scan, PHPCS, PHPStan, changelog validation, branch lint, and diff checks passed before commit.

Nash the 6th independently confirmed that the old N12 settings/admin gap list is mostly stale after A4n/A4ag/A4aj/A4q/A4r/A4s/A4t/A4u/A4v/A4w/A4x/A4ae/A4ah/A4ai/A4ak/A4al/A4am/A4an/A4ao/A4ap/A4aq/A4ar/A4as/A4at. The remaining high-leverage issue is not a known missing settings UI, but the need to rerun the accumulated gate after A4at and treat any failure as the next concrete slice.

The accumulated source gate already checks native admin route/chunk contracts and recognizes the allowed legacy redirect routes. The accumulated admin browser gate drives native provider routes under Core Settings > Payments and protected route availability, but it does not currently exercise the focused A4at deep-link alias matrix for `/payments/connect`, `/payments/onboarding`, `/payments/onboarding/kyc`, `/payments/fraud-protection`, `/payments/multi-currency-setup`, and `/payments/additional-payment-methods`. Therefore A4au should require both the accumulated A4aq orchestrator and the focused A4at alias proof, rather than claiming one fully covers the other.

## Gate Contract For This Slice

- Run `tools/woopayments-merge/a4aq-accumulated-gate.py` against the local target and reference stores with bundle, perf, admin browser, optional account-state admin, checkout browser, and log scan enabled.
- Rerun or preserve fresh A4at alias evidence proving setup aliases land on Overview for the current connected target account, fraud protection lands on the native fraud settings route, multi-currency lands on `/woopayments/settings#advanced`, additional payment methods lands on `/woopayments/settings#payment-methods`, and no reserved `section` query is present.
- Record evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4au-post-a4at-accumulated-gate/`.
- If the accumulated gate fails or reports incomplete coverage, do not weaken the harness to mask the issue. Verify the finding against source/browser evidence and turn it into the next product or harness-hardening slice.
- Keep WPCOM off-limits except for local read-only source inspection if needed. Do not modify WPCOM, do not access a WPCOM sandbox, do not push, and do not lint `.agents`.

## Result

The first full gate run failed only because the source gate had a stale plugin-era route allowlist; all browser, bundle, perf, and log phases passed with zero incomplete checks. The local ignored harness was fixed with a RED/GREEN regression in `test-a4au-admin-surface-regressions.py`, then the full rerun passed at `data/a4au-post-a4at-accumulated-gate-rerun-1/a4aq-accumulated-gate.json` with 13/13 checks passing, zero failures, and zero incomplete checks. A4at route evidence was copied into the same rerun directory as `a4at-route-reachability-browser.json`.

The pass records two limitations: the base target account still shows six optional protected route unavailable-guard checks, which are closed by the optional-account scenario, and the reference store emitted four existing non-severe `credit_card_form` deprecations. Target logs were clean.

## Settings Inventory Update

Hegel the 6th returned a fresh read-only settings parity inventory after this analysis was created. It confirms that many old N12 settings bullets are now closed, but it identifies real remaining merchant-facing gaps. The highest-priority next product slice is payment-method availability guidance parity: delayed-approval copy/link for Alipay and WeChat, pending-verification Overview link, rejected `Contact support` link, and missing-currency warning when multi-currency is off. Lower-priority candidates are VAT modal deep-link parity, fraud onboarding/tracking, express subpage notice/flag/content parity, section copy/loading polish, and sandbox switch-to-live tracking/visual polish. The report is recorded in `review-a4au-settings-inventory.md`.

This update does not change the immediate A4au gate-refresh boundary. A4at still landed after the last accumulated gate, so the right next step is still to rerun the accumulated gate and fold the route patch into the baseline. If that gate passes, use the Hegel report to select the next product slice.

## Open Questions

- If the accumulated gate passes but the focused route-alias proof is not folded into the orchestrator, record that as a standing coverage note rather than overstating the accumulated gate scope.
