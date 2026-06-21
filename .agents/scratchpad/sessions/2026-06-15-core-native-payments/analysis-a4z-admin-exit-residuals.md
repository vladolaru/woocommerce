---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 20:19
target: A4/N12 native admin residual parity after A4y
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - implementation-log.md
status: draft
last_updated: 2026-06-19 20:33
---

# A4z Admin Exit Residuals

## Trigger

A4y is committed locally. The next step is to continue reopened A4/N12 without backtracking completed slices.

## Known Committed Baseline

A4i through A4y now cover native admin reachability, settings payment methods/BNPL, row fee and duplicate notices, express checkout settings subpages, fraud settings and advanced rules, settings residuals including sandbox switch-to-live, payout bank-account and save busy state, admin menu badges, PM promotions/spotlight, overview financial/action shell, money-movement list/query, Capital/Card Readers, Uncaptured authorizations, Documents/VAT, Reports, payout/payment details, and dispute challenge parity. A4v's staging status was corrected from stale "awaiting commit" to committed based on implementation log and git history.

Native admin readiness remains fail-closed. The likely remaining work is no longer one obvious missing route; it is the residual reference-vs-target parity audit and the widened A4 exit gate. The current investigation will verify whether any source-backed gaps remain before writing the next implementation plan.

## Active Questions

- Does the native Overview still miss reference dashboard content after A4q/A4r/A4x/A4y, such as Connect notifications, inbox notifications, loan summary fragments, full account details, or dispute-readiness modules?
- Does the native settings surface still miss any reference copy/content/visual grouping after A4j-A4n/A4p, beyond known intentional native/Stripe Billing differences?
- Does the current A4 admin-surface harness actually encode the N12 exit gate dimensions: persistent reachability, per-surface/section functional and visual parity, copy/content parity, disabled-feature bundle behavior, and fail-closed native readiness?
- Are any previous staging-log entries stale enough to mislead a resumed agent into reopening committed work?

## Findings

- Current slice checkpoint: A4z has not started product implementation and has no plan yet. It exists to prevent a compaction from treating A4y as still open or jumping into an arbitrary residual. A4y is committed and closed; native admin readiness remains fail-closed until A4z either identifies and closes remaining source-backed residuals or records a widened A4/N12 exit gate.
- Parallel explorer status: Maxwell, Hegel, and Einstein returned read-only reports. Their claims were locally source-sampled before recording here.
- Gate coverage finding from Maxwell: `tools/woopayments-merge/a4-admin-surface-gate.py` currently passes and measures all eight native admin chunks, but it is not an N12 exit gate. `tools/woopayments-merge/HARNESS.md` frames it as a narrow route/chunk/source guardrail, while admin behavior remains a judged browser runbook. Source inspection confirms the Python gate checks expected chunk names, plugin-era route literals, private registry tokens, admin navigation routes, and selected token contracts, but does not browser-drive every route/subroute, does not exercise account-state matrices, does not encode full per-surface functional/visual/copy parity, does not assert disabled-feature no-chunk/no-REST behavior, and does not prove cutover readiness. This is a gate-hardening blocker before A4/N12 can be declared green.
- Overview residual finding from Hegel, verified against source: native Overview is still narrower than the reference. The reference Overview renders embedded Connect notification banner handling, inbox notifications, dispute readiness, active loan summary, and the rich `AccountDetails` card, while native `overview/page.tsx` currently renders `OverviewNotices`, connection modal, task list, `WooPaymentsAccountSettings`, spotlight, balance card, and payouts card. A4q/A4r/A4x/A4y closed important pieces, but not those reference Overview sections.
- Source-backed Overview residuals to track: embedded Connect notifications (`EmbeddedConnectNotificationBanner` and HTTPS/error/completion behavior), WooPayments-source inbox notifications, dispute readiness card/modal, active Capital loan summary embedded on Overview, rich account details card with payout status/account tools/fees, reconnect task URL generation, and smaller copy/gating drift around sandbox success/test-mode notices, dispute task copy, and connection-success modal suppression for test-mode onboarding. The native `WooPaymentsOverviewService` still returns `wpcom_reconnect_url => ''`, so reconnect is intentionally unimplemented rather than accidentally wired.
- Settings/admin residual finding from Einstein, source-sampled locally: settings parity is still incomplete at the copy/content/control level. Gaps include General test-mode help links, Transactions saved-card platform-storage copy and manual-capture/statement/support helper behavior, Notifications confirmation-email/validation/status behavior, Advanced multi-currency/subscriptions/debug-mode reference copy and dev-mode behavior, Express checkout live/fuller appearance preview and option descriptions, transaction detail composed surface depth, and dispute challenge confirmation resources/status guidance. Stripe Billing UI remains intentionally excluded.
- Stale N12 findings to stop reopening: Reports and Documents absence is stale because native routes/pages now exist; money-movement list/payout stubs are stale because transactions, disputes, payouts, payout details, export/summary/list work exists; PM promotions/spotlight, menu badges, fraud settings, express checkout route/subpages, Capital, and Card Readers have source-backed native implementations. These can still have polish gaps, but they are not missing-route blockers anymore.
- Immediate next action: pick the next coherent A4z implementation/gate-hardening chunk. The strongest product residual cluster is Overview/account-dashboard parity, because it contains several still-missing reference sections on one surface. A second coherent cluster is settings/detail copy and detail-page depth. The final A4/N12 exit gate must be widened after these residuals or in parallel as a fail-closed harness slice; the current green `a4-admin-surface-gate.py` must not be treated as enough.
- Compaction checkpoint: as of 2026-06-19 20:33 EEST, A4z has no product diff and no product plan yet. `git status --short --untracked-files=all` was clean before this scratchpad-only update. Continue by writing the A4z JIT plan, most likely for Overview/dashboard residual parity, and keep native admin readiness fail-closed until residuals plus the widened N12 exit gate pass.
