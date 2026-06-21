---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 02:16
target: A4bd post-A4bc accumulated A4/N12 gate refresh
reconciles:
  - staging-log.md
  - implementation-log.md
  - spec-conformance-baseline.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4bc-next-slice-selection.md
  - review-a4bc-navigation-badging-check.md
status: draft
---

# A4bd Post-A4bc Accumulated Gate Refresh

## Starting Point

A4bc is closed and committed locally as source/tests `c3c5e3b9a3` plus changelog `b969239346`, git range `4187640e01...b969239346`. The product work restored native WooPayments settings loading skeletons, section-level docs/copy parity, and the a11y status/busy signal for the initial loading branch.

The last accumulated A4/N12 gate refresh after reopening A4 was A4au, which folded A4at into the baseline and passed at `data/a4au-post-a4at-accumulated-gate-rerun-1/a4aq-accumulated-gate.json`. Since then, A4av through A4bc landed multiple merchant-facing settings changes. Those slices had focused tests, browser proof, and branch gates, but they have not yet been folded into the accumulated A4/N12 rollup.

## Source-Backed Direction

The A4bc navigation/badging check verified that persistent navigation and Disputes/Transactions submenu badges are closed under the Core-native route model. The plugin-era setup-required top-level badge remains parked as a separate Core product/navigation decision, not a source-backed blocker for the next slice.

The A4au settings inventory residuals have now been worked through in broad chunks: A4av closed payment-method guidance, A4aw closed VAT deep-link parity, A4ax closed express settings flags/notices/content, A4ay/A4az/A4ba closed fraud onboarding/ruleset/advanced UI parity, A4bb closed General controls, and A4bc closed section loading/docs/copy polish. No fresh source-backed settings residual is currently stronger than the accumulated gate refresh.

Decision: A4bd should run the full `a4aq-accumulated-gate.py` rollup after A4bc. Treat product code as immutable unless the gate surfaces a verified regression. If it passes, record the new accumulated baseline. If it fails, verify the failure against source/browser evidence before changing product or harness code.

## Evidence Target

Use `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate` as the first evidence directory. If the first run fails for a verified harness expectation that needs updating, write reruns to suffixed directories rather than overwriting the failed run.

## Constraints

No WPCOM sandbox access or code changes. Local WPCOM remains read-only if needed, but this slice is expected to use the existing local target/reference stores and ignored harness only. No Stripe CLI use is expected. No push. Do not lint `.agents`. Keep scratchpad lines visually wrapped by the editor, not hard-wrapped.
