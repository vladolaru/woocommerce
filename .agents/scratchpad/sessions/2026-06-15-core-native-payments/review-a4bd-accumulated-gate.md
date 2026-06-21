---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 02:26
tool: woocommerce-code-review
target: A4bd post-A4bc accumulated A4/N12 gate result
reconciles:
  - plans/2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md
  - analysis-a4bd-post-a4bc-accumulated-gate-refresh.md
last_updated: 2026-06-21 02:29
status: final
---

# A4bd Accumulated Gate Review

## Verdict

PASS_WITH_LIMITATIONS.

A4bd is sufficient to close as the post-A4bc accumulated gate refresh. I found no source-backed product blocker and no failed or incomplete gate result that requires code work before closeout. The closeout should, however, avoid saying this is unqualified full A4/N12 parity proof; it is an accumulated smoke/baseline gate backed by the earlier focused A4av-A4bc evidence, with several honest harness limitations.

## Evidence Reviewed

- `plans/2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md`: A4bd is explicitly a gate-refresh slice; product code should remain immutable unless the gate surfaces a verified regression.
- `analysis-a4bd-post-a4bc-accumulated-gate-refresh.md`: A4av-A4bc settings parity slices had focused tests/browser proof and needed folding into the accumulated rollup.
- `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`: top-level `status=pass`, 13 checks passed, zero failures, zero incomplete checks. Top-level limitations list the six target protected-route guard passes and five reference diagnostic log lines.
- `data/a4bd-post-a4bc-accumulated-gate-admin-browser-gate.json`: `status=complete`, 57 browser results, no failures. The settings/fraud/express surfaces passed on desktop/mobile for target and reference. The base target Documents/Card Readers/Capital checks passed as `coverageStatus=unavailable-guard-pass`, not content parity, because the current target account state blocks those routes.
- `data/a4bd-post-a4bc-accumulated-gate-optional-admin-admin-browser-gate.json`: `status=complete`, seven target-only optional-account checks passed after setting `is_documents_enabled`, `has_card_readers_available`, and `has_previous_capital_loans`, and the rollup records exact cache restoration. The rollup also records `reference_control: "not mutated; this scenario closes target protected-route coverage only because local cache flags cannot synthesize real reference Capital loan platform data"`.
- `data/a4bd-post-a4bc-accumulated-gate/a4bd-post-a4bc-accumulated-gate-checkout-browser-gate.json`: `status=complete`, 24 checkout/browser results, no failures, with per-surface caveats.
- `bundle-reference.json`, `bundle-target.json`, and the rollup `bundle-compare` stdout: explicit budget comparison passed.
- `perf-reference.json`, `perf-target.json`, and the rollup `perf-compare` stdout: measured perf gate passed, but its own stdout says timings are coarse local smoke signals meant for large deltas, query growth, and missing coverage, not exact latency proof.
- `implementation-log.md` A4bc/A4bd entries and `staging-log.md` append area: A4bc is closed with focused evidence; A4bd is not yet appended to staging-log at the append point, so the final staging entry is the natural place to carry the limitations below.

## Findings

No blocker.

The pass is not overstated if closeout says: "the accumulated A4/N12 rollup passed after A4bc with zero failures/incompletes, under the recorded harness limitations." It is overstated if closeout says or implies: "full optional admin route content parity, exact perf parity, exhaustive checkout parity, or deep re-verification of every A4av-A4bc settings copy/control is now proven by A4bd alone."

The only source-backed follow-up needed before closeout is documentation/logging, not product code: record the limitations below in the A4bd closeout/staging entry.

## Limitations To Record

1. Optional admin routes are not full reference content parity. The base admin browser gate has six target `unavailable-guard-pass` results for Documents, Card Readers, and Capital across desktop/mobile in `data/a4bd-post-a4bc-accumulated-gate-admin-browser-gate.json`; those target records show `targetSurfaceAvailable=false` and the route availability map has `/woopayments/documents`, `/woopayments/card-readers`, and `/woopayments/loans` as `false`. The optional-account gate then proves target rendering only after synthetic cache flags, and explicitly says the reference store was not mutated.
2. Reference optional-route checks are also account/feature gated in this local account. The reference Documents/Card Readers/Capital records in `a4bd-post-a4bc-accumulated-gate-admin-browser-gate.json` have `strictTokens=false`, `referenceOptional` explaining local account/feature gating, and missing optional surface tokens. Treat those as reachability/gating smoke evidence, not full reference content parity.
3. Perf evidence is a large-delta smoke gate. `perf-reference.json` and `perf-target.json` use `single_invocation_blocked_http` for process/refund/capture probes, and REST boot is `preinitialized_snapshot_only`; the rollup `perf-compare` stdout explicitly says it does not prove exact latency.
4. Checkout browser evidence has explicit caveats. The checkout gate caveats include no failure on BNPL/local payment-method fields, no order-attribution hidden-input comparison, no full setup submission for add-payment-method, fixture dependence for cart/product express, and no WooPay order-pay assertion.
5. A4bd's admin browser settings tokens are representative, not exhaustive. The settings root checks assert broad section tokens, while the detailed A4av-A4bc settings parity claims still rest on their focused tests/reviews/browser proofs, especially `data/a4bc-settings-loading-docs-copy-parity/target-settings-page.json` for A4bc docs/copy/loading behavior.
6. The reference log diagnostics are reference-only and non-severe for this target gate. The rollup log scan shows target debug/docker diagnostics clean, while the reference store has one WooPayments textdomain notice and four `credit_card_form` deprecations.
