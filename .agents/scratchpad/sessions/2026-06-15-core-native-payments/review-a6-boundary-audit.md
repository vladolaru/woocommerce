---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-a6-boundary-auditor
created: 2026-06-21 03:01
target: A6 cleanup boundary
reconciles:
  - analysis-a5i-local-readiness-decision-rollup.md
  - analysis-a6-replanned-cleanup-readiness.md
  - review-post-a5h-next-slice-scan.md
  - staging-log.md
  - spec-conformance-baseline.md
  - implementation-plan.md
  - bc-manifest.md
status: final
last_updated: 2026-06-21 03:04
---

# A6 Boundary Audit

## Verdict

HOLD_FOR_RELEASE_DECISION.

Do not begin A6 product-code cleanup now, and do not re-run the stale harness-deletion plan. A5i already wrote the local-readiness decision line: local A4/A5 is green under recorded limitations, but production/default-on, mandatory rollout, canary/error-rate gates, WPCOM production readiness, exact production perf, release sequencing, and A6 cleanup remain deferred and fail-closed. The next gating move is a release/default-on decision from the release owner, not an A6 code slice and not another local gate write-back unless that release decision creates new required evidence.

## Safe Before Production/Default-On

- Decision and audit documentation only. A5i says the next move after A5h was a concise decision artifact and explicitly says it should not change product code or start A6 cleanup. Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md:25-35`, `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md:1429-1434`, `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md:528-532`.
- Read-only source inventory for future A6 planning is safe, but it should be labeled as planning/audit, not execution. The existing tracked source does contain Bucket D-shaped remnants, for example native settings still expose `_wcpay_feature_subscriptions`, disabled deprecated subscription UI, and tests around disabling that flag while preserving the Stripe Billing flag. Evidence: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:29`, `:279-281`, `:591-592`; `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2172-2204`; `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php:343-346`, `:934-936`.
- Preserve the local ignored harness for verification. It is evidence infrastructure, not tracked product scaffolding. Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md:44-45`, `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md:1432-1434`.

## Must Wait For Release/Cutover Sequencing

- A5 mandatory/default-on is not complete. The canonical A5 exit requires a canary cohort green on parity, error-rate, and perf before flipping the mandatory default; A5i records that production/default-on and release sequencing remain separate fail-closed decisions. Evidence: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md:133-145`; `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md:49-53`.
- Canonical A6 cleanup is downstream of A5 and requires safety gates. A6 removes cross-version compat, one-shot migration runners only after A5 effects are ensured-applied, god-class indirection remnants, facades only after internal callers migrate, and vendored/duplicated libs, with no Bucket C/E regressions and final perf evidence. Evidence: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md:147-151`, `:226-232`.
- Bucket D subscription-engine cleanup is not a blind delete. Bucket C gateway-to-WC-Subscriptions integration must be preserved, Bucket E persisted data and external contracts must remain stable, and the Stripe Billing engine drop is gated on confirming no live merchant data path is broken or migration has already run. Evidence: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md:27-31`, `:81-95`, `:111-115`.
- Tracks cleanup must wait for explicit dropped-surface disposition. Surviving surfaces preserve event names and prop contracts; dropped Bucket D surfaces retire naturally, but each retired event must be listed so expected attrition is not misread as telemetry drift. Evidence: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md:99-106`.
- Current product source still has hard data-safety guards around legacy Stripe Billing markers. Removing adjacent settings or guard behavior before release sequencing would need a fresh A6 plan and source-backed tests proving the guard remains intact. Evidence: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsLegacySubscriptionsGuard.php:31-64`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:451-456`, `:723-730`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php:786-798`.

## Stale Cleanup Target

The old A6 harness cleanup target is stale. `tools/woopayments-merge` has zero tracked files and is excluded by `.git/info/exclude`, so deleting it would remove ignored local verification infrastructure rather than tracked product scaffolding. Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md:35`, `:45`, `:53`; `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-post-a5h-next-slice-scan.md:67`.
