---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 23:18
target: A4ab widened native admin exit gate after A4aa
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4z-admin-exit-residuals.md
  - staging-log.md
  - implementation-log.md
last_updated: 2026-06-20 00:41
status: final
---

# A4ab Admin Exit Gate Selection

## Trigger

A4aa is closed and committed locally. The remaining A4/N12 blocker recorded in staging and the baseline is not another already-known missing route; it is the widened exit gate itself: prove persistent reachability, route/subroute rendering, copy/content/visual smoke, disabled-feature no-chunk/no-REST behavior where feasible, logs, and bundle evidence across accumulated A4.

## Source-Backed Selection

N12 says the earlier A4h source gate is insufficient because it verifies route architecture, chunks, registry ownership, and selected source contracts, but not what a merchant experiences. The current `tools/woopayments-merge/a4-admin-surface-gate.py` confirms this: it scans source tokens and built chunks, but does not drive the browser route matrix, compare target/reference content, exercise persistent nav, inspect disabled-feature behavior, or scan runtime logs. The A4z residual analysis recorded the same gap before A4z/A4aa closed the known Overview and settings contact clusters. Therefore the next coherent slice is A4ab: build and run the widened A4/N12 exit gate, fail closed on any surfaced parity defect, and only call A4 ready if the accumulated surface passes rather than assuming it.

## Execution Posture

Do not flip `FILTER_NATIVE_ADMIN_SURFACES_READY` in A4ab unless the widened gate and the standing stage-boundary review pass without blockers and the resulting change is explicitly planned as an A5 readiness slice. The first A4ab deliverable is evidence: a source gate rerun, a Playwriter browser matrix over target/reference routes, log scans, and review-agent critique of the gate coverage. Any product failure becomes either a bounded in-slice fix with fresh focused gates or the next canonical A4 product slice.

## Closeout Result

A4ab closed as a widened admin exit-gate slice, not an A5 readiness flip. The gate exposed two bounded native regressions and one harness coverage gap: the native Overview route needed a route-level heading, embedded account settings needed to render as an `h2` when mounted inside Overview, multi-currency analytics registration needed to avoid resolving the default currency during WooCommerce construction, and the Playwriter harness needed target asset enforcement plus reference-scoped `useSelect` console waivers. The product fixes are committed locally as `410ad0e3ec` plus changelog `c293d2f1cc`; the ignored harness remains local in `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`.

Final evidence: focused frontend and PHP tests passed, PHP syntax/PHPCS/PHPStan passed for the touched runtime files, admin `ts:check`, targeted ESLint/Stylelint, and admin bundle build passed, the source/chunk gate passed with native admin chunks totaling `197638` raw / `53642` gzip bytes, and the stricter Playwriter matrix passed 57 checks with 0 failures. Target `debug.log` stayed at 0 lines after the matrix and focused heading check. The reference store still emitted reference-side `settings-ui` asset-registry and WooPayments plugin noise, which is recorded as reference-local caveat evidence rather than target regression evidence. Native admin readiness remains fail-closed.
