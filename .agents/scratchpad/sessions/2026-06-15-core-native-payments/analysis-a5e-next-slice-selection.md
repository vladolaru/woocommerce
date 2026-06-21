---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 01:11
last_updated: 2026-06-20 01:15
target: next slice after A5d native admin readiness
reconciles:
  - supervisor-prompt-2026-06-17-1311.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - spec-conformance-baseline.md
  - implementation-log.md
status: draft
---

# A5e Next Slice Selection

> **Prompt:** "ok. continue"

## Selection Question

A5d closed the native admin-surface readiness default after the A4ab/A5d route/chunk/browser/log gate. The next slice should keep the overall goal moving without reopening stale A4 parity work or skipping cutover safety gates. Candidate directions to verify against source before planning: an A5 cutover execution/mandatory-handoff slice, genuine B3 multi-currency cleanup, or another canonical residual surfaced by the stage-boundary docs.

## Initial Constraints

- WPCOM code changes and WPCOM sandbox access remain off limits.
- The current WC Core branch is `exp/core-native-payments`; no push and no trunk work.
- Scratchpad docs stay under this session folder and are not markdown-linted.
- Playwriter remains the browser tool if browser proof is needed.
- A5d commits are `e7612bf9e7` and `2555f71968`, with Git-visible product status clean immediately after commit.

## Source-Backed Findings

The A5 cutover path is still intentionally staged. `WooPaymentsCutoverController::is_soft_cutover_enabled()` defaults the soft notice to enabled, while `is_mandatory_cutover_enabled()` defaults mandatory auto-deactivation and activation blocking to disabled. `NativePaymentsRuntimeArbiter::get_runtime_owner()` remains plugin-wins when the standalone plugin is active, so any real merchant handoff must prove the plugin-active path before changing mandatory defaults.

A5d made the admin readiness preflight default true after the A4ab native admin route/chunk/browser gate, but it did not remove the other preflight blockers. `get_preflight_failures()` still checks native runtime enablement, native transport, platform connection/user-token readiness, provider-event disposition, operational queue disposition, financial remediation scheduling, and legacy Stripe Billing markers. That means the next useful A5 step is not another source-only default flip; it is a browser and CLI proof that the merchant-visible soft cutover works when the standalone WooPayments plugin is active and every guard is clean.

The A5 explorer independently recommended `A5e soft cutover handoff proof`: target store with standalone WooPayments active, clean preflight, soft notice visible, nonce-protected disable action succeeds, plugin deactivates, native runtime owns the next request, mandatory filter still works when forced on, negative blockers prevent deactivation, and fresh logs stay clean.

The B3 explorer independently recommended not preempting A5 with canonical B3. The old concrete WooPayments imports in generic `Internal\MultiCurrency` appear source-resolved and regression-covered; residual B3 work remains a bounded audit/disposition item, especially the subscriptions compatibility probes and multi-currency assets, but it is not the next cutover blocker. The stale top-of-log B3 status should be reconciled during documentation cleanup, not treated as a reason to pause A5e.

## Decision

Proceed with an A5e proof-first slice: build local harness gates for plugin-active soft cutover and run them against the target store. Product code should remain unchanged unless the gate exposes a real defect. Mandatory cutover remains default-off until the handoff path is proven in the local browser and CLI environment. After A5e, check the N8 advisory and then reopen A4 feature parity per N12 before later slices.
