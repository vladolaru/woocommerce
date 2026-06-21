---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 18:03
last_updated: 2026-06-20 18:27
target: A5 next-slice reground after A4as
reconciles:
  - staging-log.md
  - spec-conformance-baseline.md
  - implementation-log.md
  - README.md
  - analysis-a4as-admin-readiness-decision.md
status: draft
---

# A5 Next-Slice Reground

> **Prompt:** "Continue working toward the active thread goal."

## Initial Current-State Check

The product worktree is clean after A4as. Current branch is `exp/core-native-payments`; `HEAD` is `e500c60f96` (`fix(payments): mark native admin surfaces ready`) and the branch is ahead of `trunk` by 321 commits. The A4as staging entry records that native admin-surface readiness now defaults true after A4ar closed the accumulated A4/N12 gate incompletes. The target preflight evidence at `data/a4as-cutover-state.json` reports native ownership, empty preflight failures, empty probe failures, and `ready: true`.

The canonical implementation plan places A5 after A4: native default and cutover. A5 requires soft notice plus one-click disable, mandatory auto-deactivation gated by version/default-on flag, activation guard, webhook and Action Scheduler continuity across the swap, financial migrations, owner-token/registry verification, multisite correctness, and plugin re-activation returning the site to plugin-wins when mandatory cutover is not active. Earlier staging entries include prior A5 attempts and an A5e cutover-notice proof, but A4ac later deliberately re-gated admin readiness and subsequent A4 slices continued from that fail-closed baseline. A4as is therefore the current authoritative readiness reopening point for the next A5 work.

## Working Hypothesis

The next slice should not re-open A4 immediately because the standing task says to re-open A4 only after A5c. The next slice should re-ground A5 against current source and local runtime: identify which A5 gates remain unproven after A4as, then either run the smallest decisive A5 gate rerun or implement the first source-backed A5 gap. The likely highest-leverage candidate is a post-A4as cutover rehearsal gate that proves soft/mandatory/plugin-reactivation behavior from the current branch state without weakening mandatory cutover's default-off guard.

## Reground Findings

A4ar is the relevant A4/N12 green line behind A4as. The staging and baseline entries record `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json` as passing with no failures and no incomplete checks after the optional-admin account-state and money-path perf-fixture gaps were closed. A4as then changed only the admin-surface readiness default and verified the current target native preflight at `data/a4as-cutover-state.json` with empty failures.

A5c is already committed and remains the platform connection / WCPay V1 transport readiness slice. Its evidence includes local WPCOM readiness, owner user-token readiness, and transport-continuity probes; those scripts still exist under `tools/woopayments-merge/`. A5e is also already committed and remains the original soft/mandatory handoff proof, including browser evidence for soft cutover, mandatory auto-deactivation, activation guard, and negative preflight blocker behavior.

The reason A5e should not be treated as current enough by itself is sequencing, not source invalidation. A5e ran before A4ac reopened and reblocked admin readiness, and before the long reopened-A4 parity sequence ended at A4ar/A4as. A4as now makes otherwise-clean current target preflight ready by default, so the cutover handoff should be rehearsed again against the current branch and current local store state before later A5 claims.

Current source supports that rehearsal without needing a product refactor first. `WooPaymentsCutoverController::get_preflight_failures()` now defaults `FILTER_NATIVE_ADMIN_SURFACES_READY` to true but still preserves independent blockers for native runtime, native transport/provider readiness, platform connection, provider events, operational queue hooks, fee remediation scheduling, arbitrary preflight filters, and legacy Stripe Billing markers. Mandatory cutover still defaults false through `FILTER_MANDATORY_CUTOVER_ENABLED`, `maybe_auto_deactivate_plugin()` only runs when that filter is enabled and the plugin owns the runtime, and the activation guard only blocks WooPayments when mandatory cutover is enabled, preflight is ready, and `WC_ALLOW_MERGED_FEATURE_PLUGINS` is not true.

The existing ignored A5 harness is useful but fragmented. `a5-cutover-state.php` records runtime owner, active plugin slugs, preflight failures, soft notice, and consistency failures. `a5-cutover-browser-gate.playwriter.mjs` clicks the real soft notice control and records browser/network/console evidence. `a5-mandatory-browser-gate.playwriter.mjs` checks the mandatory success notice and inactive plugin state after setup. `a5-user-token-readiness.php`, `a5-transport-continuity.php`, and `a5-local-wpcom-readiness.sh` cover the local WPCOM/user-token/transport side. The gap is orchestration and current-state rerun discipline: setup/restore, progress output, blocked mandatory proof, activation guard proof, and log evidence should be wrapped into one post-A4as A5f gate so failures cannot be hidden by manual sequencing.

## Next Slice Selection

Select A5f: post-A4as cutover rehearsal gate. This should be a harness/evidence-first slice unless the rehearsal exposes a source-backed product bug. The gate should start from current target native ownership, prove clean baseline preflight, activate the standalone WooPayments plugin locally, verify the soft notice and one-click disable path through the browser, prove native ownership afterward, re-activate the plugin under temporary mandatory cutover, prove mandatory auto-deactivation and activation guard behavior, prove a synthetic blocker keeps mandatory cutover fail-closed, re-run local WPCOM/user-token/transport probes, scan fresh logs, restore the target to native-owned/inactive, and record everything under new A5f evidence names.

## A5f Implementation Checkpoint

Created `plans/2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md` and started the ignored local A5f harness. Added `tools/woopayments-merge/test-a5f-cutover-rehearsal.py` first; the corrected RED failed because `tools/woopayments-merge/a5f-cutover-rehearsal.py` did not exist. Added the orchestrator with local Docker WP-CLI validation, progress output, `wp eval-file -` probe execution, temporary A5f MU helper install/remove, Playwriter gate execution, rollup writes after every phase, and cleanup on failure. GREEN `python3 tools/woopayments-merge/test-a5f-cutover-rehearsal.py` passed all 3 checks, and `python3 -m py_compile tools/woopayments-merge/a5f-cutover-rehearsal.py tools/woopayments-merge/test-a5f-cutover-rehearsal.py` passed. No WooCommerce product code has changed in A5f so far.

First full A5f run reached the final debug-log scan and failed closed on two PHP notices. Source inspection showed one notice was harness-induced: `trigger-blocked-mandatory-admin-init` manually fired `admin_init` from WP-CLI, which caused WooCommerce privacy-policy registration to run outside a real admin request. Added RED checks for expected activation-guard failures and browser-driven blocked mandatory proof, then updated the orchestrator so the activation guard records as a passed expected-failure phase and the blocked mandatory path uses `a5-blocked-mandatory-browser-gate.playwriter.mjs` against a real `wp-admin/plugins.php` request. GREEN checks now pass: `python3 tools/woopayments-merge/test-a5f-cutover-rehearsal.py`, `python3 -m py_compile tools/woopayments-merge/a5f-cutover-rehearsal.py tools/woopayments-merge/test-a5f-cutover-rehearsal.py`, and `node --check tools/woopayments-merge/a5-blocked-mandatory-browser-gate.playwriter.mjs`. The remaining notice to isolate on the next A5f run is the early WooPayments textdomain load during a standalone-plugin-active browser window; do not classify it without a reference/target comparison.

Second full A5f run removed the WP-CLI-induced notice and reached the final scan with only one PHP notice: early `woocommerce-payments` textdomain load at `2026-06-20T15:20:26Z`, during the soft/browser handoff window. A reference-store comparison loaded `http://localhost:8082/wp-admin/plugins.php` with standalone WooPayments active and did not emit the WooPayments textdomain notice; it emitted unrelated reference-environment diagnostics instead. A temporary target-local `doing_it_wrong_run` MU probe plus an isolated plugin-active page load and real `Disable WooPayments` click did not reproduce the notice, which points away from a deterministic product path and toward ambient browser/page interference during the previous full run.

A fresh Playwriter session then exposed a harness race: mandatory auto-deactivation completed, but the mandatory success notice was missing because the one-shot status had likely been consumed outside the intended gate page. Added RED checks for failed Playwriter evidence copying and isolated browser page ownership. Updated the orchestrator to copy Playwriter source evidence on failure only when the source evidence changed during that command window, and updated the soft, mandatory, and blocked browser gates to create their own page and close it in `finally`. GREEN checks now pass: `python3 tools/woopayments-merge/test-a5f-cutover-rehearsal.py`, `python3 -m py_compile tools/woopayments-merge/a5f-cutover-rehearsal.py tools/woopayments-merge/test-a5f-cutover-rehearsal.py`, and `node --check` for `a5-cutover-browser-gate.playwriter.mjs`, `a5-mandatory-browser-gate.playwriter.mjs`, and `a5-blocked-mandatory-browser-gate.playwriter.mjs`. No WooCommerce product code has changed in A5f so far.
