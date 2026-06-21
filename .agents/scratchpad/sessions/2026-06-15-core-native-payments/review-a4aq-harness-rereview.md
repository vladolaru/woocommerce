---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 15:37
tool: woocommerce-code-review; pirategoat-tools:code-reviewer
target: A4aq ignored verification harness rereview
reconciles:
  - review-a4aq-e2e-harness.md
  - review-a4aq-performance.md
  - review-a4aq-reliability.md
status: final
---

# A4aq Harness Rereview

## Scope

Reviewed only the requested ignored harness files under `tools/woopayments-merge/`. I did not modify WooCommerce product code, WPCOM code, or the WooPayments reference plugin, and I did not access any WPCOM sandbox.

## Strengths

The main hardening fixes are present: checkout base/route overrides are guarded by explicit state flags and exact host checks, accumulated Playwriter state cleanup now deletes stale base/route/skip/page state, browser evidence URLs are validated against expected local hosts, target express selectors are split into separate ECE and WooPay requirements, bundle `allow_new` assets now have explicit byte ceilings, gateway registration is labeled `warmed_in_process`, and log scanning now uses Docker `--since` plus a debug-log byte offset.

## Findings

### High: Unavailable target admin routes still count as passing browser checks

`tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs:373` marks protected target routes unavailable from `adminRouteAvailability`, but the route check then suppresses the real expected-token failures at `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs:378`, suppresses missing target assets while unavailable at `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs:383`, and still returns `passed: true` at `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs:421` when the generic unavailable token is present. The accumulated gate notices this at `tools/woopayments-merge/a4aq-accumulated-gate.py:501`, but only appends a limitation at `tools/woopayments-merge/a4aq-accumulated-gate.py:509`; it does not fail or mark the browser gate incomplete. Current evidence confirms 6 target checks for `documents`, `card-readers`, and `capital` are `coverageStatus: "unavailable-guard-pass"` with missing product tokens while the child gate exits 0.

Why it matters: if native route availability regresses from available to unavailable, the browser gate can still go green and the full gate can eventually pass once the known perf fixture gap is resolved. That still overstates admin parity coverage.

Fix guidance: make target protected-route unavailability non-passing for selected admin surfaces unless the surface explicitly declares a target-side optional/gated state. Either set the child result `passed: false`/`coverageStatus: "unavailable"` or have `validate_browser_evidence()` mark `unavailable_guard_passes` as `incomplete`/`fail`, not just a limitation. Keep the current limitation text as useful evidence, but do not let it be a passing parity result.

### High: Target checkout warnings are still ignored by broad per-message allowlists

`tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:353` receives store/surface/viewport context, but several allowlists still ignore target warnings solely by exact message shape: the Blocks `useSelect` warning at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:358`, the `wcBlocksData` dependency warning at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:365`, the interactivity deprecation at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:377`, Amazon Pay fetch failures at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:383`, and Stripe HTTPS/domain warnings at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:419`. Those ignored target issues are removed from `consoleIssues` at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:689`, and pass/fail only checks non-ignored `consoleIssues` at `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs:791`.

Why it matters: current evidence contains target-side ignored checkout warnings while reporting 24/24 pass. Some allowed messages, especially the `wcBlocksData` missing dependency warning, can be caused by a real native script-registration regression. Exact text matching helps, but without target source/location/count constraints it can still hide target-only product regressions.

Fix guidance: default target checkout warnings/errors to fail. For unavoidable local noise, require explicit expected-warning metadata keyed by store, surface, viewport, message pattern, source URL/stack owner, and max count; otherwise mark ignored target warnings as incomplete in the accumulated gate. Reference-only plugin noise should stay reference-scoped.

### Medium: Accumulated gate local-only validation does not require local WP-CLI execution

`tools/woopayments-merge/a4aq-accumulated-gate.py:119` through `tools/woopayments-merge/a4aq-accumulated-gate.py:127` only rejects `--ref-wp`/`--target-wp` strings containing `wpcom.com`, `wordpress.com`, or `a8c.com`. The perf probe then executes the supplied command through `bash -c` at `tools/woopayments-merge/perf-surface-gate.sh:584`. That leaves remote WP-CLI forms such as `wp --ssh=...`, `wp --http=...`, or commands using other sandbox hostnames outside the forbidden substrings able to run under a gate that describes itself as local-only.

Why it matters: the browser side is now pinned to local hosts, but the WP-CLI/perf side can still be pointed elsewhere accidentally. That is a hidden non-local access risk and can make perf/log evidence describe a different environment from the browser evidence.

Fix guidance: parse `--ref-wp` and `--target-wp` with `shlex` in the accumulated gate and fail closed unless they are approved local forms, ideally `docker exec` against the expected local containers or explicit local WP binaries with no `--ssh`, `--http`, remote URLs, or non-local host tokens. Also consider passing WP commands as structured argv instead of executing a concatenated string through `bash -c`.

## Residual Risks

I did not flag the known `perf-compare` incomplete status; the current incomplete aggregate result is honest for missing money-path fixtures. The checkout gate remains smoke/parity evidence rather than full checkout coverage: it still does not submit card/WooPay/ECE flows, SCA, setup intents, or visual diff styling beyond selector/resource/screenshot evidence. Syntax checks passed for both Playwriter files, both Python files, and `perf-surface-gate.sh`.
